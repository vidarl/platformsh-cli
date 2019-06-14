<?php
namespace Platformsh\Cli\Command\Db;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\ApiFeatureMissingException;
use Platformsh\Cli\Model\AppConfig;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Util\YamlParser;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Platformsh\Cli\Local\BuildFlavor\Symfony;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Process\Exception\ProcessFailedException;

class DbSizeCommand extends CommandBase
{
    
    const RED_WARNING_THRESHOLD=90;//percentage
    const YELLOW_WARNING_THRESHOLD=80;//percentage
    const BYTE_TO_MBYTE=1048576;

    const WASTED_SPACE_WARNING_THRESHOLD=200;//percentage

    private $blnShowInBytes=false;

    protected function configure()
    {
        $this->setName('db:size')
            ->setDescription('Estimate the disk usage of a database')
            ->addOption('bytes', 'B', InputOption::VALUE_NONE, 'Show sizes in bytes.')
            ->addOption('cleanup', 'C', InputOption::VALUE_NONE, 'Check if tables can be cleaned up and show me recommendations (InnoDb only).')
            ->setHelp(
                "This is an estimate of the database disk usage. The real size on disk is usually a bit higher because of overhead."
            );
        $this->addProjectOption()->addEnvironmentOption()->addAppOption();
        Relationships::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition());
        Ssh::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $appName = $this->selectApp($input);
        $this->blnShowInBytes = $input->getOption('bytes');
        
        // Get the app config.
        $webApp = $this->api()
            ->getCurrentDeployment($this->getSelectedEnvironment(), true)
            ->getWebApp($appName);
        $appConfig = AppConfig::fromWebApp($webApp)->getNormalized();
        if (empty($appConfig['relationships'])) {
            $this->stdErr->writeln('No application relationships found.');
            return 1;
        }
        $sshUrl = $this->getSelectedEnvironment()->getSshUrl($appName);

        /** @var \Platformsh\Cli\Service\Relationships $relationships */
        $relationships = $this->getService('relationships');
        $database = $relationships->chooseDatabase($sshUrl, $input, $output);
        if (empty($database)) {
            $this->stdErr->writeln('No database selected.');
            return 1;
        }
        if (!isset($database['service'])) {
            $this->stdErr->writeln('Unable to find database service information.');
            return 1;
        }
        $dbServiceName = $database['service'];
        
        // Get information about the deployed service associated with the
        // selected relationship.
        $deployment = $this->api()->getCurrentDeployment($this->getSelectedEnvironment());
        $service = $deployment->getService($dbServiceName);
        
        $this->stdErr->writeln(sprintf('Checking database service <comment>%s</comment>...', $dbServiceName));

        $this->showEstimatedUsageTable($service, $database, $appName);
        $this->showInaccessibleSchemas($service, $database);
        
        if($database['schema']!='pgsql' && $input->getOption('cleanup')) {
            $this->showInnoDbTablesInNeedOfOptimizing($appName, $database);            
        }
        return 0;
    }

    private function showInnoDbTablesInNeedOfOptimizing($appName, $database) {
        $this->stdErr->writeln('');
        $this->stdErr->writeln('---------------------------------------');
        $this->stdErr->writeln('');

        $this->stdErr->write('Checking InnoDB data_free...');
        $tablesNeedingCleanup = explode(PHP_EOL,$this->runSshCommand($appName, $this->mysqlTablesInNeedOfOptimizing($database)));
        
        if(count($tablesNeedingCleanup)) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('You can save space by cleaning up by running the following commands during a maintenance window.');
            $this->stdErr->writeln('<options=bold;fg=red>WARNING:</> Running these will lock up your database for several minutes. Only run these when you know what you\'re doing.');
            $this->stdErr->writeln('');
            foreach($tablesNeedingCleanup as $row) {
                list($schema, $tablename) = explode("\t", $row);
                $this->stdErr->writeln(sprintf('ALTER TABLE `%s`.`%s` ENGINE="InnoDB";', $schema, $tablename));
            }
            $this->stdErr->writeln('');
        } else {
            $this->stdErr->writeln('<options=bold;fg=green> [ALL OK]</>');
        }
    }

    private function showEstimatedUsageTable($service, $database, $appName) {
        $this->stdErr->writeln('Calculating estimated usage...');
        $allocatedDisk  = $service->disk * self::BYTE_TO_MBYTE;
        $estimatedUsage = $this->getEstimatedUsage($appName, $database); //always in bytes
        
        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        $machineReadable = $table->formatIsMachineReadable();
        
        if ($allocatedDisk !== false) {
            $propertyNames  = ['Allocated', 'Estimated Usage', 'Percentage Used'];
            $percentageUsed = $estimatedUsage * 100 / $allocatedDisk;
            $values = [
                $this->formatBytes($allocatedDisk,$machineReadable),
                $this->formatBytes($estimatedUsage,$machineReadable),
                $this->formatPercentage($percentageUsed),                
            ];
        } else {
            $percentageUsed = null;
            $propertyNames = ['Estimated Usage'];
            $values = [
                $this->formatBytes($estimatedUsage,$machineReadable),
            ];
        }
        
        $this->stdErr->writeln('');
        $table->renderSimple($values, $propertyNames);

        $this->showWarnings($percentageUsed);
    }

    private function showInaccessibleSchemas($service, $database) {
        // Find if not all the available schemas were accessible via this relationship.
        if (isset($database['rel'])
            && isset($service->configuration['endpoints'][$database['rel']]['privileges'])) {
            $schemas = !empty($service->configuration['schemas'])
                ? $service->configuration['schemas']
                : ['main'];
            $accessible = array_keys($service->configuration['endpoints'][$database['rel']]['privileges']);
            $missing = array_diff($schemas, $accessible);
            if (!empty($missing)) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('Additionally, not all schemas are accessible through this endpoint.');
                $this->stdErr->writeln('  Endpoint:             ' . $database['rel']);
                $this->stdErr->writeln('  Accessible schemas:   <info>' . implode(', ', $accessible) . '</info>');
                $this->stdErr->writeln('  Inaccessible schemas: <comment>' . implode(', ', $missing) . '</comment>');
            }
        }
    }

    private function showWarnings($percentageUsed) {
        if($percentageUsed > self::RED_WARNING_THRESHOLD) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('<options=bold;fg=red>Warning</>');
            $this->stdErr->writeln("Databases tend to need a little bit of extra space for starting up and temporary storage when running large queries. Please increase the allocated space in services.yaml");    
        }
        $this->stdErr->writeln('');
        $this->stdErr->writeln('<options=bold;fg=yellow>Warning</>');
        $this->stdErr->writeln("This is an estimate of the database's disk usage. It does not represent its real size on disk.");
    }

    /**
     * Returns a command to query disk usage for a PostgreSQL database.
     *
     * @param array $database The database connection details.
     *
     * @return string
     */
    private function psqlQuery(array $database)
    {
        //both these queries are wrong... 
        //$query = 'SELECT SUM(pg_database_size(t1.datname)) as size FROM pg_database t1'; //does miss lots of data
        //$query = 'SELECT SUM(pg_total_relation_size(pg_class.oid)) AS size FROM pg_class LEFT OUTER JOIN pg_namespace ON (pg_namespace.oid = pg_class.relnamespace)';
        
        //but running both, and taking the average, gets us closer to the correct value
        $query = 'SELECT AVG(size) FROM (SELECT SUM(pg_database_size(t1.datname)) as size FROM pg_database t1 UNION SELECT SUM(pg_total_relation_size(pg_class.oid)) AS size FROM pg_class LEFT OUTER JOIN pg_namespace ON (pg_namespace.oid = pg_class.relnamespace)) x;';//too much
        
        /** @var \Platformsh\Cli\Service\Relationships $relationships */
        $relationships = $this->getService('relationships');
        $dbUrl = $relationships->getDbCommandArgs('psql', $database, '');

        return sprintf(
            "psql --echo-hidden -t --no-align %s -c '%s'",
            $dbUrl,
            $query
        );
    }

    private function getMysqlCommand(array $database, $query) {
        /** @var \Platformsh\Cli\Service\Relationships $relationships */
        $relationships = $this->getService('relationships');
        $connectionParams = $relationships->getDbCommandArgs('mysql', $database, '');
        
        return sprintf(
            "mysql %s --no-auto-rehash --raw --skip-column-names --execute '%s'",
            $connectionParams,
            $query
        );
    }
    /**
     * Returns a command to query table size of non-InnoDB using tables for a MySQL database in MB
     *
     * @param array $database The database connection details.
     *
     * @return string
     */
    private function mysqlNonInnodbQuery(array $database, $blnExcludeInnoDb=true)
    {
        $query = 'SELECT'
            . ' ('
            . 'SUM(data_length+index_length+data_free)'
            . ' + (COUNT(*) * 300 * 1024)'
            . ')'
            . ' AS estimated_actual_disk_usage'
            . ' FROM information_schema.tables'
            . ($blnExcludeInnoDb ? ' WHERE ENGINE <> "InnoDB"' : '');
        
        return $this->getMysqlCommand($database, $query);
    }

    /**
     * Returns a command to query disk usage for all InnoDB using tables for a MySQL database in MB
     *
     * @param array $database The database connection details.
     *
     * @return string
     */
    private function mysqlInnodbQuery(array $database)
    {
        $query = 'SELECT SUM(ALLOCATED_SIZE) FROM information_schema.innodb_sys_tablespaces;';

        return $this->getMysqlCommand($database, $query);
    }

    private function mysqlInnodbAllocatedSizeExists(array $database) {
        $query = 'SELECT count(COLUMN_NAME) FROM information_schema.COLUMNS WHERE table_schema ="information_schema" AND table_name="innodb_sys_tablespaces" AND column_name LIKE "ALLOCATED_SIZE";';

        return $this->getMysqlCommand($database, $query);
    }

    /**
     * Returns a command to query disk usage for all InnoDB using tables for a MySQL database in MB
     *
     * @param array $database The database connection details.
     *
     * @return string
     */
    private function mysqlTablesInNeedOfOptimizing(array $database)
    {
        /*, data_free, data_length, ((data_free+1)/(data_length+1))*100 as wasted_space_percentage*/ 
        $query = 'SELECT TABLE_SCHEMA, TABLE_NAME FROM information_schema.tables WHERE ENGINE = "InnoDB" AND ((data_free+1)/(data_length+1))*100 >= '.self::WASTED_SPACE_WARNING_THRESHOLD.' ORDER BY data_free DESC LIMIT 10';
        
        return $this->getMysqlCommand($database, $query);
    }

    private function getEstimatedUsage($appName, $database) {
        switch ($database['scheme']) {
            case 'pgsql':
                return $this->getPgSqlUsage($appName, $database);
            
            case 'mysql':
            default:
                return $this->getMySqlUsage($appName, $database);
        }
    }

    private function getPgSqlUsage($appName, $database) {
        return $this->runSshCommand($appName, $this->psqlQuery($database));        
    }

    private function getMySqlUsage($appName, $database) {
        $this->stdErr->writeln('Getting MySql Usage...');
        $blnAllocatedSizeSupported = $this->runSshCommand($appName, $this->mysqlInnodbAllocatedSizeExists($database));
        if($blnAllocatedSizeSupported) {
            $this->stdErr->writeln('Checking InnoDB seperately for more accurate results...');
            $innodbsize = $this->runSshCommand($appName, $this->mysqlInnodbQuery($database));
        }
        
        $othertablesizes = $this->runSshCommand($appName, $this->mysqlNonInnodbQuery($database, !$blnAllocatedSizeSupported));
            
        return $othertablesizes + $innodbsize;
    }
    
    private function formatPercentage($percentage) {
        if ($percentage > self::RED_WARNING_THRESHOLD) {
            $format = '<options=bold;fg=red> ~ %d %%</>';
        } elseif ($percentage > self::YELLOW_WARNING_THRESHOLD) {
            $format = '<options=bold;fg=yellow> ~ %d %%</>';
        } else {
            $format = '<options=bold;fg=green> ~ %d %%</>';
        }
        
        return sprintf($format, round($percentage));
    }

    private function formatBytes($intBytes, $hasToBeMachineReadable=false, $blnForceShowBytes=false) {
        if($this->blnShowInBytes) {
            return $intBytes;
        }
        return $hasToBeMachineReadable ? $intBytes     : Helper::formatMemory($intBytes);
    }
        
    private function runSshCommand($appName, $strCommandToExec) {
        
        return $this->getService('shell')
                    ->execute(
                        array_merge(
                            ['ssh'], 
                            $this->getService('ssh')->getSshArgs(),
                            [
                                $this->getSelectedEnvironment()->getSshUrl($appName),
                                $strCommandToExec
                            ]
                        ), 
                        null, true
                    );
    }
    
}

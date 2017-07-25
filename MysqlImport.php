<?php

/**
 * Class MysqlImport
 *
 */
class MysqlImport
{
    /**
     * @var string
     */
    public $sshHost;

    /**
     * @var string
     */
    public $sshPort;

    /**
     * @var string
     */
    public $sshUsername;

    /**
     * @var string
     */
    public $rsync;

    /**
     * @var string
     */
    public $ssh;

    /**
     * @var string
     */
    public $sevenZip;

    /**
     * @var string
     */
    public $localPath;

    /**
     * @var string
     */
    public $excludeFile;

    /**
     * @var string
     */
    public $unzipPath;

    /**
     * @var string
     */
    public $remotePath;

    /**
     * @var string
     */
    public $localHost = 'localhost';

    /**
     * @var string
     */
    public $localPort = '3306';

    /**
     * @var string
     */
    public $localUser = 'root';

    /**
     * @var string
     */
    public $localPass;

    /**
     * @var string
     */
    public $localDatabase;

    /**
     * @var string
     */
    public $remoteDatabase;

    /**
     * @var
     */
    public $remotePathDate;

    public $showProgress = false;
    public $remoteCommand;

    /**
     * Construct the class
     * @param array $config the class config
     */
    public function __construct($config = array())
    {
        foreach ($config as $key => $value) {
            $this->$key = $value;
        }
    }
    public function remoteCommandExecute()
    {
        if ($this->remoteCommand){
            $vOutput = '';
            if (function_exists('ssh2_connect')){
                $sshResource = ssh2_connect($this->sshHost,$this->sshPort);
                if (ssh2_auth_pubkey_file($sshResource, 'zain',
                    getenv("HOME") . '/.ssh/id_rsa.pub',
                    getenv("HOME") . '/.ssh/id_rsa')) {
                    $fStartTime = microtime(true);
                    $stream = ssh2_exec($sshResource,$this->remoteCommand );
                    stream_set_blocking($stream, true);
                    $stream_out = ssh2_fetch_stream($stream, SSH2_STREAM_STDIO);
                    $stream_error = ssh2_fetch_stream($stream, SSH2_STREAM_STDERR);
                    $vStandardOutput = stream_get_contents($stream_out);
                    $vErrorOutput = stream_get_contents($stream_error);
                    $fDiff = microtime(true) - $fStartTime;

                } else {
                    echo ('Public Key Authentication Failed');
                }
            }
            if (!empty($vStandardOutput) || !empty($vErrorOutput)){
                echo "remote command " . $this->remoteCommand . "  executed in $fDiff seconds output: \n";
                echo $vOutput . "\n";
                echo "error is: \n";
                echo $vErrorOutput;
            }
            else{
                echo "issues executing remote command " . $this->remoteCommand;
            }
            echo "\nPress Enter to continue";
            $handle = fopen ("php://stdin","r");
            fgets($handle);
        }
    }

    /**
     * Generate the commands to download the database files using rsync
     */
    public function generateDownload()
    {
        $this->remoteCommandExecute();
        if ($this->isWindows()) {
            $localPath = '/cygdrive/' . strtr($this->localPath, array('\\' => '/', ':' => ''));
            $excludeFile = '/cygdrive/' . strtr($this->excludeFile, array('\\' => '/', ':' => ''));
        }
        else {
            $localPath = strtr($this->localPath, array('\\' => '/', ':' => ''));
            $excludeFile = strtr($this->excludeFile, array('\\' => '/', ':' => ''));
        }

        $date = $this->remotePathDate ? date('Y-m-d', strtotime($this->remotePathDate)) : date('Y-m-d');
        if ($this->isWindows()) {
            $command = $this->rsync . ' -avz --progress --rsh="' . $this->ssh . ' -p' . $this->sshPort . '" --exclude-from "' . $excludeFile . '" ';
            $command .= $this->sshUsername . '@' . $this->sshHost . ':' . strtr($this->remotePath, array('{date}' => $date)) . '/' . $this->remoteDatabase . '.* ' . $localPath;
        }
        else {
            $targetPath = strtr($this->remotePath, array('{date}' => $date)) . '/' . $this->remoteDatabase . '.* ';
            $vSshPort = trim($this->sshPort);
            $vRsyncPort = '';
            if (!$vSshPort || ($vSshPort != 22)) {
                $vRsyncPort = "-e 'ssh -p {$this->sshPort}'";
            }
            $command = "{$this->rsync}  $vRsyncPort  -avz --progress {$this->sshUsername}@{$this->sshHost}:/$targetPath $localPath --exclude-from $excludeFile";
        }

        return $command;
    }

    /**
     * Generate the commands to unzip the database files using 7zip
     */
    public function generateUnzip()
    {
        if (!file_exists($this->unzipPath)) {
            mkdir($this->unzipPath, 0777, true);
        }
        $commands = array();
        foreach (glob($this->localPath . '/*.sql.gz') as $file) {
            if (strpos($file, '-schema') !== false) {
                $unzipPath = $this->unzipPath . '/schema';
            }
            else {
                $unzipPath = $this->unzipPath . '/data';
            }
            $commands[] = '"' . $this->sevenZip . '" e -y -o"' . $unzipPath . '\" "' . str_replace('/', '\\', $file) . '"';
        }
        return implode("\n", $commands);
    }

    /**
     * Generate the commands to import the database files using MySQL
     */
    public function generateImport()
    {
        $commands = array();
        foreach (glob($this->unzipPath . '/schema/*.sql') as $schemaFile) {
            $dataFile = strtr($schemaFile, array('/schema/' => '/data/', '-schema.sql' => '.sql'));
            $table = str_replace(array($this->unzipPath . '/schema/', $this->remoteDatabase . '.', '-schema.sql'), '', $schemaFile);
            $commands[] = 'echo importing ' . $table;
            $commands[] = 'mysql --user="' . $this->localUser . '" --pass="' . $this->localPass . '" --host="' . $this->localHost . '" --port="' . $this->localPort . '" --database="' . $this->localDatabase . '" --execute="SET FOREIGN_KEY_CHECKS = 0; DROP TABLE IF EXISTS `' . $table . '`"';
            $commands[] = 'mysql --user="' . $this->localUser . '" --pass="' . $this->localPass . '" --host="' . $this->localHost . '" --port="' . $this->localPort . '" --database="' . $this->localDatabase . '" < "' . $schemaFile . '"';
            if (file_exists($dataFile)) {
                $commands[] = 'mysql --user="' . $this->localUser . '" --pass="' . $this->localPass . '" --host="' . $this->localHost . '" --port="' . $this->localPort . '" --database="' . $this->localDatabase . '" < "' . $dataFile . '"';
            }
        }
        return implode("\n", $commands);
    }

    public function generateUnzipAndImportList()
    {
        $commands = array();
        foreach (glob($this->localPath . '/*.sql.gz') as $file) {
            $commands[] = $this->getUnzipImportCommand($file);
        }
        return implode("\n", $commands);
    }

    public function getUnzipImportCommand($filePath)
    {
        $mysqlCommand = "mysql --user={$this->localUser} --password={$this->localPass} --host={$this->localHost} --port={$this->localPort} --database={$this->localDatabase}";
        $fileName = basename($filePath, '.sql.gz');
        $aParts = explode('.', $fileName);
        $tableName = end($aParts);
        $tableName = str_replace('-schema', '', $tableName);

        $addLinesToPipe = <<<'PipeCommand'
        sed -e '1 i SET FOREIGN_KEY_CHECKS = 0;'| sed -e '$s@$@\nSET FOREIGN_KEY_CHECKS = 1;@'
PipeCommand;
        if ($this->showProgress) {
            $command = "pv $filePath | gunzip | $addLinesToPipe | $mysqlCommand";
        }
        else {
            $command = "zcat $filePath | $mysqlCommand";
        }
        if (strpos($fileName, '-schema')) {
            $command = $mysqlCommand . " <<<  \"SET FOREIGN_KEY_CHECKS = 0;DROP TABLE IF EXISTS $tableName;SET FOREIGN_KEY_CHECKS = 1;\"\n" . $command;
        }
        $command = "echo importing $tableName\n" . $command;
        return $command;
    }

    public function isWindows()
    {
        return (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    }

}
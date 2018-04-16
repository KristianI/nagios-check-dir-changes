#!/usr/bin/php -q
<?php
/**
 * Check directory changes.
 * 
 * @author Kristian Just <kristian@justiversen.dk>
 * @author Based on Check Log Growth by Aaron M. Segura
 */

define('VERSION', '1.0');
define('VERDATE', '16/04/2018');

class CheckDirectoryChanges
{

    /**
     * Directories to check.
     * 
     * @var array
     */
    protected $directories = [];

    /**
     * State file path.
     * 
     * @var string
     */
    protected $statefile   = '';

    /**
     * Files found in recent scan.
     * 
     * @var array
     */
    protected $lastState = [];

    /**
     * Files found in current scan.
     * 
     * @var array
     */
    protected $filesFound  = [];

    /**
     * Path to log file.
     * 
     * @var string|boolean
     */
    protected $logfile = false;

    /**
     * Command line usage.
     * 
     * @param  string $msg Optional.
     * @return void
     */
    protected function usage($msg = null)
    {
        print(basename($_SERVER["argv"][0]) ." v". VERSION ." (". VERDATE .")\n\n");
        print(basename($_SERVER["argv"][0]) ." -i <cfgfile> [-t <tmpdir>] [-l <logfile>] -c <critspec> -w <warnspec>\n");
        print("  -i <cfgfile> 	Path to configuration file containing directories to check\n");
        print("  -c <critspec>	Critical Change Threshold\n");
        print("  -w <warnspec>	Warning Change Threshold\n");
        print("  -y <tmpdir>	Directory to use for state files\n");
        print("  -l <logfile>	Path to log file\n");

        if ( $msg )
            print("\n*!* $msg\n");

        exit(1);
    }

    /**
     * Load (command line) options.
     * 
     * @param array $opts
     * @return void
     */
    public function loadOptions($opts)
    {
        foreach ( $opts as $key => $arg ) {
            switch ( $key ) {
                case "i":
                    $this->loadConfiguration($arg);
                break;
        
                case "w":
                    if ( is_numeric($arg) )
                        $this->warning_threshold = (int)$arg;
                    else
                        $this->usage("Invalid argument to -w\n");
                break;
        
                case "c":
                    if ( is_numeric($arg) )
                        $this->critical_threshold = (int)$arg;
                    else
                        $this->usage("Invalid argument to -c\n");
                break;
        
                case "y":
                    if ( is_dir($arg) && is_writable($arg) )
                        $this->tmpdir = $arg;
                    else
                        $this->usage("$arg is not a writable directory\n");
                break;
        
                case "l":
                    $this->logfile = $arg;
                break;
            }
        }
        
    }

    /**
     * Verify options.
     * 
     * @return void
     */
    public function verifyOptions()
    {   
        if ( ! isset($this->critical_threshold) ) {
            $this->usage("Must specify -c");
        }

        if ( ! isset($this->warning_threshold) ) {
            $this->usage("Must specify -w");
        }

        if ( ! isset($this->cfgfile) ) {
            $this->usage("Must specify -i");
        }

        if ( ! isset($this->tmpdir) ) {
            $this->tmpdir = '/tmp';
        }
    }

    /**
     * Scan directories.
     * 
     * @return void
     */
    public function scan()
    {
        $this->filesFound = [];

        foreach ($this->configuration['directories'] as $mainDirectory) {

            $directoryIterator = new RecursiveDirectoryIterator($mainDirectory);
            $iterator          = new RecursiveIteratorIterator($directoryIterator);

            foreach ($iterator as $path) {

                if (in_array($path->getFilename(), ['..'])) {
                    continue;
                }

                // Check exclude list.
                foreach ($this->configuration['excludes'] as $exclude) {
                    if (substr($path, 0, strlen($exclude)) === $exclude) {
                        continue 2;
                    }
                }

                $this->filesFound[] = $path . '|' . filemtime($path);

            }
        }
    }

    /**
     * Find differences between current and last scan and alert if necessary.
     * 
     * @return void
     */
    public function alert()
    {
        if ($this->lastState === false) {
            $this->end("CHANGES COULD NOT BE CHECKED - State file was not found - might be first run ($this->cfgfile)\n", 0);
        }

        $diff  = array_diff($this->filesFound, $this->lastState);
        $delta = count($diff);

        $this->log("Files differences " . date('Y-m-d H:i:s') . ":\n" . implode("\n", $diff));

        if ($delta >= $this->critical_threshold) {
            $this->end("CRITICAL CHANGES - $delta changed files/directories ($this->cfgfile) | changes=$delta\n", 2);
        }
        
        if ($delta >= $this->warning_threshold) {
            $this->end("WARNING CHANGES - $delta changed files/directories ($this->cfgfile) | changes=$delta\n", 1);
        }

        // Else ..
        $this->end("NO SIGNIFICANT CHANGES - $delta changed files/directories ($this->cfgfile) | changes=$delta\n", 0);
    }

    /**
     * Write to log, screen and end application.
     * 
     * @param  string $message
     * @param  int    $exitStatus
     * @return void
     */
    protected function end($message, $exitStatus = 0)
    {
        $this->log($message);
        print($message);
        exit($exitStatus);
    }

    /**
     * Read and load configuration file.
     * 
     * @param  string $cfgfile
     * @return void
     */
    protected function loadConfiguration($cfgfile)
    {
        if ( ! file_exists($cfgfile) ) {
            print("Configuration file not found or not readable by nagios user - $cfgfile\n");
            exit(2);
        }

        $this->cfgfile = $cfgfile;

        $this->configuration = include $cfgfile;
    }

    /**
     * Read state file for current configuration.
     * 
     * @return void
     */
    public function readState()
    {
        $this->statefile = $this->tmpdir . "/nagios_check_dir_changes_state_" . substr(md5($this->cfgfile), 0, 8);

        if ( file_exists($this->statefile) )
            $this->lastState = explode("\n", file_get_contents($this->statefile));
        else
            $this->lastState = false;	
    }

    /**
     * Update state file for current configuration.
     * 
     * @return void
     */
    public function writeState()
    {
        // Only update state file every 15. minute.
        if (file_exists($this->statefile) && filemtime($this->statefile) > time()-900) {
            return;
        }

        try {
            file_put_contents($this->statefile, implode("\n", $this->filesFound));
        } catch (Exception $e) {
            // Nagios plugins should fail gently if tmp file could not be created according 
            // to plugin development guidelines.
            return;
        }
    }

    /**
     * Add message to log file.
     * 
     * @param  string  $message
     * @return boolean
     */
    protected function log($message)
    {
        if ( ! $this->logfile) {
            return false;
        }

        return file_put_contents($this->logfile, $message . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

}

$checkDirectoryChanges = new CheckDirectoryChanges();
$checkDirectoryChanges->loadOptions(getopt("w:c:i:y:l:"));
$checkDirectoryChanges->verifyOptions();
$checkDirectoryChanges->readState();
$checkDirectoryChanges->scan();
$checkDirectoryChanges->writeState();
$checkDirectoryChanges->alert();
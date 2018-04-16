# Nagios Check Directory Changes plugin

Check directory for file changes or new files by Kristian Just.

Based on 'File size growth checking plugin for Nagios' by Aaron Segura (aaron.segura@gmail.com).

## Usage

`check_dir_changes.php -i <cfgfile> [-t <tmpdir>] -w <warning> -c <critical>`

	-i     Path to configuration file containing directories to check 
               (see configuration.php.example)
        -w     Number of files changed warning threshold
        -c     Number of files changed critical threshold
        -y     Directory to keep state files, defaults to /tmp
        -l     Path to log file

## Changelog

* 1.0 - 16/04/2018
        First working version

## Contact

Kristian Just

kristian@justiversen.dk

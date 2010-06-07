<?php

/**
 *  @author      Ben XO (me@ben-xo.com)
 *  @copyright   Copyright (c) 2010 Ben XO
 *  @license     MIT License (http://www.opensource.org/licenses/mit-license.html)
 *  
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is
 *  furnished to do so, subject to the following conditions:
 *  
 *  The above copyright notice and this permission notice shall be included in
 *  all copies or substantial portions of the Software.
 *  
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 *  THE SOFTWARE.
 */

class HistoryReader
{
    // command line switches
    protected $debug = false;
    protected $wait_for_file = true;
    protected $help = false;
    
    protected $sleep = 2;
    
    protected $appname;
    protected $filename;
    protected $historydir;
    
    protected $growl_config;
    
    /**
     * @var SSLHistoryDom
     */
    protected $tree;
    
    /**
     * @var SSLRealtimeModel
     */
    protected $rtm;
    
    public function setGrowlConfig(array $growlConfig)
    { 
        $this->growl_config = $growlConfig;
    }
    
    public function main($argc, array $argv)
    {
        date_default_timezone_set('UTC');
        
        // guess history file (always go for the most recently modified)
        $this->historydir = getenv('HOME') . '/Music/ScratchLIVE/History/Sessions';
        
        try
        {
            $this->parseOptions($argv);
            
            $appname = $this->appname;
            
            if($this->help)
            {
                $this->usage($appname, $argv);
                return;
            }
            
            $filename = $this->filename;
            
            if(empty($filename))
            {
                if($this->wait_for_file)
                {
                    echo "Waiting for new session file...\n";
                    // find the most recent file, then wait for a new one to be created and use that.
                    $first_filename = $this->getMostRecentFile($this->historydir, '.session');
                    $second_filename = $first_filename;
                    while($second_filename == $first_filename)
                    {
                        sleep($this->sleep);
                        $second_filename = $this->getMostRecentFile($this->historydir, '.session');
                    }
                    $filename = $second_filename;
                }
                else
                {
                    $filename = $this->getMostRecentFile($this->historydir, '.session');                
                }
                
                echo "Using file $filename ...\n";
            }
                            
            if(!file_exists($filename))
                throw new InvalidArgumentException("No such file $filename.");
                
            if(!is_readable($filename))
                throw new InvalidArgumentException("File $filename not readable.");
                
                
            if($this->debug)
            {
                $monitor = new SSLHistoryFileMonitor($filename);
                $monitor->debug();
                return;
            }

            // start monitoring.
            $this->monitor($filename);            
        }
        catch(Exception $e)
        {   
            echo $e->getMessage() . "\n";  
            echo $e->getTraceAsString() . "\n";  
            $this->usage($appname, $argv);
        }
    }
    
    public function usage($appname, array $argv)
    {
        echo "Usage: {$appname} [--debug] [--immediate] [session file]\n";
        echo "Session file is optional. If omitted, the most recent history file from {$this->historydir} will be used automatically\n";
        echo "    -h or --help:      This message.\n";
        echo "    -d or --debug:     Dump the file's complete structure and exit\n";
        echo "    -i or --immediate: Do not wait for the next history file to be created before monitoring. (Use if you started {$appname} mid way through a session)\n";
    }
    
    protected function parseOptions(array $argv)
    {
        $this->appname = array_shift($argv);
        
        while($arg = array_shift($argv))
        {
            if($arg == '--help' || $arg == '-h')
            {
                $this->help = true;
                continue;
            }
            
            if($arg == '--debug' || $arg == '-d')
            {
                $this->debug = true;
                continue;
            }
            
            if($arg == '--immediate' || $arg == '-i')
            {
                $this->wait_for_file = false;
                continue;
            }
            
            $this->filename = $arg;
        }        
    }
    
    protected function monitor($filename)
    {
        // set up and couple the various parts of the system
        $ts  = new TickSource();
        $hfm = new SSLHistoryFileMonitor($filename);
        $rtm = new SSLRealtimeModel();
        $rtm_printer = new SSLRealtimeModelPrinter($rtm);
        $growl_event_renderer = new SSLEventGrowlRenderer( $this->getGrowler() );
        // $scrobbler = new ScrobblerRealtimeModel();
        
        $ts->addTickObserver($history_file_monitor);
        //$ts->addTickObserver($scrobbler);
        $hfm->addDiffObserver($rtm);
        $rtm->addTrackChangeObserver($rtm_printer);
        $rtm->addTrackChangeObserver($growl_event_renderer);
        //$rtm->addTrackChangeObserver($scrobbler);
        //$scrobbler->addTimeoutObserver($growl_event_renderer);
        
        // Tick tick tick. This never returns
        $ts->startClock($this->sleep);
    }
    
    protected function getMostRecentFile($from_dir, $type)
    {
        $newest_mtime = 0;
        $fp = '';
        
        $di = new DirectoryIterator($from_dir);
        foreach($di as $f)
        {
            if(!$f->isFile() || !substr($f->getFilename(), -4) == '.' . $type)
                continue;
    
            $mtime = $f->getMTime();
            if($mtime > $newest_mtime)
            {
                $newest_mtime = $mtime;
                $fp = $f->getPathname();
            }
        }
        if($fp) return $fp;
        throw new RuntimeException("No $type file found in $from_dir");
    }  

    /**
     * @return Growl
     */
    protected function getGrowler()
    {
        $growler = new Growl(
            $this->growl_config['address'],
            $this->growl_config['password'],
            $this->growl_config['app_name']
        );
        
        $growler->addNotification('alert');
        $growler->register();
        return $growler;
    }
}
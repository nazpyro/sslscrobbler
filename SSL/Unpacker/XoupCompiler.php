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

class XoupCompiler
{
    private $debug = true;
    private $oobcheck = false;
    
    public function setDebug($debug)
    {
        $this->debug = $debug;
    }
        
    public function __construct($program)
    {   
        $this->subs = $this->parse($program);
    }
    
    public function parse($program)
    {
        $parser = new XoupParser();
        return $parser->parse($program);
    }    
    
    public function compile($filename)
    {
        L::level(L::INFO) && 
            L::log(L::INFO, __CLASS__, 'compiling %s', 
                array($filename));
        
        $class = $this->getClassName($filename);
        
        $output  = "<?php\n\n";
        $output .= "/* Autogenerated by XoupCompiler */\n\n";
        $output .= "class $class extends Unpacker\n{\n\n";
        $output .= "    private \$context = array();\n";
        $output .= "    public function unpack(\$bin)\n";
        $output .= "    {\n";
        $output .= "        \$binlen = strlen(\$bin);\n";
        $output .= "        \$acc = 0;\n";
        $output .= "        \$ptr = 0;\n";
        $output .= "        \$this->_main(\$bin, \$binlen, \$acc, \$ptr);\n";
        $output .= "        return \$this->context;\n";
        $output .= "    }\n\n";
        foreach(array_keys($this->subs) as $sub)
        {
            $output .= $this->writeFunctionHeader($sub);    
            $output .= $this->writeFunctionBody($sub);    
            $output .= $this->writeFunctionFooter();    
        }
        $output .= $this->writeLUTFunctionHeader();
        $output .= $this->writeLUTFunctionBody(array_keys($this->subs));
        $output .= $this->writeFunctionFooter();
        $output .= "}\n";
        
        file_put_contents($this->getCompiledName($filename), $output);
    }
    
    protected function writeFunctionHeader($sub)
    {
        return "    private function _$sub(\$bin, \$binlen, &\$acc, &\$ptr)\n    {\n";
    }

    protected function writeLUTFunctionHeader()
    {
        return "    private function lookup(\$sub, \$bin, \$binlen, &\$acc, &\$ptr)\n    {\n";
    }
    
    protected function writeFunctionFooter()
    {
        return "    }\n\n";
    }
    
    protected function writeLUTFunctionBody(array $subs)
    {
        $output = "        switch(\$sub) {\n";
        foreach($subs as $sub)
        {
            $output .= "            case '$sub': return \$this->_$sub(\$bin, \$binlen, \$acc, \$ptr);\n";
        }
        $output .= "            default:\n";
        $output .= "                throw new RuntimeException('No such subroutine' . \$sub);\n";
        $output .= "        }\n";
        return $output;
    }
    
    protected function writeFunctionBody($sub)
    {
        $body = '';
        $opcount = count($this->subs[$sub]);
        do
        {
            $loop = false;
            
            foreach($this->subs[$sub] as $opindex => $op)
            {                
                $body .= "        /* $op */\n";
                
                if(!preg_match('/^(?:([a-zA-Z0-9_]+)\.|(c|(r)(\d+|_|\*)(b|w|l))(>)(s|i|h|t|r|f)(_|([a-zA-Z]+)))/', $op, $matches))
                    throw new RuntimeException("Could not parse Unpacker op '$op' in sub '$sub'");
                                
                $callsub = $matches[1];
                
                if($callsub)
                {
                    switch($callsub)
                    {                                                        
                        case 'bp':
                            $body .= "        var_dump('ACC', \$acc, 'PTR', \$ptr);\n";
                            break;
                                                        
                        case 'exit':
                            $body .= "        return false;\n";
                            break;
                        default:
                            $line = "        if(!\$this->%s(%s\$bin, \$binlen, \$acc, \$ptr)) return false;\n";
                            if(strpos($callsub, '_') === false )
                            {
                                $body .= sprintf($line, "_$callsub", '');
                            }
                            else
                            {
                                $parts = explode('_', $callsub);
                                foreach($parts as $i => $part) 
                                {
                                    $parts[$i] = "'$part'";       
                                }
                                $parts = implode( " . \$acc . ", $parts);
                                $body .= sprintf($line, 'lookup', $parts . ', ');
                            }
                            break;
                    }
                    continue;
                }
                
                $copy_action = $matches[2];
                
                if($copy_action == 'c')
                {
                    $body .= "        \$datum = \$acc;\n";
                    $exit_now = false;
                }
                else
                {    
                    $read_action = $matches[3];
                    $read_length = $matches[4];
                    $read_width = $matches[5];
                    $body .= "        if(\$ptr >= \$binlen) return false;\n";
                    $body .= $this->writeRead($read_length, $read_width, $exit_now);
                }
                        
                $write_action = $matches[6];
                $type = $matches[7];
                $dest = $matches[8];
                                            
                $body .= $this->writeWrite($type, $dest);
                if($exit_now)
                {
                    $body .= "        return false; // this must be the end as we read to eof\n";    
                }
            }
        }
        while($loop); 
        
        $body .= "        return true;\n";       
        return $body;
    }
    
    protected function writeRead($length, $width, &$exit_now)
    {
        // return to outer context. Set to true if this is a "read to eof" read
        $exit_now = false;
        
        $body = '';
        $lengthVar = '';
        $toEnd = false;
        if('_' == $length)
        {
            $length = "\$acc";
        }
        elseif('*' == $length)
        {
            $toEnd = true;
        }
        else
        {
            switch($width)
            {
                case 'b':
                    $length *= 1;
                    break;
                    
                case 'w':
                    $length *= 2;
                    break;
                    
                case 'l':
                    $length *= 4;
                    break;
                    
                default:
                    throw new RuntimeException("Unknown read width '$width'. Expected 'b', 'w' or 'l'");
            }
        }
        
        if($this->oobcheck)
        {
            $body .= "        if(\$ptr + $length > \$binlen)\n";
            $body .= "            throw new OutOfBoundsException('Cannot end read past end of data');\n";
        }
        
        if($toEnd)
        {
            $body .= "        \$datum = substr(\$bin, \$ptr); // to eof\n";
            $exit_now = true;
        }
        else
        {
            $body .= "        \$datum = substr(\$bin, \$ptr, $length);\n";
            $body .= "        \$ptr += $length;\n";
        }
        
        
        return $body;
    }
    
    protected function writeWrite($type, $dest)
    {
        $body = '';
        if($dest == '_')
        {
            $dest = "\$acc";
        }
        else
        {
            $dest = "\$this->context['$dest']";
        }
        
        switch($type)
        {
            case 'r': // raw
                $body .= "        $dest = \$datum;\n";
                break;
                
            case 's': // string
                $body .= "        $dest = (string) \$this->unpackstr(\$datum);\n";
                break;
                
            case 'i': // int
                $body .= "        $dest = (int) \$this->unpackint(\$datum);\n";
                break;
                
            case 'h': // hexdump
                $body .= "        \$hd = new Hexdumper();\n";
                $body .= "        $dest = trim(\$hd->hexdump(\$datum));\n";
                break;
                
            case 't': // timestamp -> date
                $body .= "        $dest = date('Y-m-d H:i:s', (int) \$this->unpackint(\$datum));\n";
                break;
                    
            case 'f': // float
                $body .= "        $dest = (float) \$this->unpackfloat(\$datum);\n";
                break;
                    
            default:
                throw new RuntimeException("Unknown type '$type'. Expected 's' or 'i'");                
        }        
        return $body;
    }
    
    protected function getCompiledName($filename)
    {
        $basename = basename($filename, '.xoup');
        $dirname  = dirname($filename);
        $compiled_name = $dirname . '/' . $basename . '.php';
        return $compiled_name;
    }
    
    protected function getClassName($filename)
    {
        $basename = basename($filename, '.xoup');
        return 'XOUP' . $basename . 'Unpacker';
    }
}
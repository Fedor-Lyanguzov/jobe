<?php defined('BASEPATH') OR exit('No direct script access allowed');

/* ==============================================================
 *
 * Python3
 *
 * ==============================================================
 *
 * @copyright  2014 Richard Lobb, University of Canterbury
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('application/libraries/LanguageTask.php');

class Csharp_Task extends Task {
    public function __construct($filename, $input, $params) {
        $params['memorylimit'] = 0;    
	$params['cputime'] = 10;
        parent::__construct($filename, $input, $params);
    }

    public static function getVersionCommand() {
        return array('/usr/bin/csc /version', '/(.*) .*/');
    }

    public function compile() {
        $this->executableFileName = basename($this->sourceFileName, '.cs') . '.exe';
        $compileargs = $this->getParam('compileargs');
        $cmd = "/usr/bin/csc /nologo " . implode(' ', $compileargs) . " {$this->sourceFileName}";
        list($output, $this->cmpinfo) = $this->run_in_sandbox($cmd);
        if (!empty($output)) {
            $this->cmpinfo = $output . '\n' . $this->cmpinfo;
        }
    }

    public function defaultFileName($sourcecode) {
        return 'prog.cs';
    }


    public function getExecutablePath() {
        return '/usr/bin/mono';
     }


     public function getTargetFile() {
         return $this->executableFileName;
     }
};

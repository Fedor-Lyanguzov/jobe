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

class Python3_Task extends Task {
    public function __construct($filename, $input, $params) {
        parent::__construct($filename, $input, $params);
//        $this->default_params['interpreterargs'] = array('-BE');
    }

    public static function getVersionCommand() {
        return array('/usr/bin/csc /version', '/([0-9.a-z]*)/');
    }

    public function compile() {
        $this->executableFileName = basename($this->sourceFileName) . '.exe';
        $compileargs = $this->getParam('compileargs');
        $cmd = "/usr/bin/csc " . implode(' ', $compileargs) . " {$this->sourceFileName}";
        list($output, $this->cmpinfo) = $this->run_in_sandbox($cmd);
        if (!empty($this->cmpinfo) && !empty($output)) {
            $this->cmpinfo = $output . '\n' . $this->cmpinfo;
//        list($output, $this->cmpinfo) = $this->run_in_sandbox($cmd);
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

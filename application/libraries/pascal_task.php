<?php defined('BASEPATH') OR exit('No direct script access allowed');

/* ==============================================================
 *
 * Pascal
 *
 * ==============================================================
 *
 * @copyright  2015 Fedor Lyanguzov, based on 2014 Richard Lobb, University of Canterbury
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('application/libraries/LanguageTask.php');

class Pascal_Task extends Task {

    public function __construct($filename, $input, $params) {
        parent::__construct($filename, $input, $params);
        $this->default_params['compileargs'] = array(
            '-vew', // [v]erbose, [e]rrors, [w]arnings
	    '-Mdelphi', 
            '-Se'); // stop on first error
    }

    public static function getVersionCommand() {
        return array('fpc -iV', '/([0-9._]*)/');
    }

    public function compile() {
        $src = basename($this->sourceFileName);
        $errorFileName = "$src.err";
        $execFileName = "$src.exe";
        $compileargs = $this->getParam('compileargs');
        $cmd = "fpc " . implode(' ', $compileargs) . " -Fe$errorFileName -o$execFileName $src";
        list($output, $stderr) = $this->run_in_sandbox($cmd);
//	Getting not only errors, but warnings too
	exec("grep ' Warning:\| Error:\| Fatal:' $errorFileName ", $errors, $r1);
        if (!file_exists($execFileName)) {
            $this->cmpinfo = implode("\n", $errors);
        } else {
            $this->cmpinfo = implode("\n", $errors);
            $this->executableFileName = $execFileName;
        }
    }

    // A default name for Pascal programs
    public function defaultFileName($sourcecode) {
        return 'prog.pas';
    }


    // The executable is the output from the compilation
    public function getExecutablePath() {
        return "./" . $this->executableFileName;
    }


    public function getTargetFile() {
        return '';
    }
};

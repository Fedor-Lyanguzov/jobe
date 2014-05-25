<?php defined('BASEPATH') OR exit('No direct script access allowed');

/* ==============================================================
 *
 * This file defines the abstract Task class, a subclass of which
 * must be defined for each implemented language.
 *
 * ==============================================================
 *
 * @copyright  2014 Richard Lobb, University of Canterbury
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('application/libraries/resultobject.php');

define('ACTIVE_USERS', 1);  // The key for the shared memory active users array
define('MAX_RETRIES', 5);   // Maximum retries (1 secs per retry), waiting for free user account

class OverloadException extends Exception {
}


abstract class Task {

    // Symbolic constants as per ideone API

    const RESULT_COMPILATION_ERROR = 11;
    const RESULT_RUNTIME_ERROR = 12;
    const RESULT_TIME_LIMIT   = 13;
    const RESULT_SUCCESS      = 15;
    const RESULT_MEMORY_LIMIT    = 17;
    const RESULT_ILLEGAL_SYSCALL = 19;
    const RESULT_INTERNAL_ERR = 20;
    const RESULT_SERVER_OVERLOAD = 21;

    public $DEFAULT_PARAMS = array(
        'disklimit'     => 100,     // MB
        'cputime'       => 5,       // secs
        'memorylimit'   => 50,      // MB
        'numprocs'      => 20
    );

    public $cmpinfo = '';   // Output from compilation
    public $time = 0;       // Execution time (secs)
    public $memory = 0;     // Memory used (MB)
    public $signal = 0;
    public $stdout = '';    // Output from execution
    public $stderr = '';
    public $result = Task::RESULT_INTERNAL_ERR;  // Should get overwritten
    public $workdir = '';   // The temporary working directory created in constructor
    public $id = '';        // The basename of the workdir, used as a job id

    // For all languages it is necessary to store the source code in a
    // temporary file when constructing the task. A temporary directory
    // is made to hold the source code. The standard input ($input) and
    // the run parameters ($params) are
    // saved for use at runtime.
    public function __construct($sourceCode, $filename, $input, $params) {
        $this->workdir = tempnam("/home/jobe/runs", "jobe_");
        if (!unlink($this->workdir) || !mkdir($this->workdir)) {
            log_message('error', 'LanguageTask constructor: error making temp directory');
            throw new coding_exception("Task: error making temp directory (race error?)");
        }
        $this->id = basename($this->workdir);
        $this->input = $input;
        $this->sourceFileName = $filename;
        $this->params = $params;
        chdir($this->workdir);
        $handle = fopen($this->sourceFileName, "w");
        fwrite($handle, $sourceCode);
        fclose($handle);
    }


    protected function getParam($key) {
        if (isset($this->params) && array_key_exists($key, $this->params)) {
            return $this->params[$key];
        } else {
            return $this->DEFAULT_PARAMS[$key];
        }
    }
    

    // Return the JobeAPI result object to describe the state of this task
    public function resultObject() {
        if ($this->cmpinfo) {
            $this->result = Task::RESULT_COMPILATION_ERROR;
        }
        return new ResultObject(
            $this->workdir,     // TODO get a better ID than this
            $this->result,
            $this->cmpinfo,
            $this->filteredStdout(),
            $this->filteredStderr()
        );
    }

    
    // Load the specified files into the working directory.
    // The file list is an array of (fileId, filename) pairs.
    // Return False if any are not present.
    public function load_files($fileList, $filecachedir) {
        foreach ($fileList as $file) {
            $fileId = $file[0];
            $filename = $file[1];
            $path = $filecachedir . $fileId;
            $destPath = $this->workdir . '/' . $filename;
            if (!file_exists($path) || 
               ($contents = file_get_contents($path)) === FALSE ||
               (file_put_contents($destPath, $contents)) === FALSE) {
                return FALSE;
            }
        }
        return TRUE;
    }

    // Compile the current source file in the current directory, saving
    // the compiled output in a file $this->executableFileName.
    // Sets $this->cmpinfo accordingly.
    public abstract function compile();

    
    // Find a currently unused jobe user account.
    // Uses a shared memory segment containing one byte (used as a 'busy'
    // boolean) for each of the possible user accounts.
    // If no free accounts exist at present, the function sleeps for a
    // second then retries, up to a maximum of 10 retries.
    // Throws OverloadException if a free user cannot be found, otherwise 
    // returns an integer in the range 0 to jobe_max_users - 1 inclusive.
    private function getFreeUser() {
        global $CI;

        $numUsers = $CI->config->item('jobe_max_users');
        $key = ftok(__FILE__, 'j');
        $sem = sem_get($key);
        $user = -1;
        $retries = 0; 
        while ($user == -1 && $retries < MAX_RETRIES) {
            sem_acquire($sem);
            $shm = shm_attach($key); 
            if (!shm_has_var($shm, ACTIVE_USERS)) {
                // First time since boot -- initialise active list
                $active = array();
                for($i = 0; $i < $numUsers; $i++) {
                    $active[$i] = FALSE;
                }
                shm_put_var($shm, ACTIVE_USERS, $active);
            }
            $active = shm_get_var($shm, ACTIVE_USERS);
            for ($user = 0; $user < $numUsers; $user++) {
                if (!$active[$user]) {
                    $active[$user] = TRUE;
                    shm_put_var($shm, ACTIVE_USERS, $active);
                    break;
                }
            }
            shm_detach($shm);
            sem_release($sem);
            if ($user == $numUsers) {
                $user = -1;
                $retries += 1;
                if ($retries < MAX_RETRIES) {
                    sleep(1);
                } else {
                    throw new OverloadException();
                }
            }
        }
        return $user;
    }
    
    
    // Mark the given user number (0 to jobe_max_users - 1) as free.
    private function freeUser($userNum) {
        $key = ftok(__FILE__, 'j');
        $sem = sem_get($key);
        sem_acquire($sem);
        $shm = shm_attach($key);
        $active = shm_get_var($shm, ACTIVE_USERS);
        $active[$userNum] = FALSE;
        shm_put_var($shm, ACTIVE_USERS, $active);
        shm_detach($shm);
        sem_release($sem);        
    }

    
    // Execute this task, which must already have been compiled if necessary
    public function execute() {

        try {
            // Establish all the parameters for the job run
            
            $userId = $this->getFreeUser();
            $user = sprintf("jobe%02d", $userId);
            $filesize = 1000 * $this->getParam('disklimit'); // MB -> kB
            $memsize = 1000 * $this->getParam('memorylimit');
            $cputime = $this->getParam('cputime');
            $numProcs = $this->getParam('numprocs');
            $sandboxCmdBits = array(
                 "sudo " . dirname(__FILE__)  . "/../../runguard/runguard",
                 "--user=$user",
                 "--time=$cputime",         // Seconds of execution time allowed
                 "--filesize=$filesize",    // Max file sizes
                 "--nproc=$numProcs",       // Max num processes/threads for this *user*
                 "--no-core",
                 "--streamsize=$filesize");  // Max stdout/stderr sizes

            if ($memsize != 0) {  // Special case: Matlab won't run with a memsize set. TODO: WHY NOT!
                $sandboxCmdBits[] = "--memsize=$memsize";
            }
            $allCmdBits = array_merge($sandboxCmdBits, $this->getRunCommand());
            $cmd = implode(' ', $allCmdBits) . " >prog.out 2>prog.err";

            // Set up the work directory and run the job
            $workdir = $this->workdir;
            exec("setfacl -m u:$user:rwX $workdir");  // Give the user RW access
            chdir($workdir);
            file_put_contents('prog.cmd', $cmd);

            if ($this->input != '') {
                $f = fopen('prog.in', 'w');
                fwrite($f, $this->input);
                fclose($f);
                $cmd .= " <prog.in";
            }
            else {
                $cmd .= " </dev/null";
            }

            $handle = popen($cmd, 'r');
            $result = fread($handle, MAX_READ);
            pclose($handle);
            
            // Copy results back out into this object
            
            $this->stdout = file_get_contents("$workdir/prog.out");
            
            if (file_exists("$workdir/prog.err")) {
                $this->stderr = file_get_contents("$workdir/prog.err");
            }
            
            $this->stderr = $this->filteredStderr();
            $this->diagnose_result();  // Analyse output and set result
        }
        catch (OverloadException $e) {
            $this->result = Task::RESULT_SERVER_OVERLOAD;
            $this->stderr = $e->getMessage();
        }
        catch (Exception $e) {
            $this->result = Task::RESULT_INTERNAL_ERR;
            $this->stderr = $e->getMessage();
        }

        if (isset($userId)) {
            exec("sudo /usr/bin/pkill -9 -u $user"); // Kill any remaining processes
            $this->freeUser($userId);
        }

    }

    // Return the Linux command to use to run the current job with the given
    // standard input. It's an array of string arguments, suitable
    // for passing to the LiuSandbox.
    public abstract function getRunCommand();


    // Return the version of language supported by this particular Language/Task
    public static function getVersion() {
        return '';  // To be overridden by subclass
    }

    
    // Called after each run to set the task result value. Default is to
    // set the result to SUCCESS if there's no stderr output or to timelimit
    // exceeded if the appropriate warning message is found in stdout or
    // to runtime error otherwise.
    // Note that Runguard does not identify memorylimit exceeded as a special
    // type of runtime error so that value is not returned by default.
    
    // Subclasses may wish to add further postprocessing, e.g. for memory
    // limit exceeded if the language identifies this specifically.
    public function diagnose_result() {
        if (strlen($this->filteredStderr())) {
            $this->result = TASK::RESULT_RUNTIME_ERROR;
        } else {
            $this->result = TASK::RESULT_SUCCESS;
        } 
        
        // Refine RuntimeError if possible

        if (strpos($this->stderr, "warning: timelimit exceeded")) {
            $this->result = Task::RESULT_TIME_LIMIT;
            $this->signal = 9;
            $this->stderr = '';
        } else if(strpos($this->stderr, "warning: command terminated with signal 11")) {
            $this->signal = 11;
            $this->stderr = '';
        }
    }

    
    // Override the following function if the output from executing a program
    // in this language needs post-filtering to remove stuff like
    // header output.
    public function filteredStdout() {
        return $this->stdout;
    }


    // Override the following function if the stderr from executing a program
    // in this language needs post-filtering to remove stuff like
    // backspaces and bells.
    public function filteredStderr() {
        return $this->stderr;
    }


    // Called to clean up task when done
    public function close($deleteFiles=TRUE) {
        if ($deleteFiles) {
            $dir = $this->workdir;
            exec("sudo rm -R $dir");
        }
    }

    // Check if PHP exec environment includes a PATH. If not, set up a
    // default, or gcc misbehaves. [Thanks to Binoj D for this bug fix,
    // needed on his CentOS system.]
    protected function setPath() {
        $envVars = array();
        exec('printenv', $envVars);
        $hasPath = FALSE;
        foreach ($envVars as $var) {
            if (strpos($var, 'PATH=') === 0) {
                $hasPath = TRUE;
                break;
            }
        }
        if (!$hasPath) {
            putenv("PATH=/sbin:/bin:/usr/sbin:/usr/bin");
        }
    }

}


?>
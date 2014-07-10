<?php
/**
 * The WorkerPool Requires the following PHP extensions
 *    * pcntl
 *    * posix
 *    * sysvsem
 *    * sockets
 *    * proctitle (optional, PHP5.5+ comes with a builtin function)
 *
 * Use the following commands to install them on RHEL:
 *    yum install php-process php-pcntl
 *    yum install php-pear php-devel ; pecl install proctitle
 *    echo 'extension=proctitle.so' > /etc/php.d/proctitle.ini
 */

namespace QXS\WorkerPool;

/**
 * The Worker Pool class runs worker processes in parallel
 *
 */
class WorkerPool implements \Iterator, \Countable {

	/** @var bool has the pool already been created? */
	private $created = FALSE;
	/** @var int the current worker pool size */
	private $workerPoolSize = 2;
	/** @var int the id of the parent */
	protected $parentPid = 0;
	/** @var array forked processes with their pids and sockets */
	protected $processes = array();
	/** @var \QXS\WorkerPool\Worker the worker class, that is used to run the tasks */
	protected $worker = NULL;
	/** @var \QXS\WorkerPool\Semaphore the semaphore, that is used to synchronizd tasks across all processes */
	protected $semaphore = NULL;
	/** @var array queue of free process pids */
	protected $freeProcesses = array();
	/** @var array received results from the workers */
	protected $results = array();
	/** @var int number of received results */
	protected $resultPosition = 0;
	/** @var string process title of the parent */
	protected $parentProcessTitleFormat = '%basename%: Parent';
	/** @var string process title of the children */
	protected $childProcessTitleFormat = '%basename%: Worker %i% of %class% [%state%]';

	/**
	 * Sanitizes the process title format string
	 * @param string $string the process title
	 * @return string the process sanitized title
	 * @throws \DomainException in case the $string value is not within the permitted range
	 */
	public static function sanitizeProcessTitleFormat($string) {
		$string=preg_replace(
			'/[^a-z0-9-_.:% \\\\\\]\\[]/i',
			'',
			$string
		);
		$string=trim($string);
		return $string;
	}

	/**
	 * Returns the process title of the child
	 * @return string the process title of the child
	 */
	public function getChildProcessTitleFormat() {
		return $this->childProcessTitleFormat;
	}

	/**
	 * Sets the process title of the child
	 *
	 * Listing permitted replacments
	 *   %i%         The Child's Number
	 *   %basename%  The base name of PHPSELF
	 *   %fullname%  The value of PHPSELF
	 *   %class%     The Worker's Classname
	 *   %state%     The Worker's State
	 *
	 * @param string $string the process title of the child
	 * @throws \QXS\WorkerPool\WorkerPoolException in case the WorkerPool has already been created
	 * @throws \DomainException in case the $string value is not within the permitted range
	 */
	public function setChildProcessTitleFormat($string) {
		if ($this->created) {
			throw new WorkerPoolException('Cannot set the Parent\'s Process Title Format for a created pool.');
		}
		$this->childProcessTitleFormat = self::sanitizeProcessTitleFormat($string);
		return $this;
	}

	/**
	 * Returns the process title of the parent
	 * @return string the process title of the parent
	 */
	public function getParentProcessTitleFormat() {
		return $this->parentProcessTitleFormat;
	}

	/**
	 * Sets the process title of the parent
	 *
	 * Listing permitted replacments
	 *   %basename%  The base name of PHPSELF
	 *   %fullname%  The value of PHPSELF
	 *   %class%     The WorkerPool's Classname
	 *
	 * @param string $string the process title of the parent
	 * @throws \QXS\WorkerPool\WorkerPoolException in case the WorkerPool has already been created
	 * @throws \DomainException in case the $string value is not within the permitted range
	 */
	public function setParentProcessTitleFormat($string) {
		if ($this->created) {
			throw new WorkerPoolException('Cannot set the Children\'s Process Title Format for a created pool.');
		}
		$this->parentProcessTitleFormat = self::sanitizeProcessTitleFormat($string);
		return $this;
	}

	/**
	 * Returns the current size of the worker pool
	 *
	 * In case the pool hasn't yet been created, this method returns the value of the currently set pool size.
	 * In case of a created pool, this method reports the real pool size (number of alive worker processes).
	 * @return int the number of processes
	 */
	public function getWorkerPoolSize() {
		return $this->workerPoolSize;
	}

	/**
	 * Sets the current size of the worker pool
	 * @param int $size the new worker pool size
	 * @throws \QXS\WorkerPool\WorkerPoolException in case the WorkerPool has already been created
	 * @throws \DomainException in case the $size value is not within the permitted range
	 */
	public function setWorkerPoolSize($size) {
		if ($this->created) {
			throw new WorkerPoolException('Cannot set the Worker Pool Size for a created pool.');
		}
		$size = (int)$size;
		if ($size <= 0) {
			throw new \DomainException('"' . $size . '" is not an integer greater than 0.');
		}
		$this->workerPoolSize = $size;
		return $this;
	}

	/**
	 * The constructor
	 */
	public function __construct() {
	}

	/**
	 * The destructor
	 */
	public function __destruct() {
		if ($this->created) {
			$this->destroy();
		}
	}

	/**
	 * Sets the Semaphore, that will be used within the worker processes
	 * @return null|\QXS\WorkerPool\Semaphore $semaphore the Semaphore, that should be used for the workers
	 * @throws \InvalidArgumentException in case the semaphre hasn't been created
	 */
	public function getSemaphore() {
		return $this->semaphore;
	}

	/**
	 * Sets the Semaphore, that will be used within the worker processes
	 * @param \QXS\WorkerPool\Semaphore $semaphore the Semaphore, that should be used for the workers
	 * @return WorkerPool
	 * @throws \QXS\WorkerPool\WorkerPoolException in case the WorkerPool has already been created
	 * @throws \InvalidArgumentException in case the semaphre hasn't been created
	 */
	public function setSemaphore(Semaphore $semaphore) {
		if ($this->created) {
			throw new WorkerPoolException('Cannot set the Worker Pool Size for a created pool.');
		}
		if (!$semaphore->isCreated()) {
			throw new \InvalidArgumentException('The Semaphore hasn\'t yet been created.');
		}
		$this->semaphore = $semaphore;
		return $this;
	}

	/**
	 * Terminates the current process
	 * @param int $code the exit code
	 */
	public function exitPhp($code) {
		exit($code);
	}

	/**
	 * Sets the proccess title
	 *
	 * This function call requires php5.5+ or the proctitle extension!
	 * Empty title strings won't be set.
	 * @param string $title the new process title
	 * @param array $replacements an associative array of replacment values
	 */
	protected function setProcessTitle($title, array $replacements=array()) {
		// skip empty title names
		if(trim($title)=='') {
			return null;
		}
		// 1. replace the values
		$title = preg_replace_callback(
			'/\%([a-z0-9]+)\%/i',
			function ($match) use ($replacements) {
				if (isset($replacements[$match[1]])) {
					return $replacements[$match[1]];
				}
				return $match[0];
			},
			$title
		);
		// 2. remove forbidden chars
		$title = preg_replace(
			'/[^a-z0-9-_.: \\\\\\]\\[]/i',
			'',
			$title
		);
		// 3. set the title
		if (function_exists('cli_set_process_title')) {
			cli_set_process_title($title); // PHP 5.5+ has a builtin function
		} elseif (function_exists('setproctitle')) {
			setproctitle($title); // pecl proctitle extension
		}
	}

	/**
	 * Creates the worker pool (forks the children)
	 *
	 * Please close all open resources before running this function.
	 * Child processes are going to close all open resources uppon exit,
	 * leaving the parent process behind with invalid resource handles.
	 * @param \QXS\WorkerPool\Worker $worker the worker, that runs future tasks
	 */
	public function create(Worker $worker) {
		if ($this->workerPoolSize <= 1) {
			$this->workerPoolSize = 2;
		}
		$this->parentPid = getmypid();
		$this->worker = $worker;
		if ($this->created) {
			throw new WorkerPoolException('The pool has already been created.');
		}
		$this->created = TRUE;
		// when adding signals use pcntl_signal_dispatch(); or declare ticks
		pcntl_signal(SIGCHLD, array($this, 'signalHandler'));
		pcntl_signal(SIGTERM, array($this, 'signalHandler'));
		pcntl_signal(SIGHUP, array($this, 'signalHandler'));
		pcntl_signal(SIGUSR1, array($this, 'signalHandler'));

		if(!($this->semaphore instanceof Semaphore)) {
			$this->semaphore = new Semaphore();
			$this->semaphore->create(Semaphore::SEM_RAND_KEY);
		}
		elseif(!$this->semaphore->isCreated()) {
			$this->semaphore->create(Semaphore::SEM_RAND_KEY);
		}

		$this->setProcessTitle(
			$this->parentProcessTitleFormat,
			array(
				'basename' => basename($_SERVER['PHP_SELF']),
				'fullname' => $_SERVER['PHP_SELF'],
				'class' => get_class($this)
			)
		);

		for ($i = 1; $i <= $this->workerPoolSize; $i++) {
			$sockets = array();
			if (socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets) === FALSE) {
				// clean_up using posix_kill & pcntl_wait
				throw new \RuntimeException('socket_create_pair failed.');
				break;
			}
			$processId = pcntl_fork();
			if ($processId < 0) {
				// cleanup using posix_kill & pcntl_wait
				throw new \RuntimeException('pcntl_fork failed.');
				break;
			} elseif ($processId == 0) {
				// WE ARE IN THE CHILD
				$this->processes = array(); // we do not have any children
				$this->workerPoolSize = 0; // we do not have any children
				socket_close($sockets[1]); // close the parent socket
				$this->runWorkerProcess(
					$worker,
					new SimpleSocket($sockets[0]),
					$i
				);
			} else {
				// WE ARE IN THE PARENT
				socket_close($sockets[0]); // close child socket
				// create the child
				$this->processes[$processId] = array(
					'pid' => $processId,
					'socket' => new SimpleSocket($sockets[1])
				);
				$this->processes[$processId]['socket']->annotation['pid'] = $processId;
				// mark it as a free child
				$this->freeProcesses[$processId] = $processId;
			}
		}

		return $this;
	}

	/**
	 * Run the worker process
	 * @param \QXS\WorkerPool\Worker $worker the worker, that runs the tasks
	 * @param \QXS\WorkerPool\SimpleSocket $simpleSocket the simpleSocket, that is used for the communication
	 * @param int $i the number of the child
	 */
	protected function runWorkerProcess(Worker $worker, SimpleSocket $simpleSocket, $i) {
		$replacements = array(
			'basename' => basename($_SERVER['PHP_SELF']),
			'fullname' => $_SERVER['PHP_SELF'],
			'class' => get_class($worker),
			'i' => $i,
			'state' => 'free'
		);
		$this->setProcessTitle($this->childProcessTitleFormat, $replacements);
		$this->worker->onProcessCreate($this->semaphore);
		while (TRUE) {
			$output = array('pid' => getmypid());
			try {
				$replacements['state'] = 'free';
				$this->setProcessTitle($this->childProcessTitleFormat, $replacements);
				$cmd = $simpleSocket->receive();
				// invalid response from parent?
				if (!isset($cmd['cmd'])) {
					break;
				}
				$replacements['state'] = 'busy';
				$this->setProcessTitle($this->childProcessTitleFormat, $replacements);
				if ($cmd['cmd'] == 'run') {
					try {
						$output['data'] = $this->worker->run($cmd['data']);
					} catch (\Exception $e) {
						$output['workerException'] = array(
							'class' => get_class($e),
							'message' => $e->getMessage(),
							'trace' => $e->getTraceAsString()
						);
					}
					// send back the output
					$simpleSocket->send($output);
				} elseif ($cmd['cmd'] == 'exit') {
					break;
				}
			} catch (SimpleSocketException $e) {
				break;
			} catch (\Exception $e) {
				// send Back the exception
				$output['poolException'] = array(
					'class' => get_class($e),
					'message' => $e->getMessage(),
					'trace' => $e->getTraceAsString()
				);
				$simpleSocket->send($output);
			}
		}
		$this->worker->onProcessDestroy();
		$this->exitPhp(0);
	}

	/**
	 * Destroy the WorkerPool with all its children
	 * @param int $maxWaitSecs a timeout to wait for the children, before killing them
	 */
	public function destroy($maxWaitSecs = 10) {
		if (!$this->created) {
			throw new WorkerPoolException('The pool hasn\'t yet been created.');
		}
		$this->created = FALSE;

		if ($this->parentPid == getmypid()) {
			$maxWaitSecs = ((int)$maxWaitSecs) * 2;
			if ($maxWaitSecs <= 1) {
				$maxWaitSecs = 2;
			}
			// send the exit instruction
			foreach ($this->processes as $process) {
				try {
					$process['socket']->send(array('cmd' => 'exit'));
				} catch (\Exception $e) {
				}
			}
			// wait up to 10 seconds
			for ($i = 0; $i < $maxWaitSecs; $i++) {
				usleep(500000); // 0.5 seconds
				pcntl_signal_dispatch();
				if ($this->workerPoolSize == 0) {
					break;
				}
			}
			// reset the handlers
			pcntl_signal(SIGCHLD, SIG_DFL);
			pcntl_signal(SIGTERM, SIG_DFL);
			pcntl_signal(SIGHUP, SIG_DFL);
			pcntl_signal(SIGUSR1, SIG_DFL);
			// kill all remaining processes
			foreach ($this->processes as $process) {
				@socket_close($process['socket']->getSocket());
				posix_kill($process['pid'], 9);
			}
			usleep(500000); // 0.5 seconds
			// reap the remaining signals
			$this->reaper();
			// destroy the semaphore
			$this->semaphore->destroy();
		}

		return $this;
	}

	/**
	 * Receives signals
	 *
	 * DO NOT MANUALLY CALL THIS METHOD!
	 * pcntl_signal_dispatch() will be calling this method.
	 * @param int $signo the signal number
	 * @see pcntl_signal_dispatch
	 * @see pcntl_signal
	 */
	public function signalHandler($signo) {
		switch ($signo) {
			case SIGCHLD:
				$this->reaper();
				break;
			case SIGTERM:
				// handle shutdown tasks
				$this->exitPhp(0);
				break;
			case SIGHUP:
				// handle restart tasks
				break;
			case SIGUSR1:
				// handle sigusr
				break;
			default: // handle all other signals
		}
		// more signals to dispatch?
		pcntl_signal_dispatch();
	}

	/**
	 * Child process reaper
	 * @param int $pid the process id
	 * @see pcntl_waitpid
	 */
	protected function reaper($pid = -1) {
		if (!is_int($pid)) {
			$pid = -1;
		}
		$childpid = pcntl_waitpid($pid, $status, WNOHANG);
		while ($childpid > 0) {
			if (isset($this->processes[$childpid])) {
				$this->workerPoolSize--;
				@socket_close($this->processes[$childpid]['socket']->getSocket());
				unset($this->processes[$childpid]);
				unset($this->freeProcesses[$childpid]);
			}
			$childpid = pcntl_waitpid($pid, $status, WNOHANG);
		}
		// remove freeProcesses
		foreach ($this->freeProcesses as $key => $pid) {
			if (!isset($this->processes[$pid])) {
				unset($this->freeProcesses[$key]);
			}
		}
	}

	/**
	 * Waits for all children to finish their worka
	 *
	 * This function blocks until every worker has finished its work.
	 * You can kill hanging child processes, so that the parent will be unblocked.
	 */
	public function waitForAllWorkers() {
		while ($this->getBusyWorkers() > 0) {
			$this->collectWorkerResults(10);
		}
	}

	/**
	 * Returns the number of busy and free workers
	 *
	 * This function collects all the information at once.
	 * @param array with the keys 'free', 'busy', 'total'
	 */
	public function getFreeAndBusyWorkers() {
		$this->collectWorkerResults();
		return array(
			'free' => count($this->freeProcesses),
			'busy' => $this->workerPoolSize - count($this->freeProcesses),
			'total' => $this->workerPoolSize
		);;
	}

	/**
	 * Returns the number of free workers
	 *
	 * PAY ATTENTION WHEN USING THIS FUNCTION WITH A SUBSEQUENT CALL OF getBusyWorkers().
	 * IN THIS CASE THE SUM MIGHT NOT EQUAL TO THE CURRENT POOL SIZE.
	 * USE getFreeAndBusyWorkers() TO GET CONSISTENT RESULTS.
	 * @param int number of free workers
	 */
	public function getFreeWorkers() {
		$this->collectWorkerResults();
		return count($this->freeProcesses);
	}

	/**
	 * Returns the number of busy workers
	 *
	 * PAY ATTENTION WHEN USING THIS FUNCTION WITH A SUBSEQUENT CALL OF getFreeWorkers().
	 * IN THIS CASE THE SUM MIGHT NOT EQUAL TO THE CURRENT POOL SIZE.
	 * USE getFreeAndBusyWorkers() TO GET CONSISTENT RESULTS.
	 * @param int number of free workers
	 */
	public function getBusyWorkers() {
		$this->collectWorkerResults();
		return $this->workerPoolSize - count($this->freeProcesses);
	}

	/**
	 * Get the pid of the next free worker
	 *
	 * This function blocks until a worker has finished its work.
	 * You can kill all child processes, so that the parent will be unblocked.
	 * @return int the pid of the next free child
	 */
	protected function getNextFreeWorker() {
		$sec = 0;
		while (TRUE) {
			$this->collectWorkerResults($sec);
			// get a free child
			while (count($this->freeProcesses) > 0) {
				$arr = array_keys($this->freeProcesses); // combining array_keys and array_shift returns an error: Strict standards: Only variables should be passed by reference
				$childpid = array_shift($arr); //array_shift  modifies the keys
				unset($this->freeProcesses[$childpid]);
				if (isset($this->processes[$childpid])) {
					return $childpid;
				}
			}
			$sec = 10;
			if ($this->workerPoolSize <= 0) {
				throw new WorkerPoolException('All workers were gone.');
			}
		}
	}

	/**
	 * Collects the resluts form the workers and processes any pending signals
	 * @param int $sec timeout to wait for new results from the workers
	 */
	protected function collectWorkerResults($sec = 0) {
		// dispatch signals
		pcntl_signal_dispatch();
		// let's collect the information
		$read = array();
		foreach ($this->processes as $process) {
			$read[] = $process['socket'];
		}
		if (!empty($read)) {
			$result = SimpleSocket::select($read, array(), array(), $sec);
			foreach ($result['read'] as $socket) {
				$processId = $socket->annotation['pid'];
				$this->freeProcesses[$processId] = $processId;
				$result = $socket->receive();
				$result['pid'] = $processId;
				if (isset($result['data'])) {
					// null values won't be stored
					if (!is_null($result['data'])) {
						array_push($this->results, $result);
					}
				} elseif (isset($result['workerException']) || isset($result['poolException'])) {
					array_push($this->results, $result);
				}
			}
		}
		// dispatch signals
		pcntl_signal_dispatch();
	}

	/**
	 * Sends the input to the next free worker process
	 *
	 * This function blocks until a worker has finished its work.
	 * You can kill all child processes, so that the parent will be unblocked.
	 * @param mixed $input any serializeable value
	 */
	public function run($input) {
		while ($this->workerPoolSize > 0) {
			try {
				$childpid = $this->getNextFreeWorker();
				$this->processes[$childpid]['socket']->send(array('cmd' => 'run', 'data' => $input));
				return $this;
			} catch (\Exception $e) {
				pcntl_signal_dispatch();
			}
		}
		throw new WorkerPoolException('Unable to run the task.');
	}

	/**
	 * Clear all the results
	 */
	public function clearResults() {
		$this->collectWorkerResults();
		$this->results = array();
		return $this;
	}

	/**
	 * Is there any result available?
	 * @return bool true, in case we have received some results
	 */
	public function hasResults() {
		$this->collectWorkerResults();
		return !empty($this->results);
	}

	/**
	 * How many results did we receive?
	 * @return int the number of results
	 */
	public function countResults() {
		$this->collectWorkerResults();
		return $this->count();
	}

	/**
	 * Shifts the next result from the result queue
	 * @return array gets the next result
	 */
	public function getNextResult() {
		$this->collectWorkerResults();
		return array_shift($this->results);
	}

	/**
	 * Countable Method count
	 * @return int the number of results
	 * @see \QXS\WorkerPool\WorkerPool::countResults()
	 */
	public function count() {
		$this->collectWorkerResults();
		return count($this->results);
	}

	/**
	 * Iterator Method current
	 * @return array gets the current result
	 */
	public function current() {
		return reset($this->results);
	}

	/**
	 * Iterator Method key
	 * @return string returns the current key
	 */
	public function key() {
		return $this->resultPosition;
	}

	/**
	 * Iterator Method next()
	 */
	public function next() {
		$this->collectWorkerResults();
		if(!empty($this->results)) {
			$this->resultPosition++;
		}
		array_shift($this->results);
	}

	/**
	 * Iterator Method rewind()
	 */
	public function rewind() {
	}

	/**
	 * Iterator Method valid()
	 * @return bool true = there is a pending result
	 */
	public function valid() {
		return !empty($this->results);
	}
}



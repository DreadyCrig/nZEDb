<?php
namespace nzedb\libraries;

require_once(nZEDb_LIBS . 'forkdaemon-php' . DS . 'fork_daemon.php');

/**
 * Class Forking
 *
 * This forks various nZEDb scripts.
 *
 * For example, you get all the ID's of the active groups in the groups table, you then iterate over them and spawn
 * processes of misc/update_binaries.php passing the group ID's.
 *
 * @package nzedb\libraries
 */
class Forking extends \fork_daemon
{
	const OUTPUT_NONE     = 0; // Don't display child output.
	const OUTPUT_REALTIME = 1; // Display child output in real time.
	const OUTPUT_SERIALLY = 2; // Display child output when child is done.

	/**
	 * Setup required parent / self vars.
	 */
	public function __construct()
	{
		parent::__construct();

		$this->register_logging(
			[0 => $this, 1 => 'logger'],
			(defined('nZEDb_MULTIPROCESSING_LOG_TYPE') ? nZEDb_MULTIPROCESSING_LOG_TYPE : \fork_daemon::LOG_LEVEL_INFO)
		);

		$this->max_work_per_child_set(1);
		if (defined('nZEDb_MULTIPROCESSING_MAX_CHILD_WORK')) {
			$this->max_work_per_child_set(nZEDb_MULTIPROCESSING_MAX_CHILD_WORK);
		}

		$this->child_max_run_time_set(1800);
		if (defined('nZEDb_MULTIPROCESSING_MAX_CHILD_TIME')) {
			$this->child_max_run_time_set(nZEDb_MULTIPROCESSING_MAX_CHILD_TIME);
		}

		// Use a single exit method for all children, makes things easier.
		$this->register_parent_child_exit([0 => $this, 1 => 'childExit']);

		$this->outputType = self::OUTPUT_REALTIME;
		if (defined('nZEDb_MULTIPROCESSING_CHILD_OUTPUT_TYPE')) {
			switch (nZEDb_MULTIPROCESSING_CHILD_OUTPUT_TYPE) {
				case 0:
					$this->outputType = self::OUTPUT_NONE;
					break;
				case 1:
					$this->outputType = self::OUTPUT_REALTIME;
					break;
				case 2:
					$this->outputType = self::OUTPUT_SERIALLY;
					break;
				default:
					$this->outputType = self::OUTPUT_REALTIME;
			}
		}

		$this->dnr_path = PHP_BINARY . ' ' . nZEDb_MULTIPROCESSING . '.do_not_run' . DS . 'switch.php "php  ';
	}

	/**
	 * Setup the class to work on a type of work, then process the work.
	 * Valid work types:
	 *
	 * @param string $type    The type of multiProcessing to do : backfill, binaries, releases, postprocess
	 * @param array  $options Array containing arguments for the type of work.
	 *
	 * @throws ForkingException
	 */
	public function processWorkType($type, array $options = array())
	{
		// Set/reset some variables.
		$startTime = microtime(true);
		$this->workType = $type;
		$this->workTypeOptions = $options;
		$this->processAdditional = $this->processNFO = $this->processTV = $this->processMovies = $this->tablePerGroup = false;
		$this->work = array();

		// Init Settings here, as forking causes errors when it's destroyed.
		$this->pdo = new \nzedb\db\Settings();

		// Get work to fork.
		$this->getWork();

		// Now we destroy settings, to prevent errors from forking.
		unset($this->pdo);

		// Process the work we got.
		$this->processWork();

		// Process extra work that should not be forked and done after.
		$this->processEndWork();

		if (nZEDb_ECHOCLI) {
			echo (
				'Multi-processing for ' . $this->workType . ' finished in ' .  (microtime(true) - $startTime) .
				' seconds at ' . date(DATE_RFC2822) . '.' . PHP_EOL
			);
		}
	}

	/**
	 * Get work for our workers to work on, set the max child processes here.
	 */
	private function getWork()
	{
		$maxProcesses = 0;

		switch ($this->workType) {

			case 'backfill':
				$maxProcesses = $this->backfillMainMethod();
				break;

			case 'binaries':
				$maxProcesses = $this->binariesMainMethod();
				break;

			case 'releases':
				$maxProcesses = $this->releasesMainMethod();
				break;

			case 'postProcess_ama':
				$this->processSingle();
				break;

			case 'postProcess_add':
				$maxProcesses = $this->postProcessAddMainMethod();
				break;

			case 'postProcess_mov':
				$maxProcesses = $this->postProcessMovMainMethod();
				break;

			case 'postProcess_nfo':
				$maxProcesses = $this->postProcessNfoMainMethod();
				break;

			case 'postProcess_sha':
				$this->processSharing();
				break;

			case 'postProcess_tv':
				$maxProcesses = $this->postProcessTvMainMethod();
				break;

			case 'request_id':
				$maxProcesses = $this->requestIDMainMethod();
				break;

			case 'update_all':
				$maxProcesses = $this->updateAllMainMethod();
				break;
		}

		$this->setMaxProcesses($maxProcesses);
	}

	/**
	 * Process work if we have any.
	 */
	private function processWork()
	{
		$this->workCount = count($this->work);
		if ($this->workCount > 0) {

			if (nZEDb_ECHOCLI) {
				echo (
					'Multi-processing started at ' . date(DATE_RFC2822) . ' with ' . $this->workCount .
					' job(s) to do using a max of ' . $this->maxProcesses . ' child process(es).' . PHP_EOL
				);
			}

			$this->addwork($this->work);
			$this->process_work(true);
		} else {
			if (nZEDb_ECHOCLI) {
				echo 'No work to do!' . PHP_EOL;
			}
		}
	}

	/**
	 * Process any work that does not need to be forked, but needs to run at the end.
	 */
	private function processEndWork()
	{
		if ($this->workType === 'releases' && $this->tablePerGroup === true) {
			$this->executeCommand(
				$this->dnr_path . 'releases  ' . count($this->work) . '_"'
			);
		}
	}

	////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	//////////////////////////////////////// All backFill code here ////////////////////////////////////////////////////
	////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

	/**
	 * @return int
	 */
	private function backfillMainMethod()
	{
		$this->register_child_run([0 => $this, 1 => 'backFillChildWorker']);
		// The option for backFill is for doing up to x articles. Else it's done by date.
		$this->work = $this->pdo->query(
			sprintf(
				'SELECT name %s FROM groups WHERE backfill = 1',
				($this->workTypeOptions[0] === false ? '' : (', ' . $this->workTypeOptions[0] . ' AS max'))
			)
		);
		return $this->pdo->getSetting('backfillthreads');
	}

	public function backFillChildWorker($groups, $identifier = '')
	{
		foreach ($groups as $group) {
			$this->executeCommand(
				PHP_BINARY . ' ' . nZEDb_UPDATE . 'backfill.php ' .
				$group['name'] . (isset($group['max']) ? (' ' . $group['max']) : '')
			);
		}
	}

	////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	//////////////////////////////////////// All binaries code here ////////////////////////////////////////////////////
	////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

	private function binariesMainMethod()
	{
		$this->register_child_run([0 => $this, 1 => 'binariesChildWorker']);
		$this->work = $this->pdo->query(
			sprintf(
				'SELECT name, %d AS max FROM groups WHERE active = 1',
				$this->workTypeOptions[0]
			)
		);
		return $this->pdo->getSetting('binarythreads');
	}

	public function binariesChildWorker($groups, $identifier = '')
	{
		foreach ($groups as $group) {
			$this->executeCommand(
				PHP_BINARY . ' ' . nZEDb_UPDATE  . 'update_binaries.php ' . $group['name'] . ' ' . $group['max']
			);
		}
	}

	////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	//////////////////////////////////////// All releases code here ////////////////////////////////////////////////////
	////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

	private function releasesMainMethod()
	{
		$this->register_child_run([0 => $this, 1 => 'releasesChildWorker']);

		$this->tablePerGroup = ($this->pdo->getSetting('tablepergroup') == 1 ? true : false);
		if ($this->tablePerGroup === true) {

			$groups = $this->pdo->queryDirect('SELECT id FROM groups WHERE (active = 1 OR backfill = 1)');

			if ($groups->rowCount() > 0) {
				foreach($groups as $group) {
					if ($this->pdo->queryOneRow(sprintf('SELECT id FROM collections_%d  LIMIT 1',$group['id'])) !== false) {
						$this->work[] = array('id' => $group['id']);
					}
				}
			}
		} else {
			$this->work = $this->pdo->query('SELECT name FROM groups WHERE (active = 1 OR backfill = 1)');
		}

		return $this->pdo->getSetting('releasesthreads');
	}

	public function releasesChildWorker($groups, $identifier = '')
	{
		foreach ($groups as $group) {
			if ($this->tablePerGroup === true) {
				$this->executeCommand(
					$this->dnr_path . 'releases  ' .  $group['id'] . '"'
				);
			} else {
				$this->executeCommand(
					PHP_BINARY . ' ' . nZEDb_UPDATE . 'update_releases.php 1 false ' . $group['name']
				);
			}
		}
	}

	////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	/////////////////////////////////////// All post process code here /////////////////////////////////////////////////
	////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

	/**
	 * Only 1 exit method is used for post process, since they are all similar.
	 *
	 * @param        $groups
	 * @param string $identifier
	 */
	public function postProcessChildWorker($groups, $identifier = '')
	{
		foreach ($groups as $group) {
			$type = '';
			if ($this->processAdditional) {
				$type = 'pp_additional  ';
			} else if ($this->processNFO) {
				$type = 'pp_nfo  ';
			} else if ($this->processMovies) {
				$type = 'pp_movie  ';
			} else if ($this->processTV) {
				$type = 'pp_tv  ';
			}

			if ($type !== '') {
				$this->executeCommand(
					$this->dnr_path . $type .  $group['id'] . '"'
				);
			}
		}
	}

	/**
	 * Check if we should process Additional's.
	 * @return bool
	 */
	private function checkProcessAdditional()
	{
		return (
			$this->pdo->queryOneRow(
				sprintf('
					SELECT r.id
					FROM releases r
					LEFT JOIN category c ON c.id = r.categoryid
					WHERE r.nzbstatus = %d
					AND r.passwordstatus BETWEEN -6 AND -1
					AND r.haspreview = -1
					AND c.disablepreview = 0
					LIMIT 1',
					\NZB::NZB_ADDED
				)
			) === false ? false : true
		);
	}

	private function postProcessAddMainMethod()
	{
		$maxProcesses = 1;
		if ($this->checkProcessAdditional() === true) {
			$this->processAdditional = true;
			$this->register_child_run([0 => $this, 1 => 'postProcessChildWorker']);
			$this->work = $this->pdo->query(
				sprintf('
					SELECT LEFT(r.guid, 1) AS id
					FROM releases r
					LEFT JOIN category c ON c.id = r.categoryid
					WHERE r.nzbstatus = %d
					AND r.passwordstatus BETWEEN -6 AND -1
					AND r.haspreview = -1
					AND c.disablepreview = 0
					GROUP BY LEFT(r.guid, 1)
					LIMIT 16',
					\NZB::NZB_ADDED
				)
			);
			$maxProcesses = $this->pdo->getSetting('postthreads');
		}
		return $maxProcesses;
	}

	/**
	 * Check if we should process NFO's.
	 * @return bool
	 */
	private function checkProcessNfo()
	{
		if ($this->pdo->getSetting('lookupnfo') == 1) {
			return (
				$this->pdo->queryOneRow(
					sprintf(
						'SELECT id FROM releases WHERE nzbstatus = %d AND nfostatus BETWEEN -1 AND %d LIMIT 1',
						\NZB::NZB_ADDED, \Nfo::NFO_UNPROC
					)
				) === false ? false : true
			);
		}
		return false;
	}

	private function postProcessNfoMainMethod()
	{
		$maxProcesses = 1;
		if ($this->checkProcessNfo() === true) {
			$this->processNFO = true;
			$this->register_child_run([0 => $this, 1 => 'postProcessChildWorker']);
			$this->work = $this->pdo->query(
				sprintf('
					SELECT LEFT(guid, 1) AS id
					FROM releases
					WHERE nzbstatus = %d
					AND nfostatus BETWEEN -6 AND -1
					GROUP BY LEFT(guid, 1)
					LIMIT 16',
					\NZB::NZB_ADDED
				)
			);
			$maxProcesses = $this->pdo->getSetting('nfothreads');
		}
		return $maxProcesses;
	}

	/**
	 * Check if we should process Movies.
	 * @return bool
	 */
	private function checkProcessMovies()
	{
		if ($this->pdo->getSetting('lookupimdb') > 0) {
			return (
				$this->pdo->queryOneRow(
					sprintf('
						SELECT id
						FROM releases
						WHERE nzbstatus = %d
						AND imdbid IS NULL
						AND categoryid BETWEEN 2000 AND 2999
						%s
						LIMIT 1',
						\NZB::NZB_ADDED,
						($this->pdo->getSetting('lookupimdb') == 2 ? 'AND isrenamed = 1' : '')
					)
				) === false ? false : true
			);
		}
		return false;
	}

	private function postProcessMovMainMethod()
	{
		$maxProcesses = 1;
		if ($this->checkProcessMovies() === true) {
			$this->processMovies = true;
			$this->register_child_run([0 => $this, 1 => 'postProcessChildWorker']);
			$this->work = $this->pdo->query(
				sprintf('
					SELECT LEFT(guid, 1) AS id
					FROM releases
					WHERE nzbstatus = %d
					AND imdbid IS NULL
					AND categoryid BETWEEN 2000 AND 2999
					%s
					GROUP BY LEFT(guid, 1)
					LIMIT 16',
					\NZB::NZB_ADDED,
					($this->pdo->getSetting('lookupimdb') == 2 ? 'AND isrenamed = 1' : '')
				)
			);
			$maxProcesses = $this->pdo->getSetting('postthreadsnon');
		}
		return $maxProcesses;
	}

	/**
	 * Check if we should process TV's.
	 * @return bool
	 */
	private function checkProcessTV()
	{
		if ($this->pdo->getSetting('lookuptvrage') > 0) {
			return (
				$this->pdo->queryOneRow(
					sprintf('
						SELECT id
						FROM releases
						WHERE nzbstatus = %d
						AND size > 1048576
						AND rageid = -1
						AND categoryid BETWEEN 5000 AND 5999
						%s
						LIMIT 1',
						\NZB::NZB_ADDED,
						($this->pdo->getSetting('lookuptvrage') == 2 ? 'AND isrenamed = 1' : '')
					)
				) === false ? false : true
			);
		}
		return false;
	}

	private function postProcessTvMainMethod()
	{
		$maxProcesses = 1;
		if ($this->checkProcessTV() === true) {
			$this->processTV = true;
			$this->register_child_run([0 => $this, 1 => 'postProcessChildWorker']);
			$this->work = $this->pdo->query(
				sprintf('
					SELECT LEFT(guid, 1) AS id
					FROM releases
					WHERE nzbstatus = %d
					AND rageid = -1
					AND size > 1048576
					AND categoryid BETWEEN 5000 AND 5999
					%s
					GROUP BY LEFT(guid, 1)
					LIMIT 16',
					\NZB::NZB_ADDED,
					($this->pdo->getSetting('lookuptvrage') == 2 ? 'AND isrenamed = 1' : '')
				)
			);
			$maxProcesses = $this->pdo->getSetting('postthreadsnon');
		}
		return $maxProcesses;
	}

	/**
	 * Process sharing.
	 *
	 * @return bool
	 */
	private function processSharing()
	{
		$sharing = $this->pdo->queryOneRow('SELECT enabled FROM sharing');
		if ($sharing !== false && $sharing['enabled'] == 1) {
			$nntp = new \NNTP(true);
			if (($this->pdo->getSetting('alternate_nntp') == 1 ? $nntp->doConnect(true, true) : $nntp->doConnect()) === true) {
				(new \PostProcess(['Echo' => true, 'Settings' => $this->pdo]))->processSharing($nntp);
			}
			return true;
		}
		return false;
	}

	/**
	 * Process all that require a single thread.
	 */
	private function processSingle()
	{
		$postProcess = new \PostProcess(['Echo' => true, 'Settings' => $this->pdo]);
		//$postProcess->processAnime();
		$postProcess->processBooks();
		$postProcess->processConsoles();
		$postProcess->processGames();
		$postProcess->processMusic();
		$postProcess->processXXX();
	}

	////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	////////////////////////////////////// All requestID code goes here ////////////////////////////////////////////////
	////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

	private function requestIDMainMethod()
	{
		$this->register_child_run([0 => $this, 1 => 'requestIDChildWorker']);
		$this->work = $this->pdo->query(
			sprintf('
				SELECT DISTINCT(g.id)
				FROM groups g
				INNER JOIN releases r ON r.group_id = g.id
				WHERE (g.active = 1 OR g.backfill = 1)
				AND r.nzbstatus = %d
				AND r.preid = 0
				AND r.isrequestid = 1
				AND r.reqidstatus = %d',
				\NZB::NZB_ADDED,
				\RequestID::REQID_UPROC
			)
		);
		return $this->pdo->getSetting('reqidthreads');
	}

	public function requestIDChildWorker($groups, $identifier = '')
	{
		foreach ($groups as $group) {
			$this->executeCommand(
				$this->dnr_path . 'requestid  ' .  $group['id'] . '"'
			);
		}
	}

	////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	//////////////////////////////////// All "update_all" code goes here ///////////////////////////////////////////////
	////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

	private function updateAllMainMethod()
	{
		$this->register_child_run([0 => $this, 1 => 'updateAllChildWorker']);
		$this->work = $this->pdo->query('SELECT id FROM groups WHERE (active = 1 OR backfill = 1)');
		return $this->pdo->getSetting('releasesthreads');
	}

	public function updateAllChildWorker($groups, $identifier = '')
	{
		foreach ($groups as $group) {
			$this->executeCommand(
				$this->dnr_path . 'update_per_group  ' .  $group['id'] . '"'
			);
		}
	}

	////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	//////////////////////////////////////////// Various methods ///////////////////////////////////////////////////////
	////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

	/**
	 * Execute a shell command, use the appropriate PHP function based on user setting.
	 *
	 * @param string $command
	 */
	private function executeCommand($command)
	{
		switch($this->outputType) {
			case self::OUTPUT_NONE:
				exec($command);
				break;
			case self::OUTPUT_REALTIME:
				passthru($command);
				break;
			case self::OUTPUT_SERIALLY:
				echo shell_exec($command);
				break;
		}
	}

	/**
	 * Set the amount of max child processes.
	 * @param int $maxProcesses
	 */
	private function setMaxProcesses($maxProcesses)
	{
		// Check if override setting is on.
		if (defined('nZEDb_MULTIPROCESSING_MAX_CHILDREN_OVERRIDE') && nZEDb_MULTIPROCESSING_MAX_CHILDREN_OVERRIDE > 0) {
			$maxProcesses = nZEDb_MULTIPROCESSING_MAX_CHILDREN_OVERRIDE;
		}

		if (is_numeric($maxProcesses) && $maxProcesses > 0) {
			switch ($this->workType) {
				case 'postProcess_tv':
				case 'postProcess_mov':
				case 'postProcess_nfo':
				case 'postProcess_add':
					if ($maxProcesses > 16) {
						$maxProcesses = 16;
					}
			}
			$this->maxProcesses = $maxProcesses;
			$this->max_children_set($maxProcesses);
		} else {
			$this->max_children_set(1);
		}
	}

	/**
	 * Echo a message to CLI.
	 *
	 * @param string $message
	 */
	public function logger($message)
	{
		if (nZEDb_ECHOCLI) {
			echo $message . PHP_EOL;
		}
	}

	/**
	 * This method is executed whenever a child is finished doing work.
	 *
	 * @param string $pid        The PID numbers.
	 * @param string $identifier Optional identifier to give a PID a name.
	 */
	public function childExit($pid, $identifier = '')
	{
		if (nZEDb_ECHOCLI) {
			echo (
				'Process ID #' . $pid . ' has completed.' . PHP_EOL .
				'There are ' . ($this->forked_children_count - 1) . ' process(es) still active with ' .
				(--$this->workCount) . ' job(s) left in the queue.' . PHP_EOL
			);
		}
	}

	/**
	 *
	 */
	public function __destruct()
	{
		parent::__destruct();
	}

	////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
	////////////////////////////////////////// All class vars here /////////////////////////////////////////////////////
	////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

	/**
	 * Path to do not run folder.
	 * @var string
	 */
	private $dnr_path = '';

	/**
	 * Work to work on.
	 * @var array
	 */
	private $work = array();

	/**
	 * How much work do we have to do?
	 * @var int
	 */
	private $workCount = 0;

	/**
	 * The type of work we want to work on.
	 * @var string
	 */
	private $workType = '';

	/**
	 * List of passed in options for the current work type.
	 * @var array
	 */
	private $workTypeOptions = array();

	/**
	 * Max amount of child processes to do work at a time.
	 * @var int
	 */
	private $maxProcesses = 1;

	/**
	 * Are we using tablePerGroup?
	 * @var bool
	 */
	private $tablePerGroup = false;

	/**
	 * @var \nzedb\db\Settings
	 */
	private $pdo;

	/**
	 * @var bool
	 */
	private $processAdditional = false; // Should we process additional?
	private $processNFO = false;        // Should we process NFOs?
	private $processMovies = false;     // Should we process Movies?
	private $processTV = false;         // Should we process TV?
}

class ForkingException extends \Exception {}
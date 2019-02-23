<?php
/**
 * Copyright Sangoma Technologies, Inc 2018
 */
namespace FreePBX\modules;
use FreePBX\modules\Backup\Handlers as Handler;
use FreePBX\modules\Filestore\Modules\Remote as FilestoreRemote;
use FreePBX\modules\Backup\Models\BackupSplFileInfo;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Monolog\Handler\SwiftMailerHandler;
use Monolog\Handler\BufferHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use FreePBX_Helpers;
use BMO;
use splitbrain\PHPArchive\Tar;
use FreePBX\modules\Backup\Handlers\MonologSwift;
use Hhxsv5\SSE\SSE;
use Hhxsv5\SSE\Update;
use function FreePBX\modules\Backup\Json\json_decode;
use function FreePBX\modules\Backup\Json\json_encode;
include __DIR__.'/vendor/autoload.php';
class Backup extends FreePBX_Helpers implements BMO {
	public $swiftmsg = false;
	public $backupHandler  = null;
	public $restoreHandler = null;
	public $errors = [];
	public $templateFields = [];
	public $backupFields = [
		'backup_name',
		'backup_description',
		'backup_items',
		'backup_storage',
		'backup_schedule',
		'schedule_enabled',
		'maintage',
		'maintruns',
		'backup_email',
		'backup_emailtype',
		'backup_emailinline',
		'immortal',
		'warmspareenabled',
		'warmspare_remotetrunks',
		'warmspare_remotenat',
		'warmspare_remotebind',
		'warmspare_remotenat',
		'warmspare_remotedns',
		'warmspare_remoteapply',
		'warmspare_remoteip',
		'warmspare_user',
		'publickey'
	];
	public $loggingHooks = null;

	private $validModulesCache;


	public function __construct($freepbx = null) {
		if ($freepbx == null) {
				throw new Exception('Not given a FreePBX Object');
		}
		$this->freepbx = $freepbx;
		$this->db = $freepbx->Database;
	}

	public function __get($var) {
		switch($var) {
			case 'serverName':
				$this->serverName = $this->freepbx->Config->get('FREEPBX_SYSTEM_IDENT');
				return $this->serverName;
			break;
			case 'fs':
				$this->fs = new Filesystem;
				return $this->fs;
			break;
			case 'mf':
				$this->mf = \module_functions::create();
				return $this->mf;
			break;
		}
	}

	public function install(){

		/** Oh... Migration, migration, let's learn about migration. It's nature's inspiration to move around the sea.
		 * We have split the functionality up so things backup use to do may be done by another module. The other module(s)
		 * May not yet be installed or may install after.  So we need to keep a kvstore with the various data and when installing
		 * The other modules will checkin on install and process the data needed by them.
		 **/

		$dbexist = $this->db->query("SHOW TABLES LIKE 'backup'")->rowCount();
		if($dbexist === 1){
			out(_("Migrating legacy backupjobs"));
			out(_("Moving servers to filestore"));
			$servers = Backup\Migration\Servers($this->freepbx);
			$servers->process();
			out(_("Migrating legacy backups to the new backup"));
			$jobs = new Backup\Migration\Backupjobs($this->freepbx);
			$jobs->process();

			out(_("Cleaning up old data"));
			$tables = [
				'backup',
				'backup_cache',
				'backup_details',
				'backup_items',
				'backup_server_details',
				'backup_servers',
				'backup_template_details',
				'backup_templates',
			];
			foreach ($tables as $table) {
				out(sprintf(_("Removing table %s."),$table));
				$this->db->query("DROP TABLE $table");
			}
		}
	}

	public function uninstall(){
	}

	public function doConfigPageInit($page) {
		if($page == 'backup'){
			/** Delete Backup */
			if(isset($_REQUEST['action']) && $_REQUEST['action'] == 'delete'){
				return $this->deleteBackup($_REQUEST['id']);
			}
			/** Update Backup */
			if(isset($_POST['backup_name'])){
				$this->importRequest();
				return $this->updateBackup();
			}
		}
	}


	public function getActionBar($request) {
		/** No buttons unless we are in a view */
		if(!isset($request['view'])){
			return [];
		}
		/** Process restore file Buttons */
		if($request['view'] == 'processrestore'){
			return [
				'run' => [
					'name'  => 'runrestore',
					'id'    => 'runrestore',
					'value' => _("Run Restore")
				]
			];
		}
		/**	Generic button set*/
		$buttons = [
			'reset' => [
				'name'  => 'reset',
				'id'    => 'reset',
				'value' => _('Reset'),
			],
			'submit' => [
				'name'  => 'submit',
				'id'    => 'submit',
				'value' => _('Save'),
			],
			'run' => [
				'name'  => 'run',
				'id'    => 'run_backup',
				'value' => _('Save and Run'),
			],
			'delete' => [
				'name'  => 'delete',
				'id'    => 'delete',
				'value' => _('Delete'),
			],
		];
		if('backup_restore' == $request['display']){
			unset($buttons['run']);
		}

		/** If we are not in an edit screen kill the run and delete */
		if(!isset($request['id']) || empty($request['id'])){
			unset($buttons['delete']);
			unset($buttons['run']);
		}
		return $buttons;
	}

	/**
	 * Ajax Request for BMO
	 * @param string $req     [description]
	 * @param [type] $setting [description]
	 */
	public function ajaxRequest($command, &$setting) {
		switch ($command) {
			case 'deleteMultipleRestores':
			case 'backupGrid':
			case 'backupItems':
			case 'backupStorage':
			case 'runBackup':
			case 'runRestore':
			case 'remotedownload':
			case 'deleteRemote':
			case 'localdownload':
			case 'localRestoreFiles':
			case 'restoreFiles':
			case 'uploadrestore':
			case 'generateRSA':
			case 'deleteLocal':
			case 'getRestoreLog':
			case 'deleteBackup':
				return true;
			case 'restorestatus':
			case 'backupstatus':
				$setting['changesession'] = false;
				return true;
			default:
				return false;
		}
	}

	/**
	 * Ajax Module for BMO
	 */
	public function ajaxHandler() {
		switch ($_REQUEST['command']) {
			case 'deleteMultipleRestores':
				$type = $_REQUEST['type'];
				$files = $_REQUEST['files'];
				$deletes = [];
				switch($type) {
					case 'localrestorefiles':
						foreach($files as $f) {
							$filepath = $this->pathFromId($f['id']);
							if(!$filepath){
								return ['status' => false, "message" => _("Invalid ID Provided")];
							}
							$file = new \SplFileObject($filepath);
							if(!$file->isWritable()){
								return ['status' => false, "message" => _("We don't have permissions to this file")];
							}
							if(!unlink($filepath)){
								return ['status' => false, "message" => _("We can't seem to delete the chosen file")];
							}
							$deletes[] = $f['id'];
						}
						return ['status' => true, 'ids' => $deletes];
					break;
					case 'restoreFiles':
						foreach($files as $f) {
							dbug($f);
							$server = $f['id'];
							$file = $f['file'];
							$server = explode('_', $server);
							if(!$this->deleteRemote($server[0], $server[1], $file)){
								return ['status' => false, "message" => _("Something failed, The file may need to be removed manually.")];
							}
							$deletes[] = $f['id'];
						}
						return ['status' => true, 'ids' => $deletes];
					break;
					default:
						return ['status' => false, "message" => "Unknown type $type"];
					break;
				}
			break;
			case 'deleteBackup':
				$id = $_REQUEST['id'];
				if($this->deleteBackup($id)) {
					return ['status' => true, "message" => _("Backup Deleted")];
				}
				return ['status' => false, "message" => _("Something failed.")];
			break;
			case 'deleteRemote':
				$server = $_REQUEST['id'];
				$file = $_REQUEST['file'];
				$server = explode('_', $server);
				if($this->deleteRemote($server[0], $server[1], $file)){
					return ['status' => true, "message" => _("File Deleted"), "id" => $server];
				}
				return ['status' => false, "message" => _("Something failed, The file may need to be removed manually.")];
			case 'deleteLocal':
				$filepath = $this->pathFromId($_REQUEST['id']);
				if(!$filepath){
					return ['status' => false, "message" => _("Invalid ID Provided")];
				}
				$file = new \SplFileObject($filepath);
				if(!$file->isWritable()){
					return ['status' => false, "message" => _("We don't have permissions to this file")];
				}
				if(unlink($filepath)){
					return ['status' => true, "message" => "File Removed"];
				}
				return ['status' => false, "message" => _("We can't seem to delete the chosen file")];
			case 'generateRSA':
				$homedir = $this->getAsteriskUserHomeDir();
				$ssh = new FilestoreRemote();
				$ret = $ssh->generateKey($homedir.'/.ssh');
			return ['status' => $ret];
			case 'uploadrestore':
				$response = new Response(null,400,['Content-Type' => 'application/json']);
				$err = false;
				if (!isset($_FILES['file'])) {
					$err = ['status' => false, 'error' => _("No file provided")];
				}
				if ($_FILES['file']['error'] !== 0) {
					$err = ['status' => false, 'err' => $_FILES['file']['error'], 'message' => _("File reached the server but could not be processed")];
				}

				if ($_FILES['file']['type'] != 'application/x-gzip') {
					//$err = ['status' => false, 'mime' => $_FILES['file']['type'], 'message' => _("The uploaded file type is incorrect and couldn't be processed")];
				}
				if($err !== false){
					$response->setContent(json_encode($err));
					$response->send();
					exit();
				}
				$spooldir = $this->freepbx->Config->get("ASTSPOOLDIR");
				$path = sprintf('%s/backup/uploads', $spooldir);
				$finalname = $path.'/'. $_FILES['file']['name'];
				$tmp_name = $_FILES['file']['tmp_name'];
				$filename = $_FILES['file']['name'];
				$num = $_POST['dzchunkindex'];
				$num_chunks = $_POST['dztotalchunkcount'];
				$uuid = $_POST['dzuuid'];
				$partialPath = sprintf('%s/backup/uploads/%s/', $spooldir,$uuid);
				$target_file = $partialPath.$filename;
				@mkdir($partialPath, 0755, true);
				move_uploaded_file($tmp_name, $partialPath.$filename.$num);
				if($num + 1 == $num_chunks){
					for ($i = 0; $i <= $num_chunks - 1; $i++) {

						$file = fopen($target_file . $i, 'rb');
						$buff = fread($file, 2097152);
						fclose($file);

						$final = fopen($finalname, 'ab');
						$write = fwrite($final, $buff);
						fclose($final);
						unlink($target_file . $i);
					}
					$filemd5 = md5($finalname);
					$this->setConfig($filemd5, $finalname, 'localfilepaths');
					$backupFile = new BackupSplFileInfo($finalname);
					$meta = $backupFile->getMetadata();
					$this->setConfig('meta', $meta, $filemd5);
					header("HTTP/1.1 200 Ok");
					return ['status' => true, 'md5' => $filemd5];
				}
				if ($num + 1 < $num_chunks) {
					header("HTTP/1.1 201 Created");
					break;
				}

				break;
			case 'localRestoreFiles':
				return $this->getLocalFiles();
			case 'restoreFiles':
				return $this->getAllRemote();
			case 'runRestore':
				$ruid = $_GET['fileid'];
				$file = $this->pathFromId($ruid);
				if(!$file){
					return ['status' => false, 'message' => _("Could not find a file for the id supplied")];
				}

				$jobid   = $this->generateId();
				$location = $this->freepbx->Config->get('ASTLOGDIR');
				$command = $this->freepbx->Config->get('AMPSBIN').'/fwconsole backup --restore='.escapeshellarg($file).' --transaction='.escapeshellarg($jobid);
				file_put_contents($location.'/restore_'.$jobid.'_out.log','Running with: '.$command.PHP_EOL);
				$process = new Process($command.' >> '.$location.'/restore_'.$jobid.'_out.log 2> '.$location.'/restore_'.$jobid.'_err.log & echo $!');
				$process->mustRun();
				$log = file_get_contents($location.'/restore_'.$jobid.'_out.log');
				return ['status' => true, 'message' => _("Restore running"), 'transaction' => $jobid, 'restoreid' => $ruid, 'pid' => trim($process->getOutput()), 'log' => $log];
			case 'runBackup':
				if(!isset($_GET['id'])){
					return ['status' => false, 'message' => _("No backup id provided")];
				}
				$buid    = $_GET['id'];
				$jobid   = $this->generateId();
				$location = $this->freepbx->Config->get('ASTLOGDIR');
				$warmspare = $this->getConfig('warmspareenabled', $buid) === 'yes';
				$command = $this->freepbx->Config->get('AMPSBIN').'/fwconsole backup --backup=' . escapeshellarg($buid) . ' --transaction=' . escapeshellarg($jobid) . ' >> '.$location.'/backup_'.$jobid.'_out.log 2> '.$location.'/backup_'.$jobid.'_err.log & echo $!';
				if($warmspare){
					$command .= ' --warmspare';
				}
				file_put_contents($location.'/backup_'.$jobid.'_out.log','Running with: '.$command.PHP_EOL);
				$process = new Process($command);
				$process->mustRun();
				$log = file_get_contents($location.'/backup_'.$jobid.'_out.log');
				return ['status' => true, 'message' => _("Backup running"), 'transaction' => $jobid, 'backupid' => $buid, 'pid' => trim($process->getOutput()), 'log' => $log];
			case 'backupGrid':
				return array_values($this->listBackups());
			case 'backupStorage':
				$storage_ids = [];
				if(isset($_GET['id']) && !empty($_GET['id'])){
					$storage_ids = $this->getStorageByID($_GET['id']);
				}
				try {
					$fstype = $this->getFSType();
					$items  = $this->freepbx->Filestore->listLocations($fstype);
					$return = [];
					foreach ($items['locations'] as $driver => $locations ) {
						$optgroup = [
							'label'    => $driver,
							'children' => []
						];
						foreach ($locations as $location) {
							$name = isset($location['displayname'])?$location['displayname']:$location ['name'];
							$select       = in_array($driver.'_'.$location['id'], $storage_ids);
							$optgroup['children'][] = [
								'label'    => $name,
								'title'    => $location['description'],
								'value'    => $driver.'_'.$location['id'],
								'selected' => $select
							];
						}
						$return[] = $optgroup;
					}
					return $return;
				} catch (\Exception $e) {
					return $e;
				}
			break;
			case 'backupItems':
				$id  = isset($_GET['id'])?$_GET['id']: '';
				return $this->moduleItemsByBackupID($id);
			default:
				return false;
		}
	}
	public function ajaxCustomHandler() {

		switch($_REQUEST['command']){
			case 'restorestatus':
			case 'backupstatus':
				session_write_close();
				@ob_end_flush();
				header_remove();
				header('Content-Type: text/event-stream');
				header('Cache-Control: no-cache');
				header('Connection: keep-alive');
				header('X-Accel-Buffering: no');//Nginx: unbuffered responses suitable for Comet and HTTP streaming applications
				$location = $this->freepbx->Config->get('ASTLOGDIR');
				(new SSE())->start(new Update(function () use ($location) {
					if(!isset($_GET['id']) || !isset($_GET['transaction']) || !isset($_GET['pid'])){
						return json_encode(['status' => 'stopped', 'error' => _("Missing id or transaction or pid")]);
					}
					$pid = $_GET['pid'];
					$job = $_GET['transaction'];
					$buid = $_GET['id'];

					$type = $_REQUEST['command'] === 'restorestatus' ? 'restore' : 'backup';

					$outFile = $location.'/'.$type.'_'.$job.'_out.log';
					$errorFile = $location.'/'.$type.'_'.$job.'_err.log';

					$log = file_get_contents($outFile);

					if(posix_getpgid($pid) !== false) {
						return json_encode(['status' => 'running', 'log' => $log]);
					}

					$error = file_get_contents($errorFile);
					if(!empty($error)){
						@unlink($outFile);
						@unlink($errorFile);
						return json_encode(['status' => 'errored', 'log' => $log.$error]);
					}

					@unlink($outFile);
					@unlink($errorFile);
					return json_encode(['status' => 'stopped', 'log' => $log]);
				}, 1), 'new-msgs', 1000);
				exit;
			break;
			case 'remotedownload':
				$filepath = $this->remoteToLocal($_REQUEST['id'],$_REQUEST['filepath']);
			case 'localdownload':
				if(empty($_REQUEST['id'])){
					return false;
				}
				if(!isset($filepath)){
					$filepath = $this->getAll('localfilepaths');
					$filepath = isset($filepath[$_REQUEST['id']])?$filepath[$_REQUEST['id']]:false;
				}
				if(empty($filepath)){
					return false;
				}
				header("Content-disposition: attachment; filename=".basename($filepath));
				header("Content-type: application/octet-stream");
				readfile($filepath);
				exit;
		}
	}

	public function getRightNav($request) {
		switch($request['view']) {
			case 'addbackup':
			case 'editbackup':
			case 'processrestore':
				return load_view(__DIR__."/views/rnav.php",[]);
			break;
		}
	}

	//Display stuff

	public function myShowPage() {
		$view = !empty($_GET['view']) ? $_GET['view'] : '';
		switch($view) {
			case 'editbackup':
				$backup = $this->getBackup($_GET['id']);
				if(empty($backup)) {
					return _("Invalid Backup ID");
				}
			case 'addbackup':
				$randcron          = sprintf('59 23 * * %s',rand(0,6));
				$vars              = ['id' => ''];
				$vars['backup_schedule'] = $randcron;
				if(isset($backup)){
					$vars              = $backup;
					$vars['backup_schedule'] = !empty($vars['backup_schedule'])?$vars['backup_schedule']:$randcron;
					$vars['id']              = $_GET['id'];
				}
				$warmsparedisable = $this->getConfig('warmsparedisable');
				$vars['transfer']       = $this->getConfig('transferdisable');
				$vars['warmspare']      = '';
				if(empty($warmsparedisable)){
					$warmsparedefaults = [
						'warmspare_user'   => 'root',
						'warmspare_remote' => 'no',
						'warmspare_enable' => 'no',
					];
					$settings = $this->getConfig('warmsparesettings');
					$settings = $settings?$settings:[];
					foreach($warmsparedefaults as $key => $value){
						$value = isset($settings[$key])?$settings[$key]:$value;
						$vars[$key]  = $value;
					}

					$vars['warmspare'] = load_view(__DIR__.'/views/backup/warmspare.php',$vars);
				}
				$vars['transfer'] = '';
				if(!$transferdisabled){
					$vars['transfer'] = '<li role="presentation" class="'.(isset($_GET['view']) && $_GET['view'] == 'yes')?"active":"".'"><a href="?display=backup&view=transfer">'. _("System Transfer").'</a></li>';
				}
				return load_view(__DIR__.'/views/backup/form.php',$vars);
			break;
			case 'processrestore':
				if(!isset($_GET['fileid']) || empty($_GET['fileid'])){
					return load_view(__DIR__.'/views/restore/landing.php',['error' => _("No id was specified to process. Please try submitting your file again.")]);
				}
				if($_GET['type'] == 'local'){
					$fileid = $_GET['fileid'];
					$path = $this->pathFromId($_GET['fileid']);
				}
				if($_GET['type'] == 'remote'){
					$path = $this->remoteToLocal($_GET['fileid'],$_GET['filepath']);
					$fileid = md5($path);
				}
				if(empty($path)){
					return load_view(__DIR__.'/views/restore/landing.php',['error' => _("Couldn't find your file, please try submitting your file again.")]);
				}
				if($path){
					$fileClass = new BackupSplFileInfo($path);
					$manifest = $fileClass->getMetadata($path);

				}
				$vars['meta']     = $manifest;
				$vars['timestamp']     = $manifest['date'];
				$vars['jsondata'] = $this->moduleJSONFromManifest($manifest);
				$vars['id']       = $_GET['id'];
				$vars['fileid']   = $fileid;
				$vars['fileinfo'] = $fileClass;
				return load_view(__DIR__.'/views/restore/processRestore.php',$vars);
			break;
			default:
				return load_view(__DIR__.'/views/landing.php',[]);
			break;
		}
	}

	public function showPage($page){
		switch ($page) {
			case 'settings':
				$vars = [];
				$hdir = $this->getAsteriskUserHomeDir();
				$file = $hdir.'/.ssh/id_rsa.pub';
				if (!file_exists($file)) {
					$ssh = new FilestoreRemote();
					$ssh->generateKey($hdir.'/.ssh');
				}
				$data = file_get_contents($file);
				$vars['publickey'] = $data;
				return load_view(__DIR__.'/views/backup/settings.php',$vars);
			break;
			case 'backup':
				if(isset($_GET['view']) && $_GET['view'] == 'newRSA'){
					return load_view(__DIR__.'/views/backup/rsa.php');
				}
				if(isset($_GET['view']) && $_GET['view'] == 'form'){

				}
				if(isset($_GET['view']) && $_GET['view'] == 'download'){
					return load_view(__DIR__.'/views/backup/download.php');
				}
				if(isset($_GET['view']) && $_GET['view'] == 'transfer'){
					return load_view(__DIR__.'/views/backup/transfer.php');
				}
				return load_view(__DIR__.'/views/backup/grid.php');
			case  'restore'                           :
			$view = isset($_GET['view'])?$_GET['view']: 'default';
				switch ($view) {
					case 'processrestore':

					case 'restorerunning':
						$vars['job']       = $_GET['id'];
						$vars['proc']       = $_GET['proc'];
					return load_view(__DIR__.'/views/restore/status.php',$vars);
					break;
					default:
						return load_view(__DIR__.'/views/restore/landing.php');
				}
			default:
				return load_view(__DIR__.'/views/backup/grid.php');
		}
	}

	public function getBackupSettingsDisplay($module,$id = ''){
		$module = ucfirst($module);
		if($module === 'Backup'){
			return;
		}
		$class = $this->freepbx->$module;
		if( method_exists($class, 'getBackupSettingsDisplay')){
			return '<div class="hooksetting">'. $class->getBackupSettingsDisplay($id).'</div>';
		}
		return;
	}

	//Getters


	/**
	 * Sets hooks for external files in to a queue
	 * @param string $type load inbound, outbound, both
	 * @return void
	 */
	public function getHooks($type = 'all'){
		if($type == 'backup' || $type == 'all'){
			$this->preBackup  = new \SplQueue();
			$this->postBackup = new \SplQueue();
		}
		if($type == 'restore' || $type == 'all'){
			$this->preRestore  = new \SplQueue();
			$this->postRestore = new \SplQueue();
		}
		$hookpath      = getenv('BACKUPHOOKDIR');
		$homedir = $this->getAsteriskUserHomeDir();
		$hookpath      = $hookpath?$hookpath:$homedir.'/Backup';

		if (!file_exists($hookpath)) {
			return;
		}

		$filehooks     = ['BACKUPPREHOOKS' => 'preBackup','RESTOREPREHOOKS' => 'preRestore','BACKUPPOSTHOOKS' => 'postBackup','RESTOREPOSTHOOKS' => 'postRestore'];
		foreach($filehooks as $hook => $objName){
			$env = getenv($hook);
			if(empty($env)){
				continue;
			}
			$env = explode(',',$env);
			$env = !empty($env)?$env:[];
			foreach($env as $file){
				if(!empty($this->$objName)){
					$this->$objName->push($file);
				}
			}
		}

		foreach (new \DirectoryIterator($hookpath) as $fileInfo) {
			if($fileInfo->isFile() && $fileInfo->isReadable() && $fileInfo->isExecutable()){
				$fileobj = $fileInfo->openFile('r');
				while (!$fileobj->eof()) {
					$found = preg_match("/(pre|post):(backup|restore)/", $fileobj->fgets(), $out);
	   				if($found === 1){
						$hooktype = $out[1].$out[2];
						$filename = $hookpath.'/'.$fileobj->getFilename();
						if($hooktype == 'prebackup' && !empty($this->preBackup)){
							$this->preBackup->push($filename);
						}
						if($hooktype == 'postbackup' && !empty($this->postBackup)){
							$this->postBackup->push($filename);
						}
						if($hooktype == 'prerestore' && !empty($this->preRestore)){
							$this->preRestore->push($filename);
						}
						if($hooktype == 'postrestore' && !empty($this->postRestore)){
							$this->postRestore->push($filename);
						}
						break;
					}
				}
			}
		}
	}

	public function pathFromId($id){
		return $this->getConfig($id,'localfilepaths');
	}
	/**
	 * Get storage locations by backup ID
	 * @param  string $id backup id
	 * @return array  array of backup locations as DRIVER_ID
	 */
	public function getStorageById($id){
		$storage = $this->getConfig('backup_storage',$id);
		return is_array($storage)?$storage: [];
	}

	/**
	 * Gets the appropriate filesystem types to pass to filestore.
	 * @return mixed if hooks are present it will present an array, otherwise a string
	 */
	public function getFSType(){
		$types = $this->freepbx->Hooks->processHooks();
		$ret   = [];
		foreach ($types as $key => $value) {
			$value = is_array($value)?$value:[];
			$ret   = array_merge($ret,$value);
		}
		return !empty($ret)?$ret: 'backup';
	}

	/**
	 * List all backups
	 * @return array Array of backup items
	 */
	public function listBackups() {
		$return = $this->getAll('backupList');
		return is_array($return)?$return: [];
	}

	/**
	 * Get all settings for a specific backup id
	 * @param  string $id backup id
	 * @return array  an array of backup settings
	 */
	public function getBackup($id){
		$data   = $this->getAll($id);
		if(empty($data)) {
			return [];
		}
		$return = [];
		foreach ($this->backupFields as $key) {
			$return[$key] = isset($data[$key])?$data[$key]:'';
		}
		return $return;
	}

	/**
	 * Gets local backup files from the system
	 * @
	 * @return array file list
	 */
	public function getLocalFiles(){
		$files     = [];
		$base      = $this->freepbx->Config->get('ASTSPOOLDIR');
		$base      = $base?$base:'/var/spool/asterisk';
		$backupdir = $base . '/backup';

		$this->fs->mkdir($backupdir);

		$Directory = new \RecursiveDirectoryIterator($backupdir,\FilesystemIterator::SKIP_DOTS|\FilesystemIterator::CURRENT_AS_FILEINFO);
		$Iterator  = new \RecursiveIteratorIterator($Directory,\RecursiveIteratorIterator::LEAVES_ONLY);
		$this->delById('localfilepaths');
		foreach($Iterator as $k => $v){
			$path       = $v->getPathInfo()->getRealPath();
			$buname     = $v->getPathInfo()->getBasename();
			$buname     = str_replace('_',' ',$buname);
			$backupFile = new BackupSplFileInfo($k);
			$backupinfo = $backupFile->backupData();
			if(empty($backupinfo)){
				continue;
			}
			$this->setConfig(md5($k),$k,'localfilepaths');
			$backupinfo['path'] = $path;
			$backupinfo['id']   = md5($k);
			$backupinfo['name'] = $buname;
			$backupinfo['timestamp'] = $backupinfo['timestamp'];
			$files     []       = $backupinfo;
		}
		return $files;
	}

	/**
	 * Get a list of modules that implement the backup method
	 * @return array list of modules
	 */
	public function getModules(){
		if($this->validModulesCache) {
			return $this->validModulesCache;
		}
		//All modules impliment the "backup" method so it is a horrible way to know
		//which modules are valid. With the autoloader we can do this magic :)
		$webrootpath = \FreePBX::Config()->get('AMPWEBROOT');
		$moduleInfo = \FreePBX::Modules()->getInfo(false,MODULE_STATUS_ENABLED);
		$validmods = [];
		foreach ($moduleInfo as $rawname => $data) {
			$bufile = $webrootpath . '/admin/modules/' . $rawname.'/Backup.php';
			if(file_exists($bufile)){
				$validmods[$rawname] = $data;
			}
		}

		$this->validModulesCache = $validmods;

		return $validmods;
	}

	/**
	 * Get modules for a specific backup id returned in an array
	 * @param  string  $id              The backup id
	 * @return array   list of module data
	 */
	public function moduleItemsByBackupID($id = ''){
		$modules  = $this->getModules();
		if(!empty($id)) {
			$selected = $this->getAll('modules_'.$id);
			$selected = is_array($selected)? array_keys($selected) :[];
		} else {
			$selected = [];
		}

		$ret = [];
		foreach ($modules as $module) {
			$item = [
				'modulename' => $module['rawname'],
				'selected'   => empty($id) || in_array($module['rawname'], $selected),
				'display' => $module['name']
			];
			$ret[] = $item;
		}
		return $ret;
	}


	//Setters
	public function scheduleJobs($id = 'all'){
		$sbin = $this->freepbx->Config->get('AMPSBIN');
		if($id !== 'all'){
			$enabled = $this->getBackupSetting($id, 'schedule_enabled');
			$warmspare = $this->getConfig('warmspareenabled', $buid) === 'yes';
			if($enabled === 'yes'){
				$schedule = $this->getBackupSetting($id, 'backup_schedule');
				$command  = sprintf($sbin.'/fwconsole backup --backup=%s %s > /dev/null 2>&1',$id, $warmspare ? '--warmspare' : '');
				$this->freepbx->Cron->removeAll($command);
				$this->freepbx->Cron->add($schedule.' '.$command);
				return true;
			}
		}
		//Clean slate
		$allcrons = $this->freepbx->Cron->getAll();
		$allcrons = is_array($allcrons)?$allcrons:[];
		foreach ($allcrons as $cmd) {
			if (strpos($cmd, 'fwconsole backup') !== false) {
				$this->freepbx->Cron->remove($cmd);
			}
		}
		$backups = $this->listBackups();
		foreach ($backups as $key => $value) {
			$enabled = $this->getBackupSetting($key, 'schedule_enabled');
			$warmspare = $this->getConfig('warmspareenabled', $key) === 'yes';
			if($enabled === 'yes'){
				$schedule = $this->getBackupSetting($key, 'backup_schedule');
				$command  = sprintf($sbin.'/fwconsole backup --backup=%s %s> /dev/null 2>&1',$key, $warmspare ? '--warmspare' : '');
				$this->freepbx->Cron->removeAll($command);
				$this->freepbx->Cron->add($schedule.' '.$command);
			}
		}
		return true;
	}
	/**
	 * Update/Add a backup item. Note the only difference is weather we generate an ID
	 * @param  array $data an array of the items needed. typically just send the $_POST array
	 * @return string the backup id
	 */
	public function updateBackup(){
		$data = [];
		$data['id'] = $this->getReq('id');
		if(empty($data['id'])){
			$data['id'] = $this->generateID();
		}
		foreach ($this->backupFields as $col) {
			//This will be set independently
			if($col == 'immortal'){
				continue;
			}

			$value = $this->getReqUnsafe($col,'');
			$this->updateBackupSetting($data['id'], $col, $value);
		}
		$description = $this->getReq('backup_description',sprintf(_('Backup %s'),$this->getReq('backup_name')));
		$this->setConfig($data['id'],array('id' => $data['id'], 'name' => $this->getReq('backup_name',''), 'description' => $description),'backupList');
		if($this->getReq('backup_items','unchanged') !== 'unchanged'){
			$backup_items = json_decode(html_entity_decode($this->getReq('backup_items',[])),true);
			$this->setModulesById($data['id'], $backup_items);
		}
		//We expect this to be JSON so we don't sanitize it.
		$data['backup_items_settings'] = $this->getReqUnsafe('backup_items_settings', 'unchanged');
		if($data['backup_items_settings'] !== 'unchanged' ){
			$this->processBackupSettings($data['id'], json_decode($data['backup_items_settings'],true));
		}
		$this->scheduleJobs($id);
		return $id;
	}

	public function processBackupSettings($id = '', $data = []){
		$modules = $this->freepbx->Modules->getModulesByMethod('processBackupSettings');
		foreach ($modules as $module) {
			if($module === 'Backup'){
				continue;
			}
			$this->freepbx->$module->processBackupSettings($id, $data);
		}
	}

	/**
	 * Sets an individual setting
	 *
	 * @param string $id Backup id
	 * @param string $setting Backup setting
	 * @param boolean $value
	 * @return void
	 */
	public function updateBackupSetting($id, $setting, $value=false){
		$this->setConfig($setting,$value,$id);
		if($setting == 'backup_schedule'){
			$this->scheduleJobs($id);
		}
	}
	/**
	 * Get individual backup setting
	 *
	 * @param string $id backup id
	 * @param string $setting setting name
	 * @return void
	 */
	public function getBackupSetting($id,$setting){
		return $this->getConfig($setting, $id);
	}

	/**
	 * delete backup by ID
	 * @param  string $id backup id
	 * @return bool	success/failure
	 */
	public function deleteBackup($id){
		$this->setConfig($id,false,'backupList');
		$this->delById($id);
		//This should return an empty array if successful.
		$this->scheduleJobs('all');
		return empty($this->getBackup($id));
	}

	/**
	 * Set the modules to backup for a specific id. This nukes prior data
	 * @param string $id      backup id
	 * @param array $modules associative array of modules [['modulename' => 'foo'], ['modulename' => 'bar']]
	 */
	public function setModulesById($id,$modules){
		$this->delById('modules_'.$id);
		foreach ($modules as $module) {
			if(!isset($module['modulename'])){
				continue;
			}
			$this->setConfig($module['modulename'],true,'modules_'.$id);
		}
		return $this->getAll('modules_'.$id);
	}


	//UTILITY

	public function processDependencies($deps = []){
		$ret = true;
		if(!is_array($deps)) {
			return $ret;
		}
		foreach($deps as $dep){

			if($this->freepbx->Modules->getInfo(strtolower($dep),true)){
				continue;
			}
			try{
				$this->mf->install(strtolower($dep),true);
			}catch(\Exception $e){
				$ret = false;
				break;
			}
		}
		return $ret;
	}

	/**
	 * Wrapper for Ramsey UUID so we don't have to put the full namespace string everywhere
	 * @return string UUIDv4
	 */
	public function generateId(){
		return \Ramsey\Uuid\Uuid::uuid4()->toString();
	}

	/**
	 * Convert path params to actual path
	 * @static Backup::getPath
	 * @param string $string path
	 * @return void
	 */
	static function getPath($string){
		if (!preg_match("/__(.+)__/", $string, $out)) {
			return $string;
		}
		$path = $this->freepbx->Config->get($out[1]);
		if($path){
			return str_replace($out[0], $path, $string);
		}
		return $string;
	}

	/**
	 * Convert file list from the manifest into a json string
	 *
	 * @param array $data data from manifest
	 * @return string JSON representation of files.
	 */
	public function moduleJSONFromManifest($data){
		$return = [];
		if(!isset($data['modules'])){
			return json_encode([]);
		}
		foreach($data['modules'] as $module){
			$name    = $module['module'];
			$version = $module['version'];
			$status  = ($this->freepbx->Modules->checkStatus(strtolower($name)))?_("Enabled"):_("Uninstalled or Disabled");
			$return[] = [
				'modulename' => $name,
				'version'    => $version,
				'installed'  => $status
			];
		}
		return json_encode($return);
	}

	public function deleteRemote($driver, $id, $path){
		return $this->freepbx->Filestore->delete($driver, $id, $path);
	}

	public function getAllRemote(){
		$final = [];
		$serverName = str_replace(' ', '_',$this->freepbx->Config->get('FREEPBX_SYSTEM_IDENT'));
		$ret = $this->freepbx->Filestore->listAllFilesByPath($serverName);
		foreach($ret as $dname => $driver){
			foreach($driver as $id => $location){
				if(!isset($location['results'])){
					continue;
				}
				foreach($location['results'] as $file){
					if($file['type'] == 'dir'){
						continue;
					}
					$backupFile = new BackupSplFileInfo($file['path']);
					$info = $backupFile->backupData();
					if($info['isCheckSum']){
						continue;
					}
					$final[] = [
						'id' => $dname.'_'.$id.'_'.sha1($file['path']),
						'type' => $dname,
						'file' => $file['path'],
						'framework' => $info['framework'],
						'timestamp' => $info['timestamp'],
						'name' => str_replace('_',' ',explode('/',$file['dirname'])[1]),
					];
				}
			}
		}
		return $final;
	}
	public function remoteToLocal($location,$file){
		$parts = explode('_',$location);
		$fileparts = array_slice(explode('/',$file),-2);
		$spooldir = $this->freepbx->Config->get("ASTSPOOLDIR");
		$localpath = sprintf('%s/backup/%s/%s',$spooldir,$fileparts[0],$fileparts[1]);
		if(!file_exists($localpath)){
			$this->freepbx->Filestore->get($parts[0],$parts[1],$file,$localpath);
		}
		if(!file_exists($localpath)){
			return '';
		}
		$this->setConfig(md5($localpath),$localpath,'localfilepaths');

		return $localpath;
	}
	public function determineBackupFileType($filepath){
		$tar = new Tar();
		$tar->open($filepath);
		$files = $tar->contents();
		foreach ($files as $file) {
			if ($file->getIsdir() && $file->getPath() === 'modulejson') {
				return 'current';
			}
		}

		return 'legacy';
	}
	/**
	 * Returns the home directory of the AMPASTERISKWEBUSER. If the user has no home directory we return home dir for the current running process.
	 *
	 * @return string path to home dir such as /home/asterisk
	 */
	public function getAsteriskUserHomeDir(){
		if(!isset($this->homeDir) || empty($this->homeDir)){
			$webuser = $this->freepbx->Config->get('AMPASTERISKWEBUSER');

			if (!$webuser) {
				throw new \Exception(_("I don't know who I should be running Backup as."));
			}

			// We need to ensure that we can actually read the GPG files.
			$web = posix_getpwnam($webuser);
			if (!$web) {
				throw new \Exception(sprintf(_("I tried to find out about %s, but the system doesn't think that user exists"),$webuser));
			}
			$home = trim($web['dir']);
			if (!is_dir($home)) {
				// Well, that's handy. It doesn't exist. Let's use ASTSPOOLDIR instead, because
				// that should exist and be writable.
				$home = $this->freepbx->Config->get('ASTSPOOLDIR');
				if (!is_dir($home)) {
					// OK, I give up.
					throw new \Exception(sprintf(_("Asterisk home dir (%s) doesn't exist, and, ASTSPOOLDIR doesn't exist. Aborting"),$home));
				}
			}

			$this->homeDir = $home;
		}
		return $this->homeDir;
	}
}
<?php

class TestDataController extends Controller {
	static $data_dir;
	static $quiet = false;

	static function get_data_dir() {
		if (isset(self::$data_dir)) return self::$data_dir;
		return project().'/testdata/';
	}

	function init() {
		parent::init();

		$canAccess = ((Director::isDev() || Director::is_cli()) && Permission::check("ADMIN"));
		if(!$canAccess) return Security::permissionFailure($this);

		$this->message("<h1>Test Data</h1>");
	}

	function index() {
			$this->message("
<pre>
usage: dev/data/COMMAND[/PARAMETER]

The available commands are:

   load      Loads the data from yml files. The new records will be created, old removed and the
             existing ones updated. Added db objects are tracked, so it is possible to remove all
             of them later. The following COMMANDs are accepted:
            
               all
                  Scans the <wwwroot>/<project>/testdata directory for all available files and loads
                  them into the database

               name1,name2,...
                  Loads only the specified files - case insensitive, omit the .yml extension.

   reset     Removes all tracked records - only records added via `load' are affected.

   export    Accesses the exporter for the data currently in the database (reverse of load).

   translate Accesses the site translator for testing multiple Translatable translations.
</pre>
");
	}

	public function message($message) {
		if (!self::$quiet) {
			if (Director::is_cli()) {
				$message = strip_tags($message);
			}
			echo $message;
		}
	}
	
	/**
	 * Remove all data created by testdata - i.e. all rows referenced from the TestDataTag table.
	 */
	function reset($request) {
		increase_time_limit_to(600);

		$this->message("Resetting");
		$tags = DataObject::get('TestDataTag');
		if ($tags) foreach ($tags as $tag) {
			if(!class_exists($tag->Class)) {
				$this->message("\n<span style=\"background: orange; color: black;\">WARNING: %s class does not exist, but has a TestDataTag record. Skipping...</span>\n");
				continue;
			}

			$record = DataObject::get_by_id($tag->Class, $tag->RecordID, false);
			if ($record) {
				TestDataYamlFixture::attempt_unpublish($record);
				$record->delete();
			}
			$tag->delete();
			$this->message('.');
		}
		DB::query("DELETE FROM \"TestDataTag\"");
		$this->message("\n<span style=\"background: green; color: white;\">SUCCESS</span>\n");
		return;
	}

	/**
	 * Process the contents of the yml file specified via the first url parameter.
	 */
	function load($request) {
		increase_time_limit_to(600);

		$requestedFiles = Convert::raw2xml(str_replace(' ', '', strtolower($request->param('ID'))));
		if (!$requestedFiles) {
			$this->message('Parameter required.');
			return;
		}

		if ($requestedFiles=='all') {
			$requestedFiles = null;
		}
		else {
			$requestedFiles = explode(',', $requestedFiles);
		}

		$files = scandir(BASE_PATH."/".self::get_data_dir()."/");
		foreach ($files as $file) {
			// Checking the validity of the file
			if (strpos($file, '.yml')===false || $file[0]=='.' || !is_file(BASE_PATH."/".self::get_data_dir()."/".$file)) continue;
			
			// Check if the file was requested
			$fileBase = str_replace('.yml', '', $file);
			if ($requestedFiles && !in_array(strtolower($fileBase), $requestedFiles)) {
				continue;
			}

			// Update existing objects and add new ones
			$this->message("Adding and updating objects for $fileBase");
			$yml = new TestDataYamlFixture(self::get_data_dir()."/".$file);
			$yml->saveIntoDatabase(DataModel::inst());
			$this->message("\n");

			// Remove the objects that fell behind - TestDataYamlFixture increments the tag
			// version on each run, so we can easily identify these.
			$this->message("Prunning records for $fileBase");
			$latestVersion = DB::query("SELECT MAX(\"Version\") FROM \"TestDataTag\" WHERE \"FixtureFile\"='$file'")->value();
			$tags = DataObject::get('TestDataTag', "\"FixtureFile\"='$file' AND \"Version\"<'$latestVersion'");
			if ($tags) foreach ($tags as $tag) {
				if(!class_exists($tag->Class)) {
					$this->message("\n<span style=\"background: orange; color: black;\">WARNING: %s class does not exist, but has a TestDataTag record. Skipping...</span>\n");
					continue;
				}

				$record = DataObject::get_by_id($tag->Class, $tag->RecordID, false);
				if ($record) {
					TestDataYamlFixture::attempt_unpublish($record);
					$record->delete();
				}
				$tag->delete();
				$this->message('.');
			}
			$this->message("\n");
		}

		$this->message("<span style=\"background: green; color: white;\">SUCCESS</span>\n");
	}
	
}


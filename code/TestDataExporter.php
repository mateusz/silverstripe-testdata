<?php

class TestDataExporter extends Controller {

	static $allowed_actions = array(
		'index',
		'export',
		'ExportForm'
	);

	public $currentFixtureFile = '';

	function init() {
		parent::init();

		// Basic access check.
		$canAccess = (Director::isDev() || Director::is_cli() || Permission::check("ADMIN"));
		if(!$canAccess) return Security::permissionFailure($this);
	}

	/**
	 * Builds the entry form so the user can choose what to export.
	 */
	function ExportForm() {
		$fields = new FieldList();

		// Display available yml files so we can re-export easily.
		$ymlDest = BASE_PATH.'/'.TestDataController::get_data_dir(); 
		$existingFiles = scandir($ymlDest);
		$ymlFiles = array();
		foreach ($existingFiles as $file) {
			if (preg_match("/.*\.yml/", $file)) {
				$ymlFiles[$file] = $file;
			}
		}
		if ($ymlFiles) {
			$fields->push(new DropdownField('Reexport', 'Reexport to file (will override any other setting): ', $ymlFiles, '', null, '-- choose file --'));
		}

		// Get the list of available DataObjects
		$dataObjectNames = ClassInfo::subclassesFor('DataObject');
		unset($dataObjectNames['DataObject']);
		sort($dataObjectNames);

		foreach ($dataObjectNames as $dataObjectName) {
			// Skip test only classes.
			$class = singleton($dataObjectName);
			if($class instanceof TestOnly) continue;

			// Skip testdata internal class
			if ($class instanceof TestDataTag) continue;

			// 	Create a checkbox for including this object in the export file
			$count = $class::get()->Count();
			$fields->push($class = new CheckboxField("Class[$dataObjectName]", $dataObjectName." ($count)"));
			$class->addExtraClass('class-field');

			// 	Create an ID range selection input
			$fields->push($range = new TextField("Range[$dataObjectName]", ''));
			$range->addExtraClass('range-field');
		}
		// Create the "traverse relations" option - whether it should automatically include relation objects even if not explicitly ticked.
		$fields->push(new CheckboxField('TraverseRelations', 'Traverse relations (implicitly includes objects, for example pulls Groups for Members): ', 1));

		// Create the option to include real files.
		$path = BASE_PATH.'/'.TestDataController::get_data_dir();
		$fields->push(new CheckboxField('IncludeFiles', "Copy real files (into {$path}files)", 0));

		// Create file name input field
		$fields->push(new TextField('FileName', 'Name of the output YML file: ', 'output.yml'));
	
		// Create actions for the form
		$actions = new FieldList(new FormAction("export", "Export"));

		return new Form($this, "ExportForm", $fields, $actions);
	}

	/**
	 * Checks if the object is present in the the buckets and queue.
	 */
	function objectPresent($object, $buckets, $queue = null) {
		// Check buckets
		$this->assureHasTag($object);
		if (isset($buckets[$object->ClassName]) && isset($buckets[$object->ClassName][$object->YMLTag])) {
			return true;
		}

		// Check queue
		if ($queue) {
			foreach ($queue as $queued) {
				$this->assureHasTag($queued);
				if ($queued->ClassName==$object->ClassName && $queued->YMLTag==$object->YMLTag) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Introspects the object's relationships and adds the relevant objects
	 * into the queue, if not already processed into the buckets.
	 */
	function traverseRelations($object, $buckets, &$queue) {
		// Traverse has one relations.
		$oneRelations = array_merge(
			($relation = $object->has_one()) ? $relation : array(),
			($relation = $object->belongs_to()) ? $relation : array()
		);
		//  Check if the objects are already processed into buckets (or queued for processing)
		foreach ($oneRelations as $relation => $class) {
			$relatedObject = $object->$relation();
			if (!$relatedObject || !$relatedObject->ID) continue;

			$this->assureHasTag($relatedObject);
			if (!$this->objectPresent($relatedObject, $buckets, $queue)) {
				$queue[] = $relatedObject;
			}
		}

		// Same, but for *-many relations, with additional loop inside.
		$manyRelations = array_merge(
			($relation = $object->has_many()) ? $relation : array(),
			($relation = $object->many_many()) ? $relation : array() // Includes belongs_many_many
		);
		foreach ($manyRelations as $relation => $class) {
			$relatedObjects = $object->$relation();

			// Step through all objects on the other side of this relation and check if already processed.
			foreach ($relatedObjects as $relatedObject) {
				if (!$relatedObject || !$relatedObject->ID) continue;

				$this->assureHasTag($relatedObject);
				if (!$this->objectPresent($relatedObject, $buckets, $queue)) {
					$queue[] = $relatedObject;
				}
			}
		}
	}

	/**
	 * Converts the object record into YML format.
	 * Includes the relationships to other objects that can be found in the buckets.
	 */
	function generateYML($object, $buckets) {
		$output = '';

		// Write out the YML tag
		$output .= "\t$object->YMLTag:\n";

		// Find relational and meta fields we are not interested in writing right now.
		$noninterestingFields = array('ID', 'Created', 'LastEdited', 'ClassName', 'RecordClassName', 'YMLTag', 'Version');
		foreach (array_keys($object->has_one()) as $relation) {
			array_push($noninterestingFields, $relation.'ID');
		}

		// Write fields.
		foreach ($object->toMap() as $field => $value) {
			if (in_array($field, $noninterestingFields)) continue;

			if (strpos($value, "\n")) {
				// Use YAML blocks to store newlines. The block needs to be at the next level of indentation.
				$value = str_replace("\n", "\n\t\t\t", $value);
				$output .= "\t\t$field: |\n\t\t\t$value\n";
			}
			else {
				// Single-line value. Escape quotes and enclose in quotes.
				$value = str_replace('"', '\"', $value);
				$output .= "\t\t$field: \"$value\"\n";
			}
		}

		// Process has-one relationships.
		foreach ($object->has_one() as $relation => $class) {
			$relatedObject = $object->$relation();

			// Look up the object in the appropriate bucket - might not be there if cascading has been disabled.
			if ($this->objectPresent($relatedObject, $buckets)) {
				$objectFromBucket = $buckets[$relatedObject->ClassName][$relatedObject->YMLTag];
				// Write out the YML relationship using the YML tag already created before.
				$output .= "\t\t$relation: =>$objectFromBucket->ClassName.$objectFromBucket->YMLTag\n";
			}
		}

		// Process *-many relationships (similar to the previous block, but with additional loop)
		$manyRelations = array_merge(
			($relation = $object->has_many()) ? $relation : array(),
			($relation = $object->many_many()) ? $relation : array()
		);
		foreach ($manyRelations as $relation => $class) {
			$relatedObjects = $object->$relation();

			// Step through all objects on the other side of this relation.
			$outputRelation = array();
			foreach ($relatedObjects as $relatedObject) {
				// Look up the object in the appropriate bucket - might not be there if cascading has been disabled - in this case just skip it.
				if ($this->objectPresent($relatedObject, $buckets)) {
					$objectFromBucket = $buckets[$relatedObject->ClassName][$relatedObject->YMLTag];
					// Store for later
					$outputRelation[] = "=>$objectFromBucket->ClassName.$objectFromBucket->YMLTag";
				}
			}
			if (count($outputRelation)) {
				$output .= "\t\t$relation: ".implode(',', $outputRelation)."\n";
			}
		}

		return $output;
	}

	/**
	 * Prepares an automatic YML tag for the object.
	 * Reuses an existing tag, if present from the previous import so we can reexport correctly.
	 */
	function getTag($object) {
		$existingTag = TestDataTag::get()->
							filter(array('Class'=>$object->ClassName, 'RecordID'=>$object->ID, 'FixtureFile'=>$this->currentFixtureFile))->
							sort('Version', 'DESC')->
							First();

		if ($existingTag) {
			// Use existing YML tag.
			return $existingTag->FixtureID;
		}
		else {
			// Create a new YML tag.

			// First, extract the highest present numeric suffix for a same-class same-file tag.
			$existingTags = DB::query("SELECT DISTINCT \"FixtureID\" FROM \"TestDataTag\" WHERE \"Class\"='$object->ClassName' AND \"FixtureFile\"='$this->currentFixtureFile'")->column("FixtureID");
			foreach ($existingTags as $key => $tag) {
				$existingTags[$key] = (int)preg_replace("/[^0-9]/", '', $tag);
			}

			$newTagID = 1;
			if (count($existingTags)) {
				rsort($existingTags);
				$newTagID = $existingTags[0]+1;
			}

			// Pull the exported records into the TestDataTag table.
			$tag = new TestDataTag();
			$tag->Class = $object->ClassName;
			$tag->RecordID = $object->ID;
			$tag->FixtureFile = $this->currentFixtureFile;
			$tag->FixtureID = $object->ClassName.$newTagID;
			$tag->Version = 1;
			$tag->write();

			return $tag->FixtureID;
		}
	}

	/**
	 * Ensures object has an YML tag.
	 */
	function assureHasTag($object) {
		if (!$object->YMLTag) $object->YMLTag = $this->getTag($object);
	}

	/**
	 * Processes the textual id list coming from the form into a database where clause.
	 */
	function generateIDs($textual, $class) {
		$where = array();
		$textual = preg_replace('/[^0-9,\-]/', '', $textual);
		$baseClass = ClassInfo::baseDataClass($class);
		foreach (explode(',', $textual) as $token) {
			if (strpos($token, '-')===false) {
				// Single ID
				$where[] = "(\"$baseClass\".\"ID\"='".(int)$token."')";
			}
			else {
				// Range
				$range = explode('-', $token);
				$where[] = "(\"$baseClass\".\"ID\" BETWEEN '".(int)$range[0]."' AND '".(int)$range[1]."')";
			}
		}

		return implode($where, ' OR ');
	}

	/**
	 * Decodes the meta parameters from the string (presumably file content).
	 */
	function extractParams($content) {
		if (preg_match('/^#params=(.*)$/m', $content, $params)) {
			return json_decode($params[1], true);
		}

		return null;
	}

	/**
	 * Processes the form and builds the output
	 */
	function export($data, $form) {
		increase_time_limit_to(600);
		$ymlDest = BASE_PATH.'/'.TestDataController::get_data_dir();

		// If we are reexporting, override all the other settings in the form from the file.
		if (isset($data['Reexport']) && $data['Reexport']) {
			$data['Reexport'] = preg_replace('/[^a-z0-9\-_\.]/', '', $data['Reexport']);

			$params = $this->extractParams(file_get_contents($ymlDest.$data['Reexport']));
			$data = array_merge($data, $params);
		}

		// Simple validation
		if (!isset($data['FileName']) || !$data['FileName']) {
			echo "Specify file name.";
			exit;
		}

		if (!isset($data['Class'])) {
			echo "Pick some classes.";
			exit;
		}

		$this->currentFixtureFile = $data['FileName'];

		// Here we will collect all the output.
		$output = '';

		// We want to work off Draft, because that's what's visible in the CMS.
		Versioned::reading_stage('Stage');

		// Disable Translatable augmentation so we can export across the locales.
		if (class_exists('Translatable')) {
			Translatable::disable_locale_filter();
		}

		// Prepare the filesystem.
		@mkdir($ymlDest);
		if (isset($data['IncludeFiles']) && $data['IncludeFiles']) {
			$fileDest = BASE_PATH.'/'.TestDataController::get_data_dir().'files';
			@mkdir($fileDest);
		}

		// Variables:
		//  Queue for outstanding records to process.
		$queue = array();
		//  Buckets for sorting the resulting records by class name.
		$buckets = array();

		// Populate the queue of DataObjects to be exported
		foreach ($data['Class'] as $dataObjectName => $checked) {
			$class = singleton($dataObjectName);
			if (!$class) continue;

			// Apply the ID filter, if present.
			if (isset($data['Range']) && isset($data['Range'][$dataObjectName]) && $data['Range'][$dataObjectName]) {
				$where = $this->generateIDs($data['Range'][$dataObjectName], $dataObjectName);
			}

			$objects = $class::get();
			if (isset($where)) $objects = $objects->where($where);

			foreach ($objects as $object) {
				array_push($queue, $object);
			}
		}

		// Collect all the requested objects (and related objects) into buckets.
		while ($object = array_shift($queue)) {
			// Generate and attach the unique YML tag
			$this->assureHasTag($object);

			//  Add the object to the relevant bucket (e.g. "Page") by ID
			$buckets[$object->ClassName][$object->YMLTag] = $object;

			// 	If traversing has been enabled
			if (isset($data['TraverseRelations']) && $data['TraverseRelations']) {
				$this->traverseRelations($object, $buckets, $queue);
			}

			// If files should be included
			if (isset($data['IncludeFiles']) && $data['IncludeFiles']) {
				if ($object instanceof File && $object->ClassName!='Folder') {
					$source = $object->getFullPath();
					if ($source){
						@copy($source, "$fileDest/$object->Name");
						echo "Processing $source to $fileDest/$object->Name.<br>\n";
					}
				}
			}
		}

		// Sort the buckets so the records can be updated in place
		ksort($buckets, SORT_STRING);
		foreach ($buckets as $key=>$bucket) {
			ksort($bucket, SORT_STRING);
			$buckets[$key] = $bucket;
		}

		// Write objects.
		foreach ($buckets as $name => $bucket) {
			//  Write the bucket YML heading (object class)
			$output .= "$name:\n";

			foreach ($bucket as $dataObject) {
				$output .= $this->generateYML($dataObject, $buckets);
			}
		}

		// Dump metadata
		global $database;
		$output .= "\n#db=$database";
		$output .= "\n#time=".date('Y-m-d H:i:s');
		$output .= "\n#params=".json_encode($data);

		// Output the written data into a file specified in the form.
		$file = $ymlDest.preg_replace('/[^a-z0-9\-_\.]/', '', $this->currentFixtureFile);
		file_put_contents($file, $output);

		echo "File $file written.<br>\n";
	}
}


<?php

class TestDataExporter extends Controller {

	static $allowed_actions = array(
		'index',
		'export',
		'ExportForm'
	);

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

		// Get the list of available DataObjects
		$dataObjectNames = ClassInfo::subclassesFor('DataObject');
		unset($dataObjectNames['DataObject']);

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
	 * Checks if the object is present in the the buckets (and queue).
	 */
	function objectPresent($object, $buckets, $queue = null) {
		// Check buckets
		if (isset($buckets[$object->ClassName]) && isset($buckets[$object->ClassName][$object->ID])) {
			return true;
		}

		// Check queue
		if ($queue) {
			foreach ($queue as $queued) {
				if ($queued->ClassName==$object->ClassName && $queued->ID==$object->ID) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Introspects the object's relationships and adds the relevant objects
	 * into the queue, if not already processed and present in the buckets.
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

		// Write out the YML handle
		$output .= "\t$object->YMLHandle:\n";

		// Find relational and meta fields we are not interested in writing right now.
		$noninterestingFields = array('ID', 'Created', 'LastEdited', 'ClassName', 'RecordClassName', 'YMLHandle');
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
				$objectFromBucket = $buckets[$relatedObject->ClassName][$relatedObject->ID];
				// Write out the YML relationship using the YML handle already created before.
				$output .= "\t\t$relation: =>$objectFromBucket->ClassName.$objectFromBucket->YMLHandle\n";
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
					$objectFromBucket = $buckets[$relatedObject->ClassName][$relatedObject->ID];
					// Store for later
					$outputRelation[] = "=>$objectFromBucket->ClassName.$objectFromBucket->YMLHandle";
				}
			}
			if (count($outputRelation)) {
				$output .= "\t\t$relation: ".implode(',', $outputRelation)."\n";
			}
		}

		return $output;
	}

	/**
	 * Prepares an automatic YML handle for the object.
	 */
	function generateHandle($object) {
		// Create handle from object class and ID.
		return $object->ClassName.$object->ID;
	}

	/**
	 * Processes the textual id list into a where clause.
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
	 * Processes the form and builds the output
	 */
	function export($data, $form) {
		increase_time_limit_to(600);

		if (!isset($data['FileName']) || !$data['FileName']) {
			echo "Specify file name.";
			exit;
		}

		if (!isset($data['Class'])) {
			echo "Pick some classes.";
			exit;
		}

		// Here we will collect all the output.
		$output = '';

		// We want to work off Draft, because that's what's visible in the CMS.
		Versioned::reading_stage('Stage');

		// Prepare the filesystem.
		$ymlDest = BASE_PATH.'/'.TestDataController::get_data_dir(); 
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

		// While queue is not empty
		while ($object = array_shift($queue)) {
			// Generate and attach the unique YML handle (=>generateHandle)
			$object->YMLHandle = $this->generateHandle($object);

			//  Add the object to the relevant bucket (e.g. "Page") by ID
			$buckets[$object->ClassName][$object->ID] = $object;

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

		foreach ($buckets as $name => $bucket) {
			//  Write the bucket YML heading (object class)
			$output .= "$name:\n";

			//  For each object in the bucket
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
		$file = $ymlDest.preg_replace('/[^a-z0-9\-_\.]/', '', $data['FileName']);
		file_put_contents($file, $output);

		echo "File $file written.<br>\n";
	}
}


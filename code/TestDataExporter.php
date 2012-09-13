<?php

class TestDataExporter extends Controller {
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

			// 	Create a checkbox for including this object in the export file
			$count = $class::get()->Count();
			$fields->push(new CheckboxField("Class[$dataObjectName]", $dataObjectName." ($count)"));

			// 	Create an ID range selection input
			$fields->push(new TextField("Range[$dataObjectName]", ''));
		}
		// Create the "traverse relations" option checkbox - whether it should automatically include relation objects.
		$fields->push(new CheckboxField('TraverseRelations', 'Traverse relations (if unchecked some relations might not be written)', 1));

		// Create file name input field
		$fields->push(new TextField('FileName', 'Name of the output YML file', 'output.yml'));
	
		// Create actions for the form
		$actions = new FieldList(new FormAction("export", "Export"));

		return new Form($this, "ExportForm", $fields, $actions);
	}

	/**
	 * Introspects the object's relationships and adds the relevant objects
	 * into the queue, if not already processed and present in the buckets.
	 */
	function traverseRelations($object, $buckets, $queue) {
		// For each object in relation
		//  Check if the object is already processed into buckets
		//  If not, add it to the queue.
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

			$output .= "\t\t$field: \"$value\"\n";
		}

		// Process has-one relationships.
		foreach ($object->has_one() as $relation => $class) {
			$relatedObject = $object->$relation();

			// Look up the object in the appropriate bucket - might not be there if cascading has been disabled.
			if (isset($buckets[$relatedObject->ClassName]) && isset($buckets[$relatedObject->ClassName][$relatedObject->ID])) {
				$objectFromBucket = $buckets[$relatedObject->ClassName][$relatedObject->ID];
				// Write out the YML relationship using the YML handle already created before.
				$output .= "\t\t$relation: =>$objectFromBucket->ClassName.$objectFromBucket->YMLHandle\n";
			}
		}

		// Process *-many relationships.
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
				if (isset($buckets[$relatedObject->ClassName]) && isset($buckets[$relatedObject->ClassName][$relatedObject->ID])) {
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
	 * Processes the form and builds the output
	 */
	function export($data, $form) {
		if (!isset($data['FileName']) || !$data['FileName']) {
			echo "Specify file name.";
			exit;
		}


		// Variables:
		//  Queue for outstanding records to process.
		$queue = array();
		//  Buckets for sorting the resulting records by class name.
		$buckets = array();

		// Populate the queue of DataObjects to be exported
		foreach ($data['Class'] as $dataObjectName => $checked) {
			$class = singleton($dataObjectName);
			$objects = $class::get();
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
		}

		// Here we will collect all the output.
		$output = '';

		foreach ($buckets as $name => $bucket) {
			//  Write the bucket YML heading (object class)
			$output .= "$name:\n";

			//  For each object in the bucket
			foreach ($bucket as $dataObject) {
				$output .= $this->generateYML($dataObject, $buckets);
			}
		}

		// Output the written data into a file specified in the form.
		$file = BASE_PATH.'/testdata/generated/'.preg_replace('/[^a-z0-9\-_\.]/', '', $data['FileName']);
		file_put_contents($file, $output);

		echo "File $file written.";
	}
}


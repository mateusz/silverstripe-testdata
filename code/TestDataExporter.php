<?php

class TestDataExporter extends BuildTask {
	/**
	 * Builds the entry form so the user can choose what to export.
	 */
	function ExportForm() {
		// Get the list of available DataObjects
		// For each DataObject
		// 	Create a checkbox for including this object in the export file
		// 	Create an ID range selection input
		// Create the "traverse relations" option checkbox - whether it should automatically include relation objects.
		// Create file name input field
		// Create actions for the form
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
	function writeYML($object, $buckets) {
		// Write out the YML handle
		// Write all database fields, skipping the ID
		// For each object in relation to this one
		//  Look up the object in the appropriate bucket - might not be there if cascading has been disabled.
		//  If the object is found, write out the YML relationship using the YML handle already created before.
	}

	/**
	 * Prepares an automatic YML handle for the object.
	 */
	function generateHandle($object) {
		// Create handle from object class and ID.
	}

	/**
	 * Processes the form and builds the output
	 */
	function export() {
		// Variables:
		//  Queue for outstanding records to process.
		//  Buckets for sorting the resulting records by class name.
		// Populate the queue of DataObjects to be exported
		// While queue is not empty
		//  Add the object to the relevant bucket (e.g. "Page") by ID and generate the unique YML handle (=>generateHandle)
		// 	If cascading has been enabled
		//   =>traverseRelations
		// For each bucket
		//  Write the bucket YML heading (object class)
		//  For each object in the bucket
		//   =>writeYML
		// Output the written data into a file specified in the form.
	}
}


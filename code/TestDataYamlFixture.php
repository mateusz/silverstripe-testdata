<?php


class TestDataYamlFixture extends YamlFixture {
	public function saveIntoDatabase() {
		// This has to be executed only once per fixture.
		$testDataTag = basename($this->fixtureFile);
		$this->latestVersion = DB::query("SELECT MAX(\"Version\") FROM \"TestDataTag\" WHERE \"FixtureFile\"='$testDataTag'")->value();

		parent::saveIntoDatabase();
	}

	/**
	 * Mostly rewritten from parent, with changes that allow us to update objects (not only write new).
	 */
	protected function writeDataObject($dataClass, $items) {
		if (Director::isLive()) user_error('This should not be run on the live site.', E_USER_ERROR);
		
		$testDataTag = basename($this->fixtureFile);
		foreach($items as $identifier => $fields) {
			$obj = null;

			// Check if the object already exists in the database be looking for its tag.
			$tag = DataObject::get_one('TestDataTag', "\"FixtureFile\"='$testDataTag' AND \"Class\"='$dataClass' AND \"FixtureID\"='$identifier'", false);
			if ($tag) {
				// Object exists, increment the version.
				$obj = DataObject::get_by_id($tag->Class, $tag->RecordID, false);
			}

			// Create the object
			if (!isset($obj) || !$obj) {
				if ($tag) { 
					$tag->delete(); // Tag not valid, delete.
					$tag = null;
				}
				$obj = new $dataClass();
			}
			
			// If an ID is explicitly passed, then we'll sort out the initial write straight away
			// This is just in case field setters triggered by the population code in the next block
			// Call $this->write().  (For example, in FileTest)
			// Do this only if the object is new.
			if(!$tag && isset($fields['ID']) && is_array($fields)) {
				$obj->ID = $fields['ID'];

				// The database needs to allow inserting values into the foreign key column (ID in our case)
				$conn = DB::getConn();
				if(method_exists($conn, 'allowPrimaryKeyEditing')) $conn->allowPrimaryKeyEditing(ClassInfo::baseDataClass($dataClass), true);
				$obj->write(false, true);
				if(method_exists($conn, 'allowPrimaryKeyEditing')) $conn->allowPrimaryKeyEditing(ClassInfo::baseDataClass($dataClass), false);
			}
			
			// Populate the dictionary with the ID
			if(!is_array($fields)) {
				throw new Exception($dataClass . ' failed to load. Please check YML file for errors');
			}

			if($fields) foreach($fields as $fieldName => $fieldVal) {
				if($obj->many_many($fieldName) || $obj->has_many($fieldName) || $obj->has_one($fieldName)) continue;
				$obj->$fieldName = $this->parseFixtureVal($fieldVal);
			}
			$obj->write();
			
			// has to happen before relations in case a class is referring to itself
			$this->fixtureDictionary[$dataClass][$identifier] = $obj->ID;
			
			// Populate all relations
			if($fields) foreach($fields as $fieldName => $fieldVal) {
				if($obj->many_many($fieldName) || $obj->has_many($fieldName)) {
					$parsedItems = array();
					$items = preg_split('/ *, */',trim($fieldVal));
					foreach($items as $item) {
						$parsedItems[] = $this->parseFixtureVal($item);
					}
					$obj->write();
					if($obj->has_many($fieldName)) {
						$obj->getComponents($fieldName)->setByIDList($parsedItems);
					} elseif($obj->many_many($fieldName)) {
						$obj->getManyManyComponents($fieldName)->setByIDList($parsedItems);
					}
				} elseif($obj->has_one($fieldName)) {
					$obj->{$fieldName . 'ID'} = $this->parseFixtureVal($fieldVal);
				}
			}
			$obj->write();

			// Make sure the object is deployed to both stages.
			if (Object::has_extension($obj->ClassName, 'Versioned')) {
				$obj->doPublish();
			}

			// Increment the version on the tag so we can find the old unused records afterwards.
			if ($tag) {
				$tag->Version = $this->latestVersion + 1;
				$tag->write();
			}
			else {
				$tag = new TestDataTag;
				$tag->Class = $dataClass;
				$tag->RecordID = $obj->ID;
				$tag->FixtureFile = $testDataTag;
				$tag->FixtureID = $identifier;
				$tag->Version = $this->latestVersion + 1;
				$tag->write();
			}

			echo '.';
		}
	}
}

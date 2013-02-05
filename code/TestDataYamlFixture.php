<?php


class TestDataYamlFixture extends YamlFixture {
	/**
	 * Mostly rewritten from parent, but allows circular dependencies - goes through the relation loop only after
	 * the dictionary is fully populated.
	 */
	public function saveIntoDatabase(DataModel $model) {
		// Custom plumbing: this has to be executed only once per fixture.
		$testDataTag = basename($this->fixtureFile);
		$this->latestVersion = DB::query("SELECT MAX(\"Version\") FROM \"TestDataTag\" WHERE \"FixtureFile\"='$testDataTag'")->value();

		// We have to disable validation while we import the fixtures, as the order in
		// which they are imported doesnt guarantee valid relations until after the
		// import is complete.
		$validationenabled = DataObject::get_validation_enabled();
		DataObject::set_validation_enabled(false);
		
		$parser = new Spyc();
		$fixtureContent = $parser->loadFile($this->fixtureFile);

		$this->fixtureDictionary = array();
		foreach($fixtureContent as $dataClass => $items) {
			if(ClassInfo::exists($dataClass)) {
				$this->writeDataObject($model, $dataClass, $items);
			} else {
				$this->writeSQL($dataClass, $items);
			}
		}

		// Dictionary is now fully built, inject the relations.
		foreach($fixtureContent as $dataClass => $items) {
			if(ClassInfo::exists($dataClass)) {
				$this->writeRelations($dataClass, $items);
			}
		}
		
		DataObject::set_validation_enabled($validationenabled);
	}

	/**
	 * Attempt to publish the object, if it supports this funcitonality.
	 */
	protected function attemptPublish($obj) {
		if ($obj->hasExtension('Versioned')) {
			if (method_exists($obj, 'doPublish')) {
				// Detect legacy function signatures with parameters (e.g. as in EditableFormFields)
				$reflection = new ReflectionMethod(get_class($obj), 'doPublish');

				if ($reflection->getNumberOfRequiredParameters()==0) {
					// New
					$obj->doPublish();
				} else {
					// Legacy
					$obj->doPublish('Stage', 'Live');
				}
			} else {
				// Versioned default
				$obj->publish('Stage', 'Live');
			}
		}
	}

	/**
	 * Get the DataObject the tag is pointing to.
	 *
	 * @returns DataObject
	 */
	protected function getTag($testDataTag, $dataClass, $identifier) {
		return DataObject::get_one('TestDataTag', "\"FixtureFile\"='$testDataTag' AND \"Class\"='$dataClass' AND \"FixtureID\"='$identifier'", false);
	}

	/**
	 * Mostly rewritten from parent, with changes that allow us to update objects (not only write new).
	 * Also adds dummy file creation. The files will either be empty (touched only), or will be copied
	 * from the testdata directory if found.
	 */
	protected function writeDataObject($model, $dataClass, $items) {
		File::$update_filesystem = false;

		if (Director::isLive()) user_error('This should not be run on the live site.', E_USER_ERROR);
		
		$testDataTag = basename($this->fixtureFile);
		foreach($items as $identifier => $fields) {
			$tag = $this->getTag($testDataTag, $dataClass, $identifier);

			$obj = null;
			if ($tag) {
				$obj = $tag->getObject();
			}

			// Create the object
			if (!isset($obj) || !$obj) {
				if ($tag) {
					$tag->delete(); // Tag not valid, delete.
					$tag = null;
				}
				$obj = $model->$dataClass->newObject();
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

			// when creating a Folder record, the directory should exist
			if(is_a($obj, 'Folder')) {
				if(!file_exists($obj->FullPath)) mkdir($obj->FullPath);
				chmod($obj->FullPath, Filesystem::$file_create_mask);
			}

			// when creating a File record, the file should exist
			if(is_a($obj, 'File')) {
				// Create the directory.
				$dirPath = substr($obj->getFullPath(), 0, strrpos($obj->getFullPath(), '/'));
				@mkdir($dirPath, 0777, true);

				// Make sure the file exists.
				touch($obj->FullPath);

				// if there is a dummy file of the same name in a testdata dir, put it's contents into the newly created assets path
				// @todo what priority do we set if test files are found in modules versus project code in mysite?
				$result = glob(sprintf(BASE_PATH . '/*/testdata/files/%s', $obj->Name));
				if($result) file_put_contents($obj->FullPath, file_get_contents($result[0]));

				chmod($obj->FullPath, Filesystem::$file_create_mask);
			}

			// has to happen before relations in case a class is referring to itself
			$this->fixtureDictionary[$dataClass][$identifier] = $obj->ID;
			
			$this->attemptPublish($obj);

			// Increment the version on the tag so we can find the old unused records afterwards.
			if ($tag) {
				$tag->Version = $this->latestVersion + 1;
				$tag->write();
			}
			else {
				$tag = $model->TestDataTag->newObject();
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

	/**
	 * Populate relations for items of the dataClass (code moved from writeDataObject).
	 */
	protected function writeRelations($dataClass, $items) {
		$testDataTag = basename($this->fixtureFile);

		foreach($items as $identifier => $fields) {
			$tag = $this->getTag($testDataTag, $dataClass, $identifier);

			$obj = null;
			if ($tag) {
				$obj = $tag->getObject();
			}

			if (!$obj) {
				Controller::curr()->message("<br>(Could not find $dataClass::$identifier for relation updates, skipping)");
				continue;
			}
			
			// Populate all relations
			if($fields) foreach($fields as $fieldName => $fieldVal) {
				if($obj->many_many($fieldName) || $obj->has_many($fieldName)) {
					$parsedItems = array();
					$items = preg_split('/ *, */',trim($fieldVal));
					foreach($items as $item) {
						$parsedItems[] = $this->parseFixtureVal($item);
					}
					if($obj->has_many($fieldName)) {
						$obj->getComponents($fieldName)->setByIDList($parsedItems);
					} elseif($obj->many_many($fieldName)) {
						$obj->getManyManyComponents($fieldName)->setByIDList($parsedItems);
					}
				} elseif($obj->has_one($fieldName)) {
					if ($fieldName=='Parent' && $obj->URLSegment) {
						// If we are changing the parent, nullify the URLSegment so the system can get rid of suffixes.
						$obj->URLSegment = null;
					}

					$obj->{$fieldName . 'ID'} = $this->parseFixtureVal($fieldVal);
					$obj->write();
					$this->attemptPublish($obj);
				}
			}
		}
	}

	/**
	 * Copied from former YamlFixture.
	 *
	 * @TODO rewrite the whole module to use new API
	 */
	protected function parseFixtureVal($fieldVal) {
		// Parse a dictionary reference - used to set foreign keys
		if(substr($fieldVal,0,2) == '=>') {
			list($a, $b) = explode('.', substr($fieldVal,2), 2);
			return $this->fixtureDictionary[$a][$b];

			// Regular field value setting
		} else {
			return $fieldVal;
		}
	}
}

<?php


class TestDataTag extends DataObject {
	static $db = array(
		'Class' => 'Varchar',
		'RecordID' => 'Int',
		'FixtureFile' => 'Varchar',
		'FixtureID' => 'Varchar',
		'Version' => 'Int'
	);

	function getObject() {
		return DataObject::get_by_id($this->Class, $this->RecordID, false);
	}
}

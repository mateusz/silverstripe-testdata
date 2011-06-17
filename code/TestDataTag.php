<?php


class TestDataTag extends DataObject {
	static $db = array(
		'Class' => 'Varchar',
		'RecordID' => 'Int',
		'FixtureFile' => 'Varchar',
		'FixtureID' => 'Varchar',
		'Version' => 'Int'
	);
}

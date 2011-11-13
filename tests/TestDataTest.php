<?php
class TestDataTest extends FunctionalTest {
	static $fixture_file = 'testdata/tests/Baseline.yml';

	public $envType;

	protected $extraDataObjects = array(
		'TestDataTest_CircularOne',
		'TestDataTest_CircularTwo'
	);

	function setUp() {
		parent::setUp();
		TestDataController::$data_dir = 'testdata/tests';
		$this->envType = Director::get_environment_type();
		Director::set_environment_type('test');
		TestDataController::$quiet = true;
	}

	function tearDown() {
		$base = BASE_PATH;
		unlink("$base/testdata/tests/exec.yml");

		Director::set_environment_type($this->envType);
	}

	function testReset() {
		// Check that we don't delete exisiting records
		$this->get("dev/data/reset");
		$all = DataObject::get('SiteTree');
		$this->assertEquals($all->Count(), 1);

		// Check that it removes added records
		$base = BASE_PATH;
		copy("$base/testdata/tests/FirstRun.yml", "$base/testdata/tests/exec.yml");
		$this->get("dev/data/load/exec");
		$all = DataObject::get('SiteTree');

		$this->get("dev/data/reset");
		$all = DataObject::get('SiteTree');
		$this->assertEquals($all->Count(), 1);
		$this->assertEquals($all->First()->URLSegment, 'baseline');
	}

	function testRun() {
		$base = BASE_PATH;
		copy("$base/testdata/tests/FirstRun.yml", "$base/testdata/tests/exec.yml");
		$this->get("dev/data/load/exec");

		// Check that addition occurs
		copy("$base/testdata/tests/AdditionRun.yml", "$base/testdata/tests/exec.yml");
		$this->get("dev/data/load/exec");

		$all = DataObject::get('SiteTree');
		$this->assertEquals($all->Count(), 3);
		$this->assertDOSEquals(array(
			array('URLSegment'=>'firstrun'),
			array('URLSegment'=>'additionrun'),
			array('URLSegment'=>'baseline')
		), $all);
		
		// Check that records can be updated
		copy("$base/testdata/tests/UpdateRun.yml", "$base/testdata/tests/exec.yml");
		$this->get("dev/data/load/exec");

		$all = DataObject::get('SiteTree');
		$this->assertEquals($all->Count(), 3);
		$this->assertDOSEquals(array(
			array('URLSegment'=>'firstrun'),
			array('URLSegment'=>'updaterun', 'Title'=>'updaterun'),
			array('URLSegment'=>'baseline')
		), $all);

		// Check records can be removed
		copy("$base/testdata/tests/RemovalRun.yml", "$base/testdata/tests/exec.yml");
		$this->get("dev/data/load/exec");

		$all = DataObject::get('SiteTree');
		$this->assertEquals($all->Count(), 2);
		$this->assertDOSEquals(array(
			array('URLSegment'=>'additionrun'),
			array('URLSegment'=>'baseline')
		), $all);
	}

	function testCircularReferences() {
		$base = BASE_PATH;
		copy("$base/testdata/tests/Circular.yml", "$base/testdata/tests/exec.yml");
		$this->get("dev/data/load/exec");

		$circularOne = DataObject::get_one("TestDataTest_CircularOne");
		$circularTwo = DataObject::get_one("TestDataTest_CircularTwo");

		$this->assertEquals($circularOne->HasOneID, $circularTwo->ID, "Circular references in has_one work");
		$this->assertEquals($circularOne->ID, $circularTwo->HasOneID, "Circular references in has_one work");

		$this->assertEquals($circularOne->HasMany()->First()->ID, $circularTwo->ID, "Circular references in has_many work");
		$this->assertEquals($circularOne->ID, $circularTwo->HasMany()->First()->ID, "Circular references in has_many work");

		$this->assertEquals($circularOne->ManyMany()->First()->ID, $circularTwo->ID, "Circular references in many_many work");
		$this->assertEquals($circularOne->ID, $circularTwo->ManyMany()->First()->ID, "Circular references in many_many work");
	}
}

class TestDataTest_CircularOne extends DataObject implements TestOnly {
	static $db = array(
		"Name" => "Varchar"
	);
	static $has_one = array(
		"HasOne" => "TestDataTest_CircularTwo",
	); 
	static $has_many = array(
		"HasMany" => "TestDataTest_CircularTwo"
	);
	static $many_many = array(
		"ManyMany" => "TestDataTest_CircularTwo"
	);
}

class TestDataTest_CircularTwo extends DataObject implements TestOnly {
	static $db = array(
		"Name" => "Varchar"
	);
	static $has_one = array(
		"HasOne" => "TestDataTest_CircularOne",
	); 
	static $has_many = array(
		"HasMany" => "TestDataTest_CircularOne"
	);
	static $belongs_many_many = array(
		"ManyMany" => "TestDataTest_CircularOne"
	);
}

<?php
class TestDataTest extends FunctionalTest {
	static $fixture_file = 'testdata/tests/baseline.yml';

	public $envType;

	function setUp() {
		parent::setUp();
		TestDataController::$data_dir = 'testdata/tests';
		$this->envType = Director::get_environment_type();
		Director::set_environment_type('test');
		TestDataController::$quiet = true;
	}

	function tearDown() {
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
}

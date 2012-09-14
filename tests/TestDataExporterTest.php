<?php

class TestDataExporterTest extends SapphireTest {
	static $fixture_file = 'testdata/tests/TestDataExporterTest.yml';
	
	function testObjectPresent() {
		$exporter = new TestDataExporter();
		
		$page1 = new Page();
		$page1->ID = 5;
		$page2 = new Page();
		$page2->ID = 6;
		$otherPage = new Page();
		$otherPage->ID = 4;

		$buckets = array('Page'=>array('5'=>$page1));
		$queue = array($page2);

		$this->assertFalse($exporter->objectPresent($otherPage, $buckets, $queue));
		$this->assertFalse($exporter->objectPresent($page2, $buckets));
		$this->assertTrue($exporter->objectPresent($page1, $buckets, $queue));
		$this->assertTrue($exporter->objectPresent($page2, $buckets, $queue));
	}

	function testTraverseRelations() {
		$exporter = new TestDataExporter();

		$pageParent = new Page();
		$pageParent->ID = 1;
		$pageParent->write();

		$pageChild = new Page();
		$pageChild->ID = 2;
		$pageChild->ParentID = 1;
		$pageChild->write();

		$queue = array();
		$buckets = array();
		$exporter->traverseRelations($pageChild, $buckets, $queue);

		$this->assertEquals($queue[0]->ID, 1, "Finds the Parent() and adds it to the queue for further processing");
	}

	function testGenerateYML() {
		$exporter = new TestDataExporter();

		$page1 = $this->objFromFixture('Page', 'page1');
		$page1->YMLHandle = 'page1ymlhandle';
		$page2 = $this->objFromFixture('Page', 'page2');
		$page2->YMLHandle = 'page2ymlhandle';

		// Fake buckets
		$buckets = array(
			'Page'=>array($page1->ID=>$page1, $page2->ID=>$page2)
		);

		$yml = $exporter->generateYML($page2, $buckets, "Correctly export yml and includes parent relation");
		$this->assertContains('Parent: =>Page.page1ymlhandle', $yml);
		$this->assertContains('Content: "'.$page2->Content.'"', $yml);

		$yml = $exporter->generateYML($page1, $buckets, "Correctly exports multi line values");
		$this->assertContains("Content: |\n\t\t\tMulti\n\t\t\tLine", $yml);
	}

	function testGenerateHandle() {
		$exporter = new TestDataExporter();

		$page = new Page();
		$page->ID = 7;

		$this->assertEquals($exporter->generateHandle($page), 'Page7', "Generates hadle from ID and class");
	}

	function testGenerateIDs() {
		$exporter = new TestDataExporter();

		$this->assertEquals('("SiteTree"."ID"=\'1\') OR ("SiteTree"."ID" BETWEEN \'2\' AND \'3\')', $exporter->generateIDs('1,2-3', 'Page'));
	}
}

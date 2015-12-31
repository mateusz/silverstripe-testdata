<?php

class TestDataExporterTest extends SapphireTest
{
    public static $fixture_file = 'testdata/tests/TestDataExporterTest.yml';

    public function testObjectPresent()
    {
        $exporter = new TestDataExporter();
        
        $page1 = new Page();
        $page1->YMLTag = 'Page1';
        $page2 = new Page();
        $page2->YMLTag = 'Page2';
        $otherPage = new Page();
        $otherPage->YMLTag = 'Page3';

        $buckets = array('Page'=>array('Page1'=>$page1));
        $queue = array($page2);

        $this->assertFalse($exporter->objectPresent($otherPage, $buckets, $queue));
        $this->assertFalse($exporter->objectPresent($page2, $buckets));
        $this->assertTrue($exporter->objectPresent($page1, $buckets, $queue));
        $this->assertTrue($exporter->objectPresent($page2, $buckets, $queue));
    }

    public function testTraverseRelations()
    {
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

    public function testGenerateYML()
    {
        $exporter = new TestDataExporter();
        $exporter->currentFixtureFile = 'output.yml';

        $page1 = $this->objFromFixture('Page', 'page1');
        $exporter->assureHasTag($page1); // Will take first free number - "Page1"
        $page2 = $this->objFromFixture('Page', 'page2');
        $exporter->assureHasTag($page2); // "Page2"

        // Fake buckets
        $buckets = array(
            'Page'=>array($page1->YMLTag=>$page1, $page2->YMLTag=>$page2)
        );

        $yml = $exporter->generateYML($page2, $buckets, "Correctly export yml and includes parent relation");
        $this->assertContains('Parent: =>Page.Page1', $yml);
        $this->assertContains('Content: "'.$page2->Content.'"', $yml);

        $yml = $exporter->generateYML($page1, $buckets, "Correctly exports multi line values");
        $this->assertContains("Content: |\n\t\t\tMulti\n\t\t\tLine", $yml);
    }

    public function testGetTagNew()
    {
        $exporter = new TestDataExporter();

        $page = new Page();
        $page->ID = 7;

        $this->assertEquals($exporter->getTag($page), 'Page1', "Generates tag as '<class>1' if no TestDataTags are there.");
    }

    public function testGetTagNewWithExisting()
    {
        $exporter = new TestDataExporter();
        $exporter->currentFixtureFile = 'output.yml';

        // Fake some previous tag.
        $testdatatag = new TestDataTag();
        $testdatatag->Class = 'Page';
        $testdatatag->RecordID = '123';
        $testdatatag->FixtureID = 'Page100';
        $testdatatag->FixtureFile = 'output.yml';
        $testdatatag->Version = 1;
        $testdatatag->write();

        $page = new Page();
        $page->ID = 7;

        $this->assertEquals($exporter->getTag($page), 'Page101', "Generates tag from class and next available number.");
    }

    public function testGetTagOldWithExisting()
    {
        $exporter = new TestDataExporter();
        $exporter->currentFixtureFile = 'output.yml';

        // Fake some previous tag.
        $testdatatag = new TestDataTag();
        $testdatatag->Class = 'Page';
        $testdatatag->RecordID = '123';
        $testdatatag->FixtureID = 'Page100';
        $testdatatag->FixtureFile = 'output.yml';
        $testdatatag->Version = 1;
        $testdatatag->write();

        $page = new Page();
        $page->ID = 123;

        $this->assertEquals($exporter->getTag($page), 'Page100', "Provides exsiting tag if record already in TestDataTag table.");
    }

    public function testGenerateIDs()
    {
        $exporter = new TestDataExporter();

        $this->assertEquals('("SiteTree"."ID"=\'1\') OR ("SiteTree"."ID" BETWEEN \'2\' AND \'3\')', $exporter->generateIDs('1,2-3', 'Page'));
    }
}

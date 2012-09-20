# TestData module

## Maintainer 

[Mateusz Uzdowski](mailto:mateusz@silverstripe.com)

## Requirements 

SilverStripe 3 (master)
SilverStripe 2.4.x (branch 2.4)

## Changelog

2.1
* Added TestDataExporter

2.0
* 3.0-compatible release

1.0
* 2.4-compatible release.

## Installation 

1. Include the module folder in your project root folder and rename it to "testdata"
1. Flush the manifest (?flush=1)

## Features

- Allows easy injection of the test data via Yaml files
- Data is added *on top* of the current database
- The test data can then be added, updated or removed
- Test data can be broken down into chunks, separate Yaml files are allowed and can be added on the fly
- Original YamlFixture class is overriden to allow for circular references within yml files
- Creates dummy data files like images (either empty, or content copied from file with matching name)
- (New) Exporter for automatically building your yml files - just select classes & IDs and they will be exported automatically (only on master).

## Usage

You can obtain the help message by calling the following:

	framework/sake dev/data

You can also access the module via your browser. Log in as admin and make sure your site is in the dev mode. Then visit:

	<site_url>/dev/data

To invoke exporter GUI visit:

	<site_url>/dev/data/export


### Basic workflow

TestData Yaml files should be added <wwwroot>/mysite/testdata directory. The names are case insensitive and they need to have *.yml* extension. The format of these files is the same as for SapphireTest, so you can freely copy them between one and the other.

Let's start with something simple. In the data directory, create a file named *media.yml*:

	Page:
		page:
			Title: Media Releases
		release1:
			Title: Double rainbow seen over Wellington
			Parent: =>Page.page

To add this data, run:

	dev/data/load/media

Then if you'd like to add some modifications, you can edit the file:

	Page:
		page:
			Title: Media Releases
		release1:
			Title: Triple rainbow seen over Wellington
			Content: Really! Saw it with me own eyes!
			Parent: =>Page.page
		release2:
			Title: The claim about double rainbow is a hoax
			Parent: =>Page.page

To update the data, run the command again:

	dev/data/load/media

When you are done, you can remove all data by using:

	dev/data/reset

And that's basically it.

### Dummy files

The files will be automatically created (touched) by the module so they are at least available.

If you place a file in <wwwroot>/mysite/testdata/files with the same name as the one in your dummy fixture, the content will be automatically copied to the newly created file.

### Exporter

Exporter has been designed to easily build a human-editable database dump files. It will introspect the selected classes and pull them out into the Yaml. Exporter always operates on draft stage, to reflect what is being seen in the CMS. The loader on the other hand will always publish the incoming data into live, so as a result the live stage data is ignored completely for Testdata module's purposes. The file will be written to the data directory, usually <wwwroot>/mysite/testdata.

Caveat programmer! Exporter will store all files in the same directory, regardless of their path, which means that if the name collision occurs the files will be overwritten.

Class selection is done by ticking the checkboxes next to the class names. The number in the brackets gives you the number of available objects, and the box on the right allows you to select IDs. Valid selections are:
* Explicit numbers: 1,2,3
* Range: 1-10
* Mix of both: 1,2,4-7,5

Exporter will also (if requested) follow relationships and include objects on the other side of the link. For example selecting a Member will also include the Groups he/she is in, which in turn will include Permissions. Or if explicit ID is being requested, for example Page with ID=2, it will also pull the page's parent, even if its ID is not contained on the requested list. This allows easy construction of comprehensive data sets.



## Dev notes

1. Remove the `TestDataTag` table from your database when going to production. This will prevent removing live data by mistake.
1. The records are tracked on the basis of the Yaml filename and the Yaml handle, so you need to be aware that if you change any of these, TestData will think that you have added new records.
1. Because of the above, you can update test records as long as you retain the filename and the Yaml handle. Their IDs will be retained, so your relations will stay intact.
1. TestData will automatically publish versioned objects to Live, and remove them from both stages, so you don't have to do it manually.
1. From the project perspective, we find it useful to keep a dummy.yml with data used for dev and testing, so everyone is at least using the same baseline. Apart from that, ia.yml is useful for keeping a clean IA for initial loading to the production server and for the client.
1. Exporter output files contain some metadata on their last 3 lines to make the debugging easier.

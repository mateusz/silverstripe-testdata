# Polls module

## Maintainer 

[Mateusz Uzdowski](mailto:mateusz@silverstripe.com)

## Requirements 

SilverStripe 2.4.x

## Installation 

1. Include the module folder in your project root folder and rename it to "testdata"
1. Flush the manifest (?flush=1)

## Features

- Allows easy injection of the testdata via Yaml files
- Data is added *on top* of the current database 
- The test data can be updated, removed and added
- Test data can be broken down into chunks, separate Yaml files are allowed and can be added on the fly

## Usage

You can obtain the help message by calling the following:

	sapphire/sake dev/data

You can also access the module via your browser. Log in as admin and make sure your site is in the dev mode. Then visit:

	<your_webroot>/dev/data

### Preparing your files

Testdata Yaml files should be added <wwwroot>/testdata/data directory. The names are case insensitive and they need to have *.yml* extension. The format of these files is the same as for SapphireTest, so you can freely copy them between one and the other.

## Dev notes

1. Do not use this functionality on live.
1. The records are tracked on the basis of the Yaml filename and the Yaml handle, so you need to be aware that if you change any of these, testdata will think that you have added new records.
1. Because of the above, you can update test records as long as you retain the filename and the Yaml handle.

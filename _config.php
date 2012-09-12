<?php

Director::addRules(100, array('dev/data/export//$Action/$ID/$OtherID' => 'TestDataExporter'));
Director::addRules(100, array('dev/data//$Action/$ID/$OtherID' => 'TestDataController'));

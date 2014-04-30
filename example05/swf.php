<?php

require '../flaczki/classes.php';

$files = scandir("originals");
foreach($files as $file) {
	if(preg_match('/\.swf/', $file)) {
		$swfFiles["originals/$file"] = "images/$file";
	}
}

$config = array(
	'swf-files' => $swfFiles,
	'data-modules'	=> array(
		array(
			'name' => "FloogleDocs",				// name of data module
			'url' => "https://docs.google.com/document/d/1BjvPCVvsl1ozujexHMkcYIoYn06dkaisSc5zQaxROfU/edit",					// url from GoogleDocs
			'device-fonts' => array(),			// device fonts that can be used
			'use-any-embedded-font' => true,		// any font embedded in SWF can be used
			'maintain-font-size' => false,			// adjust font size to match original design
			'ignore-autogenerated' => true,			// don't export contents from text fields without a name
			'ignore-point-text' => false,			// don't export contents from point text fields
		),
	),
	'update-interval' => 1,						// how often the SWF file should be updated
	'background-update' => false,					// send old version to a visitor while the SWF is being updated
	'maximum-stale-interval' => 60 * 60 * 24,			// make a visitor wait instead of sending an old version if file is older than this parameter
);

SWFGenerator::run($config);

?>
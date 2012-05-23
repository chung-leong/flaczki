<?php

require 'flaczki/classes.php';

$config = array(
	'swf-files' => array(
			"example.original.swf" => "example.swf"		// source and destination SWF files
	),
	'data-modules'	=> array(
		array(
			'name' => "FloogleDocs",
			'url' => "https://docs.google.com/document/d/144Kj1VfsPi60slwW58Qw7R3QRc02MXqBVr-L5Jqq0bg/edit",	// url from GoogleDocs
		),
	),
	'update-interval' => 1,						// how often the SWF file should be updated
	'background-update' => false,					// send old version to a visitor while the SWF is being updated
	'maximum-stale-interval' => 60 * 60 * 24,			// make a visitor wait instead of sending an old version if file is older than this parameter
);

SWFGenerator::run($config);

?>
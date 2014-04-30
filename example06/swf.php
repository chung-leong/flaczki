<?php

require '../flaczki/classes.php';

$config = array(
	'swf-files' => array(
		"cube3d.swf" => "cube3d.new.swf"			// source and destination SWF files
	),
	'data-modules'	=> array(
		array(
			'name' => "FlopBox",				// name of data module
			'url' => "http://dl.dropbox.com/u/80776822/cube3d_images.zip",					// url at DropBox
			'update-type' => 'image'
		),
	),
	'update-interval' => 60 * 60,					// how often the SWF file should be updated
	'background-update' => true,					// send old version to a visitor while the SWF is being updated
	'maximum-stale-interval' => 60 * 60 * 24,			// make a visitor wait instead of sending an old version if file is older than this parameter
);

SWFGenerator::run($config);

?>
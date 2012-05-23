<?php

require '../../flaczki/classes.php';

$config = array(
	'swf-files' => array(
		"../Cities of Poland.swf" => "Cities of Poland.swf"	// source and destination SWF files
	),
	'data-modules'	=> array(
		array(
			'name' => "FloogleDocs",
			'url' => "https://docs.google.com/document/d/1yjoYUq2ZeQOZz2Knroqa2P3FDyf-SF4qutmi9gexe2o/edit"		// url from GoogleDocs
		),
	),
	'update-interval' => 60 * 60,					// how often the SWF file should be updated
	'background-update' => false,					// send old version to a visitor while the SWF is being updated
	'maximum-stale-interval' => 60 * 60 * 24,			// make a visitor wait instead of sending an old version if file is older than this parameter	
);

SWFGenerator::run($config);

?>
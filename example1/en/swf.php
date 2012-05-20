<?php

require '../../flaczki/classes.php';

$config = array(
	'destination' => "./",
	'swf-files' => array("../Cities of Poland.swf"),
	'update-interval' => 60 * 60,
	'deferred-update' => true,
	'deferred-update-maximum-interval' => 60 * 60 * 24,
	'data-modules'	=> array(
		array(
			'name' => "FloogleDocs",
			'url' => "https://docs.google.com/document/d/1yjoYUq2ZeQOZz2Knroqa2P3FDyf-SF4qutmi9gexe2o/edit"
		),
	)
);

SWFGenerator::run($config);

?>
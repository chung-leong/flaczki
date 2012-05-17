<?php

require 'flaczki/classes.php';

$config = array(
	'destination' => "swf",
	'swf-files' => array("example.swf"),
	'update-interval' => 1,
	'deferred-update' => false,
	'deferred-update-maximum-interval' => 60 * 60 * 24,
	'data-modules'	=> array(
		"FloogleDocs" => array(
			'url' => "https://docs.google.com/document/d/144Kj1VfsPi60slwW58Qw7R3QRc02MXqBVr-L5Jqq0bg/edit"
		),
	)
);

SWFGenerator::run($config);

?>
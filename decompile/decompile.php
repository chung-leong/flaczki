<html>
<head>
<title>ActionScript Decompiler</title>
<link rel="stylesheet" type="text/css" href="decompile.css" />
</head>
<body>
<?php

error_reporting(E_ALL & ~E_NOTICE);
require_once '../flaczki/classes.php';

if(isset($_FILES['file'])) {
	$path = $_FILES['file']['tmp_name'];
} else if(isset($_POST['url'])) {
	$path = $_POST['url'];
}

if($path) {
	$input = fopen($path, "rb");
	if($input) {
		$parser = new SWFParser;
		$dumper = new ASSourceCodeDumper;
		$swfFile = $parser->parse($input, $dumper->getRequiredTags(), true);
		fclose($input);
		if($swfFile) {
			$dumper->dump($swfFile);
		} else {
			echo "Error parsing $path";
		}
	} else {
		echo "Error opening $path";
	}
}

?>
</body>
</html>
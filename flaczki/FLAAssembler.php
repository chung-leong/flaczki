<?php

class FLAAssembler {

	protected $folderPath;
	
	public function assemble($output, $flaFile) {
		if(gettype($output) == 'string') {
			// output to folder
			$this->folderPath = $output;
		} else if(gettype($output) == 'resource') {
			// output to zip file
			$this->folderPath = StreamZipArchive::create($output);
		} else {
			throw new Exception("Invalid output");
		}
		
		$this->writeXMLFile("php://output", $flaFile->document);
	}
	
	protected function writeXMLFile($path, $rootNode) {
		$stream = fopen($path, "wb");
		$this->writeXMLNode($stream, $rootNode, true);
		fclose($stream);
	}
	
	protected function writeXMLNode($stream, $node, $addNS = false) {
		$nodeName = substr(get_class($node), 3);
		$s = "<$nodeName";
		if($addNS) {
			$s .= ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"';
			$s .= ' xmlns="http://ns.adobe.com/xfl/2008/"';
		}
		$hasChildren = false;
		foreach($node as $name => $value) {
			if($value !== null) {
				if(is_scalar($value)) {
					static $entities = array('"' => '&quot;', "'" => '&apos;', '&' => '&amp;', '<' => '&lt;', '>' => '&gt;');
					$s .= ' ' . $name . '="' . strtr($value, $entities) . '"';
				} else {
					$hasChildren = true;
				}
			}
		}
		if($hasChildren) {
			$s .= ">\n";
		} else {
			$s .= "/>\n";
		}
		fwrite($stream, $s);
		
		if($hasChildren) {
			foreach($node as $name => $value) {
				if($value !== null) {
					if(is_array($value)) {
						if(count($value)) {
							fwrite($stream, "<$name>\n");
							foreach($value as $child) {
								$this->writeXMLNode($stream, $child);
							}
							fwrite($stream, "</$name>\n");
						}
					} else if(is_object($value)) {
						$this->writeXMLNode($stream, $value);
					}
				}
			}
			fwrite($stream, "</$nodeName>\n");
		}
	}
}

?>
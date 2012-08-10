<?php

class FLAAssembler {

	protected $folderPath;
	
	public function assemble($output, $movieName, $flaFile) {
		if(gettype($output) == 'string') {
			// output to folder
			$this->folderPath = $output;
		} else if(gettype($output) == 'resource') {
			// output to zip file
			$this->folderPath = StreamZipArchive::create($output);
		} else {
			throw new Exception("Invalid output");
		}
		
		
		
		$this->writeXMLFile("{$this->folderPath}/DOMDocument.xml", $flaFile->document);
		if($flaFile->metadata) {
			$this->writeFile("{$this->folderPath}/META-INF/metadata.xml", $flaFile->metadata);
		}
		$this->writeFile("{$this->folderPath}/{$movieName}.xfl", "PROXY-CS5");
		
		foreach($flaFile->library as $href => $item) {
			if($item instanceof FLABitmap || $item instanceof FLAVideo) {
				$this->writeFile("$this->folderPath/LIBRARY/$href", $item->data);
			} else if($item instanceof FLADOMSymbolItem) {
				$this->writeXMLFile("$this->folderPath/LIBRARY/$href", $item);
			}
		}		
	}
	
	protected function createFolder($path) {
		if($this->createParentFolder($path)) {
			return mkdir($path);
		} else {
			return false;
		}
	}
	
	protected function createParentFolder($path) {
		$parentPath = dirname($path);
		if(!file_exists($parentPath)) {
			if(!preg_match('/^\w+:$/', $parentPath)) {
				return $this->createFolder($parentPath);
			} else {
				return false;
			}
		} else {
			return true;
		}
	}
	
	protected function writeXMLFile($path, $rootNode) {
		if($this->createParentFolder($path)) {
			$stream = fopen($path, "wb");
			$this->writeXMLNode($stream, $rootNode, 0, true);
			fclose($stream);
		}
	}
	
	protected function writeFile($path, $data) {
		if($this->createParentFolder($path)) {
			$stream = fopen($path, "wb");
			fwrite($stream, $data);
			fclose($stream);
		}
	}
	
	protected function writeXMLNode($stream, $node, $depth, $addNS = false) {
		if($depth > 100) {
			echo "RECURSION";
			exit;
		}
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
		if($depth) {
			fwrite($stream, str_repeat("  ", $depth));
		}
		fwrite($stream, $s);
		
		if($hasChildren) {
			foreach($node as $name => $value) {
				if($value !== null) {
					if(is_array($value)) {
						if(count($value)) {
							fwrite($stream, str_repeat("  ", $depth + 1));
							fwrite($stream, "<$name>\n");
							foreach($value as $child) {
								$this->writeXMLNode($stream, $child, $depth + 2);
							}
							fwrite($stream, str_repeat("  ", $depth + 1));
							fwrite($stream, "</$name>\n");
						}
					} else if(is_object($value) && $value) {
						if(method_exists($value, 'write')) {
							fwrite($stream, str_repeat("  ", $depth + 1));
							$value->write($stream, $name);
							fwrite($stream, "\n");
						} else {
							$this->writeXMLNode($stream, $value, $depth + 1);
						}
					}
				}
			}
			if($depth) {
				fwrite($stream, str_repeat("  ", $depth));
			}
			fwrite($stream, "</$nodeName>\n");
		}
	}
}

?>
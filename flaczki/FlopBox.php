<?php

class FlopBox extends SWFGeneratorDataModule {

	protected $sourceUrl;
	protected $input;

	public function __construct($moduleConfig, &$persistentData) {
		parent::__construct($moduleConfig, $persistentData);
		$this->sourceUrl = isset($moduleConfig['url']) ? trim($moduleConfig['url']) : null;
	}

	public function startTransfer() {
		if($this->sourceUrl) {
			$this->input = fopen($this->sourceUrl, "rb");
		}
		return ($this->input) ? true : false;
	}
	
	public function checkChanges() {
		$key = "FlopBox:{$this->sourceUrl}";
		$fileInfo = isset($this->persistentData[$key]) ? $this->persistentData[$key] : null;
		if($fileInfo) {
			$previousETag = $fileInfo['etag'];
			$previousSize = $fileInfo['size'];
			
			// perform a HEAD operation on the URL
			$context  = stream_context_create(array('http' => array('method' => 'HEAD')));
			$this->input = fopen($this->sourceUrl, 'rb', false, $context);
			if($this->input) {
				// doing a fread() to force the cURL wrapper to get the headers
				fread($this->input, 4);
				$metadata = $this->getMetadata();
				fclose($this->input);
				if($previousETag == $metadata->eTag && $previousSize == $metadata->size) {
					return false;
				}
			}
		}
		return true;
	}
	
	protected function getMetadata() {
		$metadata = new FlopBoxFileMetadata;
		$httpMetadata = stream_get_meta_data($this->input);
		foreach($httpMetadata['wrapper_data'] as $header) {
			if(preg_match('/Content-length:\s*(\d+)/i', $header, $m)) {
				$metadata->size = (int) $m[1];
			} else if(preg_match('/Etag:\s*(\S+)/i', $header, $m)) {
				$metadata->eTag = $m[1];
			} else if(preg_match('/Content-Type:\s*(\S+)/i', $header, $m)) {
				$metadata->mimeType = $m[1];
			}
		}
		return $metadata;
	}

	public function updateText($textObjects, $fontFamilies) {
		if($this->input) {
			// save etag and size
			$metadata = $this->getMetadata();
			if($metadata->eTag && $metadata->size) {
				$key = "FlopBox:{$this->sourceUrl}";
				$this->persistentData[$key] = array('etag' => $metadata->eTag, 'size' => $metadata->size);
			}
		
			if(list($parserClass, $updaterClass) = $this->getClassNames()) {
				// parse the document 
				$parser = new $parserClass;
				$document = $parser->parse($this->input);
				fclose($this->input);
		
				// update the text objects
				$updater = new $updaterClass($document);
				$updater->setPolicy(SWFTextObjectUpdater::ALLOWED_DEVICE_FONTS, $this->allowedDeviceFonts);
				$updater->setPolicy(SWFTextObjectUpdater::MAINTAIN_ORIGINAL_FONT_SIZE, $this->maintainOriginalFontSize);
				$updater->setPolicy(SWFTextObjectUpdater::ALLOW_ANY_EMBEDDED_FONT, $this->allowAnyEmbeddedFont);
				$changes = $updater->update($textObjects, $fontFamilies);
				return $changes;
			}
		}
		return array();
	}
	
	protected function getClassNames() {
		if(preg_match('/\.docx$/i', $this->sourceUrl)) {
			return array('DOCXParser', 'SWFTextObjectUpdaterDOCX');
		} else if(preg_match('/\.odt$/i', $this->sourceUrl)) {
		 	return array('ODTParser', 'SWFTextObjectUpdaterODT');
		}
	}
	
	public function validate() {
		if($this->sourceUrl) {
			echo "<div class='subsection-ok'><b>Supplied URL:</b> {$this->sourceUrl}</div>";
			flush();
			$startTime = microtime(true);
			$this->startTransfer();
			if($this->input) {
				$metadata = $this->getMetadata();
				if(list($parserClass, $updaterClass) = $this->getClassNames()) {
					$parser = new $parserClass;
					$document = $parser->parse($this->input);
					
					if($document) {
						$updater = new $updaterClass($document);
						$sections = $updater->getSections();
						$sectionCount = count($sections);
						if($sectionCount) {
							$descriptions = array();
							foreach($sections as $section) {
								$description = "\"{$section->title}\"";
								$descriptions[] = $description;							
							}
							$descriptions = implode(', ', $descriptions);
							echo "<div class='subsection-ok'><b>Text sections ($sectionCount): </b> $descriptions</div>";
						} else {
							echo "<div class='subsection-err'><b>Text sections ($sectionCount): </b></div>";
						}
					} else {
						echo "<div class='subsection-err' style='text-align: center'><em>(errors encountered reading document)</em></div>";
					}
					$endTime = microtime(true);
					$duration = sprintf("%0.4f", $endTime - $startTime);
					echo "<div class='subsection-ok'><b>Process time: </b> $duration second(s)</div>";
				} else {
					echo "<div class='subsection-err' style='text-align: center'><em>(unrecognized file type)</em></div>";
				}				
				fclose($this->input);
			} else {
				echo "<div class='subsection-err' style='text-align: center'><em>(cannot download document)</em></div>";
			}
		} else {
			echo "<div class='subsection-err'><b>Supplied URL:</b> <em>(none)</em></div>";
		}
	}
	
	public function getExportType() {
		return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
	}
	
	public function getExportFileName() {
		return 'FlopBox.docx';
	}	
	
	public function export(&$output, $textObjects, $fontFamilies) {
		// export the text into an ODTDocument object
		$exporter = new SWFTextObjectExporterDOCX;
		$exporter->setPolicy(SWFTextObjectExporter::IGNORE_AUTOGENERATED, $this->ignoreAutogenerated);
		$exporter->setPolicy(SWFTextObjectExporter::IGNORE_POINT_TEXT, $this->ignorePointText);
		$document = $exporter->export($textObjects, $fontFamilies);

		// assemble it into a ODT file
		$assembler = new DOCXAssembler;
		$assembler->assemble($output, $document);
	}
	
	public function getRequiredPHPExtensions() {
		return array('PCRE', 'XML', 'Zlib');
	}
	
	public function getModuleSpecificParameters() {
		return array('dropbox' => "Redirect to document at DropBox");
	}
	
	public function runModuleSpecificOperation($parameters) {
		if(isset($parameters['dropbox'])) {
			$format = $parameters['dropbox'];
			$this->redirect($this->sourceUrl);
			return true;
		}
		return false;
	}
	
	protected function redirect($url) {
		header("Location: $url");
	}	
}

class FlopBoxFileMetadata {
	public $eTag;
	public $size;
	public $mimeType;
}

?>
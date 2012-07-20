<?php

class FlopBox extends SWFGeneratorDataModule {

	protected $sourceUrl;
	protected $sourceType;
	protected $updateType;
	protected $input;
	protected $textUpdater;
	protected $imageUpdater;

	public function __construct($moduleConfig, &$persistentData) {
		parent::__construct($moduleConfig, $persistentData);
		$this->sourceUrl = isset($moduleConfig['url']) ? trim($moduleConfig['url']) : null;
		$this->updateType = isset($moduleConfig['update-type']) ? $moduleConfig['update-type'] : 'text';
		if(preg_match('/\.docx$/i', $this->sourceUrl)) {
			$this->sourceType = 'DOCX';
		} else if(preg_match('/\.odt$/i', $this->sourceUrl)) {
			$this->sourceType = 'ODT';
		} else if(preg_match('/\.zip$/i', $this->sourceUrl)) {
			$this->sourceType = 'ZIP';
		} else {
			if(preg_match('/image/i', $this->updateType)) {
				$this->sourceType = 'ZIP';
			} else {
				$this->sourceType = 'DOCX';
			}
		}
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
	
	public function finishTransfer() {
		if($this->input) {
			// save etag and size
			$metadata = $this->getMetadata();
			if($metadata->eTag && $metadata->size) {
				$key = "FlopBox:{$this->sourceUrl}";
				$this->persistentData[$key] = array('etag' => $metadata->eTag, 'size' => $metadata->size);
			}
		
			return $this->processSourceDocument();
		}
		return false;
	}
	
	protected function getMetadata() {
		$metadata = new FlopBoxFileMetadata;
		$httpMetadata = stream_get_meta_data($this->input);
		if(isset($httpMetadata['wrapper_data'])) {
			foreach($httpMetadata['wrapper_data'] as $header) {
				if(preg_match('/Content-length:\s*(\d+)/i', $header, $m)) {
					$metadata->size = (int) $m[1];
				} else if(preg_match('/Etag:\s*(\S+)/i', $header, $m)) {
					$metadata->eTag = $m[1];
				} else if(preg_match('/Content-Type:\s*(\S+)/i', $header, $m)) {
					$metadata->mimeType = $m[1];
				}
			}
		}
		return $metadata;
	}

	public function update($assets) {
		if($this->textUpdater) {
			$changes = $this->textUpdater->update($assets);
			return $changes;
		}
		return array();
	}
	
	protected function processSourceDocument() {
		switch($this->sourceType) {
			case 'DOCX':
				$parser = new DOCXParser;
				$document = $parser->parse($this->input);
				if($document) {
					$this->textUpdater = new SWFTextObjectUpdaterDOCX($document);
				} 
				break;
			case 'ODT':
				$parser = new ODTParser;
				$document = $parser->parse($this->input);
				if($document) {
					$this->textUpdater = new SWFTextObjectUpdaterODT($document);
				} 
				break;
			case 'ZIP':
				$zipPath = StreamZipArchive::open($this->input);
				$this->imageUpdater = new SWFImageUpdaterFolder($zipPath);
				break;
		}
		if($this->textUpdater) {
			$this->textUpdater->setPolicy(SWFTextObjectUpdater::ALLOWED_DEVICE_FONTS, $this->allowedDeviceFonts);
			$this->textUpdater->setPolicy(SWFTextObjectUpdater::MAINTAIN_ORIGINAL_FONT_SIZE, $this->maintainOriginalFontSize);
			$this->textUpdater->setPolicy(SWFTextObjectUpdater::ALLOW_ANY_EMBEDDED_FONT, $this->allowAnyEmbeddedFont);
		}
		return ($this->textUpdater || $this->imageUpdater);
	}
	
	public function validate() {
		if($this->sourceUrl) {
			echo "<div class='subsection-ok'><b>Supplied URL:</b> {$this->sourceUrl}</div>";
			flush();
			$startTime = microtime(true);
			$this->startTransfer();
			if($this->input) {
				if($this->processSourceDocument()) {
					if($this->textUpdater || $this->imageUpdater) {
						if($this->textUpdater) {
							$sections = $this->textUpdater->getSectionNames();
							$sectionCount = count($sections);
							if($sectionCount) {
								$descriptions = array();
								foreach($sections as $section) {
									$descriptions[] = "\"$section\"";
								}
								$descriptions = implode(', ', $descriptions);
								echo "<div class='subsection-ok'><b>Text sections ($sectionCount): </b> $descriptions</div>";
							} else {
								echo "<div class='subsection-err'><b>Text sections ($sectionCount): </b></div>";
							}
						}
						if($this->imageUpdater) {
							$imageNames = $this->imageUpdater->getImageNames();
							$imageCount = count($imageNames);
							if($imageCount) {
								$descriptions = implode(', ', $imageNames);
								echo "<div class='subsection-ok'><b>Images ($imageCount): </b> $descriptions</div>";
							} else {
								echo "<div class='subsection-err'><b>Images ($imageCount): </b></div>";
							}
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
			} else {
				echo "<div class='subsection-err' style='text-align: center'><em>(cannot download document)</em></div>";
			}
		} else {
			echo "<div class='subsection-err'><b>Supplied URL:</b> <em>(none)</em></div>";
		}
	}
	
	public function getExportType() {
		switch($this->sourceType) {
			case 'DOCX': return 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
			case 'ODT': return 'application/vnd.oasis.opendocument.text';
			case 'ZIP': return 'application/zip';
		}
	}
	
	public function getExportFileName() {
		switch($this->sourceType) {
			case 'DOCX': return 'FlopBox.docx';
			case 'ODT': return 'FlopBox.odt';
			case 'ZIP': return 'FlopBoxImages.zip';
		}
	}	
	
	public function export(&$output, $assets) {
		switch($this->sourceType) {
			case 'DOCX': 
				// export the text into an DOCXDocument object
				$document = new DOCXDocument;
				$exporter = new SWFTextObjectExporterDOCX;
				$exporter->setPolicy(SWFTextObjectExporter::IGNORE_AUTOGENERATED, $this->ignoreAutogenerated);
				$exporter->setPolicy(SWFTextObjectExporter::IGNORE_POINT_TEXT, $this->ignorePointText);
				$exporter->export($document, $assets);
		
				// assemble it into a DOCX file
				$assembler = new DOCXAssembler;
				$assembler->assemble($output, $document);
				break;
			case 'ODT': 
				// export the text into an ODTDocument object
				$document = new ODTDocument;
				$exporter = new SWFTextObjectExporterODT;
				$exporter->setPolicy(SWFTextObjectExporter::IGNORE_AUTOGENERATED, $this->ignoreAutogenerated);
				$exporter->setPolicy(SWFTextObjectExporter::IGNORE_POINT_TEXT, $this->ignorePointText);
				$exporter->export($document, $assets);
		
				// assemble it into a ODT file
				$assembler = new ODTAssembler;
				$assembler->assemble($output, $document);
				break;
			case 'ZIP':
				// export images into a ZIP file
				$zipPath = StreamZipArchive::create($output);
				$exporter = new SWFImageExporterFolder;
				$exporter->export($zipPath, $assets);
				StreamZipArchive::close($zipPath);
				break;
		}
	
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
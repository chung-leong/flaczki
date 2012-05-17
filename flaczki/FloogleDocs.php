<?php

class FloogleDocs extends SWFGeneratorDataModule {

	protected $suppliedUrl;
	protected $sourceUrl;
	protected $input;

	public function __construct($moduleConfig) {
		// https://docs.google.com/document/d/[document id]/edit
		$this->suppliedUrl = isset($moduleConfig['url']) ? trim($moduleConfig['url']) : null;
		// https://docs.google.com/document/d/[document id]/export?format=odt
		$this->sourceUrl = preg_match('/(.*?)edit$/', $this->suppliedUrl, $m) ? "{$m[1]}export?format=odt" : $this->suppliedUrl;
	}

	public function startTransfer() {
		$this->input = fopen($this->sourceUrl, "rb");
	}

	public function updateText($textObjects, $fontFamilies) {
		if($this->input) {
			// parse the ODT file
			$parser = new ODTParser;
			$document = $parser->parse($this->input);
			fclose($this->input);
		
			// update the text objects
			$updater = new SWFTextObjectUpdaterODT($document);
			$changes = $updater->update($textObjects, $fontFamilies);
			return $changes;
		}
	}
	
	public function validate() {
		if($this->suppliedUrl) {
			echo "<div class='subsection-ok'><b>Supplied URL:</b> {$this->suppliedUrl}</div>";
			echo "<div class='subsection-ok'><b>Retrieval URL:</b> {$this->sourceUrl}</div>";
			flush();
			$startTime = microtime(true);
			$this->startTransfer();
			if($this->input) {
				$parser = new ODTParser;
				$document = $parser->parse($this->input);
				fclose($this->input);				
				if($document) {
					$updater = new SWFTextObjectUpdaterODT($document);
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
						$endTime = microtime(true);
						$duration = sprintf("%0.4f", $endTime - $startTime);
						echo "<div class='subsection-ok'><b>Process time: </b> $duration second(s)</div>";
					} else {
					}
				} else {
					echo "<div class='subsection-err' style='text-align: center'><em>(errors encountered reading document)</em></div>";
				}				
			} else {
				if(!in_array('openssl', get_loaded_extensions())) {
					echo "<div class='subsection-err' style='text-align: center'><em>(cannot download document without OpenSSL)</em></div>";
				} else {
					echo "<div class='subsection-err' style='text-align: center'><em>(cannot download document)</em></div>";
				}
			}
		} else {
			echo "<div class='subsection-err'><b>Supplied URL:</b> <em>(none)</em></div>";
		}
	}
	
	public function getExportType() {
		return 'application/vnd.oasis.opendocument.text';
	}
	
	public function getExportFileName() {
		return 'FloogleDocs.odt';
	}
	
	public function export(&$output, $textObjects, $fontFamilies) {
		// export the text into an ODTDocument object
		$exporter = new SWFTextObjectExporterODT;
		$document = $exporter->export($textObjects, $fontFamilies);

		// assemble it into a ODT file
		$assembler = new ODTAssembler;
		$assembler->assemble($output, $document);
	}
	
	public function getRequiredPHPExtensions() {
		return array('OpenSSL', 'PCRE', 'XML', 'Zlib');
	}
}

?>
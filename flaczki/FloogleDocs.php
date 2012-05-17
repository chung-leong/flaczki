<?php

class FloogleDocs extends SWFGeneratorDataModule {

	protected $sourceUrl;
	protected $input;

	public function __construct($moduleConfig) {
		$this->sourceUrl = isset($moduleConfig['url']) ? $moduleConfig['url'] : null;
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
}

?>
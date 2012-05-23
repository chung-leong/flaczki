<?php

class SWFGeneratorDataModule {

	public function checkChanges() {
		return true;
	}
	
	public function startTransfer() {
		return true;
	}
	
	public function updateText($textObjects, $fontFamilies) {
		return array();
	}
	
	public function validate() {
	}

	public function getExportType() {
		return null;
	}
	
	public function getExportFileExtension() {
		return null;
	}
	
	public function export(&$output, $textObjects, $fontFamilies) {
	}
	
	public function getRequiredPHPExtensions() {
		return array();
	}
	
	public function runModuleSpecificOperation($parameters) {
		return false;
	}
}

?>
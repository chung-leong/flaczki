<?php

abstract class SWFGeneratorDataModule {

	abstract public function startTransfer();
	abstract public function updateText($textObjects, $fontFamilies);
	abstract public function validate();

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
}

?>
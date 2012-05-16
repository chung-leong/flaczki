<?php

abstract class SWFTextObjectExporter {

	protected $tlfParser;
	
	public function __construct() {
		$this->tlfParser = new TLFParser;
	}

	abstract protected function export($textObjects, $fontFamilies);
			
	protected function beautifySectionName($name) {
		$name = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $name);	// split up a camel-case name
		$name = str_replace('_', '-', $name);				// replace underscore with dashes
		$name = ucfirst($name);						// capitalize first letter
		return $name;
	}
}

?>
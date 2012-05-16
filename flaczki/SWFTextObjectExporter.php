<?php

abstract class SWFTextObjectExporter {

	protected $tlfParser;
	
	public function __construct() {
		$this->tlfParser = new TLFParser;
	}

	abstract protected function export($textObjects, $fontFamilies);
			
	protected function beautifySectionName($name) {
		if(strpos($name, '_') === false) {	// don't change the name if underscores are used
			$name = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $name);	// split up a camel-case name
			$name = preg_replace('/([a-zA-Z])(\d+)$/', '$1 $2', $name);	// put a space in front of trailing number
			$name = ucfirst($name);						// capitalize first letter		
		}
		return $name;
	}
}

?>
<?php

abstract class SWFTextObjectUpdater {

	protected $tlfParser;
	protected $tlfAssembler;
	protected $fontFamilies;

	public function __construct() {
		$this->tlfParser = new TLFParser;
		$this->tlfAssembler = new TLFAssembler;
	}
	
	abstract protected function getSections();
	abstract protected function updateTextObject($tlfObject, $section);

	public function update($textObjects, $fontFamilies) {
		$this->fontFamilies = $fontFamilies;
		$changes = array();
		$updated = array();
	
		$sections = $this->getSections();
		foreach($sections as $section) {
			// look for a text object with matching name and update it
			foreach($textObjects as $tIndex => $textObject) {
				if(strcasecmp($textObject->name, $section->name) == 0) {
					// in case of duplicate names, don't process the same object twice
					// look for a different object with the same name instead
					if(!isset($updated[$tIndex])) {
						// parse the TLF XML
						$tlfObject = $this->tlfParser->parse($textObject->xml);						
						
						// ask subclass to update the object
						$this->updateTextObject($tlfObject, $section);
						
						// then put it back together
						$this->tlfAssembler->assemble($textObject->xml, $tlfObject);
						$updated[$tIndex] = true;
						$changes[] = $textObject;
					}
				}
			}
		}
		$this->fontFamilies = null;
		return $changes;
	}
	
	protected function getStyleUsage($tlfObject /*...*/) {
		// get a list properties to check
		$args = func_get_args();
		array_shift($args);
		$properties = $args;
		$table = array();
		
		if($properties) {
			foreach($properties as $name) {
				$table[$name] = array();
			}
			
			// count the number of characters a particular property value is applicable to
			$textLength = 0;
			$tStyle = $tlfObject->textFlow->style;
			foreach($tlfObject->textFlow->paragraphs as $paragraph) {
				$pStyle = $paragraph->style;
				foreach($paragraph->spans as $span) {
					$sStyle = $span->style;
					$sLength = strlen($span->text);
					foreach($properties as $name) {
						if(($value = $sStyle->$name) !== null || ($value = $pStyle->$name) !== null || ($value = $tStyle->$name) !== null) {
							$row =& $table[$name];
							$count =& $row[$value]; 
							$count += $sLength;
						}
					}
					$textLength += $sLength;
				}
			}
			
			// divide the count by the total length
			foreach($table as $name => &$row) {
				foreach($row as &$value) {
					$value = (double) $value / $textLength;
				}
			}
		}
		return $table;
	}

	protected function filterName($name) {	
		$name = preg_replace('/\(.*?\)/', '', $name);	// remove any ext inside parentheses
		$name = str_replace('-', '_', $name);		// replace hyphens with underscores
		$name = preg_replace('/\W+/', '', $name);	// remove everything else
		return ($name) ? $name : null;
	}	
}

?>
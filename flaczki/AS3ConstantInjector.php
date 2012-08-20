<?php

class AS3ConstantInjector {

	protected $classConstants;

	public function getRequiredTags() {
		return array('DoABC', 'DefineBinaryData');
	}

	public function inject($swfFile, $classConstants) {
		$this->classConstants = $classConstants;
		$this->processTags($swfFile->tags);
		$this->classConstants = null;
	}
	
	protected function processTags($tags) {
		foreach($tags as $tag) {
			if($tag instanceof SWFDoABCTag) {
				$this->processABCFile($tag->abcFile);
			} else if($tag instanceof SWFDefineBinaryDataTag) {
				if($tag->swfFile) {
					$this->processTags($tag->swfFile->tags);
				}
			}
		}
	}
	
	protected function processABCFile($abcFile) {
		foreach($abcFile->instanceTable as $insRec) {
			$mnRec = $abcFile->multinameTable[$insRec->nameIndex];
			$nsRec = $abcFile->namespaceTable[$mnRec->namespaceIndex];
			$name = $abcFile->stringTable[$mnRec->stringIndex];
			$namespace = $abcFile->stringTable[$nsRec->stringIndex];
			$qName = ($namespace) ? "$namespace.$name" : $name;
			
			if(isset($this->classConstants[$qName])) {
				$constants = $this->classConstants[$qName];
				
				foreach($insRec->traits as $trRec) {
					if($trRec->data instanceof ABCTraitSlot) {
						$mnRec = $abcFile->multinameTable[$trRec->nameIndex];
						$name = $abcFile->stringTable[$mnRec->stringIndex];
						
						if(isset($constants[$name])) {
							$value = $constants[$name];
							$type = gettype($value);
							
							switch($type) {
								case 'NULL': 
									$trRec->valueType = 0x0C;
									$trRec->valueIndex = null;
									break;
								case 'boolean':
									$trRec->valueType = ($value) ? 0x0B : 0x0A;
									$trRec->valueIndex = null;
									break;
								case 'integer':
									if($value < 0) {
										if(($index = array_search($value, $abcFile->intTable, true)) == false) {
											$index = count($abcFile->intTable);
											$abcFile->intTable[] = $value;
										}
										$trRec->valueType = 0x03;
										$trRec->valueIndex = $index;
									} else {
										if(($index = array_search($value, $abcFile->uintTable, true)) == false) {
											$index = count($abcFile->uintTable);
											$abcFile->uintTable[] = $value;
										}
										$trRec->valueType = 0x04;
										$trRec->valueIndex = $index;
									}
									break;
								case 'double':								
									if(($index = array_search($value, $abcFile->doubleTable, true)) == false) {
										$index = count($abcFile->doubleTable);
										$abcFile->doubleTable[] = $value;
									}
									$trRec->valueType = 0x06;
									$trRec->valueIndex = $index;
									break;
								case 'string':
									if(($index = array_search($value, $abcFile->stringTable, true)) == false) {
										$index = count($abcFile->stringTable);
										$abcFile->stringTable[] = $value;
									}
									$trRec->valueType = 0x01;
									$trRec->valueIndex = $index;
									break;
							}
						}
					}
				}
			}
		}
	}
}

?>
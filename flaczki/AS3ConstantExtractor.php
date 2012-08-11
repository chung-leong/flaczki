<?php

class AS3ConstantExtractor {

	protected $classConstants;

	public function getRequiredTags() {
		return array('DoABC', 'DefineBinaryData');
	}

	public function extract($swfFile) {
		$this->classConstants = array();
		$this->processTags($swfFile->tags);
		$classConstants = $this->classConstants;
		$this->classConstants = null;
		return $classConstants;
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
			$constants  = array();
			foreach($insRec->traits as $trRec) {
				if($trRec->data instanceof ABCTraitSlot) {
					$slRec = $trRec->data;
					if($slRec->valueType !== null) {
						$value = null;						
						switch($slRec->valueType) {
							case 0x01: $value = $abcFile->stringTable[$slRec->valueIndex]; break;
							case 0x03: $value = $abcFile->intTable[$slRec->valueIndex]; break;
							case 0x04: $value = $abcFile->uintTable[$slRec->valueIndex]; break;
							case 0x06: $value = $abcFile->doubleTable[$slRec->valueIndex]; break;
							case 0x0A: $value = false; break;
							case 0x0B: $value = true; break;
						}
						
						if($value != null) {
							$mnRec = $abcFile->multinameTable[$trRec->nameIndex];
							$name = $abcFile->stringTable[$mnRec->stringIndex];
							$constants[$name] = $value;
						}
					}
				}
			}
			if($constants) {
				$mnRec = $abcFile->multinameTable[$insRec->nameIndex];
				$nsRec = $abcFile->namespaceTable[$mnRec->namespaceIndex];
				$name = $abcFile->stringTable[$mnRec->stringIndex];
				$namespace = $abcFile->stringTable[$nsRec->stringIndex];
				$qName = ($namespace) ? "$namespace.$name" : $name;
			
				$this->classConstants[$qName] = $constants;
			}
		}
	}
}

?>
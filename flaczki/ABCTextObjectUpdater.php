<?php

class ABCTextObjectUpdater {

	public function update($abcFile, $textObject) {
		// update the string table
		if($textObject->copyOnWrite) {
			// allocate a new string in the constant pool and change operand to the bytecode
			$textObject->xmlIndex = count($abcFile->stringTable);
			$patch = "\x2c" . $this->packU32($textObject->xmlIndex);
			$this->patchMethod($textObject->methodBody, $patch, $textObject->xmlOpOffset, $textObject->xmlOpLength);
		}
		$abcFile->stringTable[$textObject->xmlIndex] = $textObject->xml;
		
		// see if there are images
		if($textObject->extraInfo) {
			// create the extraInfo object linking source names in the XML to AS3 classes
			$patch = "";
			$count = 0;
			foreach($textObject->extraInfo as $customSource => $imageClassName) {
				$dotIndex = strrpos($imageClassName, '.');
				if($dotIndex !== false) {
					$namespace = substr($imageClassName, 0, $dotIndex);
					$className = substr($imageClassName, $dotIndex + 1);
				} else {
					$namespace = null;
					$className = $imageClassName;
				}
				$imageClassNameIndex = $this->findMultinameIndex($abcFile, $className, $namespace);
				if($imageClassNameIndex === false) {
					// create a name for the class
					$imageClassNameIndex = $this->createMultiname($abcFile, $className, $namespace);
					
					// create a class for the image
					$imageClassIndex = $this->createEmptyClass($abcFile, $imageClassNameIndex);
				}
				
				$customSourceIndex = array_search($customSource, $abcFile->stringTable, true);
				if($customSourceIndex === false) {
					// allocate a string in the constant pool
					$customSourceIndex = count($abcFile->stringTable);
					$abcFile->stringTable[] = $customSource;
				}
				
				$patch .= "\x2c" . $this->packU32($customSourceIndex) 		// pushstring
					. "\x60" . $this->packU32($imageClassNameIndex);	// getlex
				$count++;
			}
			$patch .= "\x55" . $this->packU32($count);				// newobject
			$this->patchMethod($textObject->methodBody, $patch, $textObject->extraInfoOpOffset, $textObject->extraInfoOpLength);
		}
	}
	
	protected function patchMethod($methodBody, $patch, $offset, $length) {
		$patchLength = strlen($patch);
		$bc =& $methodBody->byteCodes;
		$bc = substr($bc, 0, $offset) . $patch . substr($bc, $offset + $length);
		
		if($patchLength != $length) {
			// need to adjust the offsets in the exception table
			// in theory, operands to branch and jump instructions could be affected as well
			// since the only changes we'll be making are to the argument list to addInstance
			// this shouldn't happen 
			$difference = $patchLength - $length;
			foreach($methodBody->exceptions as $exception) {
				if($exception->from > $offset) {
					$exception->from += $difference;
				}
				if($exception->target > $offset) {
					$exception->to += $difference;
				}
				if($exception->target > $offset) {
					$exception->target += $difference;
				}
			}
		}		
	}
	
	protected function findMultinameIndex($abcFile, $name, $namespace = null) {
		// find the name in the string table first to reduce the number of string comparisons
		$nameIndex = array_search($name, $abcFile->stringTable, true);
		if($nameIndex !== false) {
			foreach($abcFile->multinameTable as $multinameIndex => $multiname) {
				if($multiname->stringIndex === $nameIndex) {
					// see if the namespace matches (if one is supplied)
					$multinameNamespace = $abcFile->namespaceTable[$multiname->namespaceIndex];
					if(!$namespace || $abcFile->stringTable[$multinameNamespace->stringIndex] == $namespace) {
						return $multinameIndex;
					}
				}
			}
		}
		return false;
	}
	
	protected function findNamespaceIndex($abcFile, $namespace) {
		foreach($abcFile->namespaceTable as $namespaceIndex => $namespaceRec) {
			if($namespaceRec->stringIndex && $abcFile->stringTable[$namespaceRec->stringIndex] == $namespace) {
				return $namespaceIndex;
			}
		}
		return false;
	}
	
	protected function createMultiname($abcFile, $name, $namespace = null) {
		$stringIndex = array_search($name, $abcFile->stringTable, true);
		if(!$stringIndex) {
			$stringIndex = count($abcFile->stringTable);
			$abcFile->stringTable[] = $name;
		}
		if($namespace) {
			$namespaceIndex = $this->findNamespaceIndex($abcFile, $namespace);
			if(!$namespaceIndex) {
				$namespaceStringIndex = array_search($namespace, $abcFile->stringTable, true);
				if($namespaceStringIndex === false) {
					$namespaceStringIndex = count($abcFile->stringTable);
					$abcFile->stringTable[] = $namespace;
				}
				$namespaceRec = new ABCNamespace;
				$namespaceRec->kind = 0x08;
				$namespaceRec->stringIndex = $namespaceStringIndex;
				$namespaceIndex = count($abcFile->namespaceTable);
				$abcFile->namespaceTable[] = $namespaceRec;
			}
		} else {
			$namespaceIndex = 0;
		}
		$multiname = new ABCMultiname;
		$multiname->type = 0x07;
		$multiname->stringIndex = $stringIndex;
		$multiname->namespaceIndex = $namespaceIndex;
		$multinameIndex = count($abcFile->multinameTable);
		$abcFile->multinameTable[] = $multiname;
		return $multinameIndex;
	}
	
	protected function createEmptyClass($abcFile, $nameIndex) {
		$classIndex = count($abcFile->classTable);

		$objectNameIndex = $this->findMultinameIndex($abcFile, 'Object');
 		$eventDispatcherNameIndex = $this->findMultinameIndex($abcFile, 'EventDispatcher', 'flash.events');
 		$displayObjectNameIndex = $this->findMultinameIndex($abcFile, 'DisplayObject', 'flash.display');
 		$interactiveObjectNameIndex = $this->findMultinameIndex($abcFile, 'InteractiveObject', 'flash.display');
 		$displayObjectContainerNameIndex = $this->findMultinameIndex($abcFile, 'DisplayObjectContainer', 'flash.display');
 		$spriteNameIndex = $this->findMultinameIndex($abcFile, 'Sprite', 'flash.display');
 		$movieClipNameIndex = $this->findMultinameIndex($abcFile, 'MovieClip', 'flash.display');
 		$bitmapDataNameIndex = $this->findMultinameIndex($abcFile, 'BitmapData', 'flash.display');
		//$ancestry = array($objectNameIndex, $eventDispatcherNameIndex, $displayObjectNameIndex, $interactiveObjectNameIndex, $displayObjectContainerNameIndex, $spriteNameIndex, $movieClipNameIndex);
		$ancestry = array($objectNameIndex, $bitmapDataNameIndex);

 		// create the instance initializer
		$methodBody = new ABCMethodBody;
		$methodBody->maxStack = 3;
		$methodBody->localCount = 3;
		$methodBody->initScopeDepth = 5;
		$methodBody->maxScopeDepth = 6;
		$methodBody->byteCodes = "\xd0\x30\xd0\xd1\xd2\x49\x02\x47"; 	// empty function that calls parent constructor
		$methodBody->methodIndex = count($abcFile->methodTable);
		$abcFile->methodBodyTable[] = $methodBody;	
		$method = new ABCMethod;
		$method->body = $methodBody;
		$abcFile->methodTable[] = $method;
		
		// create the instance
		$instance = new ABCInstance;
		$instance->nameIndex = $nameIndex;
		//$instance->superNameIndex = $movieClipNameIndex;
		$instance->superNameIndex = $bitmapDataNameIndex;
		$instance->flags = 0x8;
		$instance->protectedNamespaceIndex = $objectNameIndex;
		$instance->constructorIndex = $methodBody->methodIndex;
		$abcFile->instanceTable[] = $instance;

		// create script initializer
		$methodBody = new ABCMethodBody;
		$methodBody->maxStack = count($ancestry);
		$methodBody->localCount = 1;
		$methodBody->initScopeDepth = 1;
		$methodBody->maxScopeDepth = 4;
		$methodBody->byteCodes = "\xd0\x30\x65\x00";
		foreach($ancestry as $ancestorNameIndex) {
			$methodBody->byteCodes .= "\x60" . $this->packU32($ancestorNameIndex) . "\x30";					// getlex [index] 
		}
		//$methodBody->byteCodes .= "\x60" . $this->packU32($movieClipNameIndex) . "\x58" . $this->packU32($classIndex);
		$methodBody->byteCodes .= "\x60" . $this->packU32($bitmapDataNameIndex) . "\x58" . $this->packU32($classIndex);		// getlex [index] newclass [index]
		$methodBody->byteCodes .= str_repeat("\x1d", count($ancestry));								// popscope
		$methodBody->byteCodes .= "\x68" . $this->packU32($nameIndex) . "\x47";
		$methodBody->methodIndex = count($abcFile->methodTable);
		$abcFile->methodBodyTable[] = $methodBody;
		$method = new ABCMethod;
		$method->body = $methodBody;
		$abcFile->methodTable[] = $method;
		
		// create a script object
		$script = new ABCScript;
		$script->initializerIndex = $methodBody->methodIndex;
		$trait = new ABCTrait;
		$trait->nameIndex = $nameIndex;
		$trait->type = 0x04;
		$trait->data = new ABCTraitClass;
		$trait->data->slotId = 0;
		$trait->data->classIndex = $classIndex;
		$script->traits[] = $trait;
		array_unshift($abcFile->scriptTable, $script);
		
		// create class constructor
		$methodBody = new ABCMethodBody;
		$methodBody->maxStack = 1;
		$methodBody->localCount = 1;
		$methodBody->initScopeDepth = 4;
		$methodBody->maxScopeDepth = 5;
		$methodBody->byteCodes = "\xd0\x30\x47";			// does nothing
		$methodBody->methodIndex = count($abcFile->methodTable);
		$abcFile->methodBodyTable[] = $methodBody;
		$method = new ABCMethod;
		$method->body = $methodBody;
		$abcFile->methodTable[] = $method;
		
		// create an class object
		$class = new ABCClass;
		$class->constructorIndex = $methodBody->methodIndex;
		$abcFile->classTable[] = $class;
		
		return $classIndex;
	}
	
	protected function decodeU32($bc, &$p = 1) {
		$s = 0;
		$v = 0;
		do { 
			$b = ord($bc[$p++]);
			$v |= ($b & 0x7F) << $s;
			$s += 7;
		} while($b & 0x80);
		return $v;
	}	
	
	protected function packU32($v) {
		if(!($v & 0xFFFFFF80)) {
			$b = pack('C', $v);
		} else if(!($v & 0xFFFFC000)) {
			$b = pack('C*', $v & 0x7F | 0x80, ($v >> 7) & 0x7F);
		} else if(!($v & 0xFFE00000)) {
			$b = pack('C*', $v & 0x7F | 0x80, ($v >> 7) & 0x7F | 0x80, ($v >> 14) & 0x7F);
		} else if(!($v & 0xF0000000)) {
			$b = pack('C*', $v & 0x7F | 0x80, ($v >> 7) & 0x7F | 0x80, ($v >> 14) & 0x7F | 0x80,  ($v >> 21) & 0x7F);
		} else {
			$b = pack('C*', $v & 0x7F | 0x80, ($v >> 7) & 0x7F | 0x80, ($v >> 14) & 0x7F | 0x80,  ($v >> 21) & 0x7F | 0x80, ($v >> 28) & 0x0F);
		}
		return $b;
	}
}

?>
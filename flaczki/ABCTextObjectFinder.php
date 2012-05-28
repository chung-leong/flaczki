<?php

class ABCTextObjectFinder {

	public function find($abcFile) {
		$textObjects = array();
	
		// get the multiname index of flash.display.MovieClip and flash.display.SimpleButton
		// these are objects which can contain TLFTextObjects
		$movieClipNameIndex = $this->findMultinameIndex($abcFile, 'MovieClip', 'flash.display');
		$simpleButtonNameIndex = $this->findMultinameIndex($abcFile, 'SimpleButton', 'flash.display');

		// to create the text object, Flash makes the following function call 
		// fl.text.RuntimeManager.addInstance(	container:DisplayObject, 
		//				      	instanceName:String, 
		//					bounds:Rectangle, 
		//					data:XML, 
		//					xns:Array, 
		//					extraInfo:*, 
		//					startFrame:int, 
		//					endFrame:int, 
		//					sceneName:String, 
		//					arg10:Boolean, 
		//					arg11:Boolean ):void
		//
		// we are interested in are arguments pushed onto to the stack 
		//
		//            this: getlex fl.text.RuntimeManager callproperty getSingleton 0 (this)
		//       container: getlocal_0 
		//    instanceName: pushstring [string-index]
		//          bounds: findpropstrict flash.geom.Rectangle [...four push instrs...] constructprop flash.geom.Rectangle 4 
		//            data: getlex XML pushstring [string-index] construct 1 
		//             xns: pushnull (right now), ... newarray [count] (possibly in the future)
		//       extraInfo: getlex undefined (right now) pushstring [string-index] getlex [name-index] ... newobject [count] (one possible implementation)
		//      startFrame: pushbyte [num], pushshort [num], or pushuint [uint-index]
		//        endFrame: dup, pushbyte [num], pushshort [num], or pushuint [uint-index]
		//       sceneName: pushstring [string-index]
		//           arg10: pushtrue or push false
		//           arg11: pushtrue or push false
		//
		// the actual call: callpropvoid addInstance 11
		//
		// create a regex pattern that'll match this
		
		$runtimeManagerNameIndex = $this->findMultinameIndex($abcFile, 'RuntimeManager', 'fl.text');
		if($runtimeManagerNameIndex !== false) {
			$getSingletonNameIndex = $this->findMultinameIndex($abcFile, 'getSingleton');
			$rectangleNameIndex = $this->findMultinameIndex($abcFile, 'Rectangle', 'flash.geom');
			$xmlNameIndex = $this->findMultinameIndex($abcFile, 'XML');
			$undefinedNameIndex = $this->findMultinameIndex($abcFile, 'undefined');
			$addInstanceNameIndex = $this->findMultinameIndex($abcFile, 'addInstance');
			
			$pattern = '\x60' . $this->getU32Pattern($runtimeManagerNameIndex) . '\x46' . $this->getU32Pattern($getSingletonNameIndex) . '\x00'
				  . '\xd0'
				  . '(\x2c.+?)'
				  . '\x5d' . $this->getU32Pattern($rectangleNameIndex) . '(.+?)\x4a' . $this->getU32Pattern($rectangleNameIndex) . '\x04'
				  . '\x60' . $this->getU32Pattern($xmlNameIndex) . '(\x2c.+?)\x42\x01'
				  . '(\x20|.+?\x56.+?)' 
				  . '(\x60' . $this->getU32Pattern($undefinedNameIndex) . '|.+?\x55.+?)'
				  . '(\x24.|\x25.+?|\x2e.+?)'
				  . '(\x2a|\x24.|\x25.+?|\x2e.+?)'
				  . '(\x2c.+?)'
				  . '(\x26|\x27)'
				  . '(\x27|\x26)'
				  . '.*?\x4f' . $this->getU32Pattern($addInstanceNameIndex);

			// loop through the instances and see which ones inherits from MovieClip and SimpleButton
			foreach($abcFile->instanceTable as $instance) {
				if($instance->superNameIndex == $movieClipNameIndex || $instance->superNameIndex == $simpleButtonNameIndex) {
					// look up the initializer for the clip
					$method = $abcFile->methodTable[$instance->initializerIndex];

					// scan the AS3 bytecodes
					if($method->body && preg_match_all("/$pattern/s", $method->body->byteCodes, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
						foreach($matches as $match) {
							list($instanceNameOp, $instanceNameOpOffset) = $match[1];
							list($xmlOp, $xmlOpOffset) = $match[3];
							list($extraInfoOp, $extraInfoOpOffset) = $match[5];
							
							$textObject = new ABCTextObjectInfo;
							$textObject->nameIndex = $this->decodeU32($instanceNameOp);
							$textObject->name = $abcFile->stringTable[$textObject->nameIndex];
							$textObject->xmlIndex = $this->decodeU32($xmlOp);
							$textObject->xml = $abcFile->stringTable[$textObject->xmlIndex];
							$textObject->xmlOpOffset = $xmlOpOffset;
							$textObject->xmlOpLength = strlen($xmlOp);
							$textObject->extraInfoOpOffset = $extraInfoOpOffset;
							$textObject->extraInfoOpLength = strlen($extraInfoOp);
							$textObject->methodBody = $method->body;
							$textObjects[] = $textObject;
						}
					}
				}
			}
		}
		
		$nameIndexHash = array();
		foreach($textObjects as $index => $textObject) {
			if(isset($nameIndexHash[$textObject->xmlIndex])) {
				if($nameIndexHash[$textObject->xmlIndex] == $textObject->nameIndex) {
					// eliminate duplicates (e.g. when a text object is in a button)
					unset($textObjects[$index]);
				} else {
					// different text objects are pointing to the same text
					$textObject->copyOnWrite = true;
				}
			} else {
				$nameIndexHash[$textObject->xmlIndex] = $textObject->nameIndex;
			}
		}
		return $textObjects;
	}
	
	public function replace($abcFile, $textObjects) {
		// update the string table
		foreach($textObjects as $textObject) {		
			if($textObject->copyOnWrite) {
				// allocate a new string in the constant pool and change operand to the bytecode
				$textObject->xmlIndex = count($abcFile->stringTable);
				$patch = "\x2c" . $this->packU32($textObject->xmlIndex);
				$this->patchMethod($textObject->methodBody, $patch, $textObject->xmlOpOffset, $textObject->xmlOpLength);
			}
			$abcFile->stringTable[$textObject->xmlIndex] = $textObject->xml;
			
			// see if there are images
			if($textObject->referencedImageClasses) {
				// create the extraInfo object linking source names in the XML to AS3 classes
				$patch = "";
				$count = 0;
				foreach($textObject->referencedImageClasses as $sourceName => $imageClass) {
					if(!$imageClass->nameIndex) {
						// create a name for the class
						$imageClass->nameIndex = $this->findMultinameIndex($abcFile, $imageClass->name, $imageClass->namespace);
						if(!$imageClass->nameIndex) {
							$imageClass->nameIndex = $this->createMultiname($abcFile, $imageClass->name, $imageClass->namespace);
						}
					}
					if(!$imageClass->classIndex) {
						// create a class for the image
						$imageClass->classIndex = $this->createEmptyClass($abcFile, $imageClass->nameIndex);
					}
					if(!$imageClass->sourceNameIndex) {
						// allocate a string in the constant pool
						$imageClass->sourceNameIndex = array_search($sourceName, $abcFile->stringTable, true);
						if(!$imageClass->sourceNameIndex) {
							$imageClass->sourceNameIndex = count($abcFile->stringTable);
							$abcFile->stringTable[] = $sourceName;
						}
						$imageClass->sourceName = $sourceName;
					}
					
					$patch .= "\x2c" . $this->packU32($imageClass->sourceNameIndex) // pushstring
						. "\x60" . $this->packU32($imageClass->nameIndex);	// getlex
					$count++;
				}
				$patch .= "\x55" . $this->packU32($count);				// newobject
				$this->patchMethod($textObject->methodBody, $patch, $textObject->extraInfoOpOffset, $textObject->extraInfoOpLength);
			}
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
		foreach($abcFile->multinameTable as $multinameIndex => $multiname) {
			if($multiname->stringIndex === $nameIndex) {
				// see if the namespace matches (if one is supplied)
				$multinameNamespace = $abcFile->namespaceTable[$multiname->namespaceIndex];
				if(!$namespace || $abcFile->stringTable[$multinameNamespace->stringIndex] == $namespace) {
					return $multinameIndex;
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
				$namespaceRec = new ABCNamespace;
				$namespaceRec->kind = 0x08;
				$namespaceRec->stringIndex = count($abcFile->stringTable);
				$abcFile->stringTable[] = $namespace;
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
		$instance->initializerIndex = $methodBody->methodIndex;
		$abcFile->instanceTable[] = $instance;

		// create script initializer
		$methodBody = new ABCMethodBody;
		$methodBody->maxStack = 2;
		$methodBody->localCount = 1;
		$methodBody->initScopeDepth = 1;
		$methodBody->maxScopeDepth = 4;
		$methodBody->byteCodes = "\xd0\x30\x65\x00";
		//$ancestry = array($objectNameIndex, $eventDispatcherNameIndex, $displayObjectNameIndex, $interactiveObjectNameIndex, $displayObjectContainerNameIndex, $spriteNameIndex, $movieClipNameIndex);
		$ancestry = array($objectNameIndex, $bitmapDataNameIndex);
		foreach($ancestry as $ancestorNameIndex) {
			$methodBody->byteCodes .= "\x60" . $this->packU32($ancestorNameIndex) . "\x30";
		}
		//$methodBody->byteCodes .= "\x60" . $this->packU32($movieClipNameIndex) . "\x58" . $this->packU32($classIndex);
		$methodBody->byteCodes .= "\x60" . $this->packU32($bitmapDataNameIndex) . "\x58" . $this->packU32($classIndex);
		$methodBody->byteCodes .= str_repeat("\x1d", count($ancestry));
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
	
	protected function getU32Pattern($v) {
		$b = $this->packU32($v);
		$p = "";
		for($i = 0; $i < strlen($b); $i++) {
			$p .= sprintf('\x%02x', ord($b[$i]));
		}
		return $p;
	}
}

class ABCTextObjectInfo {
	public $name;
	public $nameIndex;
	public $xml;
	public $xmlIndex;
	public $methodBody;
	public $copyOnWrite;
	public $referencedImageClasses = array();		// keyed by customSource name
}

class ABCImageClassInfo {
	public $multinameIndex;
	public $nameapace;
	public $namespaceIndex;
	public $name;
	public $nameIndex;
	public $sourceNameIndex;
	public $sourceName;
	public $classIndex;
}

?>
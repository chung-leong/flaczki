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
					if($method->body && preg_match_all("/$pattern/", $method->body->byteCodes, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
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
			if($textObject->imageClasses) {
				// create the extraInfo object linking source names in the XML to AS3 classes
				$patch = "";
				$count = 0;
				foreach($textObject->imageClasses as $imageClass) {
					if(!$imageClass->classIndex) {
						// create a class for the image
					}
					if(!$imageClass->nameIndex) {
						// create a name for the class
					}
					if(!$imageClass->sourceNameIndex) {
						// allocate a string in the constant pool
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
	public $imageClasses = array();
}

class ABCGraphicClass {
	public $name;
	public $nameIndex;
	public $sourceName;
	public $sourceNameIndex;
	public $classIndex;
}

?>
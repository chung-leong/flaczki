<?php

class ABCTextObjectFinder {

	public function find($abcFile) {	
		$textObjects = array();
	
		// get the multiname index of flash.display.MovieClip and flash.display.SimpleButton
		// these are objects which can contain TLFTextObjects
		$movieClipNameIndex = $this->findMultinameIndex($abcFile, 'MovieClip', 'flash.display');
		$simpleButtonNameIndex = $this->findMultinameIndex($abcFile, 'SimpleButton', 'flash.display');
		
		// we know that the initialization code will call methods of fl.text.RuntimeManager
		// look for the multiname index and construct the bytecode fragment 'getlex [index]'
		// we will then use that to quickly see if an initializer is worth parsing or not
		$runtimeManagerNameIndex = $this->findMultinameIndex($abcFile, 'RuntimeManager', 'fl.text');
		if($runtimeManagerNameIndex !== false) {
			$codefragment = "\x60" . $this->packU32($runtimeManagerNameIndex);
		
			// loop through the instances and see which ones inherits from MovieClip and SimpleButton
			foreach($abcFile->instanceTable as $instance) {
				if($instance->superNameIndex == $movieClipNameIndex || $instance->superNameIndex == $simpleButtonNameIndex) {
					// look up the initializer for the clip
					$method = $abcFile->methodTable[$instance->initializerIndex];
					$methodBody = $method->body;
					$byteCodes = $methodBody->byteCodes;
					
					// scan the AS3 bytecodes for TLFTextObject initializations
					if(strpos($byteCodes, $codefragment) !== false) {
						$this->scanByteCodes($abcFile, $byteCodes, $textObjects);
					}
				}
			}
		}
		
		$nameIndexAndTextIndexHash = array();
		foreach($textObjects as $index => $textObject) {
			$key = $textObject->nameIndex . '=' . $textObject->xmlIndex;
			if(isset($nameIndexAndTextIndexHash[$key])) {
				// eliminate duplicates (e.g. when a text object is in a button)
				unset($textObjects[$index]);
			} else {
				$nameIndexAndTextIndexHash[$key] = true;
			}
		}
		return $textObjects;
	}
	
	public function replace($abcFile, $textObjects) {
		// update the string table
		foreach($textObjects as $textObject) {
			$abcFile->stringTable[$textObject->nameIndex] = $textObject->name;
			$abcFile->stringTable[$textObject->xmlIndex] = $textObject->xml;
		}
	}
	
	protected function findMultinameIndex($abcFile, $name, $namespace = null) {
		// find the name in the string table first to reduce the number of string comparisons
		$nameIndex = array_search($name, $abcFile->stringTable);
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
	
	public function scanByteCodes($abcFile, $bc, &$textObjects) {
		// operands expected by each instruction
		// 0 means none
		// 1 and 2 mean one or two U32's (variable length 32-bit integer)
		// 0x10 means one U8
		// 0x20 means one S24 (3-byte integer)
		// 0xFF and 0xEE are special cases (debug and switch loop handling)
		static $opt = array(
			"\xa0" => 0,	/* add */		"\x62" => 1,	/* getlocal */		"\xc7" => 0,	/* multiply_i */	"\x50" => 0,	/* sxi_1 */				
			"\xc5" => 0,	/* add_i */		"\xd0" => 0,	/* getlocal_1 */	"\x90" => 0,	/* negate */		"\x51" => 0,	/* sxi_8 */
			"\x86" => 1,	/* astype */		"\xd1" => 0,	/* getlocal_2 */	"\xc4" => 0,	/* negate_i */		"\x52" => 0,	/* sxi_16 */
			"\x87" => 0,	/* astypelate */	"\xd2" => 0,	/* getlocal_3 */	"\x57" => 0,	/* newactivation */	"\x03" => 0,	/* throw */
			"\xa8" => 0,	/* bitand */		"\xd3" => 0,	/* getlocal_4 */	"\x56" => 1,	/* newarray */		"\x95" => 0,	/* typeof */
			"\x97" => 0,	/* bitnot */		"\x66" => 1,	/* getproperty */	"\x5a" => 1,	/* newcatch */		"\xa7" => 0,	/* urshift */
			"\xa9" => 0,	/* bitor */		"\x65" => 0x10,	/* getscopeobject */	"\x58" => 1,	/* newclass */
			"\xaa" => 0,	/* bitxor */		"\x6c" => 1,	/* getslot */		"\x40" => 1,	/* newfunction */
			"\x41" => 1,	/* call */		"\x04" => 1,	/* getsuper */		"\x55" => 1,	/* newobject */
			"\x43" => 2,	/* callmethod */	"\xb0" => 0,	/* greaterequals */	"\x1e" => 0,	/* nextname */
			"\x46" => 2,	/* callproperty */	"\xaf" => 0,	/* greaterthan */	"\x23" => 0,	/* nextvalue */
			"\x4c" => 2,	/* callproplex */	"\x1f" => 0,	/* hasnext */		"\x02" => 0,	/* nop */
			"\x4f" => 2,	/* callpropvoid */	"\x32" => 2,	/* hasnext2 */		"\x96" => 0,	/* not */
			"\x44" => 2,	/* callstatic */	"\x13" => 0x20,	/* ifeq */		"\x29" => 0,	/* pop */
			"\x45" => 2,	/* callsuper */		"\x12" => 0x20,	/* iffalse */		"\x1d" => 0,	/* popscope */
			"\x4e" => 2,	/* callsupervoid */	"\x18" => 0x20,	/* ifge */		"\x24" => 0x10,	/* pushbyte */
			"\x78" => 0,	/* checkfilter */	"\x17" => 0x20,	/* ifgt */		"\x2f" => 1,	/* pushdouble */
			"\x80" => 1,	/* coerce */		"\x16" => 0x20,	/* ifle */		"\x27" => 0,	/* pushfalse */
			"\x82" => 0,	/* coerce_a */		"\x15" => 0x20,	/* iflt */		"\x2d" => 1,	/* pushint */
			"\x85" => 0,	/* coerce_s */		"\x14" => 0x20,	/* ifne */		"\x31" => 1,	/* pushnamespace */
			"\x42" => 1,	/* construct */		"\x0f" => 0x20,	/* ifnge */		"\x28" => 0,	/* pushnan */
			"\x4a" => 2,	/* constructprop */	"\x0e" => 0x20,	/* ifngt */		"\x20" => 0,	/* pushnull */
			"\x49" => 1,	/* constructsuper */	"\x0d" => 0x20,	/* ifnle */		"\x30" => 0,	/* pushscope */
			"\x76" => 0,	/* convert_b */		"\x0c" => 0x20,	/* ifnlt */		"\x25" => 1,	/* pushshort */
			"\x75" => 0,	/* convert_d */		"\x19" => 0x20,	/* ifstricteq */	"\x2c" => 1,	/* pushstring */
			"\x73" => 0,	/* convert_i */		"\x1a" => 0x20,	/* ifstrictne */	"\x26" => 0,	/* pushtrue */
			"\x77" => 0,	/* convert_o */		"\x11" => 0x20,	/* iftrue */		"\x2e" => 1,	/* pushuint */
			"\x70" => 0,	/* convert_s */		"\xb4" => 0,	/* in */		"\x21" => 0,	/* pushundefined */
			"\x74" => 0,	/* convert_u */		"\x92" => 1,	/* inclocal */		"\x1c" => 0,	/* pushwith */
			"\xef" => 0xFF,	/* debug */		"\xc2" => 1,	/* inclocal_i */	"\x48" => 0,	/* returnvalue */
			"\xf1" => 1,	/* debug_file */	"\x91" => 0,	/* increment */		"\x47" => 0,	/* returnvoid */
			"\xf0" => 1,	/* debug_line */	"\xc0" => 0,	/* increment_i */	"\xa6" => 0,	/* rshift */
			"\x94" => 1,	/* declocal */		"\x68" => 1,	/* initproperty */	"\x6f" => 1,	/* setglobalslot */
			"\xc3" => 1,	/* declocal_i */	"\xb1" => 0,	/* instanceof */	"\x63" => 1,	/* setlocal */
			"\x93" => 0,	/* decrement */		"\xb2" => 1,	/* istype */		"\xd4" => 0,	/* setlocal_0 */
			"\xc1" => 0,	/* decrement_i */	"\xb3" => 0,	/* istypelate */	"\xd5" => 0,	/* setlocal_1 */
			"\x6a" => 1,	/* deleteproperty */	"\x10" => 0x20,	/* jump */		"\xd6" => 0,	/* setlocal_2 */
			"\xa3" => 0,	/* divide */		"\x08" => 1,	/* kill */		"\xd7" => 0,	/* setlocal_3 */
			"\x2a" => 0,	/* dup */		"\x09" => 0,	/* label */		"\x61" => 1,	/* setproperty */
			"\x06" => 1,	/* dxns */		"\xae" => 0,	/* lessequals */	"\x6d" => 1,	/* setslot */
			"\x07" => 0,	/* dxnslate */		"\xad" => 0,	/* lessthan */		"\x05" => 1,	/* setsuper */
			"\xab" => 0,	/* equals */		"\x38" => 0,	/* lf32 */		"\x3d" => 0,	/* sf32 */
			"\x72" => 0,	/* esc_xattr */		"\x35" => 0,	/* lf64 */		"\x3d" => 0,	/* sf64 */
			"\x71" => 0,	/* esc_xelem */		"\x35" => 0,	/* li8 */		"\x3a" => 0,	/* si8 */
			"\x5e" => 1,	/* findproperty */	"\x36" => 0,	/* li16 */		"\x3b" => 0,	/* si16 */
			"\x5d" => 1,	/* findpropstrict */	"\x37" => 0,	/* li32 */		"\x3c" => 0,	/* si32 */
			"\x59" => 1,	/* getdescendants */	"\x1b" => 0xEE,	/* lookupswitch */	"\xac" => 0,	/* strictequals */
			"\x64" => 0,	/* getglobalscope */	"\xa5" => 0,	/* lshift */		"\xa1" => 0,	/* subtract */
			"\x6e" => 1,	/* getglobalslot */	"\xa4" => 0,	/* modulo */		"\xc6" => 0,	/* subtract_i */
			"\x60" => 1,	/* getlex */		"\xa2" => 0,	/* multiply */		"\x2b" => 0,	/* swap */
		);
		
		$count = 0;
		$si1 = null;
		$si2 = null;
		for($i = 0, $l = strlen($bc); $i < $l; $i++) {
			$op = $bc[$i];
			$type = $opt[$op];		
				
			switch($type) {
				case 0:
					break;
				case 1:
					$o1 = $this->readU32($bc, $i);
					break;
				case 2:
					$o1 = $this->readU32($bc, $i);
					$o2 = $this->readU32($bc, $i);
					break;
				case 0x10: 
					$o1 = $this->readU8($bc, $i);
					break;
				case 0x20:
					$o1 = $this->readS24($bc, $i);
					break;
				case 0xEE:	// lookupswitch - variable number of cases (jump table is S24's)
					$o1 = $this->readS24($bc, $i);
					$i += $o1 * 3;
					break;
				case 0xFF:	// debug
					$o1 = $this->readU8($bc, $i);
					$o2 = $this->readU32($bc, $i);
					$o3 = $this->readU8($bc, $i);
					$o4 = $this->readU32($bc, $i);
					break;
			}
			
			// to create the text object, Flash is calling to make the following function call 
			// fl.text.RuntimeManager.addInstance(Object, ObjectName, Rectangle, XML, null, undefined, Number, Number, SceneName, Boolean, Boolean)
			// what we are looking for is the sequence:
			//
			// 	pushstring [name string index] 
			//	...
			//	pushstring [XML string index]
			//	construct 0x1
			//
			// the last opcode calls the XML constructor (with one parameter)
			if($op == "\x2c") {	// pushstring
				$si1 = $si2;
				$si2 = $o1;
			} else if($op == "\x42" && $o1 == 1) {
				if($si1 !== null && $si2 !== null) {
					$s1 = $abcFile->stringTable[$si1];
					$s2 = $abcFile->stringTable[$si2];
					if(substr_compare($s2, '<tlfTextObject', 0, 14) == 0) {
						$textObject = new ABCTextObjectInfo;
						$textObject->name = $s1;
						$textObject->nameIndex = $si1;
						$textObject->xml = $s2;
						$textObject->xmlIndex = $si2;
						$textObjects[] = $textObject;
						$si1 = null;
						$si2 = null;
						$count++;
					}
				}
			}
		}	
		return $count;
	}
	
	protected function readU8($bc, &$p) {
		return ord($bc[++$p]);
	}
	
	protected function readS24($bc, &$p) {
		// 'V' is unsigned long big endian so this function doesn't read negative number correctly
		// we really only need it for the loopupswitch though 
		$a = unpack('V', substr($bc, $p + 1, 3) . "\0");
		$p += 3;
		return $a[1];
	}
	
	protected function readU32($bc, &$p) {
		$s = 0;
		$v = 0;
		do { 
			$b = ord($bc[++$p]);
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

class ABCTextObjectInfo {
	public $name;
	public $nameIndex;
	public $xml;
	public $xmlIndex;
}

?>
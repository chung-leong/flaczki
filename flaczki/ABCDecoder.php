<?php

class ABCDecoder {

	public function decode($byteCodes) {	
		static $nameTable = array(
			0xa0 => "add",
			0xc5 => "add_i",
			0x53 => "applytype",
			0x86 => "astype",
			0x87 => "astypelate",
			0x01 => "bkpt",
			0xf2 => "bkptline",
			0xa8 => "bitand",
			0x97 => "bitnot",
			0xa9 => "bitor",
			0xaa => "bitxor",
			0x41 => "call",
			0x43 => "callmethod",
			0x46 => "callproperty",
			0x4c => "callproplex",
			0x4f => "callpropvoid",
			0x44 => "callstatic",
			0x45 => "callsuper",
			0x4e => "callsupervoid",
			0x78 => "checkfilter",
			0x80 => "coerce",
			0x82 => "coerce_a",
			0x85 => "coerce_s",
			0x42 => "construct",
			0x4a => "constructprop",
			0x49 => "constructsuper",
			0x76 => "convert_b",
			0x75 => "convert_d",
			0x73 => "convert_i",
			0x77 => "convert_o",
			0x70 => "convert_s",
			0x74 => "convert_u",
			0xef => "debug",
			0xf1 => "debugfile",
			0xf0 => "debugline",
			0x94 => "declocal",
			0xc3 => "declocal_i",
			0x93 => "decrement",
			0xc1 => "decrement_i",
			0x6a => "deleteproperty",
			0xa3 => "divide",
			0x2a => "dup",
			0x06 => "dxns",
			0x07 => "dxnslate",
			0xab => "equals",
			0x72 => "esc_xattr",
			0x71 => "esc_xelem",
			0x5e => "findproperty",
			0x5d => "findpropstrict",
			0x59 => "getdescendants",
			0x64 => "getglobalscope",
			0x6e => "getglobalslot",
			0x60 => "getlex",
			0x62 => "getlocal",
			0xd0 => "getlocal_0",
			0xd1 => "getlocal_1",
			0xd2 => "getlocal_2",
			0xd3 => "getlocal_3",
			0x66 => "getproperty",
			0x65 => "getscopeobject",
			0x6c => "getslot",
			0x04 => "getsuper",
			0xb0 => "greaterequals",
			0xaf => "greaterthan",
			0x1f => "hasnext",
			0x32 => "hasnext2",
			0x13 => "ifeq",
			0x12 => "iffalse",
			0x18 => "ifge",
			0x17 => "ifgt",
			0x16 => "ifle",
			0x15 => "iflt",
			0x14 => "ifne",
			0x0f => "ifnge",
			0x0e => "ifngt",
			0x0d => "ifnle",
			0x0c => "ifnlt",
			0x19 => "ifstricteq",
			0x1a => "ifstrictne",
			0x11 => "iftrue",
			0xb4 => "in",
			0x92 => "inclocal",
			0xc2 => "inclocal_i",
			0x91 => "increment",
			0xc0 => "increment_i",
			0x68 => "initproperty",
			0xb1 => "instanceof",
			0xb2 => "istype",
			0xb3 => "istypelate",
			0x10 => "jump",
			0x08 => "kill",
			0x09 => "label",
			0xae => "lessequals",
			0xad => "lessthan",
			0x38 => "lf32",
			0x35 => "lf64",
			0x35 => "li8",
			0x36 => "li16",
			0x37 => "li32",
			0x1b => "lookupswitch",
			0xa5 => "lshift",
			0xa4 => "modulo",
			0xa2 => "multiply",
			0xc7 => "multiply_i",
			0x90 => "negate",
			0xc4 => "negate_i",
			0x57 => "newactivation",
			0x56 => "newarray",
			0x5a => "newcatch",
			0x58 => "newclass",
			0x40 => "newfunction",
			0x55 => "newobject",
			0x1e => "nextname",
			0x23 => "nextvalue",
			0x02 => "nop",
			0x96 => "not",
			0x29 => "pop",
			0x1d => "popscope",
			0x24 => "pushbyte",
			0x2f => "pushdouble",
			0x27 => "pushfalse",
			0x2d => "pushint",
			0x31 => "pushnamespace",
			0x28 => "pushnan",
			0x20 => "pushnull",
			0x30 => "pushscope",
			0x25 => "pushshort",
			0x2c => "pushstring",
			0x26 => "pushtrue",
			0x2e => "pushuint",
			0x21 => "pushundefined",
			0x1c => "pushwith",
			0x48 => "returnvalue",
			0x47 => "returnvoid",
			0xa6 => "rshift",
			0x6f => "setglobalslot",
			0x63 => "setlocal",
			0xd4 => "setlocal_0",
			0xd5 => "setlocal_1",
			0xd6 => "setlocal_2",
			0xd7 => "setlocal_3",
			0x61 => "setproperty",
			0x6d => "setslot",
			0x05 => "setsuper",
			0x3d => "sf32",
			0x3d => "sf64",
			0x3a => "si8",
			0x3b => "si16",
			0x3c => "si32",
			0xac => "strictequals",
			0xa1 => "subtract",
			0xc6 => "subtract_i",
			0x2b => "swap",
			0x50 => "sxi_1",
			0x51 => "sxi_8",
			0x52 => "sxi_16",
			0x03 => "throw",
			0x95 => "typeof",
			0xa7 => "urshift",
		);
			
		static $operandTable = array(
			0xa0 => 0x0000,	// add
			0xc5 => 0x0000,	// add_i
			0x53 => 0x0111,	// applytype
			0x86 => 0x0001,	// astype (mname_index)
			0x87 => 0x0000,	// astypelate
			0x01 => 0x0000,	// bkpt
			0xf2 => 0x0001,	// bkptline (line_num)
			0xa8 => 0x0000,	// bitand
			0x97 => 0x0000,	// bitnot
			0xa9 => 0x0000,	// bitor
			0xaa => 0x0000,	// bitxor
			0x41 => 0x0001,	// call (arg_count)
			0x43 => 0x0011,	// callmethod (method_index, arg_count)
			0x46 => 0x0011,	// callproperty (mname_index, arg_count)
			0x4c => 0x0011,	// callproplex (mname_index, arg_count)
			0x4f => 0x0011,	// callpropvoid (mname_index, arg_count)
			0x44 => 0x0011,	// callstatic (method_index, arg_count)
			0x45 => 0x0011,	// callsuper (mname_index, arg_count)
			0x4e => 0x0011,	// callsupervoid (mname_index, arg_count)
			0x78 => 0x0000,	// checkfilter
			0x80 => 0x0001,	// coerce (mname_index)
			0x82 => 0x0000,	// coerce_a
			0x85 => 0x0000,	// coerce_s
			0x42 => 0x0001,	// construct (arg_count)
			0x4a => 0x0011,	// constructprop (mname_index, arg_count)
			0x49 => 0x0001,	// constructsuper (arg_count)
			0x76 => 0x0000,	// convert_b
			0x75 => 0x0000,	// convert_d
			0x73 => 0x0000,	// convert_i
			0x77 => 0x0000,	// convert_o
			0x70 => 0x0000,	// convert_s
			0x74 => 0x0000,	// convert_u
			0xef => 0x1515,	// debug (debug_type, string_index, reg_index, extra)
			0xf1 => 0x0001,	// debug_file (string_index)
			0xf0 => 0x0001,	// debug_line (line_num)
			0x94 => 0x0001,	// declocal (reg_index)
			0xc3 => 0x0001,	// declocal_i (reg_index)
			0x93 => 0x0000,	// decrement
			0xc1 => 0x0000,	// decrement_i
			0x6a => 0x0001,	// deleteproperty (mname_index)
			0xa3 => 0x0000,	// divide
			0x2a => 0x0000,	// dup
			0x06 => 0x0001,	// dxns (string_index)
			0x07 => 0x0000,	// dxnslate
			0xab => 0x0000,	// equals
			0x72 => 0x0000,	// esc_xattr
			0x71 => 0x0000,	// esc_xelem
			0x5e => 0x0001,	// findproperty (mname_index)
			0x5d => 0x0001,	// findpropstrict (mname_index)
			0x59 => 0x0001,	// getdescendants (mname_index)
			0x64 => 0x0000,	// getglobalscope
			0x6e => 0x0001,	// getglobalslot (slot_index)
			0x60 => 0x0001,	// getlex (mname_index)
			0x62 => 0x0001,	// getlocal (reg_index)
			0xd0 => 0x0000,	// getlocal_0
			0xd1 => 0x0000,	// getlocal_1
			0xd2 => 0x0000,	// getlocal_2
			0xd3 => 0x0000,	// getlocal_3
			0x66 => 0x0001,	// getproperty (mname_index)
			0x65 => 0x0001,	// getscopeobject (stack_index)
			0x6c => 0x0001,	// getslot (slot_index)
			0x04 => 0x0001,	// getsuper (mname_index)
			0xb0 => 0x0000,	// greaterequals
			0xaf => 0x0000,	// greaterthan
			0x1f => 0x0000,	// hasnext
			0x32 => 0x0011,	// hasnext2 (reg_index, reg_index)
			0x13 => 0x0002,	// ifeq (offset)
			0x12 => 0x0002,	// iffalse
			0x18 => 0x0002,	// ifge
			0x17 => 0x0002,	// ifgt
			0x16 => 0x0002,	// ifle
			0x15 => 0x0002,	// iflt
			0x14 => 0x0002,	// ifne
			0x0f => 0x0002,	// ifnge
			0x0e => 0x0002,	// ifngt
			0x0d => 0x0002,	// ifnle
			0x0c => 0x0002,	// ifnlt
			0x19 => 0x0002,	// ifstricteq
			0x1a => 0x0002,	// ifstrictne
			0x11 => 0x0002,	// iftrue
			0xb4 => 0x0000,	// in
			0x92 => 0x0001,	// inclocal (reg_index)
			0xc2 => 0x0001,	// inclocal_i (reg_index)
			0x91 => 0x0000,	// increment
			0xc0 => 0x0000,	// increment_i
			0x68 => 0x0001,	// initproperty (mname_index)
			0xb1 => 0x0000,	// instanceof
			0xb2 => 0x0001,	// istype (mname_index)
			0xb3 => 0x0000,	// istypelate
			0x10 => 0x0002,	// jump
			0x08 => 0x0001,	// kill (reg_index)
			0x09 => 0x0000,	// label
			0xae => 0x0000,	// lessequals
			0xad => 0x0000,	// lessthan
			0x38 => 0x0000,	// lf32
			0x35 => 0x0000,	// lf64
			0x35 => 0x0000,	// li8
			0x36 => 0x0000,	// li16
			0x37 => 0x0000,	// li32
			0x1b => 0x0212,	// lookupswitch (default_offset, case_count, case_offsets)
			0xa5 => 0x0000,	// lshift
			0xa4 => 0x0000,	// modulo
			0xa2 => 0x0000,	// multiply
			0xc7 => 0x0000,	// multiply_i
			0x90 => 0x0000,	// negate
			0xc4 => 0x0000,	// negate_i
			0x57 => 0x0000,	// newactivation
			0x56 => 0x0001,	// newarray (arg_count)
			0x5a => 0x0001,	// newcatch (exception_index)
			0x58 => 0x0001,	// newclass (class_index)
			0x40 => 0x0001,	// newfunction (method_index)
			0x55 => 0x0001,	// newobject (arg_count)
			0x1e => 0x0000,	// nextname
			0x23 => 0x0000,	// nextvalue
			0x02 => 0x0000,	// nop
			0x96 => 0x0000,	// not
			0x29 => 0x0000,	// pop
			0x1d => 0x0000,	// popscope
			0x24 => 0x0004,	// pushbyte (byte)
			0x2f => 0x0001,	// pushdouble (double_index)
			0x27 => 0x0000,	// pushfalse
			0x2d => 0x0001,	// pushint (int_index)
			0x31 => 0x0001,	// pushnamespace (namespace_index)
			0x28 => 0x0000,	// pushnan
			0x20 => 0x0000,	// pushnull
			0x30 => 0x0000,	// pushscope
			0x25 => 0x0003,	// pushshort (value)
			0x2c => 0x0001,	// pushstring (string_index)
			0x26 => 0x0000,	// pushtrue
			0x2e => 0x0001,	// pushuint (uint_index)
			0x21 => 0x0000,	// pushundefined
			0x1c => 0x0000,	// pushwith
			0x48 => 0x0000,	// returnvalue
			0x47 => 0x0000,	// returnvoid
			0xa6 => 0x0000,	// rshift
			0x6f => 0x0001,	// setglobalslot (slot_index)
			0x63 => 0x0001,	// setlocal (reg_index)
			0xd4 => 0x0000,	// setlocal_0
			0xd5 => 0x0000,	// setlocal_1
			0xd6 => 0x0000,	// setlocal_2
			0xd7 => 0x0000,	// setlocal_3
			0x61 => 0x0001,	// setproperty (mname_index)
			0x6d => 0x0001,	// setslot (slot_index)
			0x05 => 0x0001,	// setsuper (mname_index)
			0x3d => 0x0000,	// sf32
			0x3d => 0x0000,	// sf64
			0x3a => 0x0000,	// si8
			0x3b => 0x0000,	// si16
			0x3c => 0x0000,	// si32
			0xac => 0x0000,	// strictequals
			0xa1 => 0x0000,	// subtract
			0xc6 => 0x0000,	// subtract_i
			0x2b => 0x0000,	// swap
			0x50 => 0x0000,	// sxi_1
			0x51 => 0x0000,	// sxi_8
			0x52 => 0x0000,	// sxi_16
			0x03 => 0x0000,	// throw
			0x95 => 0x0000,	// typeof
			0xa7 => 0x0000,	// urshift	
		);
		
		$c = $byteCodes;
		$p = 0;
		$l = strlen($byteCodes);
		$ops = array();
		while($p < $l) {
			$ip = $p;
			$op = new ABCOp;
			$op->code = ord($c[$p++]);
			$op->name = $nameTable[$op->code];
			$types = $operandTable[$op->code];
			$count = 1;
			while($types) {
				switch($types & 0x000F) {
					case 1:	// U30
						$s = 0;
						$v = 0;
						do { 
							$b = ord($c[$p++]);
							$v |= ($b & 0x7F) << $s;
							$s += 7;
						} while($b & 0x80);
						break;
					case 2: // S24
						$b1 = ord($c[$p++]);
						$b2 = ord($c[$p++]);
						$b3 = ord($c[$p++]);
						$v = ($b3 << 16) | ($b2 << 8) | $b1;
						if($v & 0x00800000) {
							$v |= -1 << 24;
						}
						break;
						
					case 3: // S30
						$s = 0;
						$v = 0;
						do { 
							$b = ord($c[$p++]);
							$v |= ($b & 0x7F) << $s;
							$s += 7;
						} while($b & 0x80);
						if($b & 0x40) {
							$v |= -1 << $s;
						}
						break;
					case 4: // S8
						$v = ord($c[$p++]);
						if($v & 0x80) {
							$v |= -1 << 8;		// fill in the remaining upper bits
						}
						break;
					case 5:
						$v = ord($c[$p++]);
						break;
				}
				$name = "op" . $count++;
				$op->$name = $v;
				$types = ($types >> 4) & 0x0FFF;
			}
			
			if($op->code == 0x1b) {
				// finish reading the cases
				for($i = 0; $i < $op->op2; $i++) {
					$b1 = ord($c[$p++]);
					$b2 = ord($c[$p++]);
					$b3 = ord($c[$p++]);
					$v = ($b3 << 16) | ($b2 << 8) | $b1;
					if($v & 0x00800000) {
						$v |= -1 << 24;
					}
					$name = "op" . $count++;
					$op->$name = $v;					
				}
			}
			$ops[$ip] = $op;
		}
		return $ops;
	}
}

class ABCOp {
	public $code;
	public $name;
}

?>
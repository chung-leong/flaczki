<?

class AVM1Decoder {
	
	protected $byteCodes;
	protected $position;
	protected $nextPosition;
	protected $registers;
	protected $constantPool;

	public function decode($byteCodes) {
		$this->byteCodes = $byteCodes;
		$this->position = 0;
		$this->registers = null;
		$this->constantPool = null;
		$function = $this->decodeInstructions(strlen($byteCodes));
		return $function->operations;
	}
	
	protected function decodeInstructions($count, $registers = null) {
		static $opNames = array(
			0x0A => 'Add',
			0x47 => 'Add2',
			0x10 => 'And',
			0x33 => 'AsciiToChar',
			0x60 => 'BitAnd',
			0x63 => 'BitLShift',
			0x61 => 'BitOr',
			0x64 => 'BitRShift',
			0x65 => 'BitURShift',
			0x62 => 'BitXor',
			0x9E => 'Call',
			0x3D => 'CallFunction',
			0x52 => 'CallMethod',
			0x2B => 'CastOp',
			0x32 => 'CharToAscii',
			0x24 => 'CloneSprite',
			0x88 => 'ConstantPool',
			0x51 => 'Decrement',
			0x9B => 'DefineFunction',
			0x8E => 'DefineFunction2',
			0x3C => 'DefineLocal',
			0x41 => 'DefineLocal2',
			0x3A => 'Delete',
			0x3B => 'Delete2',
			0x0D => 'Divide',
			0x28 => 'EndDrag',
			0x46 => 'Enumerate',
			0x55 => 'Enumerate2',
			0x0E => 'Equals',
			0x49 => 'Equals2',
			0x69 => 'Extends',
			0x4E => 'GetMember',
			0x22 => 'GetProperty',
			0x34 => 'GetTime',
			0x83 => 'GetURL',
			0x9A => 'GetURL2',
			0x1C => 'GetVariable',
			0x81 => 'GotoFrame',
			0x9F => 'GotoFrame2',
			0x8C => 'GoToLabel',
			0x67 => 'Greater',
			0x9D => 'If',
			0x2C => 'ImplementsOp',
			0x50 => 'Increment',
			0x42 => 'InitArray',
			0x43 => 'InitObject',
			0x54 => 'InstanceOf',
			0x99 => 'Jump',
			0x0F => 'Less',
			0x48 => 'Less2',
			0x37 => 'MBAsciiToChar',
			0x36 => 'MBCharToAscii',
			0x35 => 'MBStringExtract',
			0x31 => 'MBStringLength',
			0x3F => 'Modulo',
			0x0C => 'Multiply',
			0x53 => 'NewMethod',
			0x40 => 'NewObject',
			0x04 => 'NextFrame',
			0x12 => 'Not',
			0x11 => 'Or',
			0x06 => 'Play',
			0x17 => 'Pop',
			0x05 => 'PrevFrame',
			0x96 => 'Push',
			0x4C => 'PushDuplicate',
			0x30 => 'RandomNumber',
			0x25 => 'RemoveSprite',
			0x3E => 'Return',
			0x4F => 'SetMember',
			0x23 => 'SetProperty',
			0x8B => 'SetTarget',
			0x20 => 'SetTarget2',
			0x1D => 'SetVariable',
			0x4D => 'StackSwap',
			0x27 => 'StartDrag',
			0x07 => 'Stop',
			0x09 => 'StopSounds',
			0x87 => 'StoreRegister',
			0x66 => 'StrictEquals',
			0x21 => 'StringAdd',
			0x13 => 'StringEquals',
			0x15 => 'StringExtract',
			0x68 => 'StringGreater',
			0x14 => 'StringLength',
			0x29 => 'StringLess',
			0x0B => 'Subtract',
			0x45 => 'TargetPath',
			0x08 => 'ToggleQualty',
			0x18 => 'ToInteger',
			0x4A => 'ToNumber',
			0x4B => 'ToString',
			0x26 => 'Trace',
			0x8F => 'Try',
			0x44 => 'TypeOf',
			0x8A => 'WaitForFrame',
			0x8D => 'WaitForFrame2',
			0x94 => 'With',
		);
		
		if($registers) {
			$savedRegisters = $this->registers;
			$this->registers = $registers;
		}		
		$endPosition = $this->position + $count;
		$ops = array();
		while($this->position < $endPosition) {
			$opcodePosition = $this->position;
			$opcode = $this->readUI8();
			if($opcode) {
				$name = $opNames[$opcode];
				$op = new AVM1Op;
				$op->name = $name;
				$op->code = $opcode;
				if($opcode >= 0x80) {
					$length = $this->readUI16();
					$this->nextPosition = $this->position + $length;
					$handler = "decode$name";
					$this->$handler($op);
				} 
				$ops[$opcodePosition] = $op;
			}
		}
		$function = new AVM1FunctionBody;
		$function->operations = $ops;		
		if($registers) {		
			$this->registers = $savedRegisters;
		}
		return $function;
	}
	
	protected function decodeCall($op) {
		/* no operands */
	}
	
	protected function decodeConstantPool($op) {
		$op->op1 = $count = $this->readUI16();
		$stringTable = array();
		for($i = 0; $i < $count; $i++) {
			$stringTable[] = $this->readString();
		}
		$op->op2 = $this->constantPool = $stringTable;
	}
	
	protected function decodeDefineFunction($op) {
		$op->op1 = $this->readString();
		$op->op2 = $argumentCount = $this->readUI16();
		$arguments = array();
		for($i = 0; $i < $argumentCount; $i++) {
			$argument = new AS2Variable;
			$arguments[] = $this->readString();
		}
		$op->op3 = $arguments;
		$op->op4 = $codeSize = $this->readUI16();
		$op->op5 = $this->decodeInstructions($codeSize);
	}
	
	protected function decodeDefineFunction2($op) {
		$op->op1 = $this->readString();
		$op->op2 = $argumentCount = $this->readUI16();
		$op->op3 = $registerCount = $this->readUI8();
		$op->op4 = $flags = $this->readUI16();
		$registers = array();
		$arguments = array();
		for($i = 0; $i < $argumentCount; $i++) {
			$index = $this->readUI8();
			$name = $this->readString();
			if($index) {
				$register = new AVM1Register;
				$register->index = $index;
				$register->name = $name;
				$arguments[] = $register;
				$registers[$index] = $register;
			} else {
				$arguments[] = $name;
			}
		}
		$op->op5 = $arguments;
		static $predefinedVariables = array(
			0x0001 => 'this',
			0x0004 => 'arguments',
			0x0010 => 'super',
			0x0040 => '_root',
			0x0080 => '_parent',
			0x0100 => '_global'
		);
		$index = 1;
		foreach($predefinedVariables as $flag => $name) {		
			if($flags & $flags) {
				$register = new AVM1Register;
				$register->index = $index++;
				$register->name = $name;
				$registers[$index] = $register;
			}
		}
		for($i = 1; $i < $registerCount; $i++) {
			if(!isset($registers[$i])) {
				$register = new AVM1Register;
				$register->index = $index++;
				$registers[$i] = $register;
			}
		}
		$op->op6 = $codeSize = $this->readUI16();
		$op->op7 = $this->decodeInstructions($codeSize, $registers);
	}
	
	protected function decodeGetURL($op) {
		$op->op1 = $this->readString();
		$op->op2 = $this->readString();
	}
	
	protected function decodeGetURL2($op) {
		$op->op1 = $this->readUI8();
	}
	
	protected function decodeGotoFrame($op) {
		$op->op1 = $this->readUI16();
	}
	
	protected function decodeGotoFrame2($op) {
		$op->op1 = $this->readUI16();
		$op->op2 = ($flags & 0x02) ? $this->readUI16() : null;
	}
	
	protected function decodeGoToLabel($op) {
		$op->op1 = $this->readString();
	}
	
	protected function decodeIf($op) {
		$op->op1 = $this->readSI16();
	}
	
	protected function decodeJump($op) {
		$op->op1 = $this->readSI16();
	}
	
	protected function decodePush($op) {
		$values = array();
		do {
			$type = $this->readUI8();
			switch($type) {
				case 0: $value = $this->readString(); break;
				case 1: $value = $this->readF32(); break;
				case 2: $value = null; break;
				case 3: $value = AVM1Undefined::$singleton; break;
				case 4: $value = $this->registers[ $this->readUI8() ]; break;
				case 5: $value = $this->readUI8(); break;
				case 6: $value = $this->readF64(); break;
				case 7: $value = $this->readUI32(); break;
				case 8: $value = $this->constantPool[ $this->readUI8() ]; break;
				case 9: $value = $this->constantPool[ $this->readUI16() ]; break;
			}
			$values[] = $value;
		} while($this->position < $this->nextPosition);
		$op->op1 = $values;
	}
	
	protected function decodeSetTarget($op) {
		$op->op1 = $this->readString();
	}
	
	protected function decodeStoreRegister($op) {
		$op->op1 = $this->registers[ $this->readUI8() ];
	}
	
	protected function decodeTry($op) {
		$op->op1 = $flags = $this->readUI8();
		$op->op2 = $trySize = $this->readUI16();
		$op->op3 = $catchSize = $this->readUI16();
		$op->op4 = $finallySize = $this->readUI16();
		if($flags & 0x04) {	// CatchInRegister
			$catchName = null;
			$op->op2 = $catchRegister = $this->registers[ $this->readUI8() ];
		} else {
			$op->op2 = $catchName = $this->readString();
			$catchRegister = null;
		}
		$op->op5 = $this->decodeInstructions($trySize);
		$op->op6 = ($flags & 0x01) ? $this->decodeInstructions($catchSize) : null;
		$op->op7 = ($flags & 0x02) ? $this->decodeInstructions($finallySize) : null;
		
	}
	
	protected function decodeWaitForFrame($op) {
		$op->op1 = $this->readUI16();
		
	}
	
	
	protected function decodeWaitForFrame2($op) {
		$op->op1 = $this->readUI8();
		
	}
	
	protected function decodeWith($op) {
	}
	
	protected function readString() {
		$zeroPos = strpos($this->byteCodes, "\x00", $this->position);
		if($zeroPos === false) {
			$zeroPos = strlen($this->byteCodes);
		}
		$length = $zeroPos - $this->position;
		$string = substr($this->byteCodes, $this->position, $length);
		$this->position += $length + 1;
		return $string;
	}
	
	protected function readBytes($count) {
		$data = substr($this->byteCodes, $this->position, $count);
		$this->position += $count;
		return $data;
	}
	
	protected function readUI8() {
		$value = ord($this->byteCodes[$this->position++]);
		return $value;
	}
	
	protected function readUI16() {
		$data = substr($this->byteCodes, $this->position, 2);
		$array = unpack('v', $data);
		$this->position += 2;
		return $array[1];
	}
	
	protected function readSI16() {
		$value = $this->readUI16();
		if($value & 0x00008000) {
			$value |= -1 << 16;
		}
		return $value;
	}
	
	protected function readUI32() {
		$data = substr($this->byteCodes, $this->position, 4);
		$array = unpack('V', $data);
		$this->position += 4;
		return $array[1];
	}
	
	protected function readF32() {
		$data = substr($this->byteCodes, $this->position, 4);
		$array = unpack('f', $data);
		$this->position += 4;
		return $array[1];
	}
	
	protected function readF64() {
		$data = substr($this->byteCodes, $this->position, 8);
		$array = unpack('d', $data);
		$this->position += 8;
		return $array[1];
	}
}

class AVM1Undefined {
	public static $singleton;
}
AVM1Undefined::$singleton = new AVM1Undefined;

class AVM1Register {
	public $index;
	public $name;
}

class AVM1FunctionBody {
	public $operations;
}

class AVM1Op {
	public $code;
	public $name;
}

?>
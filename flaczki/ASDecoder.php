<?php

class ASDecoder {

	public function decode($bytes) {
		static $nameTable = array(
			0x0A => 'Add',
			0x10 => 'And',
			0x33 => 'AsciiToChar',
			0x9E => 'Call',
			0x32 => 'CharToAscii',
			0x24 => 'CloneSprite',
			0x0D => 'Divide',
			0x0E => 'Equals',
			0x28 => 'EndDrag',
			0x22 => 'GetProperty',
			0x34 => 'GetTime',
			0x83 => 'GetURL',
			0x9A => 'GetURL2',
			0x1C => 'GetVariable',
			0x81 => 'GotoFrame',
			0x9F => 'GotoFrame2',
			0x9D => 'If',
			0x99 => 'Jump',
			0x0F => 'Less',
			0x37 => 'MBAsciiToChar',
			0x36 => 'MBCharToAscii',
			0x31 => 'MBStringLength',
			0x35 => 'MBStringExtract',
			0x0C => 'Multiply',
			0x04 => 'NextFrame',
			0x12 => 'Not',
			0x11 => 'Or',
			0x05 => 'PreviousFrame',
			0x06 => 'Play',
			0x17 => 'Pop',
			0x96 => 'Push',
			0x30 => 'RandomNumber',
			0x25 => 'RemoveSprite',
			0x23 => 'SetProperty',
			0x8B => 'SetTarget',
			0x20 => 'SetTarget2',
			0x1D => 'SetVariable',
			0x27 => 'StartDrag',
			0x07 => 'Stop',
			0x09 => 'StopSounds',
			0x13 => 'StringEquals',
			0x15 => 'StringExtract',
			0x14 => 'StringLength',
			0x21 => 'StringAdd',
			0x29 => 'StringLess',
			0x0B => 'Subtract',
			0x18 => 'ToInteger',
			0x08 => 'ToggleQuality',
			0x26 => 'Trace',
			0x8A => 'WaitForFrame',
			0x8D => 'WaitForFrame2',
		);

	}

}

class ASOp {
	public $code;
	public $name;
}


?>
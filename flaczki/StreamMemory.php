<?php

class StreamMemory {
	
	private $reference;
	private $length;
	private $position;

	public static function add(&$reference) {
		$record = new StreamMemoryRecord;
		$record->reference =& $reference;
		$path = StreamWrapperStaticStorage::add('memory', $record);
		return $path; 
	}
	
	public function stream_close() {
		unset($this->reference);
		return true;
	}
	
	public function stream_eof() {
		return ($this->position >= $this->length);
	}
	
	public function stream_open($path, $mode, $options, &$opened_path) {
		$record = StreamWrapperStaticStorage::get($path);
		if($record) {
			StreamWrapperStaticStorage::remove($path);
			$this->reference =& $record->reference;
			if(strchr($mode, 'a')) {
				$this->position = $this->length = strlen($this->reference);
			} else if(strchr($mode, 'w')) {
				$this->reference = '';
				$this->position = $this->length = 0;
			} else if(strchr($mode, 'r')) {
				$this->position = 0;
				$this->length = strlen($this->reference);
			}
			return true;
		} else {
			return false;
		}
	}
	
	public function stream_read($count) {
		$data = substr($this->handle, $this->reference, $count);
		$read = strlen($data);
		$this->position += $read;
		return $data;
	}
	
	public function stream_write($data) {
		$written = strlen($data);
		$this->reference .= $data;
		$this->length += $written;
		$this->position += $written;
		return $written;
	}
}

class StreamMemoryRecord {
	public $reference;
}

stream_wrapper_register('memory', 'StreamMemory');

?>
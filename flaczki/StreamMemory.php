<?php

class StreamMemory {
	
	const PROTOCOL = 'memory';
	
	private $bytes;
	private $length;
	private $position;

	// for writeable streams
	public static function create(&$bytes) {
		$record = new StreamMemoryRecord;
		$record->bytes =& $bytes;
		$path = StreamWrapperStaticStorage::add(self::PROTOCOL, $record);
		return $path; 
	}
	
	// for readable streams (pass by value to avoid trigger copy-on-write)
	public static function add($bytes) {
		$record = new StreamMemoryRecord;
		$record->bytes = $bytes;
		$path = StreamWrapperStaticStorage::add(self::PROTOCOL, $record);
		return $path; 
	}
	
	public function stream_close() {
		return true;
	}
	
	public function stream_eof() {
		return ($this->position >= $this->length);
	}
	
	public function stream_open($path, $mode, $options, &$opened_path) {
		$record = StreamWrapperStaticStorage::get($path);
		if($record) {
			StreamWrapperStaticStorage::remove($path);
			if(strchr($mode, 'a')) {
				$this->bytes =& $record->bytes;
				$this->position = $this->length = strlen($this->bytes);
			} else if(strchr($mode, 'w')) {
				$this->bytes =& $record->bytes;
				$this->bytes = '';
				$this->position = $this->length = 0;
			} else if(strchr($mode, 'r')) {
				$this->bytes = $record->bytes;
				$this->position = 0;
				$this->length = strlen($this->bytes);
			}
			return true;
		} else {
			return false;
		}
	}
	
	public function stream_read($count) {
		$data = substr($this->handle, $this->bytes, $count);
		$read = strlen($data);
		$this->position += $read;
		return $data;
	}
	
	public function stream_write($data) {
		$written = strlen($data);
		$this->bytes .= $data;
		$this->length += $written;
		$this->position += $written;
		return $written;
	}
}

class StreamMemoryRecord {
	public $bytes;
}

stream_wrapper_register(StreamMemory::PROTOCOL, 'StreamMemory');

?>
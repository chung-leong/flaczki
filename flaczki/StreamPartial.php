<?php

class StreamPartial {

	private $handle;
	private $length;
	private $position;
	private $header;
	
	public static function add($handle, $length, $header = null) {
		$record = new StreamPartialRecord;
		$record->handle = $handle;
		$record->length = $length;
		if($header) {
			$record->header = $header;
			$record->length += strlen($header);
		}
		$path = StreamWrapperStaticStorage::add('partial', $record);
		return $path; 
	}
	
	public function stream_close() {
		// make sure the stream is read to the end
		while(!$this->stream_eof()) {
			$this->stream_read(1024);
		}
		return true;
	}
	
	public function stream_eof() {
		return ($this->position >= $this->length);
	}
	
	public function stream_open($path, $mode, $options, &$opened_path) {
		$record = StreamWrapperStaticStorage::get($path);
		if($record) {
			StreamWrapperStaticStorage::remove($path);
			$this->handle = $record->handle;
			$this->header = $record->header;
			$this->length = $record->length;
			$this->position = 0;
			return true;
		} else {
			return false;
		}
	}
		
	public function stream_read($count) {	
		$extra = false;
		if($this->position < strlen($this->header)) {
			$extra = substr($this->header, $this->position, $count);
			$read = strlen($extra);
			$count -= $read;
			$this->position += $read;
		} 
		$remaining = $this->length - $this->position;
		if($count > $remaining) {
			$count = $remaining;
		}
		$data = ($count > 0) ? fread($this->handle, $count) : false;
		if($data === false) {
			return $extra;
		}
		$read = strlen($data);
		$this->position += $read;
		return $extra . $data;
	}
}

class StreamPartialRecord {
	public $handle;
	public $length;
	public $header;
}

stream_wrapper_register('partial', 'StreamPartial');

?>
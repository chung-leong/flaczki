<?php

class StreamProxy {
	
	const PROTOCOL = 'proxy';
	
	private $handle;
	private $count;

	public static function add($handle, &$count = 0) {
		$record = new StreamProxyRecord;
		$record->handle = $handle;
		$record->count =& $count;
		$path = StreamWrapperStaticStorage::add(self::PROTOCOL, $record);
		return $path; 
	}
	
	public function stream_close() {
		return true;
	}
	
	public function stream_eof() {
		return feof($this->handle);
	}
	
	public function stream_open($path, $mode, $options, &$opened_path) {
		$record = StreamWrapperStaticStorage::get($path);
		if($record) {
			StreamWrapperStaticStorage::remove($path);
			$this->handle = $record->handle;
			$this->count =& $record->count;
			$this->count = 0;
			return true;
		} else {
			return false;
		}
	}
	
	public function stream_read($count) {
		$read = fread($this->handle, $count);
		$this->count += $read;
		return $read;
	}
	
	public function stream_write($count) {
		$written = fwrite($this->handle, $count);
		$this->count += $written;
		return $written;
	}
}

class StreamProxyRecord {
	public $handle;
	public $count;
}

stream_wrapper_register(StreamProxy::PROTOCOL, 'StreamProxy');

?>
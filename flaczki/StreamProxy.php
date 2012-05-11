<?php

class StreamProxy {
	
	private $handle;

	public static function add($handle) {
		$record = new StreamProxyRecord;
		$record->handle = $handle;
		$path = StreamWrapperStaticStorage::add('proxy', $record);
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
			return true;
		} else {
			return false;
		}
	}
	
	public function stream_read($count) {
		return fread($this->handle, $count);
	}
	
	public function stream_write($count) {
		return fwrite($this->handle, $count);
	}
}

class StreamProxyRecord {
	public $handle;
}

stream_wrapper_register('proxy', 'StreamProxy');

?>
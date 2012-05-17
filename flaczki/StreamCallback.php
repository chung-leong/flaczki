<?php

class StreamCallback {

	const PROTOCOL = 'callback';

	private $eof;
	private $callback;
	
	public static function add($callback) {
		$path = StreamWrapperStaticStorage::add(self::PROTOCOL, $callback);
		return $path; 
	}
	
	public function stream_close() {
		return true;
	}
	
	public function stream_eof() {
		return $this->eof;
	}
	
	public function stream_open($path, $mode, $options, &$opened_path) {
		$callback = StreamWrapperStaticStorage::get($path);
		if($callback) {
			StreamWrapperStaticStorage::remove($path);
			$this->callback = $callback;
			return true;
		}
		return false;
	}

	public function stream_read($count) {
		if(!$this->eof) {
			$data = call_user_func($this->callback, $count);
			if($data === false) {
				$this->eof = true;
			}
			return $data;
		}
		return false;
	}
	
	public function stream_write($data) {
		return call_user_func($this->callback, $data);
	}
}

stream_wrapper_register(StreamCallback::PROTOCOL, 'StreamCallback');

?>
<?php

class StreamZipDDDelimited {

	private $handle;
	private $position;
	private $path;
	private $eof;
	
	public static function add($handle) {
		$record = new ZipDDDelimitedStreamRecord;
		$record->handle = $handle;
		$path = StreamWrapperStaticStorage::add('zipaddd', $record);
		return $path; 
	}
	
	public function stream_close() {
		// make sure the stream is read to the end
		while(!$this->stream_eof()) {
			$this->stream_read(1024);
		}
		return true;
	}
	
	public function stream_open($path, $mode, $options, &$opened_path) {
		$record = StreamWrapperStaticStorage::get($path);
		if($record) {
			StreamWrapperStaticStorage::remove($path);
			$this->handle = $record->handle;
			$this->position = 0;
			$this->eof = false;
			return true;
		} else {
			return false;
		}
	}
	
	public function stream_eof() {
		return $this->eof;
	}
	
	public function stream_read($count) {	
		$read = 0;
		$data = false;
		// make sure we don't read beyond the data descriptor
		if(!$this->eof) {
			while($read < $count) {
				$b1 = fread($this->handle, 1);
				if($b1 === "\x50") {
					$b2 = fread($this->handle, 1);
					if($b2 === "\x4b") {
						$b3 = fread($this->handle, 1);
						if($b3 === "\x07") {
							$b4 = fread($this->handle, 1);
							if($b4 === "\x08") {
								$descriptor = fread($this->handle, 12);
								$array = unpack("Vcrc32/VcompressedSize/VuncompressedSize", $descriptor);
								// can't verify the crc32 or the uncompressed size
								if($array['compressedSize'] == ($this->position + $read)) {
									$this->eof = true;
									break;
								} else {
									$data .= "\x50\x04\x07\x08" . $descriptor;
									$read += 4 + strlen($descriptor);
								}
							} else {
								$data .= "\x50\x04\x07" . $b4;
								$read += 4;
							}
						} else {
							$data .= "\x50\x04" . $b3;
							$read += 3;
						}
					} else {
						$data .= "\x50" . $b2;
						$read += 2;
					}
				} else if($b1 != '') {
					$data .= $b1;
					$read++;
				} else {
					break;
				}
			}
			if($read == 0) {
				$this->eof = true;
			} else {
				$this->position += $read;
			}
		}
		return $data;
	}
}

class StreamZipDDDelimitedRecord {
	public $handle;
}

stream_wrapper_register('zipddd', 'StreamZipDDDelimited');

?>
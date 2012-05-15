<?php

class StreamZipArchive {

	const PROTOCOL = 'ziparchive';
	
	private $archive;	
	private $stream;
	private $eof = false;
	private $dirPath = '';

	public static function open($handle) {
		$archive = new StreamZipArchiveRecord;
		$archive->handle = $handle;
		$archive->path = StreamWrapperStaticStorage::add(self::PROTOCOL, $archive);
		return $archive->path; 
	}
	
	public static function create($handle) {
		$archive = new StreamZipArchiveRecord;
		$archive->handle = $handle;
		$archive->writable = true;
		$archive->path = StreamWrapperStaticStorage::add(self::PROTOCOL, $archive);		
		$datetime = date('Y n j G i s');
		sscanf($datetime, '%d %d %d %d %d %d', $year, $month, $day, $hour, $min, $sec);
		$archive->time = ($hour << 11) | ($min << 5) | ($sec >> 1);
		$archive->date = (($year - 1980) << 9) | ($month << 5) | $day;
		return $archive->path; 
	}
	
	public static function close($path, $comments = '') {
		$archive = StreamWrapperStaticStorage::get($path);
		if($archive) {
			$archive->comments = $comments;
			if($archive->activeStream) {
				$archive->activeStream->stream_close();
			}
			
			// write the central directory
			self::writeCentralDirectory($archive);
			fclose($archive->handle);
			StreamWrapperStaticStorage::remove($path);
		}
	}
	
	public static function setCompressionLevel($path, $compressionLevel) {
		$archive = StreamWrapperStaticStorage::get($path);
		if($archive) {
			$archive->compressionLevel = $compressionLevel;
		} 
	}
	
	public function dir_closedir() {
	}
	
	public function dir_opendir($path, $options) {
		$rootPath = $this->getArchivePath($path);
		$zipPath = substr($path, strlen($rootPath) + 1);
		$zipPath = str_replace('\\', '/', $zipPath);
		$archive = StreamWrapperStaticStorage::get($rootPath);
		if($archive && !$archive->writable) {
			$this->archive = $archive;
			if($zipPath) {
				if(substr($zipPath, -1) != '/') {
					$zipPath .= '/';
				}
				$this->dirPath = $zipPath;
			}
			return true;
		}
		return false;
	}
	
	public function dir_readdir() {
		$archive = $this->archive;
		if(!$archive->currentFileRecord || $archive->currentFileRecord->name == $archive->lastReturnedPath) {
			// read in a new record
			$this->readNextLocalHeader();
		}
		while($fileRecord = $archive->currentFileRecord) {			
			$pathLen = strlen($this->dirPath);
			if(!$pathLen || (strlen($fileRecord->name) > $pathLen && substr_compare($fileRecord->name, $this->dirPath, 0, $pathLen) == 0)) {
				$relativePath = substr($fileRecord->name, strlen($this->dirPath));
				$slashPos = strpos($relativePath, '/');
				if($slashPos === false) {
					// a file in this directory
					$fullPath = $fileRecord->name;
				} else {
					// a file in a subdirectory
					$relativePath = substr($relativePath, 0, $slashPos);
					$fullPath = $this->dirPath . $relativePath;
				}
				if($archive->lastReturnedPath == $fullPath) {
					// it's the same as the last one we returned--read another header
					$this->readNextLocalHeader();
				} else {					
					// return it
					$archive->lastReturnedPath = $fullPath;
					return $relativePath;
				}
			} else {
				break;
			}
		}
		return false;
	}
	
	public function dir_rewinddir() {
		return false;
	}
	
	public function stream_close() {
		$archive = $this->archive;
		$fileRecord = $archive->currentFileRecord;
		if($archive->activeStream === $this) {
			if($archive->writable) {
				if(!$this->stream) {
					// stream is closing before the write buffer filled up
					// we can figure out the crc32 and compressed size ahead of time then
					$fileRecord->crc32 = crc32($archive->buffer);
					$fileRecord->uncompressedSize = strlen($archive->buffer);
					if($archive->compressionLevel == 0) {
						$data = $archive->buffer;
						$fileRecord->compressedSize = $fileRecord->uncompressedSize;
					} else {
						$data = gzdeflate($archive->buffer, $archive->compressionLevel);
						$fileRecord->compressedSize = strlen($data);
					}
					$this->writeLocalHeader();
					$archive->position += fwrite($archive->handle, $data);
				} else {
					// need to add a data descriptor following the data
					fclose($this->stream);
					self::writeDataDescriptor($archive, $fileRecord);
				}
			} else {
				fclose($this->stream);
			}
			$archive->activeStream = null;
		}
		return true;
	}
	
	public function stream_eof() {
		return $this->eof;
	}
	
	public function stream_open($path, $mode, $options, &$opened_path) {
		$rootPath = $this->getArchivePath($path);
		$zipPath = substr($path, strlen($rootPath) + 1);
		$zipPath = str_replace('\\', '/', $zipPath);
		$archive = StreamWrapperStaticStorage::get($rootPath);
		if($archive) {
			// only one stream can be active at a given time
			if($archive->activeStream) {
				$archive->activeStream->stream_close();
			}
			if((strchr($mode, 'r') && !$archive->writable)) {
				// can only open the current file record
				if($archive->currentFileRecord->name == $zipPath) {
					$this->archive = $archive;
					$path = StreamCallback::add(array($this, 'stream_read_raw'));
					$this->stream = fopen($path, "rb");
					if($archive->currentFileRecord->compressionMethod == 8) {
						stream_filter_append($this->stream, 'zlib.inflate', STREAM_FILTER_READ);
					}
					$archive->activeStream = $this;
					return true;
				}
			} else if((strchr($mode, 'w') && $archive->writable)) {
				if(!isset($archive->fileRecords[$zipPath])) {
					$this->archive = $archive;
					$fileRecord = new StreamZipArchiveFileRecord;
					$fileRecord->offset = $archive->position;
					$fileRecord->name = $zipPath;
					$fileRecord->compressionMethod = ($archive->compressionLevel == 0) ? 0 : 8;
					$archive->currentFileRecord = $archive->fileRecords[$zipPath] = $fileRecord;
					$archive->activeStream = $this;
					return true;
				}
			}
		}
		return false;
	}
		
	public function stream_read($count) {
		$archive = $this->archive;
		$fileRecord = $archive->currentFileRecord;
		if(!$archive->writable && $archive->activeStream == $this) {
			if(!$this->eof) {
				$data = fread($this->stream, $count);
				if($data) {
					$count = strlen($data);
					if($count > 0) {
						if(($fileRecord->flags & 0x08)) {
							// update the size and crc32
							$fileRecord->uncompressedSize += $count;
							$fileRecord->crc32 = $this->combineCRC32($fileRecord->crc32, crc32($data), $count);
						}
					}
				} else {
					$this->eof = true;
				}
				return $data;
			}
		}
		return false;
	}
	
	public function stream_read_raw($count) {
		$archive = $this->archive;
		$fileRecord = $archive->currentFileRecord;
		$data = false;
		if($fileRecord) {
			$bufferedCount = strlen($archive->buffer);
			if($bufferedCount < $count) {
				$archive->buffer .= fread($archive->handle, max($count - $bufferedCount, 64));
				$bufferedCount = strlen($archive->buffer);
			}			
			if(!($fileRecord->flags & 0x08)) {
				// the compressed size is known
				$bytesRead = $archive->position - $fileRecord->offset;
				$bytesRemaining = $fileRecord->compressedSize - $bytesRead;
				if($count > $bytesRemaining) {
					$count = $bytesRemaining;
				}
				if($count > 0) {
					if($count < $bufferedCount) {
						$data = substr($archive->buffer, 0, $count);
						$archive->buffer = substr($archive->buffer, $count);
						$archive->position += $count;
					} else {
						$data = $archive->buffer;
						$archive->buffer = '';
						$archive->position += $bufferedCount;
					}
				}
			} else {
				// the compressed size is not known
				// need to look for the data descriptor signature 0x08074b50
				$signaturePos = strpos($archive->buffer, "\x50\x4b\x07\x08");
				if($signaturePos === false) {
					// return three bytes less, in case the buffer ends in the middle of a signature
					if($bufferedCount > 3) {
						$data = substr($archive->buffer, 0, $bufferedCount - 3);
						$archive->buffer = substr($archive->buffer, $bufferedCount - 3);
						$archive->position += $bufferedCount - 3;
					} else {
						$data = '';
					}
				} else {
					if($signaturePos > 0) {
						// return bytes up to the possible data descriptor
						$data = substr($archive->buffer, 0, $signaturePos);
						$archive->buffer = substr($archive->buffer, $signaturePos);
						$archive->position += $signaturePos;
					} else {
						// see if the compressed size match
						$array = unpack('Vsignature/Vcrc32/VcompressedSize/VuncompressedSize', $archive->buffer);
						if($array['compressedSize'] == $fileRecord->compressedSize) {
							$archive->buffer = substr($archive->buffer, 16);
							$archive->position += 16;
							$fileRecord->flags &= ~0x08;
						} else {
							$data = substr($archive->buffer, 0, 4);
							$archive->buffer = substr($archive->buffer, 4);
							$archive->position += 4;
						}
					}
				}
				// update the file entry
				if($data) {
					$fileRecord->compressedSize += strlen($data);
				}
			}		
		}
		return $data;
	}
	
	public function stream_stat() {
		$archive = $this->archive;
		$fileRecord = $archive->currentFileRecord;
		if($archive->activeStream == $this) {
			$time = $this->convertDosDateTime($fileRecord->date, $fileRecord->time);
			$mode = ($archive->writable) ? 0222 : 0444;
			return array(
				'dev' => 0,
				'ino' => 0,
				'mode' => $mode,
				'nlink' => 1,
				'uid' => 0,
				'gid' => 0,
				'rdev' => 0,
				'size' => $fileRecord->uncompressedSize,
				'atime' => $time,
				'mtime' => $time,
				'ctime' => $time,
				'blksize' => -1,
				'blocks' => -1
			);
		}
		return false;
	}
	
	public function stream_write($data) {
		$archive = $this->archive;
		$fileRecord = $archive->currentFileRecord;
		if($archive->writable && $archive->activeStream === $this) {
			$count = strlen($data);
						
			// we're still filling up the buffer
			if(!$this->stream) {
				$bufferedCount = strlen($archive->buffer);
				if($bufferedCount + $count < 1024) {
					$archive->buffer .= $data;
					return $count;
				} else {
					// write the local header without empty size and crc
					// need a data descriptor to follow the data
					$fileRecord->flags |= 0x08;					
					$this->writeLocalHeader($archive, $fileRecord);

					// create a callback stream that calls stream_write_raw() and append a deflate filter to it
					// this allows use to figure out what the compressed size is
					$path = StreamCallback::add(array($this, 'stream_write_raw'));
					$this->stream = fopen($path, "wb");
					if($fileRecord->compressionMethod == 8) {					
						$params = array('level' => $this->archive->compressionLevel);
						stream_filter_append($this->stream, 'zlib.deflate', STREAM_FILTER_WRITE, $params);
					}
					
					// set the initial CRC32 value
					$fileRecord->crc32 = crc32($archive->buffer);
					$fileRecord->uncompressedSize += $bufferedCount;
					
					// write the buffered data
					$written = fwrite($this->stream, $archive->buffer);
					$archive->buffer = '';
				}
			} 
			// update the CRC32
			$fileRecord->crc32 = $this->combineCRC32($fileRecord->crc32, crc32($data), $count);
			$fileRecord->uncompressedSize += $count;
			return fwrite($this->stream, $data);
		}
		return false;
	}
	
	public function stream_write_raw($data) {
		$archive = $this->archive;
		$fileRecord = $archive->currentFileRecord;
		$fileRecord->compressedSize += strlen($data);
		$written = fwrite($archive->handle, $data);
		$archive->position += $written;
		return $written;
	}
	
	public function url_stat($url, $flags) {
		$rootPath = $this->getArchivePath($url);
		$zipPath = substr($url, strlen($rootPath) + 1);
		$zipPath = str_replace('\\', '/', $zipPath);
		$archive = StreamWrapperStaticStorage::get($rootPath);
		if($archive) {
			$this->archive = $archive;
			if(isset($archive->fileRecords[$zipPath])) {
				$fileRecord = $archive->fileRecords[$zipPath];
				$time = $this->convertDosDateTime($fileRecord->date, $fileRecord->time);
				$mode = ($archive->writable) ? 0222 : 0444;
				return array(
					'dev' => 0,
					'ino' => 0,
					'mode' => $mode,
					'nlink' => 1,
					'uid' => 0,
					'gid' => 0,
					'rdev' => 0,
					'size' => $fileRecord->uncompressedSize,
					'atime' => $time,
					'mtime' => $time,
					'ctime' => $time,
					'blksize' => -1,
					'blocks' => -1
				);
			} else if($fileRecord = $this->findDirectory($zipPath)) {
				// a directory by that name exists
				$time = $this->convertDosDateTime($fileRecord->date, $fileRecord->time);
				$mode = (($archive->writable) ? 0222 : 0444) | 040000;
				return array(
					'dev' => 0,
					'ino' => 0,
					'mode' => $mode,
					'nlink' => 1,
					'uid' => 0,
					'gid' => 0,
					'rdev' => 0,
					'size' => 0,
					'atime' => $time,
					'mtime' => $time,
					'ctime' => $time,
					'blksize' => -1,
					'blocks' => -1
				);
			}
		}
		return false;
	}
	
	private function getArchivePath($path) {
		$si = strpos($path, '://') + 3;
		$ei = strpos($path, '/', $si);
		return ($ei) ? substr($path, 0, $ei) : $path;
	}
		
	private function writeLocalHeader() {
		$archive = $this->archive;
		$fileRecord = $archive->currentFileRecord;
		$bytes = pack("Vv5V3v2", 0x04034b50, 20, $fileRecord->flags, $fileRecord->compressionMethod, $archive->time, $archive->date, $fileRecord->crc32, $fileRecord->compressedSize, $fileRecord->uncompressedSize, strlen($fileRecord->name), strlen($fileRecord->extra)) . $fileRecord->name . $fileRecord->extra;
		$archive->position += fwrite($archive->handle, $bytes);
	}
	
	private function writeDataDescriptor() {
		$archive = $this->archive;
		$fileRecord = $archive->currentFileRecord;
		$bytes = pack("V4", 0x08074b50, $fileRecord->crc32, $fileRecord->compressedSize, $fileRecord->uncompressedSize);
		$archive->position += fwrite($archive->handle, $bytes);
	}
	
	private static function writeCentralDirectory($archive) {
		$offset = $archive->position;
		$recordCount = 0;
		$directorySize = 0;
		foreach($archive->fileRecords as $fileRecord) {
			$bytes = pack('Vv6V3v5V2', 0x02014b50, 20, 20, $fileRecord->flags, $fileRecord->compressionMethod, $archive->time, $archive->date, $fileRecord->crc32, $fileRecord->compressedSize, $fileRecord->uncompressedSize, strlen($fileRecord->name), strlen($fileRecord->extra), 0, 0, 0, $fileRecord->attributes, $fileRecord->offset) . $fileRecord->name . $fileRecord->extra;
			$archive->position += fwrite($archive->handle, $bytes);
			$recordCount++;
			$directorySize += strlen($bytes);
		}
		
		$bytes = pack('Vv4V2v', 0x06054b50, 0, 0, $recordCount, $recordCount, $directorySize, $offset, strlen($archive->comments)) . $archive->comments;
		$archive->position += fwrite($archive->handle, $bytes);
	}
	
	private function readNextLocalHeader() {
		$archive = $this->archive;
		while($this->stream_read_raw(1024)) {
			// remove any file content
		}
		$bufferedCount = strlen($archive->buffer);		
		if($bufferedCount < 30) {
			// there should be at least 30 bytes in the read buffer
			$archive->buffer .= fread($archive->handle, 64);
			$bufferedCount = strlen($archive->buffer);
		}
		$archive->currentFileRecord = null;
		while($bufferedCount >= 30) {
			$array = unpack("Vsignature/vversion/vflags/vmethod/vlastModifiedTime/vlastModifiedDate/Vcrc32/VcompressedSize/VuncompressedSize/vnameLength/vextraLength", $archive->buffer);
			if($array['signature'] == 0x04034b50) {
				$fileRecord = new StreamZipArchiveFileRecord;
				$fileRecord->flags = $array['flags'];
				$fileRecord->compressionMethod = $array['method'];
				$fileRecord->crc32 = $array['crc32'];
				$fileRecord->compressedSize = $array['compressedSize'];
				$fileRecord->uncompressedSize = $array['uncompressedSize'];				
				$bufferedCount -= 30;				
				$archive->buffer = substr($archive->buffer, 30);				
				$archive->position += 30;
				$nameLength = $array['nameLength'];
				$extraLength = $array['extraLength'];
				if($bufferedCount < $nameLength + $extraLength) {
					$archive->buffer .= fread($archive->handle, $nameLength + $extraLength);
				}
				$fileRecord->name = substr($archive->buffer, 0, $nameLength);
				$fileRecord->extra = substr($archive->buffer, $nameLength, $extraLength);
				$archive->buffer = substr($archive->buffer, $nameLength + $extraLength);
				$archive->position += $nameLength + $extraLength;
				$fileRecord->offset = $archive->position;
				$archive->fileRecords[$fileRecord->name] = $archive->currentFileRecord = $fileRecord;
				return true;
			} else if($array['signature'] == 0x02014b50) {
				// we've reached the central directory
				break;
			} else {
				// shift one byte forward and try again
				$archive->buffer = substr($archive->buffer, 1);
				$archive->position++;
				$bufferedCount--;
				if($bufferedCount < 30) {
					$archive->buffer .= fread($archive->handle, 64);
					$bufferedCount = strlen($archive->buffer);
				}
			}
		}
		return false;
	}
	
	private function convertDosDateTime($date, $time) {
		return mktime(($time >> 11) & 0x1F, ($time >> 5) & 0x3F, ($time << 1) & 0x1F, ($date >> 5) & 0x0F, $date & 0x1F, ($date >> 9) & 0x7F);	
	}
	
	private function findDirectory($zipPath) {
		$archive = $this->archive;
		if(substr($zipPath, -1) != '/') {
			$zipPath .= '/';
		}
		$pathLen = strlen($zipPath);
		foreach($archive->fileRecords as $filePath => $fileRecord) {
			if(strlen($filePath) > $pathLen && substr_compare($filePath, $zipPath, 0, $pathLen) == 0) {
				return $fileRecord;
			}
		}
		return false;
	}
	
	private function combineCRC32($crc1, $crc2, $len2) {
		// precalculated matrices (valid up to $len2 = 2147483647)
		static $matrices = array(
			array(	0x76dc4190,0xedb88320,0x00000001,0x00000002,0x00000004,0x00000008,0x00000010,0x00000020,
				0x00000040,0x00000080,0x00000100,0x00000200,0x00000400,0x00000800,0x00001000,0x00002000,
				0x00004000,0x00008000,0x00010000,0x00020000,0x00040000,0x00080000,0x00100000,0x00200000,
				0x00400000,0x00800000,0x01000000,0x02000000,0x04000000,0x08000000,0x10000000,0x20000000),
			array(	0x1db71064,0x3b6e20c8,0x76dc4190,0xedb88320,0x00000001,0x00000002,0x00000004,0x00000008,
				0x00000010,0x00000020,0x00000040,0x00000080,0x00000100,0x00000200,0x00000400,0x00000800,
				0x00001000,0x00002000,0x00004000,0x00008000,0x00010000,0x00020000,0x00040000,0x00080000,
				0x00100000,0x00200000,0x00400000,0x00800000,0x01000000,0x02000000,0x04000000,0x08000000),
			array(	0x77073096,0xee0e612c,0x076dc419,0x0edb8832,0x1db71064,0x3b6e20c8,0x76dc4190,0xedb88320,
				0x00000001,0x00000002,0x00000004,0x00000008,0x00000010,0x00000020,0x00000040,0x00000080,
				0x00000100,0x00000200,0x00000400,0x00000800,0x00001000,0x00002000,0x00004000,0x00008000,
				0x00010000,0x00020000,0x00040000,0x00080000,0x00100000,0x00200000,0x00400000,0x00800000),
			array(	0x191b3141,0x32366282,0x646cc504,0xc8d98a08,0x4ac21251,0x958424a2,0xf0794f05,0x3b83984b,
				0x77073096,0xee0e612c,0x076dc419,0x0edb8832,0x1db71064,0x3b6e20c8,0x76dc4190,0xedb88320,
				0x00000001,0x00000002,0x00000004,0x00000008,0x00000010,0x00000020,0x00000040,0x00000080,
				0x00000100,0x00000200,0x00000400,0x00000800,0x00001000,0x00002000,0x00004000,0x00008000),
			array(	0xb8bc6765,0xaa09c88b,0x8f629757,0xc5b428ef,0x5019579f,0xa032af3e,0x9b14583d,0xed59b63b,
				0x01c26a37,0x0384d46e,0x0709a8dc,0x0e1351b8,0x1c26a370,0x384d46e0,0x709a8dc0,0xe1351b80,
				0x191b3141,0x32366282,0x646cc504,0xc8d98a08,0x4ac21251,0x958424a2,0xf0794f05,0x3b83984b,
				0x77073096,0xee0e612c,0x076dc419,0x0edb8832,0x1db71064,0x3b6e20c8,0x76dc4190,0xedb88320),
			array(	0xccaa009e,0x4225077d,0x844a0efa,0xd3e51bb5,0x7cbb312b,0xf9766256,0x299dc2ed,0x533b85da,
				0xa6770bb4,0x979f1129,0xf44f2413,0x33ef4e67,0x67de9cce,0xcfbd399c,0x440b7579,0x8816eaf2,
				0xcb5cd3a5,0x4dc8a10b,0x9b914216,0xec53826d,0x03d6029b,0x07ac0536,0x0f580a6c,0x1eb014d8,
				0x3d6029b0,0x7ac05360,0xf580a6c0,0x30704bc1,0x60e09782,0xc1c12f04,0x58f35849,0xb1e6b092),
			array(	0xae689191,0x87a02563,0xd4314c87,0x73139f4f,0xe6273e9e,0x173f7b7d,0x2e7ef6fa,0x5cfdedf4,
				0xb9fbdbe8,0xa886b191,0x8a7c6563,0xcf89cc87,0x44629f4f,0x88c53e9e,0xcafb7b7d,0x4e87f0bb,
				0x9d0fe176,0xe16ec4ad,0x19ac8f1b,0x33591e36,0x66b23c6c,0xcd6478d8,0x41b9f7f1,0x8373efe2,
				0xdd96d985,0x605cb54b,0xc0b96a96,0x5a03d36d,0xb407a6da,0xb37e4bf5,0xbd8d91ab,0xa06a2517),
			array(	0xf1da05aa,0x38c50d15,0x718a1a2a,0xe3143454,0x1d596ee9,0x3ab2ddd2,0x7565bba4,0xeacb7748,
				0x0ee7e8d1,0x1dcfd1a2,0x3b9fa344,0x773f4688,0xee7e8d10,0x078c1c61,0x0f1838c2,0x1e307184,
				0x3c60e308,0x78c1c610,0xf1838c20,0x38761e01,0x70ec3c02,0xe1d87804,0x18c1f649,0x3183ec92,
				0x6307d924,0xc60fb248,0x576e62d1,0xaedcc5a2,0x86c88d05,0xd6e01c4b,0x76b13ed7,0xed627dae),
			array(	0x8f352d95,0xc51b5d6b,0x5147bc97,0xa28f792e,0x9e6ff41d,0xe7aeee7b,0x142cdab7,0x2859b56e,
				0x50b36adc,0xa166d5b8,0x99bcad31,0xe8085c23,0x0b61be07,0x16c37c0e,0x2d86f81c,0x5b0df038,
				0xb61be070,0xb746c6a1,0xb5fc8b03,0xb0881047,0xba6126cf,0xafb34bdf,0x841791ff,0xd35e25bf,
				0x7dcd4d3f,0xfb9a9a7e,0x2c4432bd,0x5888657a,0xb110caf4,0xb95093a9,0xa9d02113,0x88d14467),
			array(	0x33fff533,0x67ffea66,0xcfffd4cc,0x448eafd9,0x891d5fb2,0xc94bb925,0x49e6740b,0x93cce816,
				0xfce8d66d,0x22a0aa9b,0x45415536,0x8a82aa6c,0xce745299,0x4799a373,0x8f3346e6,0xc5178b8d,
				0x515e115b,0xa2bc22b6,0x9e09432d,0xe763801b,0x15b60677,0x2b6c0cee,0x56d819dc,0xadb033b8,
				0x80116131,0xdb53c423,0x6dd68e07,0xdbad1c0e,0x6c2b3e5d,0xd8567cba,0x6bddff35,0xd7bbfe6a),
			array(	0xce3371cb,0x4717e5d7,0x8e2fcbae,0xc72e911d,0x552c247b,0xaa5848f6,0x8fc197ad,0xc4f2291b,
				0x52955477,0xa52aa8ee,0x9124579d,0xf939a97b,0x290254b7,0x5204a96e,0xa40952dc,0x9363a3f9,
				0xfdb641b3,0x201d8527,0x403b0a4e,0x8076149c,0xdb9d2f79,0x6c4b58b3,0xd896b166,0x6a5c648d,
				0xd4b8c91a,0x72009475,0xe40128ea,0x13735795,0x26e6af2a,0x4dcd5e54,0x9b9abca8,0xec447f11),
			array(	0x1072db28,0x20e5b650,0x41cb6ca0,0x8396d940,0xdc5cb4c1,0x63c86fc3,0xc790df86,0x5450b94d,
				0xa8a1729a,0x8a33e375,0xcf16c0ab,0x455c8717,0x8ab90e2e,0xce031a1d,0x4777327b,0x8eee64f6,
				0xc6adcfad,0x562a991b,0xac553236,0x83db622d,0xdcc7c21b,0x62fe8277,0xc5fd04ee,0x508b0f9d,
				0xa1161f3a,0x995d3835,0xe9cb762b,0x08e7ea17,0x11cfd42e,0x239fa85c,0x473f50b8,0x8e7ea170),
			array(	0xf891f16f,0x2a52e49f,0x54a5c93e,0xa94b927c,0x89e622b9,0xc8bd4333,0x4a0b8027,0x9417004e,
				0xf35f06dd,0x3dcf0bfb,0x7b9e17f6,0xf73c2fec,0x35095999,0x6a12b332,0xd4256664,0x733bca89,
				0xe6779512,0x179e2c65,0x2f3c58ca,0x5e78b194,0xbcf16328,0xa293c011,0x9e568663,0xe7dc0a87,
				0x14c9134f,0x2992269e,0x53244d3c,0xa6489a78,0x97e032b1,0xf4b16323,0x3213c007,0x6427800e),
			array(	0x88b6ba63,0xca1c7287,0x4f49e34f,0x9e93c69e,0xe6568b7d,0x17dc10bb,0x2fb82176,0x5f7042ec,
				0xbee085d8,0xa6b00df1,0x96111da3,0xf7533d07,0x35d77c4f,0x6baef89e,0xd75df13c,0x75cae439,
				0xeb95c872,0x0c5a96a5,0x18b52d4a,0x316a5a94,0x62d4b528,0xc5a96a50,0x5023d2e1,0xa047a5c2,
				0x9bfe4dc5,0xec8d9dcb,0x026a3dd7,0x04d47bae,0x09a8f75c,0x1351eeb8,0x26a3dd70,0x4d47bae0),
			array(	0x5ad8a92c,0xb5b15258,0xb013a2f1,0xbb5643a3,0xaddd8107,0x80ca044f,0xdae50edf,0x6ebb1bff,
				0xdd7637fe,0x619d69bd,0xc33ad37a,0x5d04a0b5,0xba09416a,0xaf638495,0x85b60f6b,0xd01d1897,
				0x7b4b376f,0xf6966ede,0x365ddbfd,0x6cbbb7fa,0xd9776ff4,0x699fd9a9,0xd33fb352,0x7d0e60e5,
				0xfa1cc1ca,0x2f4885d5,0x5e910baa,0xbd221754,0xa13528e9,0x991b5793,0xe947a967,0x09fe548f),
			array(	0xb566f6e2,0xb1bceb85,0xb808d14b,0xab60a4d7,0x8db04fef,0xc011999f,0x5b52357f,0xb6a46afe,
				0xb639d3bd,0xb702a13b,0xb5744437,0xb1998e2f,0xb8421a1f,0xabf5327f,0x8c9b62bf,0xc247c33f,
				0x5ffe803f,0xbffd007e,0xa48b06bd,0x92670b3b,0xffbf1037,0x240f262f,0x481e4c5e,0x903c98bc,
				0xfb083739,0x2d616833,0x5ac2d066,0xb585a0cc,0xb07a47d9,0xbb8589f3,0xac7a15a7,0x83852d0f),
			array(	0x9d9129bf,0xe053553f,0x1bd7ac3f,0x37af587e,0x6f5eb0fc,0xdebd61f8,0x660bc5b1,0xcc178b62,
				0x435e1085,0x86bc210a,0xd6094455,0x77638eeb,0xeec71dd6,0x06ff3ded,0x0dfe7bda,0x1bfcf7b4,
				0x37f9ef68,0x6ff3ded0,0xdfe7bda0,0x64be7d01,0xc97cfa02,0x4988f245,0x9311e48a,0xfd52cf55,
				0x21d498eb,0x43a931d6,0x875263ac,0xd5d5c119,0x70da8473,0xe1b508e6,0x181b178d,0x30362f1a),
			array(	0x2ee43a2c,0x5dc87458,0xbb90e8b0,0xac50d721,0x83d0a803,0xdcd05647,0x62d1aacf,0xc5a3559e,
				0x5037ad7d,0xa06f5afa,0x9bafb3b5,0xec2e612b,0x032dc417,0x065b882e,0x0cb7105c,0x196e20b8,
				0x32dc4170,0x65b882e0,0xcb7105c0,0x4d930dc1,0x9b261b82,0xed3d3145,0x010b64cb,0x0216c996,
				0x042d932c,0x085b2658,0x10b64cb0,0x216c9960,0x42d932c0,0x85b26580,0xd015cd41,0x7b5a9cc3),
			array(	0x1b4511ee,0x368a23dc,0x6d1447b8,0xda288f70,0x6f2018a1,0xde403142,0x67f164c5,0xcfe2c98a,
				0x44b49555,0x89692aaa,0xc9a35315,0x4837a06b,0x906f40d6,0xfbaf87ed,0x2c2e099b,0x585c1336,
				0xb0b8266c,0xba014a99,0xaf739373,0x859620a7,0xd05d470f,0x7bcb885f,0xf79710be,0x345f273d,
				0x68be4e7a,0xd17c9cf4,0x79883fa9,0xf3107f52,0x3d51f8e5,0x7aa3f1ca,0xf547e394,0x31fec169),
			array(	0xbce15202,0xa2b3a245,0x9e1642cb,0xe75d83d7,0x15ca01ef,0x2b9403de,0x572807bc,0xae500f78,
				0x87d118b1,0xd4d33723,0x72d76807,0xe5aed00e,0x102ca65d,0x20594cba,0x40b29974,0x816532e8,
				0xd9bb6391,0x6807c163,0xd00f82c6,0x7b6e03cd,0xf6dc079a,0x36c90975,0x6d9212ea,0xdb2425d4,
				0x6d394de9,0xda729bd2,0x6f9431e5,0xdf2863ca,0x6521c1d5,0xca4383aa,0x4ff60115,0x9fec022a),
			array(	0xff08e5ef,0x2560cd9f,0x4ac19b3e,0x9583367c,0xf0776ab9,0x3b9fd333,0x773fa666,0xee7f4ccc,
				0x078f9fd9,0x0f1f3fb2,0x1e3e7f64,0x3c7cfec8,0x78f9fd90,0xf1f3fb20,0x3896f001,0x712de002,
				0xe25bc004,0x1fc68649,0x3f8d0c92,0x7f1a1924,0xfe343248,0x271962d1,0x4e32c5a2,0x9c658b44,
				0xe3ba10c9,0x1c0527d3,0x380a4fa6,0x70149f4c,0xe0293e98,0x1b237b71,0x3646f6e2,0x6c8dedc4),
			array(	0x6f76172e,0xdeec2e5c,0x66a95af9,0xcd52b5f2,0x41d46da5,0x83a8db4a,0xdc20b0d5,0x633067eb,
				0xc660cfd6,0x57b099ed,0xaf6133da,0x85b361f5,0xd017c5ab,0x7b5e8d17,0xf6bd1a2e,0x360b321d,
				0x6c16643a,0xd82cc874,0x6b2896a9,0xd6512d52,0x77d35ce5,0xefa6b9ca,0x043c75d5,0x0878ebaa,
				0x10f1d754,0x21e3aea8,0x43c75d50,0x878ebaa0,0xd46c7301,0x73a9e043,0xe753c086,0x15d6874d),
			array(	0x56f5cab9,0xadeb9572,0x80a62ca5,0xda3d5f0b,0x6f0bb857,0xde1770ae,0x675fe71d,0xcebfce3a,
				0x460e9a35,0x8c1d346a,0xc34b6e95,0x5de7db6b,0xbbcfb6d6,0xacee6bed,0x82add19b,0xde2aa577,
				0x67244caf,0xce48995e,0x47e034fd,0x8fc069fa,0xc4f1d5b5,0x5292ad2b,0xa5255a56,0x913bb2ed,
				0xf906639b,0x297dc177,0x52fb82ee,0xa5f705dc,0x909f0df9,0xfa4f1db3,0x2fef3d27,0x5fde7a4e),
			array(	0x385993ac,0x70b32758,0xe1664eb0,0x19bd9b21,0x337b3642,0x66f66c84,0xcdecd908,0x40a8b451,
				0x815168a2,0xd9d3d705,0x68d6a84b,0xd1ad5096,0x782ba76d,0xf0574eda,0x3bdf9bf5,0x77bf37ea,
				0xef7e6fd4,0x058dd9e9,0x0b1bb3d2,0x163767a4,0x2c6ecf48,0x58dd9e90,0xb1bb3d20,0xb8077c01,
				0xab7ffe43,0x8d8efac7,0xc06cf3cf,0x5ba8e1df,0xb751c3be,0xb5d2813d,0xb0d4043b,0xbad90e37),
			array(	0xb4247b20,0xb339f001,0xbd02e643,0xa174cac7,0x999893cf,0xe84021df,0x0bf145ff,0x17e28bfe,
				0x2fc517fc,0x5f8a2ff8,0xbf145ff0,0xa559b9a1,0x91c27503,0xf8f5ec47,0x2a9adecf,0x5535bd9e,
				0xaa6b7b3c,0x8fa7f039,0xc43ee633,0x530cca27,0xa619944e,0x97422edd,0xf5f55bfb,0x309bb1b7,
				0x6137636e,0xc26ec6dc,0x5fac8bf9,0xbf5917f2,0xa5c329a5,0x90f7550b,0xfa9fac57,0x2e4e5eef),
			array(	0x695186a7,0xd2a30d4e,0x7e371cdd,0xfc6e39ba,0x23ad7535,0x475aea6a,0x8eb5d4d4,0xc61aafe9,
				0x57445993,0xae88b326,0x8660600d,0xd7b1c65b,0x74128af7,0xe82515ee,0x0b3b2d9d,0x16765b3a,
				0x2cecb674,0x59d96ce8,0xb3b2d9d0,0xbc14b5e1,0xa3586d83,0x9dc1dd47,0xe0f2bccf,0x1a947fdf,
				0x3528ffbe,0x6a51ff7c,0xd4a3fef8,0x7236fbb1,0xe46df762,0x13aae885,0x2755d10a,0x4eaba214),
			array(	0x66bc001e,0xcd78003c,0x41810639,0x83020c72,0xdd751ea5,0x619b3b0b,0xc3367616,0x5d1dea6d,
				0xba3bd4da,0xaf06aff5,0x857c59ab,0xd189b517,0x78626c6f,0xf0c4d8de,0x3af8b7fd,0x75f16ffa,
				0xebe2dff4,0x0cb4b9a9,0x19697352,0x32d2e6a4,0x65a5cd48,0xcb4b9a90,0x4de63361,0x9bcc66c2,
				0xece9cbc5,0x02a291cb,0x05452396,0x0a8a472c,0x15148e58,0x2a291cb0,0x54523960,0xa8a472c0),
			array(	0xb58b27b3,0xb0674927,0xbbbf940f,0xac0e2e5f,0x836d5aff,0xddabb3bf,0x6026613f,0xc04cc27e,
				0x5be882bd,0xb7d1057a,0xb4d30cb5,0xb2d71f2b,0xbedf3817,0xa6cf766f,0x96efea9f,0xf6aed37f,
				0x362ca0bf,0x6c59417e,0xd8b282fc,0x6a1403b9,0xd4280772,0x732108a5,0xe642114a,0x17f524d5,
				0x2fea49aa,0x5fd49354,0xbfa926a8,0xa4234b11,0x93379063,0xfd1e2687,0x214d4b4f,0x429a969e),
			array(	0xfe273162,0x273f6485,0x4e7ec90a,0x9cfd9214,0xe28a2269,0x1e654293,0x3cca8526,0x79950a4c,
				0xf32a1498,0x3d252f71,0x7a4a5ee2,0xf494bdc4,0x32587dc9,0x64b0fb92,0xc961f724,0x49b2e809,
				0x9365d012,0xfdbaa665,0x20044a8b,0x40089516,0x80112a2c,0xdb535219,0x6dd7a273,0xdbaf44e6,
				0x6c2f8f8d,0xd85f1f1a,0x6bcf3875,0xd79e70ea,0x744de795,0xe89bcf2a,0x0a469815,0x148d302a),
			array(	0xd3c98813,0x7ce21667,0xf9c42cce,0x28f95fdd,0x51f2bfba,0xa3e57f74,0x9cbbf8a9,0xe206f713,
				0x1f7ce867,0x3ef9d0ce,0x7df3a19c,0xfbe74338,0x2cbf8031,0x597f0062,0xb2fe00c4,0xbe8d07c9,
				0xa66b09d3,0x97a715e7,0xf43f2d8f,0x330f5d5f,0x661ebabe,0xcc3d757c,0x430becb9,0x8617d972,
				0xd75eb4a5,0x75cc6f0b,0xeb98de16,0x0c40ba6d,0x188174da,0x3102e9b4,0x6205d368,0xc40ba6d0),
			array(	0xf7d6deb4,0x34dcbb29,0x69b97652,0xd372eca4,0x7d94df09,0xfb29be12,0x2d227a65,0x5a44f4ca,
				0xb489e994,0xb262d569,0xbfb4ac93,0xa4185f67,0x9341b88f,0xfdf2775f,0x2095e8ff,0x412bd1fe,
				0x8257a3fc,0xdfde41b9,0x64cd8533,0xc99b0a66,0x4847128d,0x908e251a,0xfa6d4c75,0x2fab9eab,
				0x5f573d56,0xbeae7aac,0xa62df319,0x972ae073,0xf524c6a7,0x31388b0f,0x6271161e,0xc4e22c3c),
			array(	0xedb88320,0x00000001,0x00000002,0x00000004,0x00000008,0x00000010,0x00000020,0x00000040,
				0x00000080,0x00000100,0x00000200,0x00000400,0x00000800,0x00001000,0x00002000,0x00004000,
				0x00008000,0x00010000,0x00020000,0x00040000,0x00080000,0x00100000,0x00200000,0x00400000,
				0x00800000,0x01000000,0x02000000,0x04000000,0x08000000,0x10000000,0x20000000,0x40000000),
			array(	0x76dc4190,0xedb88320,0x00000001,0x00000002,0x00000004,0x00000008,0x00000010,0x00000020,
				0x00000040,0x00000080,0x00000100,0x00000200,0x00000400,0x00000800,0x00001000,0x00002000,
				0x00004000,0x00008000,0x00010000,0x00020000,0x00040000,0x00080000,0x00100000,0x00200000,
				0x00400000,0x00800000,0x01000000,0x02000000,0x04000000,0x08000000,0x10000000,0x20000000),
		);
		$matrix_index = 0;
		
		$even = $matrices[$matrix_index++];
		$odd = $matrices[$matrix_index++];
			
		do {
			/* apply zeros operator for this bit of len2 */
			$even = $matrices[$matrix_index++];
			if ($len2 & 1)
				$crc1=$this->gf2_matrix_times($even, $crc1);
			
			$len2>>=1;
			
			/* if no more bits set, then done */
			if ($len2==0)
			break;
			
			/* another iteration of the loop with odd and even swapped */
			$odd = $matrices[$matrix_index++];
			
			if ($len2 & 1)
				$crc1=$this->gf2_matrix_times($odd, $crc1);
			$len2>>= 1;
		} while ($len2 != 0);
		
		$crc1 ^= $crc2;
		return $crc1;
	}
	
	public function gf2_matrix_square(&$square, &$mat) {
		for ($n=0;$n<32;$n++) {
			$square[$n]=$this->gf2_matrix_times($mat, $mat[$n]);
		}
	}
	
	public function gf2_matrix_times($mat, $vec) {
		$sum=0;
		$i=0;
		
		while ($vec) {
			if ($vec & 1) {
				$sum ^= $mat[$i];
			}
			$vec = ($vec >> 1) & 0x7FFFFFFF;
			$i++;       
		}
		return $sum;
	}
}

class StreamZipArchiveRecord {
	public $handle;
	public $path;
	public $time;
	public $date;
	public $fileRecords = array();
	public $currentFileRecord;
	public $compressionLevel = -1;
	public $comments = '';	
	public $writable = false;
	public $activeStream;
	public $buffer;
	public $position = 0;
	public $lastReturnedPath;
}

class StreamZipArchiveFileRecord {
	public $name = '';
	public $offset;
	public $flags = 0;
	public $compressionMethod = 0;
	public $crc32 = 0;
	public $compressedSize = 0;
	public $uncompressedSize = 0;
	public $extra = '';
	public $attributes = 0x0020;		// DOS attributes
	public $time;
	public $date;
}

stream_wrapper_register(StreamZipArchive::PROTOCOL, 'StreamZipArchive');

?>
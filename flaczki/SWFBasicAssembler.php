<?php

class SWFBasicAssembler {

	protected $output;
	protected $bitBuffer;
	protected $bitsRemaining;
	protected $byteBuffer;
	protected $bytesRemaining;
	protected $adler32Context;
	protected $written;
	
	public function assemble(&$output, $swfFile, $tearDown = false) {
		if(gettype($output) == 'string') {
			$path = StreamMemory::create($output);
			$this->output = fopen($path, "wb");
		} else if(gettype($output) == 'resource') {
			$this->output = $output;
		} else {
			throw new Exception("Invalid output");
		}
		$this->written = 0;
		$this->bitBuffer = $this->bitsRemaining = 0;
		$this->byteBuffer = '';
		$this->bytesRemaining = 0;

		// prepare the tags for writing (making sure the tag lengths are correct, etc.)
		foreach($swfFile->tags as $tag) {
			$this->finalizeTag($tag, $tearDown);
		}

		// signature
		$signature = (($swfFile->compressed) ? 0x535743 : 0x535746) | ($swfFile->version << 24);
		$signature = $this->writeUI32($signature);
		
		// file length (uncompressed)
		$fileLength = 8 + ((($swfFile->frameSize->numBits * 4 + 5) + 7) >> 3) + 4;
		foreach($swfFile->tags as $tag) {
			$fileLength += $tag->headerLength + $tag->length;
		}		
		$this->writeUI32($fileLength);
		
		if($swfFile->compressed) {
			$this->flush();
			fwrite($this->output, "\x78\x9C");		// zlib header
			$filter = stream_filter_append($this->output, "zlib.deflate");
			
			// calculate the adler32 checksum if we can use the hash extension
			// the Flash Player doesn't seem to mind if it is missing 
			if(function_exists('hash_init')) {
				$this->adler32Context = hash_init('adler32');
			}
		}

		// frame size		
		$this->writeRect($swfFile->frameSize);
		
		// frame rate and count
		$this->writeUI16($swfFile->frameRate);
		$this->writeUI16($swfFile->frameCount);
		
		foreach($swfFile->tags as $index => $tag) {
			$this->writeTag($tag);
			if($tearDown) {
				// free the tag to conserve memory
				unset($swfFile->tags[$index]);
			}
		}
		$this->flush();
		
		if($swfFile->compressed) {
			stream_filter_remove($filter);

			if($this->adler32Context) {
				$hash = hash_final($this->adler32Context, true);
				if(hash("adler32", "") == "\x01\x00\x00\x00") {
					// if the byte order is wrong, then reverses it
					$hash = strrev($hash);
				}			
				fwrite($this->output, $hash);
			}
		}
		$written = $this->written;
		$this->written = 0;
		$this->output = null;
		return $written;
	}
	
	public function assembleTag($path, $tag) {
		$this->output = fopen($path, "wb");
		$this->finalizeTag($tag, false);
		$this->writeTag($tag);
		$this->flush();
	}
	
	protected function finalizeTag($tag, $tearDown) {
		$className = get_class($tag);
		$methodName = "finalize" . substr($className, 3);
		if(method_exists($this, $methodName)) {
			$this->$methodName($tag, $tearDown);
		}
		if($tag->length > 63 && $tag->headerLength == 2) {
			// need to use long format instead
			$tag->headerLength = 6;
		}
	}
	
	protected function writeTag($tag) {
		if($tag->headerLength == 6) {
			$tagCodeAndLength = ($tag->code << 6) | 0x003F;
			$this->writeUI16($tagCodeAndLength);
			$this->writeUI32($tag->length);
		} else {
			$tagCodeAndLength = ($tag->code << 6) | $tag->length;
			$this->writeUI16($tagCodeAndLength);
		}
			
		$className = get_class($tag);
		$methodName = "write" . substr($className, 3);
		if(method_exists($this, $methodName)) {
			$tag = $this->$methodName($tag);
		} else {
			if($tag instanceof SWFGenericTag) {
				$this->writeGenericTag($tag);
			} else {
				throw new Exception("Missing implementation: $methodName()");
			}
		}
		$this->alignToByte();
	}
	
	protected function writeGenericTag($tag) {
		$this->writeBytes($tag->data);
	}
	
	protected function finalizeDefineSpriteTag($tag, $tearDown) {
		$tagLength = 4;
		foreach($tag->tags as $child) {
			$this->finalizeTag($child, $tearDown);
			$tagLength += $child->headerLength + $child->length;
		}
		$tag->length = $tagLength;
	}
	
	protected function writeDefineSpriteTag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeUI16($tag->frameCount);
		foreach($tag->tags as $child) {
			$this->writeTag($child);
		}
	}
	
	protected function finalizeDefineBinaryDataTag($tag, $tearDown) {
		if(!$tag->data && $tag->swfFile) {
			// the tag is an embedded SWF file
			// assemble the file using a clone of $this
			$oldLength = $tag->length;
			$oldTree = $tag->swfFile;
			$tag->data = '';
			$assembler = clone $this;
			$assembler->assemble($tag->data, $tag->swfFile, $tearDown);
			$tag->length = 6 + strlen($tag->data);
			if($tearDown) {
				$tag->swfFile = null;
			}
		}
	}
		
	protected function writeDefineShapeTag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeRect($tag->shapeBounds);
		switch($tag->code) {
			case 2:	
				$this->writeShapeWithStyle($tag->shape, 1);
				break;			
			case 22:	
				$this->writeShapeWithStyle($tag->shape, 2);
				break;			
			case 32:	
				$this->writeShapeWithStyle($tag->shape, 3);
				break;			
			case 83:
				$this->writeRect($tag->edgeBounds);
				$this->writeUI8($tag->flags);
				$this->writeShapeWithStyle($tag->shape, 4);
				break;			
		}
	}
	
	protected function writeShapeWithStyle($shape, $version) {
		$this->writeFillStyles($shape->fillStyles, $version);
		$this->writeLineStyles($shape->lineStyles, $version);
		$this->writeUB($shape->numFillBits, 4);
		$this->writeUB($shape->numLineBits, 4);
		$numFillBits = $shape->numFillBits;
		$numLineBits = $shape->numLineBits;
		foreach($shape->records as $record) {
			if($record instanceof SWFStraightEdge) {
				$this->writeUB(0x03, 2);
				$this->writeUB($record->numBits - 2, 4);
				if($record->deltaX != 0 && $record->deltaY != 0) {
					$this->writeUB(0x01, 1);
					$this->writeSB($record->deltaX, $record->numBits);
					$this->writeSB($record->deltaY, $record->numBits);
				} else {
					if($record->deltaY != 0) {
						$this->writeUB(0x01, 2);
						$this->writeSB($record->deltaY, $record->numBits);
					} else {
						$this->writeUB(0x00, 2);
						$this->writeSB($record->deltaX, $record->numBits);
					}
				}				
			} else if($record instanceof SWFQuadraticCurve) {
				$this->writeUB(0x02, 2);
				$this->writeUB($record->numBits - 2, 4);
				$this->writeSB($record->controlDeltaX, $record->numBits);
				$this->writeSB($record->controlDeltaY, $record->numBits);
				$this->writeSB($record->anchorDeltaX, $record->numBits);
				$this->writeSB($record->anchorDeltaY, $record->numBits);
			} else if($record instanceof SWFStyleChange) {
				$this->writeUB(0x00, 1);
				$flags = 0x00;
				if($record->numMoveBits !== null) {
					$flags |= 0x01;
				}
				if($record->fillStyle0 !== null) {
					$flags |= 0x02;
				}
				if($record->fillStyle1 !== null) {
					$flags |= 0x04;
				}
				if($record->lineStyle !== null) {
					$flags |= 0x08;
				}
				if($record->newFillStyles !== null || $record->newLineStyles !== null) {
					$flags |= 0x10;
				}
				$this->writeUB($flags, 5);
				if($flags & 0x01) {
					$this->writeSB($record->numMoveBits, 5);
					$this->writeSB($record->moveDeltaX, $record->numMoveBits);
					$this->writeSB($record->moveDeltaY, $record->numMoveBits);
				}
				if($flags & 0x02) {
					$this->writeUB($record->fillStyle0, $numFillBits);
				}
				if($flags & 0x04) {
					$this->writeUB($record->fillStyle1, $numFillBits);
				}
				if($flags & 0x08) {
					$this->writeUB($record->lineStyle, $numLineBits);
				}
				if($flags & 0x10) {
					$this->writeFillStyles($record->newFillStyles, $version);
					$this->writeLineStyles($record->newLineStyles, $version);
					$this->writeUB($record->numFillBits, 4);
					$this->writeUB($record->numLineBits, 4);
					$numFillBits = $record->numFillBits;
					$numLineBits = $record->numLineBits;
				}
			}
		}		
		$this->writeUB(0x00, 6);	// end record
	}
	
	protected function writeFillStyles($styles, $version) {
		$count = count($styles);
		if($count >= 64) {
			$this->writeUI8(0xFF);
			$this->writeUI16($count);
		} else {
			$this->writeUI8($count);
		}
		foreach($styles as $style) {
			$this->writeFillStyle($style, $version);
		}
	}

	protected function writeFillStyle($style, $version) {
		$this->writeUI8($style->type);
		if($style->type == 0x00) {
			if($version >= 3) {
				$this->writeRGBA($style->color);
			} else {
				$this->writeRGB($style->color);
			}
		}
		if($style->type == 0x10 || $style->type == 0x12 || $style->type == 0x13) {
			$this->writeMatrix($style->gradientMatrix);
			if($style->type == 0x13) {
				$this->writeFocalGradient($style->gradient, $version);
			} else {
				$this->writeGradient($style->gradient, $version);
			}
		}
		if($style->type == 0x40 || $style->type == 0x41 || $style->type == 0x42 || $style->type == 0x43) {
			$this->writeUI16($style->bitmapId);
			$this->writeMatrix($style->bitmapMatrix);
		}
	}
	
	protected function writeLineStyles($styles, $version) {
		$count = count($styles);
		if($count >= 64) {
			$this->writeUI8(0xFF);
			$this->writeUI16($count);
		} else {
			$this->writeUI8($count);
		}
		foreach($styles as $style) {
			if($version == 4) {
				$this->writeLineStyle2($style, $version);
			} else {
				$this->writeLineStyle($style, $version);
			}
		}
	}
		
	protected function writeLineStyle2($style, $version) {
		$this->writeUI16($style->width);
		$this->writeUI16($style->flags);
		if(($style->flags & 0x0030) == 0x0020) {
			$this->writeUI16($style->miterLimitFactor);
		}
		if($style->flags & 0x0008) {
			$this->writeFillStyle($style->fillStyle, $version);
		} else {
			$this->writeRGBA($style->color);
		}
	}
	
	protected function writeLineStyle($style, $version) {
		$this->writeUI16($style->width);
		if($version >= 3) {
			$this->writeRGBA($style->color);
		} else {
			$this->writeRGB($style->color);
		}
	}
	
	protected function writeGradient($gradient, $version) {
		$this->writeUB($gradient->spreadMode, 2);
		$this->writeUB($gradient->interpolationMode, 2);
		$this->writeGradientControlPoints($gradient->controlPoints, $version);
	}
	
	protected function writeFocalGradient($gradient, $version) {
		$this->writeUB($gradient->spreadMode, 2);
		$this->writeUB($gradient->interpolationMode, 2);
		$this->writeGradientControlPoints($gradient->controlPoints, $version);
		$this->writeUI16($gradient->focalPoint);
	}
	
	protected function writeGradientControlPoints($controlPoints, $version) {
		$this->writeUB(count($controlPoints), 4);
		foreach($controlPoints as $controlPoint) {
			$this->writeUI8($controlPoint->ratio);
			if($version >= 3) {
				$this->writeRGBA($controlPoint->color);
			} else {
				$this->writeRGB($controlPoint->color);
			}
		}
	}
	
	protected function writeDefineBinaryDataTag($tag) {
		$this->writeUI16($tag->characterId);
		$this->writeUI32($tag->reserved);
		$this->writeBytes($tag->data);
	}

	protected function writeColorTransformAlpha($transform) {
		$hasAddTerms = $transform->redAddTerm || $transform->greenAddTerm || $transform->blueAddTerm || $transform->alphaAddTerm;
		$hasMultTerms = $transform->redMultTerm || $transform->greenMultTerm || $transform->blueMultTerm || $transform->alphaMultTerm;
		$this->writeUB($hasAddTerms, 1);
		$this->writeUB($hasMultTerms, 1);
		$this->writeUB($transform->numBits, 4);
		if($hasMultTerms) {
			$this->writeSB($transform->redMultTerm, $transform->numBits);
			$this->writeSB($transform->greenMultTerm, $transform->numBits);
			$this->writeSB($transform->blueMultTerm, $transform->numBits);
			$this->writeSB($transform->alphaMultTerm, $transform->numBits);
		}
		if($hasAddTerms) {
			$this->writeSB($transform->redAddTerm, $transform->numBits);
			$this->writeSB($transform->greenAddTerm, $transform->numBits);
			$this->writeSB($transform->blueAddTerm, $transform->numBits);
			$this->writeSB($transform->alphaAddTerm, $transform->numBits);
		}
		
	}
	
	protected function writeColorTransform($transform) {
		$hasAddTerms = $transform->redAddTerm || $transform->greenAddTerm || $transform->blueAddTerm;
		$hasMultTerms = $transform->redMultTerm || $transform->greenMultTerm || $transform->blueMultTerm;
		$this->writeUB($hasAddTerms, 1);
		$this->writeUB($hasMultTerms, 1);
		$this->writeUB($transform->numBits, 4);
		if($hasMultTerms) {
			$this->writeSB($transform->redMultTerm, $transform->numBits);
			$this->writeSB($transform->greenMultTerm, $transform->numBits);
			$this->writeSB($transform->blueMultTerm, $transform->numBits);
		}
		if($hasAddTerms) {
			$this->writeSB($transform->redAddTerm, $transform->numBits);
			$this->writeSB($transform->greenAddTerm, $transform->numBits);
			$this->writeSB($transform->blueAddTerm, $transform->numBits);
		}
	}

	protected function writeMatrix($matrix) {
		$this->writeUB($matrix->nScaleBits != null, 1);
		if($matrix->nScaleBits != null) {
			$this->writeUB($matrix->nScaleBits, 5);
			$this->writeSB($matrix->scaleX, $matrix->nScaleBits);
			$this->writeSB($matrix->scaleY, $matrix->nScaleBits);
		}
		$this->writeUB($matrix->nRotateBits != null, 1);
		if($matrix->nRotateBits != null) {
			$this->writeUB($matrix->nRotateBits, 5);
			$this->writeSB($matrix->rotateSkew0, $matrix->nRotateBits);
			$this->writeSB($matrix->rotateSkew1, $matrix->nRotateBits);
		}
		$this->writeUB($matrix->nTraslateBits, 5);
		$this->writeSB($matrix->translateX, $matrix->nTraslateBits);
		$this->writeSB($matrix->translateY, $matrix->nTraslateBits);
		$this->alignToByte();
	}
	
	protected function writeRect($rect) {
		$this->writeUB($rect->numBits, 5);
		$this->writeSB($rect->left, $rect->numBits);
		$this->writeSB($rect->right, $rect->numBits);
		$this->writeSB($rect->top, $rect->numBits);
		$this->writeSB($rect->bottom, $rect->numBits);
		$this->alignToByte();
	}
	
	protected function writeRGB($rgb) {
		$this->writeUI8($rgb->red);
		$this->writeUI8($rgb->green);
		$this->writeUI8($rgb->blue);
	}
		
	protected function writeRGBA($rgb) {
		$this->writeUI8($rgb->red);
		$this->writeUI8($rgb->green);
		$this->writeUI8($rgb->blue);
		$this->writeUI8($rgb->alpha);
	}
		
	protected function writeARGB($rgb) {
		$this->writeUI8($rgb->alpha);
		$this->writeUI8($rgb->red);
		$this->writeUI8($rgb->green);
		$this->writeUI8($rgb->blue);
	}
	
	protected function writeUI8($value) {
		$byte = chr($value);
		$this->alignToByte();
		$this->writeBytes($byte);
	}

	protected function writeUI16($value) {
		$bytes = pack('v', $value);
		$this->alignToByte();
		$this->writeBytes($bytes);
	}

	protected function writeUI32($value) {
		$bytes = pack('V', $value);
		$this->alignToByte();
		$this->writeBytes($bytes);
	}
	
	protected function writeString($value) {
		$this->alignToByte();
		$this->writeBytes("$value\0");
	}
	
	protected function writeSB($value, $numBits) {
		if($value < 0) {
			// mask out the upper bits
			$value &= ~(-1 << $numBits);
		}
		$this->writeUB($value, $numBits);
	}
	
	protected function writeUB($value, $numBits) {
		$this->bitBuffer = $this->bitBuffer | ($value << (32 - $numBits - $this->bitsRemaining));
		$this->bitsRemaining += $numBits;
		while($this->bitsRemaining > 8) {
			$byte = chr(($this->bitBuffer >> 24) & 0x000000FF);
			$this->bitsRemaining -= 8;
			$this->bitBuffer = (($this->bitBuffer << 8) & (-1 << (32 - $this->bitsRemaining))) & 0xFFFFFFFF;
			$this->byteBuffer .= $byte;
			$this->bytesRemaining++;
		}
	}
	
	protected function alignToByte() {
		if($this->bitsRemaining) {
			$byte = chr(($this->bitBuffer >> 24) & 0x000000FF);
			$this->byteBuffer .= $byte;
			$this->bytesRemaining++;
			$this->bitBuffer = $this->bitsRemaining = 0;
		}		
	}
	
	protected function flush() {
		if($this->bytesRemaining) {
			if($this->adler32Context) {
				hash_update($this->adler32Context, $this->byteBuffer);
			}
			$this->written += fwrite($this->output, $this->byteBuffer);
			$this->byteBuffer = '';
			$this->bytesRemaining = 0;
		}
	}
	
	protected function writeBytes($bytes) {
		$count = strlen($bytes);		
		if($count + $this->bytesRemaining > 1024) {
			$this->flush();
			$this->byteBuffer = $bytes;
			$this->bytesRemaining = $count;
			if($count > 1024) {
				$this->flush();
			}
		} else {
			$this->byteBuffer .= $bytes;
			$this->bytesRemaining++;
		}
	}	
}

?>
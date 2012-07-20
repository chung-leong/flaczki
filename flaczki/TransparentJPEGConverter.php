<?php

class TransparentJPEGConverter {

	public static function isAvailable() {
		return function_exists('imagecreatefromstring');
	}

	public function convertToPNG($tag) {
		// create the image and get the GD raw data, using output buffering as
		// GD doesn't work with stream wrappers in all versions of PHP
		$image = imagecreatefromstring($tag->imageData);
		imagesavealpha($image, true);
		ob_start();
		imagegd2($image);
		imagedestroy($image);
		$gdRawData = ob_get_clean();
		
		$alphaData = gzuncompress($tag->alphaData);
		
		// scan the GD header (20 bytes)
		$array = unpack("Nheader/nversion/nimageWidth/nimageHeight/nchunkSize/ndataFormat/ncolumnCount/nrowCount", $gdRawData);
		$imageWidth = $array['imageWidth'];
		$imageHeight = $array['imageHeight'];

		// a image in GD is divided into chunks of 128x128
		// the raw data is not a continous series of scanlines 
		$chunkWidth = $chunkHeight = $array['chunkSize'];
		$columnCount = $array['columnCount'];
		$rowCount = $array['rowCount'];
		$lastColumnWidth = $imageWidth - ($columnCount - 1) * $chunkWidth;
		$lastRowHeight = $imageHeight - ($rowCount - 1) * $chunkHeight;
		
		// translate alpha valeus (255 = opaque, 0 = fully transparent to transparency value (0 = opaque, 127 = fully transparent) 
		static $alphaToTransparency = array("\x00" => "\x7f", "\x01" => "\x7f", "\x02" => "\x7e", "\x03" => "\x7e", "\x04" => "\x7d", "\x05" => "\x7d", "\x06" => "\x7c", "\x07" => "\x7c", "\x08" => "\x7b", "\x09" => "\x7b", "\x0a" => "\x7a", "\x0b" => "\x7a", "\x0c" => "\x79", "\x0d" => "\x79", "\x0e" => "\x78", "\x0f" => "\x78", "\x10" => "\x77", "\x11" => "\x77", "\x12" => "\x76", "\x13" => "\x76", "\x14" => "\x75", "\x15" => "\x75", "\x16" => "\x74", "\x17" => "\x74", "\x18" => "\x73", "\x19" => "\x73", "\x1a" => "\x72", "\x1b" => "\x72", "\x1c" => "\x71", "\x1d" => "\x71", "\x1e" => "\x70", "\x1f" => "\x70", "\x20" => "\x6f", "\x21" => "\x6f", "\x22" => "\x6e", "\x23" => "\x6e", "\x24" => "\x6d", "\x25" => "\x6d", "\x26" => "\x6c", "\x27" => "\x6c", "\x28" => "\x6b", "\x29" => "\x6b", "\x2a" => "\x6a", "\x2b" => "\x6a", "\x2c" => "\x69", "\x2d" => "\x69", "\x2e" => "\x68", "\x2f" => "\x68", "\x30" => "\x67", "\x31" => "\x67", "\x32" => "\x66", "\x33" => "\x66", "\x34" => "\x65", "\x35" => "\x65", "\x36" => "\x64", "\x37" => "\x64", "\x38" => "\x63", "\x39" => "\x63", "\x3a" => "\x62", "\x3b" => "\x62", "\x3c" => "\x61", "\x3d" => "\x61", "\x3e" => "\x60", "\x3f" => "\x60", "\x40" => "\x5f", "\x41" => "\x5f", "\x42" => "\x5e", "\x43" => "\x5e", "\x44" => "\x5d", "\x45" => "\x5d", "\x46" => "\x5c", "\x47" => "\x5c", "\x48" => "\x5b", "\x49" => "\x5b", "\x4a" => "\x5a", "\x4b" => "\x5a", "\x4c" => "\x59", "\x4d" => "\x59", "\x4e" => "\x58", "\x4f" => "\x58", "\x50" => "\x57", "\x51" => "\x57", "\x52" => "\x56", "\x53" => "\x56", "\x54" => "\x55", "\x55" => "\x55", "\x56" => "\x54", "\x57" => "\x54", "\x58" => "\x53", "\x59" => "\x53", "\x5a" => "\x52", "\x5b" => "\x52", "\x5c" => "\x51", "\x5d" => "\x51", "\x5e" => "\x50", "\x5f" => "\x50", "\x60" => "\x4f", "\x61" => "\x4f", "\x62" => "\x4e", "\x63" => "\x4e", "\x64" => "\x4d", "\x65" => "\x4d", "\x66" => "\x4c", "\x67" => "\x4c", "\x68" => "\x4b", "\x69" => "\x4b", "\x6a" => "\x4a", "\x6b" => "\x4a", "\x6c" => "\x49", "\x6d" => "\x49", "\x6e" => "\x48", "\x6f" => "\x48", "\x70" => "\x47", "\x71" => "\x47", "\x72" => "\x46", "\x73" => "\x46", "\x74" => "\x45", "\x75" => "\x45", "\x76" => "\x44", "\x77" => "\x44", "\x78" => "\x43", "\x79" => "\x43", "\x7a" => "\x42", "\x7b" => "\x42", "\x7c" => "\x41", "\x7d" => "\x41", "\x7e" => "\x40", "\x7f" => "\x40", "\x80" => "\x3f", "\x81" => "\x3f", "\x82" => "\x3e", "\x83" => "\x3e", "\x84" => "\x3d", "\x85" => "\x3d", "\x86" => "\x3c", "\x87" => "\x3c", "\x88" => "\x3b", "\x89" => "\x3b", "\x8a" => "\x3a", "\x8b" => "\x3a", "\x8c" => "\x39", "\x8d" => "\x39", "\x8e" => "\x38", "\x8f" => "\x38", "\x90" => "\x37", "\x91" => "\x37", "\x92" => "\x36", "\x93" => "\x36", "\x94" => "\x35", "\x95" => "\x35", "\x96" => "\x34", "\x97" => "\x34", "\x98" => "\x33", "\x99" => "\x33", "\x9a" => "\x32", "\x9b" => "\x32", "\x9c" => "\x31", "\x9d" => "\x31", "\x9e" => "\x30", "\x9f" => "\x30", "\xa0" => "\x2f", "\xa1" => "\x2f", "\xa2" => "\x2e", "\xa3" => "\x2e", "\xa4" => "\x2d", "\xa5" => "\x2d", "\xa6" => "\x2c", "\xa7" => "\x2c", "\xa8" => "\x2b", "\xa9" => "\x2b", "\xaa" => "\x2a", "\xab" => "\x2a", "\xac" => "\x29", "\xad" => "\x29", "\xae" => "\x28", "\xaf" => "\x28", "\xb0" => "\x27", "\xb1" => "\x27", "\xb2" => "\x26", "\xb3" => "\x26", "\xb4" => "\x25", "\xb5" => "\x25", "\xb6" => "\x24", "\xb7" => "\x24", "\xb8" => "\x23", "\xb9" => "\x23", "\xba" => "\x22", "\xbb" => "\x22", "\xbc" => "\x21", "\xbd" => "\x21", "\xbe" => "\x20", "\xbf" => "\x20", "\xc0" => "\x1f", "\xc1" => "\x1f", "\xc2" => "\x1e", "\xc3" => "\x1e", "\xc4" => "\x1d", "\xc5" => "\x1d", "\xc6" => "\x1c", "\xc7" => "\x1c", "\xc8" => "\x1b", "\xc9" => "\x1b", "\xca" => "\x1a", "\xcb" => "\x1a", "\xcc" => "\x19", "\xcd" => "\x19", "\xce" => "\x18", "\xcf" => "\x18", "\xd0" => "\x17", "\xd1" => "\x17", "\xd2" => "\x16", "\xd3" => "\x16", "\xd4" => "\x15", "\xd5" => "\x15", "\xd6" => "\x14", "\xd7" => "\x14", "\xd8" => "\x13", "\xd9" => "\x13", "\xda" => "\x12", "\xdb" => "\x12", "\xdc" => "\x11", "\xdd" => "\x11", "\xde" => "\x10", "\xdf" => "\x10", "\xe0" => "\x0f", "\xe1" => "\x0f", "\xe2" => "\x0e", "\xe3" => "\x0e", "\xe4" => "\x0d", "\xe5" => "\x0d", "\xe6" => "\x0c", "\xe7" => "\x0c", "\xe8" => "\x0b", "\xe9" => "\x0b", "\xea" => "\x0a", "\xeb" => "\x0a", "\xec" => "\x09", "\xed" => "\x09", "\xee" => "\x08", "\xef" => "\x08", "\xf0" => "\x07", "\xf1" => "\x07", "\xf2" => "\x06", "\xf3" => "\x06", "\xf4" => "\x05", "\xf5" => "\x05", "\xf6" => "\x04", "\xf7" => "\x04", "\xf8" => "\x03", "\xf9" => "\x03", "\xfa" => "\x02", "\xfb" => "\x02", "\xfc" => "\x01", "\xfd" => "\x01", "\xfe" => "\x00", "\xff" => "\x00");

		// loop through all rows 
		for($r = 0, $j = 23; $r < $rowCount; $r++) {
			$rowHeight = ($r == $rowCount - 1) ? $lastRowHeight : $chunkHeight;
			// loop through chunks in each row
			for($c = 0; $c < $columnCount; $c++) {
				$columnWidth = ($c == $columnCount - 1) ? $lastColumnWidth : $chunkWidth;
				$firstY = $r * $chunkHeight;
				$lastY = $firstY + $rowHeight;
				// loop through scanlines in each chunk
				for($y = $firstY; $y < $lastY; $y++) {
					$firstX = $c * $chunkWidth;
					$firstAlphaPosition = $y * $imageWidth + $firstX;
					$lastAlphaPosition = $firstAlphaPosition + $columnWidth;
					// loop through each pixel in each chunk scanline
					for($i = $firstAlphaPosition; $i < $lastAlphaPosition; $i++, $j+= 4) {
						$gdRawData[$j] = $alphaToTransparency[$alphaData[$i]];
					}
				}
			}
		}
		
		// recreate the image
		unset($alphaData);
		$image = imagecreatefromstring($gdRawData);
		unset($gdRawData);
		
		// capture the PNG data and return it
		imagesavealpha($image, true);		
		ob_start();
		imagepng($image);
		imagedestroy($image);
		$pngData = ob_get_clean();
		return $pngData;
	}
	
	public function convertFromPNG($pngData, $deblockingParam) {
		// create the image and get the GD raw data, using output buffering as
		// GD doesn't work with stream wrappers in all versions of PHP
		$image = imagecreatefromstring($pngData);
		imagesavealpha($image, true);
		ob_start();
		imagegd2($image);
		$gdRawData = ob_get_clean();
		
		ob_start();
		imagejpeg($image);
		imagedestroy($image);
		$jpegData = ob_get_clean();
	
		$array = unpack("Nheader/nversion/nimageWidth/nimageHeight/nchunkSize/ndataFormat/ncolumnCount/nrowCount", $gdRawData);
		$imageWidth = $array['imageWidth'];
		$imageHeight = $array['imageHeight'];
		
		$chunkWidth = $chunkHeight = $array['chunkSize'];
		$columnCount = $array['columnCount'];
		$rowCount = $array['rowCount'];
		$lastColumnWidth = $imageWidth - ($columnCount - 1) * $chunkWidth;
		$lastRowHeight = $imageHeight - ($rowCount - 1) * $chunkHeight;
		
		static $transparencyToAlpha = array("\x7f" => "\x00", "\x7f" => "\x01", "\x7e" => "\x02", "\x7e" => "\x03", "\x7d" => "\x04", "\x7d" => "\x05", "\x7c" => "\x06", "\x7c" => "\x07", "\x7b" => "\x08", "\x7b" => "\x09", "\x7a" => "\x0a", "\x7a" => "\x0b", "\x79" => "\x0c", "\x79" => "\x0d", "\x78" => "\x0e", "\x78" => "\x0f", "\x77" => "\x10", "\x77" => "\x11", "\x76" => "\x12", "\x76" => "\x13", "\x75" => "\x14", "\x75" => "\x15", "\x74" => "\x16", "\x74" => "\x17", "\x73" => "\x18", "\x73" => "\x19", "\x72" => "\x1a", "\x72" => "\x1b", "\x71" => "\x1c", "\x71" => "\x1d", "\x70" => "\x1e", "\x70" => "\x1f", "\x6f" => "\x20", "\x6f" => "\x21", "\x6e" => "\x22", "\x6e" => "\x23", "\x6d" => "\x24", "\x6d" => "\x25", "\x6c" => "\x26", "\x6c" => "\x27", "\x6b" => "\x28", "\x6b" => "\x29", "\x6a" => "\x2a", "\x6a" => "\x2b", "\x69" => "\x2c", "\x69" => "\x2d", "\x68" => "\x2e", "\x68" => "\x2f", "\x67" => "\x30", "\x67" => "\x31", "\x66" => "\x32", "\x66" => "\x33", "\x65" => "\x34", "\x65" => "\x35", "\x64" => "\x36", "\x64" => "\x37", "\x63" => "\x38", "\x63" => "\x39", "\x62" => "\x3a", "\x62" => "\x3b", "\x61" => "\x3c", "\x61" => "\x3d", "\x60" => "\x3e", "\x60" => "\x3f", "\x5f" => "\x40", "\x5f" => "\x41", "\x5e" => "\x42", "\x5e" => "\x43", "\x5d" => "\x44", "\x5d" => "\x45", "\x5c" => "\x46", "\x5c" => "\x47", "\x5b" => "\x48", "\x5b" => "\x49", "\x5a" => "\x4a", "\x5a" => "\x4b", "\x59" => "\x4c", "\x59" => "\x4d", "\x58" => "\x4e", "\x58" => "\x4f", "\x57" => "\x50", "\x57" => "\x51", "\x56" => "\x52", "\x56" => "\x53", "\x55" => "\x54", "\x55" => "\x55", "\x54" => "\x56", "\x54" => "\x57", "\x53" => "\x58", "\x53" => "\x59", "\x52" => "\x5a", "\x52" => "\x5b", "\x51" => "\x5c", "\x51" => "\x5d", "\x50" => "\x5e", "\x50" => "\x5f", "\x4f" => "\x60", "\x4f" => "\x61", "\x4e" => "\x62", "\x4e" => "\x63", "\x4d" => "\x64", "\x4d" => "\x65", "\x4c" => "\x66", "\x4c" => "\x67", "\x4b" => "\x68", "\x4b" => "\x69", "\x4a" => "\x6a", "\x4a" => "\x6b", "\x49" => "\x6c", "\x49" => "\x6d", "\x48" => "\x6e", "\x48" => "\x6f", "\x47" => "\x70", "\x47" => "\x71", "\x46" => "\x72", "\x46" => "\x73", "\x45" => "\x74", "\x45" => "\x75", "\x44" => "\x76", "\x44" => "\x77", "\x43" => "\x78", "\x43" => "\x79", "\x42" => "\x7a", "\x42" => "\x7b", "\x41" => "\x7c", "\x41" => "\x7d", "\x40" => "\x7e", "\x40" => "\x7f", "\x3f" => "\x80", "\x3f" => "\x81", "\x3e" => "\x82", "\x3e" => "\x83", "\x3d" => "\x84", "\x3d" => "\x85", "\x3c" => "\x86", "\x3c" => "\x87", "\x3b" => "\x88", "\x3b" => "\x89", "\x3a" => "\x8a", "\x3a" => "\x8b", "\x39" => "\x8c", "\x39" => "\x8d", "\x38" => "\x8e", "\x38" => "\x8f", "\x37" => "\x90", "\x37" => "\x91", "\x36" => "\x92", "\x36" => "\x93", "\x35" => "\x94", "\x35" => "\x95", "\x34" => "\x96", "\x34" => "\x97", "\x33" => "\x98", "\x33" => "\x99", "\x32" => "\x9a", "\x32" => "\x9b", "\x31" => "\x9c", "\x31" => "\x9d", "\x30" => "\x9e", "\x30" => "\x9f", "\x2f" => "\xa0", "\x2f" => "\xa1", "\x2e" => "\xa2", "\x2e" => "\xa3", "\x2d" => "\xa4", "\x2d" => "\xa5", "\x2c" => "\xa6", "\x2c" => "\xa7", "\x2b" => "\xa8", "\x2b" => "\xa9", "\x2a" => "\xaa", "\x2a" => "\xab", "\x29" => "\xac", "\x29" => "\xad", "\x28" => "\xae", "\x28" => "\xaf", "\x27" => "\xb0", "\x27" => "\xb1", "\x26" => "\xb2", "\x26" => "\xb3", "\x25" => "\xb4", "\x25" => "\xb5", "\x24" => "\xb6", "\x24" => "\xb7", "\x23" => "\xb8", "\x23" => "\xb9", "\x22" => "\xba", "\x22" => "\xbb", "\x21" => "\xbc", "\x21" => "\xbd", "\x20" => "\xbe", "\x20" => "\xbf", "\x1f" => "\xc0", "\x1f" => "\xc1", "\x1e" => "\xc2", "\x1e" => "\xc3", "\x1d" => "\xc4", "\x1d" => "\xc5", "\x1c" => "\xc6", "\x1c" => "\xc7", "\x1b" => "\xc8", "\x1b" => "\xc9", "\x1a" => "\xca", "\x1a" => "\xcb", "\x19" => "\xcc", "\x19" => "\xcd", "\x18" => "\xce", "\x18" => "\xcf", "\x17" => "\xd0", "\x17" => "\xd1", "\x16" => "\xd2", "\x16" => "\xd3", "\x15" => "\xd4", "\x15" => "\xd5", "\x14" => "\xd6", "\x14" => "\xd7", "\x13" => "\xd8", "\x13" => "\xd9", "\x12" => "\xda", "\x12" => "\xdb", "\x11" => "\xdc", "\x11" => "\xdd", "\x10" => "\xde", "\x10" => "\xdf", "\x0f" => "\xe0", "\x0f" => "\xe1", "\x0e" => "\xe2", "\x0e" => "\xe3", "\x0d" => "\xe4", "\x0d" => "\xe5", "\x0c" => "\xe6", "\x0c" => "\xe7", "\x0b" => "\xe8", "\x0b" => "\xe9", "\x0a" => "\xea", "\x0a" => "\xeb", "\x09" => "\xec", "\x09" => "\xed", "\x08" => "\xee", "\x08" => "\xef", "\x07" => "\xf0", "\x07" => "\xf1", "\x06" => "\xf2", "\x06" => "\xf3", "\x05" => "\xf4", "\x05" => "\xf5", "\x04" => "\xf6", "\x04" => "\xf7", "\x03" => "\xf8", "\x03" => "\xf9", "\x02" => "\xfa", "\x02" => "\xfb", "\x01" => "\xfc", "\x01" => "\xfd", "\x00" => "\xfe", "\x00" => "\xff");
		
		$alphaDataSize = $imageWidth * $imageHeight;
		$alphaData = str_repeat("\x00", $alphaDataSize);

		// loop through all rows 
		for($r = 0, $j = 23; $r < $rowCount; $r++) {
			$rowHeight = ($r == $rowCount - 1) ? $lastRowHeight : $chunkHeight;
			// loop through chunks in each row
			for($c = 0; $c < $columnCount; $c++) {
				$columnWidth = ($c == $columnCount - 1) ? $lastColumnWidth : $chunkWidth;
				$firstY = $r * $chunkHeight;
				$lastY = $firstY + $rowHeight;
				// loop through scanlines in each chunk
				for($y = $firstY; $y < $lastY; $y++) {
					$firstX = $c * $chunkWidth;
					$firstAlphaPosition = $y * $imageWidth + $firstX;
					$lastAlphaPosition = $firstAlphaPosition + $columnWidth;
					// loop through each pixel in each chunk scanline
					for($i = $firstAlphaPosition; $i < $lastAlphaPosition; $i++, $j+= 4) {
						$alphaData[$i] = $transparencyToAlpha[$gdRawData[$j]];
					}
				}
			}
		}
		
		if($deblockingParam) {
			$tag = new SWFDefineBitsJPEG4Tag;
			$tag->deblockingParam = $deblockingParam;
		} else {
			$tag = new SWFDefineBitsJPEG3Tag;
		}
		$tag->imageData = $jpegData;
		$tag->alphaData = gzcompress($alphaData);
		return $tag;
	}
}

?>
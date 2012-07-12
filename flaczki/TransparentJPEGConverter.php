<?php

class TransparentJPEGConverter {

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
}

?>
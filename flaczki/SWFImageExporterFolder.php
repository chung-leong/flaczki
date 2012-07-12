<?php

class SWFImageExporterFolder extends SWFImageExporter {

	public function export($folderPath, $assets) {
		$count = 1;
		foreach($assets->images as $image) {
			$imageData = $image->data;
			$imagePath = StreamMemory::add($imageData);
			$imageInfo = getimagesize($imagePath);
			$mimeType = $imageInfo['mime'];
			switch($mimeType) {
				case 'image/jpeg': $extension = '.jpeg'; break;
				case 'image/png': $extension = '.png'; break;
				case 'image/gif': $extension = '.gif'; break;
			}
			$path = "{$folderPath}/image{$count}{$extension}";
			file_put_contents($path, $imageData);
			$count++;
		}
	}
}

?>
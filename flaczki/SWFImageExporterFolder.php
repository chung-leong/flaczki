<?php

class SWFImageExporterFolder extends SWFImageExporter {

	public function export($folderPath, $assets) {
		foreach($assets->images as $image) {
			$imageData = $image->data;
			if($imageData) {
				switch($image->mimeType) {
					case 'image/jpeg': $extension = '.jpeg'; break;
					case 'image/png': $extension = '.png'; break;
					case 'image/gif': $extension = '.gif'; break;
				}
				$path = "{$folderPath}/{$image->name}{$extension}";
				file_put_contents($path, $imageData);
			}
		}
	}
}

?>
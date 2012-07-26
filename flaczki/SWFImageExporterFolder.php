<?php

class SWFImageExporterFolder {

	public function export($folderPath, $assets) {
		foreach($assets->images as $image) {
			$imageData = $image->data;
			if($imageData) {
				switch($image->mimeType) {
					case 'image/jpeg': $extension = '.jpg'; break;
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
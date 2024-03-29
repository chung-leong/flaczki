<?php

class PanoseDatabase {

	public static function find($fontName) {
		static $table = array(
			'Al Bayan' => '0000000000',
			'Andale Mono' => '2b59000004',
			'Andalus' => '2263545234',
			'Angsana New' => '2263545234',
			'AngsanaUPC' => '2263545234',
			'Apple Chancery' => '3272456654',
			'Arabic Typesetting' => '3242446323',
			'Arial' => '2b64222224',
			'Arial Black' => '2ba4212224',
			'Ayuthaya' => '0040000000',
			'Baghdad' => '1050000204',
			'BiauKai' => '2161011111',
			'Big Caslon' => '2063900203',
			'Browallia New' => '2b64222224',
			'BrowalliaUPC' => '2b64222224',
			'Calibri' => '2f52224324',
			'Candara' => '2e52333224',
			'Comic Sans MS' => '3f72332224',
			'Consolas' => '2b69224324',
			'Constantia' => '2362536333',
			'Corbel' => '2b53224224',
			'Cordia New' => '2b34222224',
			'CordiaUPC' => '2b34222224',
			'Courier New' => '2739225244',
			'DFKai-SB' => '3059000000',
			'DaunPenh' => '1111111111',
			'David' => '2e52641111',
			'DecoType Naskh' => '0040000000',
			'DilleniaUPC' => '2263545234',
			'DokChampa' => '2b64222224',
			'Estrangelo Edessa' => '3860000000',
			'EucrosiaUPC' => '2263545234',
			'Euphemia' => '2b53412214',
			'FangSong' => '2169611111',
			'FrankRuehl' => '2e53611111',
			'FreesiaUPC' => '2b64222224',
			'Gautami' => '2b52424223',
			'Georgia' => '2452545233',
			'Gisha' => '2b52424223',
			'Herculanum' => '2055000204',
			'Hoefler Text' => '0000000000',
			'Impact' => '2b86392524',
			'IrisUPC' => '2b64222224',
			'Iskoola Pota' => '2b52424223',
			'JasmineUPC' => '2263545234',
			'Kalinga' => '2b52424223',
			'Kartika' => '2253344623',
			'KodchiangUPC' => '2263545234',
			'Krungthep' => '2040000000',
			'Latha' => '2b64222224',
			'Leelawadee' => '2b52424223',
			'Levenim MT' => '2152611111',
			'LilyUPC' => '2b64222224',
			'Lucida Console' => '2b69454224',
			'Lucida Sans Unicode' => '2b62354224',
			'MV Boli' => '2050320900',
			'Malgun Gothic' => '2b53200204',
			'Mangal' => '2453523322',
			'Marlett' => '0000000000',
			'Microsoft Himalaya' => '1110111111',
			'Microsoft JhengHei' => '2b64354424',
			'Microsoft Sans Serif' => '2b64222224',
			'Microsoft Tai Le' => '2b52424223',
			'Microsoft Uighur' => '2000000000',
			'Microsoft YaHei' => '2b53224224',
			'Microsoft Yi Baiti' => '3050000000',
			'Miriam' => '2b52511111',
			'Mongolian Baiti' => '3050000000',
			'MoolBoran' => '2b10111111',
			'Mshtakan' => '2040000000',
			'Nadeem' => '0040000000',
			'Narkisim' => '2e52511111',
			'Nyala' => '2054730203',
			'Osaka' => '2b60000000',
			'Palatino Linotype' => '2452555334',
			'Plantagenet Cherokee' => '2200000000',
			'Raavi' => '2b52424223',
			'Rod' => '2359511111',
			'Sathu' => '0040000000',
			'Segoe Print' => '2060000000',
			'Segoe Script' => '2b54200003',
			'Segoe UI' => '2b52424223',
			'Shruti' => '2b52424223',
			'Silom' => '0040000000',
			'SimHei' => '2169611111',
			'SimSun-ExtB' => '2169611111',
			'Simplified Arabic' => '2263545234',
			'Skia' => '2d52224224',
			'Sylfaen' => '1a52536333',
			'Symbol' => '5512176257',
			'Tahoma' => '2b64354424',
			'Times New Roman' => '2263545234',
			'Traditional Arabic' => '2263545234',
			'Trebuchet MS' => '2b63222224',
			'Tunga' => '2b52424223',
			'Verdana' => '2b64354424',
			'Vrinda' => '2b52424223',
			'Webdings' => '5312159673',
			'Wingdings' => '5000000000',
		);
		
		if(isset($table[$fontName])) {
			$panose = array();
			$string = $table[$fontName];
			for($i = 0; $i < 10; $i++) {
				$panose[$i + 1] = hexdec($string[$i]);
			}
			return $panose;
		}
	}
}

?>
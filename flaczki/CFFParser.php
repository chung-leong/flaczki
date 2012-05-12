<?php

class CFFParser {

	public function parse($input) {
		if(gettype($input) == 'string') {
			$data = $input;
		} else if(gettype($input) == 'resource') {
			$data = '';
			while($chunk = fread($input, 8192)) {
				$data .= $chunk;
			}
		} else {
			throw new Exception("Invalid input");
		}
		
		$header = unpack('Nversion/nnumTables/nsearchRange/nentrySelector/nrangeShift', $data);
		if($header['version'] == 0x4F54544f) {	// 'OTTO'
			$font = new CFFFont;
			for($i = 0, $n = $header['numTables'], $offset = 12; $i < $n; $i++) {
				$table = unpack('Ntag/NcheckSum/Noffset/Nlength', substr($data, $offset, 16));
				if($table['tag'] == 0x4F532F32) {	// OS/2
					$tableData = substr($data, $table['offset'], $table['length']);
					$os2 = unpack('nversion/navgCharWidth/nweightClass/nwidthClass/nfsType/nsubscriptXSize/nsubscriptYSize/nsubscriptXOffset/nsubscriptYOffset/nsuperscriptXSize/nsuperscriptYSize/nsuperscriptXOffset/nsuperscriptYOffset/nstrikeoutSize/nstrikeoutPosition/nfamilyClass/C10panose/N4unicodeRange/a4vendId/nselection/nfirstCharIndex/nlastCharIndex/ntypoAscender/ntypoDescender/ntypeLineGap/nwinAscent/nwinDescent/NcodePageRange1/NcodePageRange2', $tableData);
					$font->weight = $os2['weightClass'];
					$font->width = $os2['widthClass'];
					$font->italic = ($os2['selection'] & 0x0001) != 0;
					$font->oblique = ($os2['selection'] & 0x0200) != 0;
					$font->bold = ($os2['selection'] & 0x0020) != 0;
					for($i = 1; $i <= 10; $i++) {
						$font->panose[$i] = $os2["panose$i"];
					}
					break;
				}
				$offset += 16;
			}
			return $font;
		}
	}
}

class CFFFont {
	public $bold;
	public $italic;
	public $oblique;
	public $weight;			// 100 = thin, 400 = normal, 900 = heavy
	public $width;			// 1 = ultra-condensed, 5 = normal, 9 = ultra-expanded
	public $panose = array();	// see http://www.monotypeimaging.com/ProductsServices/pan1.aspx
}

?>
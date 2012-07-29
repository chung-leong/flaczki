<?php

class ColorMatrixSolver extends IterativeSolver {
	public $brightness = 0;
	public $contrast = 0;
	public $saturation = 0;
	public $hue = 0;

	public function solve($matrix) {
		$parameters = array();
		$parameters[0] = $this->brightness;
		$parameters[1] = $this->contrast;
		$parameters[2] = $this->saturation;
		$parameters[3] = $this->hue;
		
		// skip values affecting the alpha channel
		$actualValues = array();
		$actualValues[0] = $matrix[0];
		$actualValues[1] = $matrix[1];
		$actualValues[2] = $matrix[2];
		$actualValues[3] = $matrix[4];
		$actualValues[4] = $matrix[5];
		$actualValues[5] = $matrix[6];
		$actualValues[6] = $matrix[7];
		$actualValues[7] = $matrix[9];
		$actualValues[8] = $matrix[10];
		$actualValues[9] = $matrix[11];
		$actualValues[10] = $matrix[12];
		$actualValues[11] = $matrix[14];
		
		if(parent::solve(null, $actualValues, $parameters, 100, 0.001)) {
			$this->brightness = $this->undenormalizeBrightness($parameters[0]);
			$this->contrast = $this->undenormalizeContrast($parameters[1]);
			$this->saturation = $this->undenormalizeSaturation($parameters[2]);
			$this->hue = $this->undenormalizeHue($parameters[3]);
			return true;
		} else {
			return false;
		}
	}
	
	protected function undenormalizeBrightness($value) {
		return (int) round($value);
	}
	
	protected function undenormalizeContrast($value) {
		// based on the calculation done in fl.motion.AdjustColor
		$value = (int) round($value);
		if($value > 0) {
			$denormalizedValues = array(0, 1, 2, 4, 5, 6, 7, 8, 10, 11, 12, 14, 15, 16, 17, 18, 20, 21, 22, 24, 25, 27, 28, 30, 32, 34, 36, 38, 40, 42, 44, 46, 48, 50, 53, 56, 59, 62, 65, 68, 71, 74, 77, 80, 83, 86, 89, 92, 95, 98, 100, 106, 112, 118, 124, 130, 136, 142, 148, 154, 160, 166, 172, 178, 184, 190, 196, 200, 212, 225, 237, 250, 262, 275, 287, 300, 320, 340, 360, 380, 400, 430, 470, 490, 500, 550, 600, 650, 680, 700, 730, 750, 780, 800, 840, 870, 900, 940, 960, 980, 1000);
			$smallestError = 1000;
			$closestIndex = 0;
			foreach($denormalizedValues as $index => $denormalizedValue) {
				$error = $denormalizedValue - $value;
				if($error < 0) {
					$error = -$error;
				}
				if($error < $smallestError) {
					$smallestError = $error;
					$closestIndex = $index;
					if($error == 0) {
						break;
					}
				}
			}
			$value = $closestIndex;
		}
		return $value;
	}

	protected function undenormalizeSaturation($value) {
		// based on the calculation done in fl.motion.AdjustColor		
		if($value > 0) {
			$value = $value / 3; 
		}
		$value = (int) round($value);
		return $value;
	}

	protected function undenormalizeHue($value) {
		while($value < 0) {
			$value += 360;
		}
		return (int) (round($value) % 360);
	}
	
	protected function compute($inputValues, $parameters, &$outputValues) {
		// based on the calculation done in fl.motion.ColorMatrix
		$B = $parameters[0];
		$C = $parameters[1];
		$S = $parameters[2];
		$H = $parameters[3] * 0.0174533;
		
		if($S < -100) {
			// not sure why but sometimes the saturation converges to a value below the lower bound
			// make the error really large to force the solver back onto the right track
			$S = $S * 100;
		}
	
		$C1 = $C * 0.01 + 1;
		$C2 = $C * -0.635;
	
		$S1R = $S * -0.003086;
		$S1G = $S * -0.006094;
		$S1B = $S * -0.000820;
		$S2 = $S * 0.01 + 1;
		$S3R = $S1R + $S2;
		$S3G = $S1G + $S2;
		$S3B = $S1B + $S2;
		
		$HC = cos($H);
		$HS = sin($H);
		$H00 = 0.213 + (0.787 * $HC) - (0.213 * $HS);
		$H10 = 0.213 - (0.213 * $HC) + (0.143 * $HS);
		$H20 = 0.213 - (0.213 * $HC) - (0.787 * $HS);
		$H01 = 0.715 - (0.715 * $HC) - (0.715 * $HS);
		$H11 = 0.715 + (0.285 * $HC) + (0.140 * $HS);
		$H21 = 0.715 - (0.715 * $HC) + (0.715 * $HS);
		$H02 = 0.072 - (0.072 * $HC) + (0.928 * $HS);
		$H12 = 0.072 - (0.072 * $HC) - (0.283 * $HS);
		$H22 = 0.072 + (0.928 * $HC) + (0.072 * $HS);
		
		$B1 = ($B * $C1) + $C2;
		
		$outputValues[0]  = ($C1 * $S3R * $H00) + ($C1 * $S1R * $H01) + ($C1 * $S1R * $H02);
		$outputValues[1]  = ($C1 * $S1G * $H00) + ($C1 * $S3G * $H01) + ($C1 * $S1G * $H02);
		$outputValues[2]  = ($C1 * $S1B * $H00) + ($C1 * $S1B * $H01) + ($C1 * $S3B * $H02);
		$outputValues[3]  = $B1;
		
		$outputValues[4]  = ($C1 * $S3R * $H10) + ($C1 * $S1R * $H11) + ($C1 * $S1R * $H12);
		$outputValues[5]  = ($C1 * $S1G * $H10) + ($C1 * $S3G * $H11) + ($C1 * $S1G * $H12);
		$outputValues[6]  = ($C1 * $S1B * $H10) + ($C1 * $S1B * $H11) + ($C1 * $S3B * $H12);
		$outputValues[7]  = $B1;

		$outputValues[8]  = ($C1 * $S3R * $H20) + ($C1 * $S1R * $H21) + ($C1 * $S1R * $H22);
		$outputValues[9]  = ($C1 * $S1G * $H20) + ($C1 * $S3G * $H21) + ($C1 * $S1G * $H22);
		$outputValues[10] = ($C1 * $S1B * $H20) + ($C1 * $S1B * $H21) + ($C1 * $S3B * $H22);
		$outputValues[11] = $B1;
	}
}

?>
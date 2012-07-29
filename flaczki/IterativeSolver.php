<?php

// based on C++ code found at http://www.crbond.com/nonlinear.htm

abstract class IterativeSolver {

	abstract protected function compute($inputValues, $parameters, &$outputValues);
	
	public function solve($inputValues, $actualOutputValues, &$parameters, $maximumIteration = 100, $desiredError = 0.001) {
		$desiredErrorSquared = $desiredError * $desiredError;
		$equationCount = count($actualOutputValues);
		$parameterCount = count($parameters);

		$partialDerivatives = array_fill(0, $equationCount, array());
		$partialDerivativesSquared = $partialDerivativesSquaredInversed = array_fill(0, $parameterCount, array()); 
		$outputValues = 
		$outputValuesWithDelta = 
		$residuals = 
		$residualDeltas = array();
		
		for($loop = 0; $loop < $maximumIteration; $loop++) {
			$this->compute($inputValues, $parameters, $outputValues);
			// Compute matrix of partial derivatives
		        foreach($parameters as $parameterIndex => &$parameter) {
				$originalValue = $parameter;
				$delta = ($parameter > 1.0) ? $desiredErrorSquared * $parameter : $desiredErrorSquared;
				$parameter += $delta;
				$delta = $parameter - $originalValue;
				$this->compute($inputValues, $parameters, $outputValuesWithDelta);
				$parameter = $originalValue;
				
				for($i = 0; $i < $equationCount; $i++) {
					$partialDerivatives[$i][$parameterIndex] = ($outputValuesWithDelta[$i] - $outputValues[$i]) / $delta;
				}
			}
			
			// Form product: partialDerivativesSquared = partialDerivatives' x partialDerivatives
			for($k = 0; $k < $parameterCount; $k++) {
				for($i = 0; $i < $parameterCount; $i++) {
					$sum = 0;
					for($j = 0; $j < $equationCount; $j++) {
						$sum += $partialDerivatives[$j][$i] * $partialDerivatives[$j][$k];
					}
					$partialDerivativesSquared[$k][$i] = $sum;
				}
			}
			
			// Find inverse
		        if(!$this->inverse($partialDerivativesSquared, $partialDerivativesSquaredInversed)) {
		        	break;
			}
		
			// Get residuals (measured - model)
			for($i = 0; $i < $equationCount; $i++) {
				$residuals[$i] = $actualOutputValues[$i] - $outputValues[$i];
			}
		
			// Form partialDerivatives' x residuals
			for($i = 0; $i < $parameterCount; $i++) {
				$sum = 0;
				for($j = 0; $j < $equationCount; $j++) {
					$sum += $partialDerivatives[$j][$i] * $residuals[$j];
				}
				$residualDeltas[$i] = $sum;
			}
		
			// Compute parameter deltas
			$errorSquared = 0;
		        foreach($parameters as $parameterIndex => &$parameter) {
				$delta = 0;
				for($j = 0; $j < $parameterCount; $j++) {
					$delta += $partialDerivativesSquaredInversed[$parameterIndex][$j] * $residualDeltas[$j];
				}
				$parameter += $delta;
				$errorSquared += $delta * $delta;
			}
				
			// Check for convergence
		        if ($errorSquared < $desiredErrorSquared) {
				return $errorSquared;
			}
		}
		return false;
    	}
    	
    	protected function inverse($matrix, &$inversed) {
		$size = count($matrix);
		
		// Initialize identity matrix
		for($i = 0; $i < $size; $i++) {
			for($j = 0; $j < $size; $j++) {
				$inversed[$i][$j] = 0.0;
			}
			$inversed[$i][$i] = 1.0;
		}

		for($k = 0; $k < $size; $k++) {
			$divider = $matrix[$k][$k];
			if($divider == 0) {
				return false;
			}
			for($j = 0; $j < $size; $j++) {
				if($j > $k) { 
					$matrix[$k][$j] /= $divider;    // Don't bother with previous entries
				}
				$inversed[$k][$j] /= $divider;
			}
			for($i = 0; $i < $size; $i++) {             // Loop over rows
				if($i == $k) {
					continue;
				}
				$multiplier = $matrix[$i][$k];
				for($j = 0; $j < $size; $j++) {
					if($j > $k) {
						$matrix[$i][$j] -= $matrix[$k][$j] * $multiplier;
					}
					$inversed[$i][$j] -= $inversed[$k][$j] * $multiplier;
				}
			}
		}
		return true;
	}
}

?>
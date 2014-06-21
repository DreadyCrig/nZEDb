<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright     Copyright 2011, Union of RAD (http://union-of-rad.org)
 * @license       http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace li3_nzedb\extensions\test\rules;

class EndsWithCloseTagNewline extends \li3_quality\test\Rule {

	public function apply($testable, array $config = array()) {
		$message = "File does not end with ?>";
		$lines = $testable->lines();

		if ($lines[count($lines) - 1] !== "?>" &&
			($lines[count($lines) - 1] !== '' && $lines[count($lines) - 2] !== "?>")) {
			$this->addViolation(array(
				'message' => $message,
				'line' => count($lines) - 1
			));
		}
	}
}

?>
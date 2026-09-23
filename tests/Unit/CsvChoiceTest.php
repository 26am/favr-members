<?php
/**
 * CSV select/radio normalisation.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Tests\Unit;

use FavrMembers\ImportExport\Csv;

final class CsvChoiceTest extends TestCase {

	private function field( string $type ): array {
		return array(
			'type'    => $type,
			'options' => array(
				'active' => 'Active',
				'lapsed' => 'Lapsed',
			),
		);
	}

	public function test_keys_and_labels_in_any_case(): void {
		$this->assertSame( 'lapsed', Csv::choice( $this->field( 'select' ), 'Lapsed' ) );
		$this->assertSame( 'lapsed', Csv::choice( $this->field( 'select' ), ' LAPSED ' ) );
		$this->assertSame( 'active', Csv::choice( $this->field( 'radio' ), 'active' ) );
	}

	public function test_unknown_values_pass_through_for_the_sanitizer_to_reject(): void {
		$this->assertSame( 'Sleeping', Csv::choice( $this->field( 'select' ), 'Sleeping' ) );
	}

	public function test_other_field_types_are_untouched(): void {
		$this->assertSame( 'Lapsed', Csv::choice( $this->field( 'text' ), 'Lapsed' ) );
	}
}

<?php
/**
 * Base test case.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase as Base;

/**
 * Sets up Brain Monkey with faithful-enough stubs of the WP sanitizers we rely on.
 */
abstract class TestCase extends Base {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\stubs(
			array(
				'sanitize_text_field'     => static fn( $v ) => trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $v ) ) ),
				'sanitize_textarea_field' => static fn( $v ) => trim( strip_tags( (string) $v ) ),
				'sanitize_email'          => static fn( $v ) => filter_var( trim( (string) $v ), FILTER_VALIDATE_EMAIL ) ? trim( (string) $v ) : '',
				'esc_url_raw'             => static fn( $v ) => filter_var( $v, FILTER_VALIDATE_URL ) ? $v : '',
				'absint'                  => static fn( $v ) => abs( (int) $v ),
				'__'                      => static fn( $v ) => $v,
				'apply_filters'           => static fn( $hook, $value ) => $value,
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}

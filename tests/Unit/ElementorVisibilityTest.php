<?php
/**
 * Elementor visibility rules.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Tests\Unit;

use FavrMembers\Integration\Elementor;

final class ElementorVisibilityTest extends TestCase {

	public function test_rules(): void {
		// mode, logged in, member => visible.
		$cases = array(
			array( '', false, false, true ),
			array( 'members', true, true, true ),
			array( 'members', true, false, false ),
			array( 'members', false, false, false ),
			array( 'non_members', false, false, true ),
			array( 'non_members', true, false, true ),
			array( 'non_members', true, true, false ),
			array( 'logged_in', true, false, true ),
			array( 'logged_in', false, false, false ),
			array( 'logged_out', false, false, true ),
			array( 'logged_out', true, true, false ),
			array( 'unknown', false, false, true ),
		);
		foreach ( $cases as $c ) {
			$this->assertSame( $c[3], Elementor::visible( $c[0], $c[1], $c[2] ), implode( '/', array_map( 'var_export', array_slice( $c, 0, 3 ), array_fill( 0, 3, true ) ) ) );
		}
	}
}

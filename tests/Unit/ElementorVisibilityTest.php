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

	public function test_the_visibility_rules_on_the_way_down_to_an_element(): void {
		$page = array(
			array(
				'id'       => 'top',
				'settings' => array( 'favr_members_visibility' => 'members' ),
				'elements' => array(
					array(
						'id'       => 'inner',
						'settings' => array(),
						'elements' => array(
							array(
								'id'       => 'leaf',
								'settings' => array( 'favr_members_visibility' => 'logged_in' ),
							),
						),
					),
				),
			),
			array(
				'id'       => 'open',
				'settings' => array(),
				'elements' => array(),
			),
		);
		// An element inside a members-only section is members-only, whatever it says itself.
		$this->assertSame( array( 'members', '', 'logged_in' ), Elementor::pathModes( $page, 'leaf' ) );
		$this->assertSame( array( 'members' ), Elementor::pathModes( $page, 'top' ) );
		$this->assertSame( array( '' ), Elementor::pathModes( $page, 'open' ) );
		$this->assertNull( Elementor::pathModes( $page, 'missing' ) );
		$this->assertNull( Elementor::pathModes( array( 'junk', null ), 'leaf' ) );
	}

	public function test_a_save_that_says_nothing_about_the_members_only_switch_is_not_switching_it_off(): void {
		// Elementor's editor leaves out every page setting that still has its starting value,
		// and other tools save a page with no settings at all.
		$this->assertNull( Elementor::switchIn( array() ) );
		$this->assertNull( Elementor::switchIn( array( 'post_status' => 'publish' ) ) );
		$this->assertTrue( Elementor::switchIn( array( 'favr_members_only' => '1' ) ) );
		$this->assertTrue( Elementor::switchIn( array( 'favr_members_only' => 'yes' ) ) );
		$this->assertFalse( Elementor::switchIn( array( 'favr_members_only' => '' ) ) );
		$this->assertFalse( Elementor::switchIn( array( 'favr_members_only' => null ) ) );
		$this->assertFalse( Elementor::switchIn( array( 'favr_members_only' => '0' ) ) );
	}
}

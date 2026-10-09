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

	public function test_a_save_changes_the_switch_only_when_the_person_changed_it(): void {
		// A tool that says it outright, with no word on how the switch stood: do as it says.
		$this->assertTrue( Elementor::change( true, null, null ) );
		$this->assertFalse( Elementor::change( false, null, null ) );
		$this->assertNull( Elementor::change( null, true, null ) );
		// The editor sends the switch with every save, and how it stood when the page was opened.
		$this->assertNull( Elementor::change( true, true, null ), 'On when opened and still on: a tab left open must not undo a change made elsewhere.' );
		$this->assertNull( Elementor::change( false, false, null ), 'Off and off: this is also what looking at a revision leaves behind on a protected page.' );
		$this->assertTrue( Elementor::change( true, false, null ) );
		$this->assertFalse( Elementor::change( false, true, null ) );
		// Later in the same session, what it last did is how the switch stands.
		$this->assertFalse( Elementor::change( false, false, true ), 'Switched on and published, then off again.' );
		$this->assertNull( Elementor::change( true, false, true ) );
		$this->assertTrue( Elementor::change( true, true, false ), 'Switched off and published, then on again.' );
	}

	public function test_how_the_switch_stood_when_the_editor_opened(): void {
		$this->assertSame( array( null, '' ), Elementor::baseline( null ), 'Not sent at all.' );
		$this->assertSame( array( true, 'abc123XYZ' ), Elementor::baseline( '1|abc123XYZ' ) );
		$this->assertSame( array( false, 'abc123XYZ' ), Elementor::baseline( '|abc123XYZ' ) );
		// The control's starting value, which is what Elementor resets it to when a revision is shown.
		$this->assertSame( array( false, '' ), Elementor::baseline( '' ) );
		$this->assertSame( array( true, '' ), Elementor::baseline( '1' ) );
		$this->assertSame( array( false, 'ab' ), Elementor::baseline( '|a<b>;' ), 'Only letters and digits make a session token.' );
		$this->assertSame( array( false, '' ), Elementor::baseline( array( 'junk' ) ) );
	}

	public function test_which_addresses_are_elementor_rendering_one_element_on_request(): void {
		$this->assertTrue( Elementor::isElementorRoute( '/elementor-pro/v1/posts-widget' ) );
		// WordPress matches routes whatever the letter case, so the guard must too.
		$this->assertTrue( Elementor::isElementorRoute( '/ELEMENTOR-PRO/V1/Posts-Widget' ) );
		$this->assertTrue( Elementor::isElementorRoute( '/elementor-pro/v1/refresh-loop' ) );
		$this->assertTrue( Elementor::isElementorRoute( '/elementor/v1/anything' ) );
		$this->assertFalse( Elementor::isElementorRoute( '/wp/v2/posts' ) );
		$this->assertFalse( Elementor::isElementorRoute( '/favr/elementor' ) );
		$this->assertFalse( Elementor::isElementorRoute( '' ) );
	}

	public function test_what_an_elementor_address_is_asked_to_render(): void {
		$this->assertSame(
			array(
				'post'     => 1120,
				'elements' => array( 'abc1234' ),
			),
			Elementor::askedFor( array( 'post_id' => '1120', 'element_id' => 'abc1234' ) )
		);
		// Elementor reads the page's number without its sign, so the guard must as well.
		$this->assertSame( 1120, Elementor::askedFor( array( 'post_id' => '-1120' ) )['post'] );
		$this->assertSame( 1120, Elementor::askedFor( array( 'post_id' => '-1120.0' ) )['post'] );
		$this->assertSame( 1120, Elementor::askedFor( array( 'post_id' => '1120abc' ) )['post'] );
		$this->assertSame( 1, Elementor::askedFor( array( 'post_id' => array( '1120' ) ) )['post'], 'A list reads as 1, as it does for Elementor.' );
		$this->assertSame( 0, Elementor::askedFor( array() )['post'] );
		// Some routes render "element_id", others "widget_id": whatever is named gets checked.
		$this->assertSame( array( 'bogus', 'real123' ), Elementor::askedFor( array( 'post_id' => 5, 'element_id' => 'bogus', 'widget_id' => 'real123' ) )['elements'] );
		$this->assertSame( array( 'real123' ), Elementor::askedFor( array( 'post_id' => 5, 'element_id' => array( 'x' ), 'widget_id' => 'real123' ) )['elements'] );
		$this->assertSame( array(), Elementor::askedFor( array( 'post_id' => 5, 'element_id' => '' ) )['elements'] );
	}

	public function test_what_an_editing_session_last_did_is_remembered_with_the_page(): void {
		$this->assertSame( array( 'aaa' => 'on' ), Elementor::remember( array(), 'aaa', true ) );
		$this->assertSame( array( 'aaa' => 'off' ), Elementor::remember( array( 'aaa' => 'on' ), 'aaa', false ) );
		$this->assertTrue( Elementor::recall( array( 'aaa' => 'on' ), 'aaa' ) );
		$this->assertFalse( Elementor::recall( array( 'aaa' => 'off' ), 'aaa' ) );
		$this->assertNull( Elementor::recall( array( 'aaa' => 'on' ), 'bbb' ) );
		$this->assertNull( Elementor::recall( 'junk', 'aaa' ) );
		$this->assertNull( Elementor::recall( array( 'aaa' => 'on' ), '' ) );
		// Only the last few sessions are kept, newest last.
		$many = array();
		foreach ( range( 1, 12 ) as $n ) {
			$many = Elementor::remember( $many, 't' . $n, true );
		}
		$this->assertSame( 10, count( $many ) );
		$this->assertNull( Elementor::recall( $many, 't1' ) );
		$this->assertTrue( Elementor::recall( $many, 't12' ) );
		$this->assertSame( array( 'aaa' => 'on' ), Elementor::remember( 'junk', 'aaa', true ) );
		$this->assertSame( array(), Elementor::remember( array(), '', true ), 'No session token: nothing to remember it by.' );
	}
}

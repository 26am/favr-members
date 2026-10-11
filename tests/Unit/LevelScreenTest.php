<?php
/**
 * The Membership levels list when Favr Members runs without Favr Directory.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Tests\Unit;

use Brain\Monkey\Functions;
use FavrMembers\Admin\LevelScreen;

final class LevelScreenTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubs(
			array(
				'esc_attr'           => static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ),
				'sanitize_hex_color' => static fn( $color ) => '' === $color ? '' : ( preg_match( '|^#([A-Fa-f0-9]{3}){1,2}$|', (string) $color ) ? $color : null ),
			)
		);
	}

	/**
	 * A level as the list holds it.
	 *
	 * @param int    $id   Term id.
	 * @param string $name Name.
	 */
	private function level( int $id, string $name ): object {
		return (object) array(
			'term_id' => $id,
			'name'    => $name,
		);
	}

	/**
	 * The list showed an order number per level but was sorted by name (Associate 3, Fellow 1,
	 * Professional 2), so the screen contradicted itself.
	 */
	public function test_levels_are_listed_by_their_order_value(): void {
		$sorted = LevelScreen::byOrder(
			array( $this->level( 7, 'Associate' ), $this->level( 8, 'Fellow' ), $this->level( 9, 'Professional' ) ),
			array(
				7 => 3,
				8 => 1,
				9 => 2,
			)
		);

		$this->assertSame( array( 'Fellow', 'Professional', 'Associate' ), array_column( $sorted, 'name' ) );
	}

	public function test_levels_with_the_same_order_go_by_name_and_one_without_counts_as_zero(): void {
		$sorted = LevelScreen::byOrder(
			array( $this->level( 1, 'silver' ), $this->level( 2, 'Gold' ), $this->level( 3, 'Trial' ), $this->level( 4, 'Bronze' ) ),
			array(
				1 => 10,
				2 => 10,
				4 => 20,
			)
		);

		$this->assertSame( array( 'Trial', 'Gold', 'silver', 'Bronze' ), array_column( $sorted, 'name' ) );
	}

	public function test_the_order_column_follows_the_name_and_holds_only_the_number(): void {
		Functions\when( 'get_term_meta' )->justReturn( '2' );
		$screen = new LevelScreen();

		$this->assertSame(
			array( 'cb', 'name', 'favr_order', 'posts' ),
			array_keys(
				$screen->columns(
					array(
						'cb'          => '',
						'name'        => 'Name',
						'description' => 'Description',
						'posts'       => 'Count',
					)
				)
			)
		);
		$this->assertSame( '2', $screen->column( '', 'favr_order', 9 ) );
		$this->assertSame( 'kept', $screen->column( 'kept', 'posts', 9 ) );
	}

	/**
	 * The level's colour sits in front of its name, as in the Membership level box and the
	 * Members list, not in front of the order number.
	 */
	public function test_the_colour_dot_comes_before_the_levels_name(): void {
		$this->assertSame(
			'<span class="favr-level__swatch" style="background:#12355b"></span> Fellow',
			LevelScreen::nameWithColour( 'Fellow', '#12355b' )
		);
		$this->assertSame( 'Associate', LevelScreen::nameWithColour( 'Associate', '' ) );
		$this->assertSame( 'Associate', LevelScreen::nameWithColour( 'Associate', 'red; x:y' ) );
	}

	/**
	 * WordPress escapes a term's name on the `term_name` filter at priority 30. Hooked earlier,
	 * the dot would be printed as the text "<span class=…" in the list.
	 */
	public function test_the_colour_dot_is_added_after_wordpress_has_escaped_the_name(): void {
		\Brain\Monkey\Functions\when( 'add_action' )->alias(
			static function ( $hook, $callback ): void {
				if ( 'init' === $hook ) {
					$callback();
				}
			}
		);
		$priority = null;
		\Brain\Monkey\Functions\when( 'add_filter' )->alias(
			static function ( $hook, $callback, $at = 10 ) use ( &$priority ): void {
				if ( 'term_name' === $hook ) {
					$priority = $at;
				}
			}
		);

		( new LevelScreen() )->hook();

		$this->assertNotNull( $priority, 'The name filter is hooked.' );
		$this->assertGreaterThan( 30, $priority );
	}
}

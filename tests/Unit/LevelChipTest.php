<?php
/**
 * The level chip in the Members list: the level's own colour, then its name.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Tests\Unit;

use Brain\Monkey\Functions;
use FavrMembers\Admin\LevelChip;

final class LevelChipTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\stubs(
			array(
				'esc_html'           => static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ),
				'esc_attr'           => static fn( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ),
				// WordPress's own rule: "#" and three or six hex digits, anything else is not a colour.
				'sanitize_hex_color' => static fn( $color ) => '' === $color ? '' : ( preg_match( '|^#([A-Fa-f0-9]{3}){1,2}$|', (string) $color ) ? $color : null ),
			)
		);
	}

	public function test_a_level_is_a_chip_with_its_colour_and_its_name(): void {
		// The markup Favr Sites' skin draws as a chip (assets/skin/screens.css).
		$this->assertSame(
			'<span class="favr-level-chip"><span class="favr-level__swatch" style="background:#12355b"></span>Fellow</span>',
			LevelChip::html( 'Fellow', '#12355b' )
		);
	}

	public function test_a_level_without_a_colour_has_no_swatch_element(): void {
		$this->assertSame( '<span class="favr-level-chip">Associate</span>', LevelChip::html( 'Associate', '' ) );
	}

	/**
	 * @dataProvider notColours
	 *
	 * @param string $stored What the term meta holds.
	 */
	public function test_what_is_not_a_hex_colour_never_reaches_the_style_attribute( string $stored ): void {
		$this->assertSame( '<span class="favr-level-chip">Gold</span>', LevelChip::html( 'Gold', $stored ) );
	}

	/** @return array<string, array{0: string}> */
	public static function notColours(): array {
		return array(
			'a colour name'      => array( 'gold' ),
			'a second property'  => array( '#b45309;background-image:url(//example.org/x.png)' ),
			'markup'             => array( '"><script>alert(1)</script>' ),
			'hex without a hash' => array( 'b45309' ),
		);
	}

	public function test_the_name_is_escaped(): void {
		$this->assertSame(
			'<span class="favr-level-chip"><span class="favr-level__swatch" style="background:#0f766e"></span>Friends &amp; &lt;b&gt;family&lt;/b&gt;</span>',
			LevelChip::html( 'Friends & <b>family</b>', '#0f766e' )
		);
	}
}

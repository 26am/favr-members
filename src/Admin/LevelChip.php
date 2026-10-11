<?php
/**
 * A membership level as a chip.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Admin;

/**
 * The level's own colour as a dot, then its name. Favr Sites draws the markup as a pill for
 * Editors (`.favr-level-chip`); for everyone else it is the name with the level's colour beside
 * it, like the Membership level box. Favr Directory prints the same chip in its Level column.
 */
final class LevelChip {

	/**
	 * The chip.
	 *
	 * @param string $name  The level's name (plain text).
	 * @param string $color The level's colour (term meta `favr_level_color`). A level without
	 *                      one, or with something that is not a hex colour, gets no dot.
	 * @return string Escaped HTML.
	 */
	public static function html( string $name, string $color ): string {
		$hex    = (string) sanitize_hex_color( $color );
		$swatch = '' !== $hex ? sprintf( '<span class="favr-level__swatch" style="background:%s"></span>', esc_attr( $hex ) ) : '';
		return sprintf( '<span class="favr-level-chip">%s%s</span>', $swatch, esc_html( $name ) );
	}
}

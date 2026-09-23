<?php
/**
 * Cache-busting asset versions.
 *
 * @package FavrCore
 */

declare(strict_types=1);

namespace FavrMembers\Vendor\FavrCore\Support;

/**
 * The plugin version in production; the file's mtime under WP_DEBUG so edits show up
 * immediately during development.
 */
final class AssetVersion {

	/**
	 * Version string for an asset.
	 *
	 * @param string $path    Absolute file path.
	 * @param string $version Plugin version.
	 */
	public static function of( string $path, string $version ): string {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && is_readable( $path ) ) {
			return $version . '.' . (string) filemtime( $path );
		}
		return $version;
	}
}

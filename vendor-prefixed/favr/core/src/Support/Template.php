<?php
/**
 * Template loading with theme overrides.
 *
 * @package FavrCore
 */

declare(strict_types=1);

namespace FavrMembers\Vendor\FavrCore\Support;

/**
 * Renders a plugin's PHP templates. A theme overrides any of them by shipping
 * `{theme_dir}/{name}.php` (child theme first, then parent).
 */
final class Template {

	/**
	 * Constructor.
	 *
	 * @param string $plugin_dir Absolute path to the plugin's templates directory.
	 * @param string $theme_dir  Override folder inside themes (e.g. "favr-members").
	 * @param string $filter     Filter name for the resolved path.
	 */
	public function __construct(
		private string $plugin_dir,
		private string $theme_dir,
		private string $filter = ''
	) {}

	/**
	 * Resolve a template path.
	 *
	 * @param string $name Template name without extension, e.g. "parts/card".
	 */
	public function locate( string $name ): string {
		$name  = ltrim( str_replace( '..', '', $name ), '/' );
		$theme = locate_template( array( $this->theme_dir . '/' . $name . '.php' ) );
		$path  = '' !== $theme ? $theme : trailingslashit( $this->plugin_dir ) . $name . '.php';
		if ( '' !== $this->filter ) {
			$path = (string) apply_filters( $this->filter, $path, $name ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- consumer-provided name.
		}
		return $path;
	}

	/**
	 * Render a template to a string.
	 *
	 * @param string               $name Template name.
	 * @param array<string, mixed> $vars Variables available to the template.
	 */
	public function render( string $name, array $vars = array() ): string {
		$path = $this->locate( $name );
		if ( ! is_readable( $path ) ) {
			return '';
		}
		ob_start();
		( static function ( string $__path, array $__vars ): void {
			extract( $__vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- template scope.
			include $__path;
		} )( $path, $vars );
		return (string) ob_get_clean();
	}
}

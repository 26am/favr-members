<?php
/**
 * Plugin Name:       Favr Members
 * Plugin URI:        https://github.com/26am/favr-members
 * Description:       Member records (individual or business), member logins and a member dashboard for Chambers of Commerce and associations. Part of Favr Sites.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      8.1
 * Author:            Favr Sites
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       favr-members
 * Domain Path:       /languages
 *
 * @package FavrMembers
 */

defined( 'ABSPATH' ) || exit;

define( 'FAVR_MEMBERS_VERSION', '1.0.0' );
define( 'FAVR_MEMBERS_FILE', __FILE__ );
define( 'FAVR_MEMBERS_PATH', plugin_dir_path( __FILE__ ) );
define( 'FAVR_MEMBERS_URL', plugin_dir_url( __FILE__ ) );

// Namespace-prefixed shared library (favr/core via Strauss), committed with the plugin.
require_once __DIR__ . '/vendor-prefixed/autoload.php';

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'FavrMembers\\';
		if ( strncmp( $class_name, $prefix, strlen( $prefix ) ) !== 0 || str_starts_with( $class_name, 'FavrMembers\\Vendor\\' ) ) {
			return;
		}
		$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

require_once __DIR__ . '/src/api.php';

register_activation_hook( __FILE__, array( \FavrMembers\Model\Activation::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \FavrMembers\Model\Activation::class, 'deactivate' ) );

\FavrMembers\Plugin::boot();

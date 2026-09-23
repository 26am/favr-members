<?php
/**
 * Template renderer for Favr Members.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Frontend;

use FavrMembers\Vendor\FavrCore\Support\Template;

/**
 * Templates in /templates, overridable from yourtheme/favr-members/.
 */
final class View {

	/**
	 * Shared loader.
	 *
	 * @var Template|null
	 */
	private static ?Template $template = null;

	/**
	 * Render a template.
	 *
	 * @param string               $name Template name.
	 * @param array<string, mixed> $vars Variables.
	 */
	public static function render( string $name, array $vars = array() ): string {
		if ( null === self::$template ) {
			self::$template = new Template( FAVR_MEMBERS_PATH . 'templates', 'favr-members', 'favr_members_template' );
		}
		return self::$template->render( $name, $vars );
	}
}

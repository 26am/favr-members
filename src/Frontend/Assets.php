<?php
/**
 * Front-end assets.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Frontend;

use FavrMembers\Vendor\FavrCore\Support\AssetVersion;

/**
 * One small stylesheet, loaded only where a member form or gate renders.
 */
final class Assets {

	/** Enqueue. */
	public static function enqueue(): void {
		wp_enqueue_style( 'favr-members', FAVR_MEMBERS_URL . 'assets/public/members.css', array(), AssetVersion::of( FAVR_MEMBERS_PATH . 'assets/public/members.css', FAVR_MEMBERS_VERSION ) );
	}
}

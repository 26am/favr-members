<?php
/**
 * Elementor: Member Account.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Integration\Elementor;

use FavrMembers\Frontend\Pages;
use FavrMembers\Vendor\FavrCore\Integrations\Elementor\Widget;

/**
 * Same renderer as the block and shortcode.
 */
final class AccountWidget extends Widget {

	/** Name. */
	public function get_name(): string {
		return 'favr-members-account';
	}

	/** Title. */
	public function get_title(): string {
		return __( 'Member Account', 'favr-members' );
	}

	/** Icon. */
	public function get_icon(): string {
		return 'eicon-person';
	}

	/** Keywords. */
	public function get_keywords(): array {
		return array_map( 'trim', explode( ',', 'members, account, dashboard, profile, favr' ) );
	}

	/** Styles. */
	public function get_style_depends(): array {
		return array( 'favr-members' );
	}

	/** Settings. */
	protected function settings(): array {
		return array();
	}

	/**
	 * Render.
	 *
	 * @param array<string, mixed> $settings Settings.
	 */
	protected function output( array $settings ): string {
		return ( new Pages() )->account();
	}
}

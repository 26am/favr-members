<?php
/**
 * Elementor: Membership Application.
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
final class ApplicationWidget extends Widget {

	/** Name. */
	public function get_name(): string {
		return 'favr-members-register';
	}

	/** Title. */
	public function get_title(): string {
		return __( 'Membership Application', 'favr-members' );
	}

	/** Icon. */
	public function get_icon(): string {
		return 'eicon-form-horizontal';
	}

	/** Keywords. */
	public function get_keywords(): array {
		return array_map( 'trim', explode( ',', 'members, join, apply, register, application, favr' ) );
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
		return ( new Pages() )->register();
	}
}

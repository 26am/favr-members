<?php
/**
 * Elementor integration (optional).
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Integration;

use FavrMembers\Frontend\Restrict;
use FavrMembers\Schema\Identifiers as ID;
use FavrMembers\Vendor\FavrCore\Integrations\Elementor\Widget;

/**
 * - Widgets: Member Login, Member Account, Membership Application (shared "Favr" category).
 * - "Favr Members → Visibility" on every section, container and widget (Advanced tab):
 *   everyone, members only, non-members, logged-in or logged-out visitors. Hidden elements
 *   aren't rendered at all, so nothing leaks into the page source.
 * - "Members only" in each page's Elementor settings, stored as the same page flag the block
 *   editor toggle uses, so the whole-page gate works identically.
 * Everything runs on Elementor's hooks; without Elementor none of it loads.
 */
final class Elementor {

	private const CONTROL = 'favr_members_visibility';

	/** Hook. */
	public function hook(): void {
		add_action( 'elementor/elements/categories_registered', array( Widget::class, 'registerCategory' ) );
		add_action( 'elementor/widgets/register', array( $this, 'widgets' ) );
		add_action( 'elementor/element/after_section_end', array( $this, 'visibilityControls' ), 10, 2 );
		foreach ( array( 'widget', 'section', 'column', 'container' ) as $type ) {
			add_filter( "elementor/frontend/{$type}/should_render", array( $this, 'shouldRender' ), 10, 2 );
		}
		// Elementor's element cache stores page HTML; anything with a visibility rule must be
		// rendered per visitor instead.
		add_filter( 'elementor/element/is_dynamic_content', array( $this, 'dynamic' ), 10, 2 );
		// Elementor replaces the_content with its own output after our page gate runs, so gate
		// the builder output too.
		add_filter( 'elementor/frontend/the_content', array( $this, 'gateBuilderContent' ) );
		add_action( 'elementor/documents/register_controls', array( $this, 'documentControls' ) );
		add_action( 'elementor/document/after_save', array( $this, 'documentSaved' ), 10, 2 );
	}

	/**
	 * Widgets.
	 *
	 * @param object $manager Widgets manager.
	 */
	public function widgets( $manager ): void {
		foreach ( array( Elementor\LoginWidget::class, Elementor\AccountWidget::class, Elementor\ApplicationWidget::class ) as $class ) {
			$manager->register( new $class() );
		}
	}

	/**
	 * Visibility options.
	 *
	 * @return array<string, string>
	 */
	public static function modes(): array {
		return array(
			''            => __( 'Everyone', 'favr-members' ),
			'members'     => __( 'Current members only', 'favr-members' ),
			'non_members' => __( 'Everyone except current members', 'favr-members' ),
			'logged_in'   => __( 'Logged-in visitors', 'favr-members' ),
			'logged_out'  => __( 'Logged-out visitors', 'favr-members' ),
		);
	}

	/**
	 * Pure rule: may this element render for this visitor?
	 *
	 * @param string $mode      Mode.
	 * @param bool   $logged_in Visitor is logged in.
	 * @param bool   $member    Visitor is a current member (or staff who can edit the page).
	 */
	public static function visible( string $mode, bool $logged_in, bool $member ): bool {
		switch ( $mode ) {
			case 'members':
				return $member;
			case 'non_members':
				return ! $member;
			case 'logged_in':
				return $logged_in;
			case 'logged_out':
				return ! $logged_in;
		}
		return true;
	}

	/**
	 * Add the Visibility section after each element's Responsive section (Advanced tab).
	 *
	 * @param object $element    Elementor element.
	 * @param string $section_id Section just closed.
	 */
	public function visibilityControls( $element, $section_id ): void {
		if ( '_section_responsive' !== $section_id || ! method_exists( $element, 'start_controls_section' ) ) {
			return;
		}
		$element->start_controls_section(
			'favr_members_section',
			array(
				'label' => __( 'Favr Members: Visibility', 'favr-members' ),
				'tab'   => \Elementor\Controls_Manager::TAB_ADVANCED,
			)
		);
		$element->add_control(
			self::CONTROL,
			array(
				'label'       => __( 'Show to', 'favr-members' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => '',
				'options'     => self::modes(),
				'description' => __( 'Always visible while editing. Tip: pair a “Current members only” block with an “Everyone except current members” block holding a Join or Log in button.', 'favr-members' ),
			)
		);
		$element->end_controls_section();
	}

	/**
	 * Hide elements the visitor may not see.
	 *
	 * @param bool   $should_render Current decision.
	 * @param object $element       Element.
	 */
	public function shouldRender( $should_render, $element ): bool {
		if ( ! $should_render || ! is_object( $element ) || ! method_exists( $element, 'get_settings' ) ) {
			return (bool) $should_render;
		}
		$mode = (string) $element->get_settings( self::CONTROL );
		if ( '' === $mode || self::editing() ) {
			return true;
		}
		return self::visible( $mode, is_user_logged_in(), Restrict::canView( (int) get_the_ID() ) );
	}

	/**
	 * Mark elements with a visibility rule as dynamic.
	 *
	 * @param bool  $dynamic  Current.
	 * @param mixed $raw_data Element data.
	 */
	public function dynamic( $dynamic, $raw_data ): bool {
		return (bool) $dynamic || ( is_array( $raw_data ) && ! empty( $raw_data['settings'][ self::CONTROL ] ) );
	}

	/**
	 * Whole-page gate for Elementor-built pages.
	 *
	 * @param string $content Builder output.
	 */
	public function gateBuilderContent( $content ): string {
		$post_id = (int) get_the_ID();
		if ( $post_id && Restrict::isGated( $post_id ) && ! Restrict::canView( $post_id ) && ! self::editing() ) {
			return Restrict::gate( '' );
		}
		return (string) $content;
	}

	/**
	 * "Members only" in the page's Elementor settings.
	 *
	 * @param object $document Elementor document.
	 */
	public function documentControls( $document ): void {
		if ( ! is_object( $document ) || ! method_exists( $document, 'get_main_id' ) || ! in_array( get_post_type( (int) $document->get_main_id() ), array( 'page', 'post' ), true ) ) {
			return;
		}
		$document->start_controls_section(
			'favr_members_document',
			array(
				'label' => __( 'Favr Members', 'favr-members' ),
				'tab'   => \Elementor\Controls_Manager::TAB_SETTINGS,
			)
		);
		$document->add_control(
			'favr_members_only',
			array(
				'label'        => __( 'Members only', 'favr-members' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => '1',
				'default'      => get_post_meta( (int) $document->get_main_id(), ID::PAGE_META_GATED, true ) ? '1' : '',
				'description'  => __( 'Only current members see this page’s content; everyone else gets a login prompt. Also hidden from search engines.', 'favr-members' ),
			)
		);
		$document->end_controls_section();
	}

	/**
	 * Mirror the page setting into the shared page flag.
	 *
	 * @param object $document Document.
	 * @param array  $data     Saved data.
	 */
	public function documentSaved( $document, $data ): void {
		if ( ! is_object( $document ) || ! method_exists( $document, 'get_main_id' ) || ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return;
		}
		$post_id = (int) $document->get_main_id();
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! empty( $data['settings']['favr_members_only'] ) ) {
			update_post_meta( $post_id, ID::PAGE_META_GATED, true );
		} else {
			delete_post_meta( $post_id, ID::PAGE_META_GATED );
		}
	}

	/** In the Elementor editor or its preview (show everything so it can be edited). */
	private static function editing(): bool {
		if ( ! class_exists( '\Elementor\Plugin' ) || ! isset( \Elementor\Plugin::$instance ) ) {
			return false;
		}
		$plugin = \Elementor\Plugin::$instance;
		return ( isset( $plugin->editor ) && $plugin->editor->is_edit_mode() ) || ( isset( $plugin->preview ) && $plugin->preview->is_preview_mode() );
	}
}

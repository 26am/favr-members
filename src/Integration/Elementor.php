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
 * - "Members only" in each page's Elementor settings. The switch has one home, the page flag the
 *   block editor toggle uses: Elementor is shown its state when the editor opens and never
 *   stores a copy of its own (in the page, an autosave or a revision) that could go stale.
 * Everything runs on Elementor's hooks; without Elementor none of it loads.
 */
final class Elementor {

	private const CONTROL = 'favr_members_visibility';

	/** The "Members only" switch in a page's Elementor settings. */
	private const PAGE_SWITCH = 'favr_members_only';

	/**
	 * What a save in progress says about the switch (document object id => on or off).
	 *
	 * @var array<int, bool>
	 */
	private array $switched = array();

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
		// A members-only page gives Elementor nothing to build, whoever asks it to (a template
		// shortcode, a template widget, another page embedding it).
		add_filter( 'elementor/frontend/builder_content_data', array( $this, 'gateBuilderData' ), 10, 2 );
		// Elementor Pro has a public REST route that renders one element of any page on request,
		// skipping every check a page view makes.
		add_filter( 'rest_request_before_callbacks', array( $this, 'guardRenderRoute' ), 10, 3 );
		add_action( 'elementor/documents/register_controls', array( $this, 'documentControls' ) );
		add_filter( 'elementor/document/config', array( $this, 'documentConfig' ), 10, 2 );
		add_filter( 'elementor/document/save/data', array( $this, 'documentSaving' ), 10, 2 );
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
		return self::visible( $mode, is_user_logged_in(), Restrict::canView( self::buildingId() ) );
	}

	/**
	 * The visibility rules from the top of a page down to one element, outermost first (null when
	 * the element is not on the page). An element inside a hidden section is hidden with it.
	 *
	 * @param array<int, mixed> $elements Elementor element data (a page's, or one element's children).
	 * @param string            $id       Element id to find.
	 * @return list<string>|null
	 */
	public static function pathModes( array $elements, string $id ): ?array {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$mode = (string) ( $element['settings'][ self::CONTROL ] ?? '' );
			if ( (string) ( $element['id'] ?? '' ) === $id ) {
				return array( $mode );
			}
			$below = self::pathModes( isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : array(), $id );
			if ( null !== $below ) {
				return array_merge( array( $mode ), $below );
			}
		}
		return null;
	}

	/**
	 * Nothing to build from a members-only page for someone who may not read it.
	 *
	 * @param mixed $data    Element data.
	 * @param int   $post_id Document id.
	 * @return mixed
	 */
	public function gateBuilderData( $data, $post_id = 0 ) {
		return ! self::editing() && Restrict::isGated( (int) $post_id ) ? array() : $data;
	}

	/**
	 * Elementor Pro's "posts-widget" route renders one element of a page for anyone who asks
	 * for it by id. Refuse it for a page the visitor may not read, for an element (or a section
	 * around it) the visitor may not see, and for a page that is not published.
	 *
	 * @param mixed            $response Response so far.
	 * @param mixed            $handler  Route handler.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function guardRenderRoute( $response, $handler, $request ) {
		if ( is_wp_error( $response ) || ! $request instanceof \WP_REST_Request || false === strpos( $request->get_route(), '/posts-widget' ) || 0 !== strpos( $request->get_route(), '/elementor' ) ) {
			return $response;
		}
		$post_id = (int) $request->get_param( 'post_id' );
		$post    = $post_id ? get_post( $post_id ) : null;
		if ( ! $post ) {
			return $response;
		}
		$refuse = Restrict::isGated( $post_id ) || ( ! is_post_publicly_viewable( $post ) && ! current_user_can( 'edit_post', $post_id ) );
		if ( ! $refuse && class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->documents ) ) {
			$document = \Elementor\Plugin::$instance->documents->get( $post_id );
			$modes    = $document && method_exists( $document, 'get_elements_data' ) ? self::pathModes( (array) $document->get_elements_data(), (string) $request->get_param( 'element_id' ) ) : null;
			foreach ( (array) $modes as $mode ) {
				if ( ! self::visible( (string) $mode, is_user_logged_in(), Restrict::canView( $post_id ) ) ) {
					$refuse = true;
					break;
				}
			}
		}
		return $refuse
			? new \WP_Error( 'favr_members_only', __( 'This content is for members.', 'favr-members' ), array( 'status' => rest_authorization_required_code() ) )
			: $response;
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
	 * The page Elementor is building right now. Not get_the_ID(): while Elementor builds the
	 * site header, the "current post" is whatever the page is about (on a list, its first post),
	 * and while it builds a page embedded in another, it is the page doing the embedding.
	 */
	private static function buildingId(): int {
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->documents ) ) {
			$document = \Elementor\Plugin::$instance->documents->get_current();
			if ( is_object( $document ) && method_exists( $document, 'get_main_id' ) ) {
				return (int) $document->get_main_id();
			}
		}
		return (int) get_the_ID();
	}

	/**
	 * Whole-page gate for Elementor-built pages: what Elementor built from a members-only page
	 * is replaced by the members box for anyone who may not read it.
	 *
	 * @param string $content Builder output.
	 */
	public function gateBuilderContent( $content ): string {
		if ( self::editing() ) {
			return (string) $content;
		}
		return Restrict::isGated( self::buildingId() ) ? Restrict::gate( '' ) : (string) $content;
	}

	/**
	 * "Members only" in the page's Elementor settings.
	 *
	 * Elementor builds these controls once for all pages of a kind, so nothing here may depend
	 * on the page: the switch starts off, and documentConfig() says how this page has it.
	 * save_default makes the editor send the switch with every save. Without it Elementor
	 * leaves out a setting that still has its starting value.
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
			self::PAGE_SWITCH,
			array(
				'label'        => __( 'Members only', 'favr-members' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => '1',
				'default'      => '',
				'save_default' => true,
				// Elementor builds one set of controls per kind of document, so the wording may
				// depend on page or post, never on which page.
				'description'  => 'post' === get_post_type( (int) $document->get_main_id() )
					? __( 'Only current members can read this post; everyone else gets a login prompt. Its title still shows in lists. Also hidden from search engines.', 'favr-members' )
					: __( 'Only current members see this page’s content; everyone else gets a login prompt. Also hidden from search engines. The log-in, account and join pages always stay open.', 'favr-members' ),
			)
		);
		$document->end_controls_section();
	}

	/**
	 * When the editor opens a page, show the switch as the page has it.
	 *
	 * @param mixed $config  Extra editor configuration for the document.
	 * @param mixed $post_id The page being edited.
	 * @return array<string, mixed>
	 */
	public function documentConfig( $config, $post_id = 0 ): array {
		$config  = is_array( $config ) ? $config : array();
		$post_id = (int) $post_id;
		if ( ! in_array( get_post_type( $post_id ), array( 'page', 'post' ), true ) ) {
			return $config;
		}
		$settings = isset( $config['settings'] ) && is_array( $config['settings'] ) ? $config['settings'] : array();
		$values   = isset( $settings['settings'] ) && is_array( $settings['settings'] ) ? $settings['settings'] : array();

		$values[ self::PAGE_SWITCH ] = Restrict::isMembersOnly( $post_id ) ? '1' : '';
		$settings['settings']        = $values;
		$config['settings']          = $settings;
		return $config;
	}

	/**
	 * What a save says about the switch: on, off, or nothing at all (null). Saying nothing is
	 * not switching it off.
	 *
	 * @param array<string, mixed> $settings Page settings being saved.
	 */
	public static function switchIn( array $settings ): ?bool {
		return array_key_exists( self::PAGE_SWITCH, $settings ) ? Restrict::flagged( $settings[ self::PAGE_SWITCH ] ) : null;
	}

	/**
	 * A page is being saved: take the switch out of what Elementor is about to store, and
	 * remember what it said until the save has gone through.
	 *
	 * @param mixed  $data     Data being saved.
	 * @param object $document Document.
	 * @return mixed
	 */
	public function documentSaving( $data, $document = null ) {
		if ( ! is_array( $data ) || ! is_object( $document ) || ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return $data;
		}
		$says = self::switchIn( $data['settings'] );
		if ( null !== $says ) {
			$this->switched[ spl_object_id( $document ) ] = $says;
			unset( $data['settings'][ self::PAGE_SWITCH ] );
		}
		return $data;
	}

	/**
	 * A save went through: if it said something about the switch, and it was the page itself that
	 * was saved (not an autosave of changes nobody has published yet), set the page flag.
	 *
	 * @param object $document Document.
	 */
	public function documentSaved( $document ): void {
		if ( ! is_object( $document ) || ! isset( $this->switched[ spl_object_id( $document ) ] ) ) {
			return;
		}
		$on = $this->switched[ spl_object_id( $document ) ];
		unset( $this->switched[ spl_object_id( $document ) ] );
		if ( ! method_exists( $document, 'get_main_id' ) || ! method_exists( $document, 'get_post' ) ) {
			return;
		}
		$post_id = (int) $document->get_main_id();
		$saved   = $document->get_post();
		if ( ! $saved instanceof \WP_Post || (int) $saved->ID !== $post_id || ! in_array( $saved->post_type, array( 'page', 'post' ), true ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( $on ) {
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

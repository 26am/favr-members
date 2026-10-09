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
 *   block editor toggle uses. Elementor is shown its state when the editor opens, the page never
 *   stores a copy that could go stale, and a save changes the flag only when the person at that
 *   editor changed the switch (see change()).
 * Everything runs on Elementor's hooks; without Elementor none of it loads.
 */
final class Elementor {

	private const CONTROL = 'favr_members_visibility';

	/** The "Members only" switch in a page's Elementor settings. */
	private const PAGE_SWITCH = 'favr_members_only';

	/** How the switch stood when the editor opened ("1|token" or "|token"), sent back with each save. */
	private const PAGE_SWITCH_WAS = 'favr_members_only_was';

	/** Where Elementor keeps a document's page settings. */
	private const PAGE_SETTINGS = '_elementor_page_settings';

	/** How many editing sessions' last action on the switch are remembered with a page. */
	private const SESSIONS_KEPT = 100;

	/**
	 * What a save in progress does to the switch: document => [ on or off, the editing
	 * session's token ]. Only saves that change it are in here.
	 *
	 * @var \WeakMap<object, array{0: bool, 1: string}>|null
	 */
	private ?\WeakMap $switched = null;

	/**
	 * The document whose editor configuration Elementor is putting together.
	 *
	 * @var object|null
	 */
	private ?object $configuring = null;

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
		// Elementor Pro has public REST routes that render one element of any page on request,
		// skipping every check a page view makes.
		add_filter( 'rest_request_before_callbacks', array( $this, 'guardRenderRoute' ), 10, 3 );
		add_action( 'elementor/documents/register_controls', array( $this, 'documentControls' ) );
		add_action( 'elementor/document/before_get_config', array( $this, 'configuring' ) );
		add_filter( 'elementor/document/config', array( $this, 'documentConfig' ), 10, 2 );
		add_action( 'elementor/template-library/after_save_template', array( $this, 'templateSaved' ), 10, 2 );
		add_filter( 'elementor/document/save/data', array( $this, 'documentSaving' ), 10, 2 );
		add_action( 'elementor/document/before_save', array( $this, 'documentSaved' ) );
		add_action( 'wp_ajax_elementor_library_direct_actions', array( $this, 'guardTemplateExport' ), 0 );
		add_filter( 'handle_bulk_actions-edit-elementor_library', array( $this, 'guardTemplateBulkExport' ), 1, 3 );
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
	 * @param callable|null     $inner    Gives an element's children the way Elementor finds them
	 *                                    (a component keeps its insides in a document of its own):
	 *                                    fn( array $children, array $element ): array.
	 * @return list<string>|null
	 */
	public static function pathModes( array $elements, string $id, ?callable $inner = null ): ?array {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}
			$mode = (string) ( $element['settings'][ self::CONTROL ] ?? '' );
			if ( (string) ( $element['id'] ?? '' ) === $id ) {
				return array( $mode );
			}
			$children = isset( $element['elements'] ) && is_array( $element['elements'] ) ? $element['elements'] : array();
			if ( $inner ) {
				$children = (array) $inner( $children, $element );
			}
			$below = self::pathModes( $children, $id, $inner );
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
	 * Whether a REST route is one of Elementor's. WordPress matches routes whatever the letter
	 * case and hands over the path as it was typed, so the comparison ignores case too.
	 *
	 * @param string $route Route as requested.
	 */
	public static function isElementorRoute( string $route ): bool {
		return 0 === strpos( strtolower( $route ), '/elementor' );
	}

	/**
	 * Every page and every element a request names, read the way Elementor reads them.
	 *
	 * A request has several parts (the address, a form, a JSON body) and whoever reads it picks
	 * a winner among them; two readers can pick differently (a JSON null beats the address for
	 * one WordPress accessor and not for the other). So nothing is picked here: every part is
	 * looked at. Elementor drops the sign of a page's number ("-12" is page 12 to it) and reads a
	 * list as 1. Some of its routes render "element_id" and others "widget_id".
	 *
	 * @param array<int, mixed> $sources The request's parameters, one array per part.
	 * @return array{posts: list<int>, elements: list<string>}
	 */
	public static function askedFor( array $sources ): array {
		$posts    = array();
		$elements = array();
		foreach ( $sources as $params ) {
			if ( ! is_array( $params ) ) {
				continue;
			}
			$raw = $params['post_id'] ?? 0;
			$id  = is_scalar( $raw ) ? abs( (int) $raw ) : ( is_array( $raw ) && $raw ? 1 : 0 );
			if ( $id > 0 ) {
				$posts[ $id ] = $id;
			}
			foreach ( array( 'element_id', 'widget_id' ) as $name ) {
				if ( isset( $params[ $name ] ) && is_scalar( $params[ $name ] ) && '' !== (string) $params[ $name ] ) {
					$elements[ (string) $params[ $name ] ] = (string) $params[ $name ];
				}
			}
		}
		return array(
			'posts'    => array_values( $posts ),
			'elements' => array_values( $elements ),
		);
	}

	/**
	 * Elementor Pro renders one element of a page for anyone who asks for it by id (its
	 * "posts-widget", "refresh-loop" and "refresh-search" routes, used by load-more buttons and
	 * filters), skipping every check a page view makes. So for any Elementor route that names a
	 * page: refuse when the visitor may not read that page, when the page is not published, and
	 * when an element it names (or a section around it) is not shown to this visitor. Decided
	 * by what the request names anywhere in it, not by the route, so a route Elementor adds
	 * later is covered.
	 *
	 * @param mixed            $response Response so far.
	 * @param mixed            $handler  Route handler.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public function guardRenderRoute( $response, $handler, $request ) {
		if ( is_wp_error( $response ) || ! $request instanceof \WP_REST_Request || ! self::isElementorRoute( (string) $request->get_route() ) ) {
			return $response;
		}
		$asked = self::askedFor(
			array(
				array(
					'post_id'    => $request->get_param( 'post_id' ),
					'element_id' => $request->get_param( 'element_id' ),
					'widget_id'  => $request->get_param( 'widget_id' ),
				),
				$request->get_url_params(),
				$request->get_query_params(),
				$request->get_body_params(),
				$request->get_json_params(),
				$request->get_default_params(),
			)
		);
		// The children of an element, the way Elementor's own lookup reaches them.
		$inner = static fn( array $children, array $element ): array => (array) apply_filters( 'elementor/utils/find_element_recursive/inner_elements', $children, $element ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- Elementor's own filter, asked the way Elementor asks it.
		foreach ( $asked['posts'] as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				continue;
			}
			$refuse = Restrict::isGated( $post_id ) || ( ! is_post_publicly_viewable( $post ) && ! current_user_can( 'edit_post', $post_id ) );
			if ( ! $refuse && $asked['elements'] && class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->documents ) ) {
				$document = \Elementor\Plugin::$instance->documents->get( $post_id );
				$data     = $document && method_exists( $document, 'get_elements_data' ) ? (array) $document->get_elements_data() : array();
				foreach ( $asked['elements'] as $element ) {
					foreach ( (array) self::pathModes( $data, $element, $inner ) as $mode ) {
						if ( ! self::visible( (string) $mode, is_user_logged_in(), Restrict::canView( $post_id ) ) ) {
							$refuse = true;
							break 2;
						}
					}
				}
			}
			if ( $refuse ) {
				return new \WP_Error( 'favr_members_only', __( 'This content is for members.', 'favr-members' ), array( 'status' => rest_authorization_required_code() ) );
			}
		}
		return $response;
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
	 * save_default makes the editor send both values with every save. Without it Elementor
	 * leaves out a setting that still has its starting value.
	 *
	 * @param object $document Elementor document.
	 */
	public function documentControls( $document ): void {
		if ( ! self::hasSwitch( $document ) ) {
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
		$document->add_control(
			self::PAGE_SWITCH_WAS,
			array(
				'type'         => \Elementor\Controls_Manager::HIDDEN,
				'default'      => '',
				'save_default' => true,
			)
		);
		$document->end_controls_section();
	}

	/**
	 * Whether a document is a page or a post (the only things with the switch).
	 *
	 * @param mixed $document Elementor document.
	 */
	private static function hasSwitch( $document ): bool {
		return is_object( $document ) && method_exists( $document, 'get_main_id' ) && in_array( get_post_type( (int) $document->get_main_id() ), array( 'page', 'post' ), true );
	}

	/**
	 * Whether a document is the page itself, as opposed to an autosave or a revision of it.
	 * (Elementor keeps a person's unpublished changes to a published page in an autosave; for a
	 * page that is not published yet it saves them into the page.)
	 *
	 * @param object $document Elementor document.
	 */
	private static function isThePage( object $document ): bool {
		if ( ! method_exists( $document, 'get_main_id' ) || ! method_exists( $document, 'get_post' ) ) {
			return false;
		}
		$saved = $document->get_post();
		return $saved instanceof \WP_Post && (int) $saved->ID === (int) $document->get_main_id();
	}

	/**
	 * Elementor is about to put together a document's editor configuration.
	 *
	 * @param mixed $document Elementor document.
	 */
	public function configuring( $document ): void {
		$this->configuring = is_object( $document ) ? $document : null;
	}

	/**
	 * When the editor opens a page, show the switch as the page has it, or as the person left
	 * it if they changed it and have not published yet. Alongside goes how the page has it right
	 * now and a token for this editing session; both come back with every save.
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
		$stands = Restrict::isMembersOnly( $post_id );
		$shown  = $stands;
		$source = $this->configuring;
		if ( $source && method_exists( $source, 'get_main_id' ) && (int) $source->get_main_id() === $post_id && ! self::isThePage( $source ) && method_exists( $source, 'get_post' ) && $source->get_post() instanceof \WP_Post ) {
			$waiting = get_post_meta( (int) $source->get_post()->ID, self::PAGE_SETTINGS, true );
			if ( is_array( $waiting ) && array_key_exists( self::PAGE_SWITCH, $waiting ) ) {
				$shown = Restrict::flagged( $waiting[ self::PAGE_SWITCH ] );
			}
		}
		$settings = isset( $config['settings'] ) && is_array( $config['settings'] ) ? $config['settings'] : array();
		$values   = isset( $settings['settings'] ) && is_array( $settings['settings'] ) ? $settings['settings'] : array();

		$values[ self::PAGE_SWITCH ]     = $shown ? '1' : '';
		$values[ self::PAGE_SWITCH_WAS ] = ( $stands ? '1' : '' ) . '|' . wp_generate_password( 16, false );
		$settings['settings']            = $values;
		$config['settings']              = $settings;
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
	 * How the switch stood when the editor opened, as the editor sends it back: "1|token" or
	 * "|token". Null when it was not sent at all (a tool, not the editor). An empty value is the
	 * control's starting value, which is what Elementor resets it to when a revision is shown.
	 *
	 * @param mixed $raw Value sent.
	 * @return array{0: bool|null, 1: string} How it stood, and the editing session's token.
	 */
	public static function baseline( $raw ): array {
		if ( null === $raw ) {
			return array( null, '' );
		}
		$parts = explode( '|', is_string( $raw ) ? $raw : '', 2 );
		return array( Restrict::flagged( $parts[0] ), substr( (string) preg_replace( '/[^A-Za-z0-9]/', '', $parts[1] ?? '' ), 0, 32 ) );
	}

	/**
	 * What a save does to the switch: on (true), off (false), or nothing (null).
	 *
	 * The editor sends the switch with every save, so a save only counts as a change when the
	 * switch differs from how it stood: as the editor found it, or as this editing session last
	 * left it. That way a tab left open, or a switch Elementor reset on its own (looking at a
	 * revision does that), never undoes a page's protection.
	 *
	 * @param bool|null $says    What the save says (null: nothing).
	 * @param bool|null $was     How the switch stood when the editor opened (null: not said).
	 * @param bool|null $applied What this editing session last did to it (null: nothing yet).
	 */
	public static function change( ?bool $says, ?bool $was, ?bool $applied ): ?bool {
		if ( null === $says ) {
			return null;
		}
		if ( null === $was ) {
			return $says;
		}
		return ( $applied ?? $was ) === $says ? null : $says;
	}

	/**
	 * What an editing session last did to the switch (null: nothing, or not known).
	 *
	 * @param mixed  $sessions What is remembered with the page (token => on|off).
	 * @param string $token    The session's token.
	 */
	public static function recall( $sessions, string $token ): ?bool {
		if ( '' === $token || ! is_array( $sessions ) || ! isset( $sessions[ $token ] ) || ! in_array( $sessions[ $token ], array( 'on', 'off' ), true ) ) {
			return null;
		}
		return 'on' === $sessions[ $token ];
	}

	/**
	 * Remember what an editing session did to the switch, keeping the last few sessions.
	 *
	 * @param mixed  $sessions What is remembered with the page so far.
	 * @param string $token    The session's token.
	 * @param bool   $on       What it did.
	 * @return array<string, string>
	 */
	public static function remember( $sessions, string $token, bool $on ): array {
		$sessions = is_array( $sessions ) ? $sessions : array();
		if ( '' === $token ) {
			return $sessions;
		}
		unset( $sessions[ $token ] );
		$sessions[ $token ] = $on ? 'on' : 'off';
		return array_slice( $sessions, -self::SESSIONS_KEPT, null, true );
	}

	/**
	 * A page is being saved. Work out whether the person changed the switch (see change()),
	 * take what belongs to the switch out of what Elementor is about to store, and remember a
	 * change until Elementor has let the save through.
	 *
	 * An autosave of a published page is where Elementor keeps a person's unpublished changes:
	 * it keeps the switch too, but only when the person changed it. A switch that Elementor
	 * reset by itself, or that a tab left open still shows the old way, is no change and is
	 * not kept, or it would come back later looking like one.
	 *
	 * @param mixed  $data     Data being saved.
	 * @param object $document Document.
	 * @return mixed
	 */
	public function documentSaving( $data, $document = null ) {
		if ( ! is_array( $data ) || ! is_object( $document ) || ! isset( $data['settings'] ) || ! is_array( $data['settings'] ) ) {
			return $data;
		}
		$this->switched ??= new \WeakMap();
		unset( $this->switched[ $document ] );
		$says = self::switchIn( $data['settings'] );
		$was  = self::baseline( $data['settings'][ self::PAGE_SWITCH_WAS ] ?? null );
		unset( $data['settings'][ self::PAGE_SWITCH ], $data['settings'][ self::PAGE_SWITCH_WAS ] );
		if ( null === $says || ! self::hasSwitch( $document ) ) {
			return $data;
		}
		$change = self::change( $says, $was[0], self::recall( get_post_meta( (int) $document->get_main_id(), ID::PAGE_META_SESS, true ), $was[1] ) );
		if ( null === $change ) {
			return $data;
		}
		if ( self::isThePage( $document ) ) {
			$this->switched[ $document ] = array( $change, $was[1] );
		} else {
			$data['settings'][ self::PAGE_SWITCH ] = $change ? '1' : '';
		}
		return $data;
	}

	/**
	 * Elementor has checked that the person may save and is about to: if they changed the
	 * switch, set the page flag now, before the page's text and status are stored, so that a
	 * page first published with the switch on is never public for a moment.
	 *
	 * @param object $document Document.
	 */
	public function documentSaved( $document ): void {
		if ( ! is_object( $document ) || ! $this->switched || ! isset( $this->switched[ $document ] ) ) {
			return;
		}
		list( $change, $token ) = $this->switched[ $document ];
		unset( $this->switched[ $document ] );
		if ( ! self::isThePage( $document ) ) {
			return;
		}
		$post_id = (int) $document->get_main_id();
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( $change ) {
			update_post_meta( $post_id, ID::PAGE_META_GATED, true );
		} else {
			delete_post_meta( $post_id, ID::PAGE_META_GATED );
		}
		if ( '' !== $token ) {
			update_post_meta( $post_id, ID::PAGE_META_SESS, self::remember( get_post_meta( $post_id, ID::PAGE_META_SESS, true ), $token, $change ) );
		}
	}

	/**
	 * "Save as template" copies a page's text into Elementor's template library, which visitors
	 * can list and search. The copy is told which page it was saved from and follows that page
	 * into protection (Restrict keeps copies in step). A copy made while the page was
	 * members-only holds members-only text: it is marked and stays protected whatever the page
	 * does later.
	 *
	 * Which page: the one the editor is on (every editor request names it). The save dialog's
	 * own field still names the page the editor was first opened on after the person has moved
	 * to another page inside the editor, so it is only the fallback; if either is members-only,
	 * so is the copy.
	 *
	 * @param mixed $template_id   The new template.
	 * @param mixed $template_data What it was saved from.
	 */
	public function templateSaved( $template_id, $template_data = array() ): void {
		$template_id = (int) $template_id;
		$named       = is_array( $template_data ) && isset( $template_data['post_id'] ) ? absint( $template_data['post_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Elementor has verified the request; this only reads which page the editor is on.
		$editing = isset( $_REQUEST['editor_post_id'] ) ? absint( wp_unslash( $_REQUEST['editor_post_id'] ) ) : 0;
		$sources = array();
		foreach ( array( $editing, $named ) as $from ) {
			$parent = $from ? wp_is_post_revision( $from ) : 0;
			$from   = $parent ? (int) $parent : $from;
			if ( $from > 0 && $from !== $template_id && get_post( $from ) ) {
				$sources[] = $from;
			}
		}
		if ( $template_id <= 0 || array() === $sources ) {
			return;
		}
		// A copy of a copy belongs to the page the first copy was made from.
		$origin = (int) get_post_meta( $sources[0], ID::PAGE_META_COPY, true );
		update_post_meta( $template_id, ID::PAGE_META_COPY, $origin > 0 ? $origin : $sources[0] );
		foreach ( $sources as $from ) {
			if ( Restrict::flagged( get_post_meta( $from, ID::PAGE_META_GATED, true ) ) ) {
				update_post_meta( $template_id, ID::PAGE_META_GATED, true );
				update_post_meta( $template_id, ID::PAGE_META_KEPT, true );
				break;
			}
		}
	}

	/**
	 * Elementor lets anyone who can write posts export any saved template as a file. Not a
	 * members-only one, for someone who may not read it.
	 */
	public function guardTemplateExport(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only ever refuses; Elementor checks the nonce before it does anything.
		$action = isset( $_REQUEST['library_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['library_action'] ) ) : '';
		$id     = isset( $_REQUEST['template_id'] ) ? absint( wp_unslash( $_REQUEST['template_id'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( 'export_template' === $action && $id && Restrict::isGated( $id ) ) {
			wp_die( esc_html__( 'This content is for members.', 'favr-members' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * The same for "Export" in the Templates list's bulk actions.
	 *
	 * @param mixed $redirect Where to go afterwards.
	 * @param mixed $action   The bulk action.
	 * @param mixed $post_ids Templates ticked.
	 * @return mixed
	 */
	public function guardTemplateBulkExport( $redirect, $action = '', $post_ids = array() ) {
		if ( is_string( $action ) && false !== strpos( $action, 'export' ) ) {
			foreach ( (array) $post_ids as $id ) {
				// Elementor drops the sign of the number it is given.
				if ( is_scalar( $id ) && Restrict::isGated( abs( (int) $id ) ) ) {
					wp_die( esc_html__( 'This content is for members.', 'favr-members' ), '', array( 'response' => 403 ) );
				}
			}
		}
		return $redirect;
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

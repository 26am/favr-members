<?php
/**
 * Members-only content.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Frontend;

use FavrMembers\Schema\Identifiers as ID;

/**
 * Three ways to restrict content to current members:
 *  - the "Members Only" block (wraps any blocks),
 *  - the [favr_members_only]…[/favr_members_only] shortcode,
 *  - a "Members only" toggle on pages and posts (the whole content).
 * Staff who can edit the post always see the content.
 */
final class Restrict {

	/** Hook. */
	public function hook(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_shortcode( 'favr_members_only', array( $this, 'shortcode' ) );
		add_filter( 'the_content', array( $this, 'gatePage' ), 5 );
		add_filter( 'get_the_excerpt', array( $this, 'gateExcerpt' ), 5, 2 );
		add_filter( 'the_content_feed', array( $this, 'gateFeed' ), 5 );
		add_filter( 'the_excerpt_rss', array( $this, 'gateFeed' ), 5 );
		add_filter( 'rest_prepare_post', array( $this, 'gateRest' ), 10, 2 );
		add_filter( 'rest_prepare_page', array( $this, 'gateRest' ), 10, 2 );
		add_filter( 'wp_robots', array( $this, 'robots' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editorAssets' ) );
	}

	/** Block + page meta. */
	public function register(): void {
		register_block_type(
			FAVR_MEMBERS_PATH . 'blocks/members-only',
			array( 'render_callback' => array( $this, 'renderBlock' ) )
		);
		// Login, account and application blocks: the same renderers as the shortcodes.
		$pages = new Pages();
		foreach ( array( 'login', 'account', 'register' ) as $slug ) {
			register_block_type(
				FAVR_MEMBERS_PATH . 'blocks/' . $slug,
				array(
					'render_callback' => static fn(): string => sprintf( '<div %s>%s</div>', get_block_wrapper_attributes(), $pages->{$slug}() ),
				)
			);
		}
		foreach ( array( 'page', 'post' ) as $type ) {
			register_post_meta(
				$type,
				ID::PAGE_META_GATED,
				array(
					'type'          => 'boolean',
					'single'        => true,
					'default'       => false,
					'show_in_rest'  => true,
					'auth_callback' => static fn( $allowed, $key, $post_id ): bool => current_user_can( 'edit_post', (int) $post_id ),
				)
			);
		}
	}

	/** Editor UI: the block and the page toggle panel. */
	public function editorAssets(): void {
		wp_enqueue_script(
			'favr-members-editor',
			FAVR_MEMBERS_URL . 'assets/blocks/editor.js',
			array( 'wp-blocks', 'wp-block-editor', 'wp-element', 'wp-components', 'wp-plugins', 'wp-editor', 'wp-data', 'wp-core-data', 'wp-i18n' ),
			\FavrMembers\Vendor\FavrCore\Support\AssetVersion::of( FAVR_MEMBERS_PATH . 'assets/blocks/editor.js', FAVR_MEMBERS_VERSION ),
			true
		);
	}

	/**
	 * Whether the current visitor may see members-only content.
	 *
	 * @param int $post_id Post being viewed (staff who can edit it always pass).
	 */
	public static function canView( int $post_id = 0 ): bool {
		if ( $post_id && current_user_can( 'edit_post', $post_id ) ) {
			return true;
		}
		return is_user_logged_in() && favr_members_is_active();
	}

	/**
	 * Block render.
	 *
	 * @param array<string, mixed> $attributes Attributes.
	 * @param string               $content    Inner blocks.
	 */
	public function renderBlock( array $attributes, string $content ): string {
		if ( self::canView( (int) get_the_ID() ) ) {
			return $content;
		}
		return self::gate( (string) ( $attributes['message'] ?? '' ) );
	}

	/**
	 * Shortcode.
	 *
	 * @param array<string, string>|string $atts    Attributes.
	 * @param string|null                  $content Enclosed content.
	 */
	public function shortcode( $atts, ?string $content = null ): string {
		$atts = shortcode_atts( array( 'message' => '' ), is_array( $atts ) ? $atts : array() );
		return self::canView( (int) get_the_ID() ) ? do_shortcode( (string) $content ) : self::gate( (string) $atts['message'] );
	}

	/**
	 * Whole-page gate.
	 *
	 * @param string $content Content.
	 */
	public function gatePage( string $content ): string {
		// Every context that runs the_content (single views, archives, blocks, search,
		// excerpts generated from content) — not just singular pages.
		return self::isGated( (int) get_the_ID() ) ? self::gate( '' ) : $content;
	}

	/**
	 * Whether a post is members-only and hidden from the current visitor.
	 *
	 * @param int $post_id Post id.
	 */
	public static function isGated( int $post_id ): bool {
		return $post_id > 0 && (bool) get_post_meta( $post_id, ID::PAGE_META_GATED, true ) && ! self::canView( $post_id );
	}

	/**
	 * Hand-written excerpts are hidden too.
	 *
	 * @param string        $excerpt Excerpt.
	 * @param \WP_Post|null $post    Post.
	 */
	public function gateExcerpt( string $excerpt, $post = null ): string {
		$post_id = $post instanceof \WP_Post ? (int) $post->ID : (int) get_the_ID();
		return self::isGated( $post_id ) ? __( 'This content is for members.', 'favr-members' ) : $excerpt;
	}

	/**
	 * RSS/Atom never carries members-only content.
	 *
	 * @param string $content Content.
	 */
	public function gateFeed( string $content ): string {
		return self::isGated( (int) get_the_ID() ) ? __( 'This content is for members.', 'favr-members' ) : $content;
	}

	/**
	 * REST responses for members-only posts omit content and excerpt for non-members.
	 *
	 * @param \WP_REST_Response $response Response.
	 * @param \WP_Post          $post     Post.
	 */
	public function gateRest( \WP_REST_Response $response, \WP_Post $post ): \WP_REST_Response {
		if ( ! self::isGated( (int) $post->ID ) ) {
			return $response;
		}
		$data = $response->get_data();
		foreach ( array( 'content', 'excerpt' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$data[ $key ]['rendered'] = '';
				if ( isset( $data[ $key ]['raw'] ) ) {
					$data[ $key ]['raw'] = '';
				}
				$data[ $key ]['protected'] = true;
			}
		}
		$response->set_data( $data );
		return $response;
	}

	/**
	 * Keep gated pages out of search results.
	 *
	 * @param array<string, bool|string> $robots Robots.
	 * @return array<string, bool|string>
	 */
	public function robots( array $robots ): array {
		if ( is_singular() && get_post_meta( (int) get_queried_object_id(), ID::PAGE_META_GATED, true ) ) {
			$robots['noindex'] = true;
		}
		return $robots;
	}

	/**
	 * The message shown instead of restricted content.
	 *
	 * @param string $message Custom message.
	 */
	public static function gate( string $message ): string {
		Assets::enqueue();
		return View::render( 'gate', array( 'message' => $message ) );
	}
}

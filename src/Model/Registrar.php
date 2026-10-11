<?php
/**
 * Registers the member post type, the shared level taxonomy and meta.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Model;

use FavrMembers\Schema\Identifiers as ID;
use FavrMembers\Vendor\FavrCore\Fields\Sanitizer;

/**
 * Member records are private staff data: no public URLs, no front-end queries and no REST
 * endpoint. Staff manage them in wp-admin; members see their own through the dashboard.
 */
final class Registrar {

	/** Hook. */
	public function hook(): void {
		add_action( 'init', array( $this, 'registerPostType' ), 6 );
		add_action( 'init', array( $this, 'registerLevels' ), 7 );
		add_action( 'init', array( $this, 'registerMeta' ), 8 );
	}

	/**
	 * The words for members, in sentence case ("All members / Add member / Edit member").
	 *
	 * @return array<string, string>
	 */
	public static function memberLabels(): array {
		return array(
			'name'                  => __( 'Members', 'favr-members' ),
			'singular_name'         => __( 'Member', 'favr-members' ),
			'menu_name'             => __( 'Members', 'favr-members' ),
			'all_items'             => __( 'All members', 'favr-members' ),
			'add_new'               => __( 'Add member', 'favr-members' ),
			'add_new_item'          => __( 'Add member', 'favr-members' ),
			'edit_item'             => __( 'Edit member', 'favr-members' ),
			'new_item'              => __( 'New member', 'favr-members' ),
			'view_item'             => __( 'View member', 'favr-members' ),
			'view_items'            => __( 'View members', 'favr-members' ),
			'search_items'          => __( 'Search members', 'favr-members' ),
			'not_found'             => __( 'No members found.', 'favr-members' ),
			'not_found_in_trash'    => __( 'No members found in Trash.', 'favr-members' ),
			// Headings a screen reader announces on the list; WordPress would say "Posts list".
			'filter_items_list'     => __( 'Filter members list', 'favr-members' ),
			'items_list_navigation' => __( 'Members list navigation', 'favr-members' ),
			'items_list'            => __( 'Members list', 'favr-members' ),
			'item_updated'          => __( 'Member updated.', 'favr-members' ),
		);
	}

	/**
	 * The words for membership levels: label for label the ones Favr Directory registers for
	 * this shared taxonomy (its Registrar::levelLabels()), so the screens read alike whichever
	 * plugin registers it. All of them are spelled out: levels are hierarchical, and what is left
	 * out WordPress fills in with its category wording ("Search Categories", "Parent Category",
	 * "Most Used", "Category Link"). tests/Unit/AdminWordsTest.php holds the list; Favr
	 * Directory's tests hold the same one.
	 *
	 * @return array<string, string>
	 */
	public static function levelLabels(): array {
		return array(
			'name'                  => __( 'Membership levels', 'favr-members' ),
			'singular_name'         => __( 'Membership level', 'favr-members' ),
			'menu_name'             => __( 'Membership levels', 'favr-members' ),
			'search_items'          => __( 'Search levels', 'favr-members' ),
			'all_items'             => __( 'All levels', 'favr-members' ),
			'parent_item'           => __( 'Parent level', 'favr-members' ),
			'parent_item_colon'     => __( 'Parent level:', 'favr-members' ),
			'edit_item'             => __( 'Edit level', 'favr-members' ),
			'view_item'             => __( 'View level', 'favr-members' ),
			'update_item'           => __( 'Update level', 'favr-members' ),
			'add_new_item'          => __( 'Add level', 'favr-members' ),
			'new_item_name'         => __( 'New level name', 'favr-members' ),
			'not_found'             => __( 'No levels found.', 'favr-members' ),
			'no_terms'              => __( 'No levels', 'favr-members' ),
			'filter_by_item'        => __( 'Filter by level', 'favr-members' ),
			'items_list_navigation' => __( 'Levels list navigation', 'favr-members' ),
			'items_list'            => __( 'Levels list', 'favr-members' ),
			'most_used'             => __( 'Most used', 'favr-members' ),
			'back_to_items'         => __( '← All levels', 'favr-members' ),
			'item_link'             => __( 'Level link', 'favr-members' ),
			'item_link_description' => __( 'A link to a level.', 'favr-members' ),
		);
	}

	/** The member post type. */
	public function registerPostType(): void {
		register_post_type(
			ID::POST_TYPE,
			array(
				'labels'              => self::memberLabels(),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				// Private staff data: never exposed through the REST API. WordPress serves any
				// "publish" post of a REST-enabled type to anonymous readers, so REST stays off.
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'menu_icon'           => 'dashicons-groups',
				'menu_position'       => 26,
				'supports'            => array( 'custom-fields', 'revisions' ),
				'capability_type'     => array( ID::CAP_TYPE, ID::CAP_PLURAL ),
				'map_meta_cap'        => true,
				'rewrite'             => false,
				'query_var'           => false,
			)
		);
	}

	/**
	 * Membership levels are shared with Favr Directory. Whichever plugin loads first registers
	 * the taxonomy; the other attaches its post type to it.
	 */
	public function registerLevels(): void {
		if ( taxonomy_exists( ID::TAX_LEVEL ) ) {
			register_taxonomy_for_object_type( ID::TAX_LEVEL, ID::POST_TYPE );
			return;
		}
		register_taxonomy(
			ID::TAX_LEVEL,
			array( ID::POST_TYPE ),
			array(
				'labels'             => self::levelLabels(),
				'hierarchical'       => true,
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_admin_column'  => false,
				'show_in_rest'       => true,
				'rest_base'          => 'membership-levels',
				'rewrite'            => false,
				'meta_box_cb'        => false,
				'capabilities'       => array(
					'manage_terms' => ID::CAP_SETTINGS,
					'edit_terms'   => ID::CAP_SETTINGS,
					'delete_terms' => ID::CAP_SETTINGS,
					'assign_terms' => 'edit_' . ID::CAP_PLURAL,
				),
			)
		);
	}

	/** Register every field as typed post meta (REST: editors only; private fields never). */
	public function registerMeta(): void {
		foreach ( Fields::set()->all() as $field ) {
			register_post_meta(
				ID::POST_TYPE,
				ID::meta( (string) $field['id'] ),
				array(
					'type'              => 'number' === $field['type'] ? 'integer' : 'string',
					'single'            => true,
					'default'           => 'number' === $field['type'] ? 0 : (string) $field['default'],
					'show_in_rest'      => false,
					'sanitize_callback' => static fn( $value ) => Sanitizer::sanitize( $field, $value ),
					'auth_callback'     => static fn( $allowed, $meta_key, $post_id ): bool => current_user_can( 'edit_post', (int) $post_id ),
				)
			);
		}
	}
}

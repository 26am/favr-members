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

	/** The member post type. */
	public function registerPostType(): void {
		register_post_type(
			ID::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Members', 'favr-members' ),
					'singular_name'      => __( 'Member', 'favr-members' ),
					'menu_name'          => __( 'Members', 'favr-members' ),
					'all_items'          => __( 'All Members', 'favr-members' ),
					'add_new'            => __( 'Add Member', 'favr-members' ),
					'add_new_item'       => __( 'Add New Member', 'favr-members' ),
					'edit_item'          => __( 'Edit Member', 'favr-members' ),
					'new_item'           => __( 'New Member', 'favr-members' ),
					'search_items'       => __( 'Search Members', 'favr-members' ),
					'not_found'          => __( 'No members found.', 'favr-members' ),
					'not_found_in_trash' => __( 'No members found in Trash.', 'favr-members' ),
					'item_updated'       => __( 'Member updated.', 'favr-members' ),
				),
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
				'labels'             => array(
					'name'          => __( 'Membership Levels', 'favr-members' ),
					'singular_name' => __( 'Membership Level', 'favr-members' ),
					'menu_name'     => __( 'Membership Levels', 'favr-members' ),
					'all_items'     => __( 'All Levels', 'favr-members' ),
					'edit_item'     => __( 'Edit Level', 'favr-members' ),
					'update_item'   => __( 'Update Level', 'favr-members' ),
					'add_new_item'  => __( 'Add New Level', 'favr-members' ),
					'new_item_name' => __( 'New Level Name', 'favr-members' ),
					'not_found'     => __( 'No levels found.', 'favr-members' ),
				),
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

<?php
/**
 * Level order and color fields (only when Favr Directory isn't active).
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Admin;

use FavrMembers\Schema\Identifiers as ID;

/**
 * Favr Directory already adds these fields to the shared level taxonomy; this is the same UI
 * for sites running Favr Members alone. Same term meta keys, so data is shared either way.
 */
final class LevelScreen {

	/** Hook (skipped when the Directory provides the fields). */
	public function hook(): void {
		add_action(
			'init',
			function (): void {
				if ( class_exists( '\FavrDirectory\Admin\LevelScreen' ) ) {
					return;
				}
				add_action( ID::TAX_LEVEL . '_add_form_fields', array( $this, 'fields' ) );
				add_action( ID::TAX_LEVEL . '_edit_form_fields', array( $this, 'editFields' ) );
				add_action( 'created_' . ID::TAX_LEVEL, array( $this, 'save' ) );
				add_action( 'edited_' . ID::TAX_LEVEL, array( $this, 'save' ) );
			},
			20
		);
	}

	/** Add form. */
	public function fields(): void {
		wp_nonce_field( 'favr_members_level', '_favr_members_level_nonce' );
		printf( '<div class="form-field"><label for="favr-level-order">%s</label><input type="number" id="favr-level-order" name="favr_level_order" value="10" min="0"><p>%s</p></div>', esc_html__( 'Display order', 'favr-members' ), esc_html__( 'Lower numbers are higher tiers.', 'favr-members' ) );
		printf( '<div class="form-field"><label for="favr-level-color">%s</label><input type="text" id="favr-level-color" name="favr_level_color" value="#0f766e" class="favr-color"></div>', esc_html__( 'Badge color', 'favr-members' ) );
	}

	/**
	 * Edit form.
	 *
	 * @param \WP_Term $term Term.
	 */
	public function editFields( \WP_Term $term ): void {
		wp_nonce_field( 'favr_members_level', '_favr_members_level_nonce' );
		printf( '<tr class="form-field"><th><label for="favr-level-order">%s</label></th><td><input type="number" id="favr-level-order" name="favr_level_order" value="%d" min="0"></td></tr>', esc_html__( 'Display order', 'favr-members' ), (int) get_term_meta( $term->term_id, 'favr_level_order', true ) );
		printf( '<tr class="form-field"><th><label for="favr-level-color">%s</label></th><td><input type="text" id="favr-level-color" name="favr_level_color" value="%s" class="favr-color"></td></tr>', esc_html__( 'Badge color', 'favr-members' ), esc_attr( (string) get_term_meta( $term->term_id, 'favr_level_color', true ) ) );
	}

	/**
	 * Save.
	 *
	 * @param int $term_id Term id.
	 */
	public function save( int $term_id ): void {
		if ( ! isset( $_POST['_favr_members_level_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_favr_members_level_nonce'] ) ), 'favr_members_level' ) || ! current_user_can( ID::CAP_SETTINGS ) ) {
			return;
		}
		if ( isset( $_POST['favr_level_order'] ) ) {
			update_term_meta( $term_id, 'favr_level_order', absint( wp_unslash( $_POST['favr_level_order'] ) ) );
		}
		if ( isset( $_POST['favr_level_color'] ) ) {
			update_term_meta( $term_id, 'favr_level_color', (string) sanitize_hex_color( sanitize_text_field( wp_unslash( $_POST['favr_level_color'] ) ) ) );
		}
	}
}

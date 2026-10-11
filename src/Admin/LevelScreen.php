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
 * The list is in the levels' own order, with the colour in front of each name.
 */
final class LevelScreen {

	/**
	 * Hook. The fields, the Order column and the colour are skipped when the Directory provides
	 * them; the order of the list is not, since sorting what is already sorted changes nothing.
	 */
	public function hook(): void {
		add_filter( 'get_terms', array( $this, 'order' ), 10, 3 );
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
				add_filter( 'manage_edit-' . ID::TAX_LEVEL . '_columns', array( $this, 'columns' ) );
				add_filter( 'manage_' . ID::TAX_LEVEL . '_custom_column', array( $this, 'column' ), 10, 3 );
				// After WordPress's own escaping of the name (priority 30), or the dot is printed as text.
				add_filter( 'term_name', array( $this, 'name' ), 40, 2 );
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
	 * The list's columns: Order after the name, no Description.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			if ( 'description' === $key ) {
				continue;
			}
			$out[ $key ] = $label;
			if ( 'name' === $key ) {
				$out['favr_order'] = __( 'Order', 'favr-members' );
			}
		}
		return $out;
	}

	/**
	 * The Order cell: the number, and nothing else.
	 *
	 * @param string $content Content.
	 * @param string $column  Column.
	 * @param int    $term_id Term id.
	 */
	public function column( $content, $column, $term_id ): string {
		return 'favr_order' === $column ? (string) (int) get_term_meta( (int) $term_id, 'favr_level_order', true ) : (string) $content;
	}

	/**
	 * A level's name in the list, after its colour.
	 *
	 * @param string $name The name as WordPress prints it.
	 * @param mixed  $term The term.
	 */
	public function name( $name, $term = null ): string {
		if ( ! $term instanceof \WP_Term || ID::TAX_LEVEL !== $term->taxonomy ) {
			return (string) $name;
		}
		return self::nameWithColour( (string) $name, (string) get_term_meta( $term->term_id, 'favr_level_color', true ) );
	}

	/**
	 * The level's colour as a dot, then its name. No dot without a (hex) colour.
	 *
	 * @param string $name  The name, already safe to print.
	 * @param string $color The level's colour.
	 */
	public static function nameWithColour( string $name, string $color ): string {
		$hex = (string) sanitize_hex_color( $color );
		return '' !== $hex ? sprintf( '<span class="favr-level__swatch" style="background:%s"></span> %s', esc_attr( $hex ), $name ) : $name;
	}

	/**
	 * The Membership levels list in the order its Order column shows, unless a column heading
	 * was clicked. WordPress fetches every level for this list (levels are hierarchical), and
	 * lays them out in the order it gets them, so sorting what it fetched sorts the screen.
	 *
	 * @param mixed        $terms      What get_terms() found.
	 * @param mixed        $taxonomies The taxonomies asked for.
	 * @param array<mixed> $args       The query.
	 * @return mixed
	 */
	public function order( $terms, $taxonomies = array(), $args = array() ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reads whether the list was sorted by a column.
		if ( ! $screen || 'edit-' . ID::TAX_LEVEL !== $screen->id || ! empty( $_GET['orderby'] ) || array( ID::TAX_LEVEL ) !== array_values( (array) $taxonomies ) || 'all' !== ( $args['fields'] ?? 'all' ) || ! is_array( $terms ) ) {
			return $terms;
		}
		$orders = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				return $terms;
			}
			$orders[ $term->term_id ] = (int) get_term_meta( $term->term_id, 'favr_level_order', true );
		}
		return self::byOrder( $terms, $orders );
	}

	/**
	 * Levels by their order value (none counts as 0, which is what the column shows), then by
	 * name.
	 *
	 * @param list<object>    $terms  Levels (term_id, name).
	 * @param array<int, int> $orders Term id => order.
	 * @return list<object>
	 */
	public static function byOrder( array $terms, array $orders ): array {
		usort(
			$terms,
			static fn( object $a, object $b ): int => array( $orders[ $a->term_id ] ?? 0, strtolower( (string) $a->name ) ) <=> array( $orders[ $b->term_id ] ?? 0, strtolower( (string) $b->name ) )
		);
		return $terms;
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

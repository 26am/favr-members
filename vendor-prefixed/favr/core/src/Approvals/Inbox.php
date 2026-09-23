<?php
/**
 * One "Approvals" screen shared by every Favr plugin.
 *
 * @package FavrCore
 */

declare(strict_types=1);

namespace FavrMembers\Vendor\FavrCore\Approvals;

/**
 * Plugins contribute queues with the `favr_approvals_providers` filter (a plain WordPress
 * hook, so it works across each plugin's namespace-prefixed copy of this library). The first
 * plugin to boot registers the screen; later copies do nothing.
 *
 * Provider shape (array):
 *  - id          string   Unique id.
 *  - label       string   Queue heading, e.g. "Listing updates".
 *  - capability  string   Who may review.
 *  - items       callable(): list<array{id: int, title: string, subtitle?: string, edit_url?: string, time?: int, details: string, fields?: array<string,string>}>
 *                         `details` is escaped HTML (e.g. from Inbox::diff()); `fields` (key => label)
 *                         enables approving individual fields.
 *  - decide      callable( int $id, string $decision approve|reject, ?list<string> $fields, string $note, string $version ): string
 *                         $fields is null when the item had no field checkboxes (act on everything),
 *                         otherwise the ticked fields (possibly none). $version echoes the item's
 *                         `version` (see below) so a provider can refuse to act on something that
 *                         changed after the reviewer loaded the page. Returns a human result message.
 *  - count       optional callable(): int, a cheap count for the menu badge (else items() is counted).
 * Items may carry `version` (string): a fingerprint of what the reviewer is looking at.
 */
final class Inbox {

	public const PAGE = 'favr-approvals';

	/** Register the screen once per request, whichever plugin gets here first. */
	public static function boot(): void {
		if ( ! empty( $GLOBALS['favr_approvals_inbox_booted'] ) ) {
			return;
		}
		$GLOBALS['favr_approvals_inbox_booted'] = true;
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_post_favr_approval', array( self::class, 'handle' ) );
	}

	/**
	 * Providers the current user may review.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function providers(): array {
		$out = array();
		foreach ( (array) apply_filters( 'favr_approvals_providers', array() ) as $provider ) {
			if ( is_array( $provider ) && isset( $provider['id'], $provider['items'], $provider['decide'] ) && current_user_can( (string) ( $provider['capability'] ?? 'manage_options' ) ) ) {
				$out[] = $provider;
			}
		}
		return $out;
	}

	/** Total waiting across queues. */
	public static function count(): int {
		$total = 0;
		foreach ( self::providers() as $provider ) {
			$total += isset( $provider['count'] ) && is_callable( $provider['count'] ) ? (int) call_user_func( $provider['count'] ) : count( (array) call_user_func( $provider['items'] ) );
		}
		return $total;
	}

	/** Top-level menu with a count bubble. */
	public static function menu(): void {
		if ( ! self::providers() ) {
			return;
		}
		$count  = self::count();
		$bubble = $count ? sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>', $count ) : '';
		add_menu_page(
			__( 'Approvals', 'favr-core' ),
			__( 'Approvals', 'favr-core' ) . $bubble,
			'read',
			self::PAGE,
			array( self::class, 'render' ),
			'dashicons-yes-alt',
			24
		);
	}

	/** The screen. */
	public static function render(): void {
		$providers = self::providers();
		$message   = (string) get_transient( 'favr_approvals_result_' . get_current_user_id() );
		delete_transient( 'favr_approvals_result_' . get_current_user_id() );
		echo '<style>.favr-approvals__item{background:#fff;border:1px solid #c3c4c7;padding:12px 16px;margin:0 0 12px;max-width:1100px}.favr-approvals__head{display:flex;gap:10px;align-items:baseline;flex-wrap:wrap;margin-bottom:8px}.favr-approvals__sub,.favr-approvals__time{color:#646970}.favr-diff td,.favr-diff th{vertical-align:top}.favr-diff__old{color:#8a2424}.favr-diff__new{color:#00632b}.favr-diff img{max-width:80px;height:auto;margin:0 4px 4px 0}.favr-approvals__fields label{margin-right:12px;white-space:nowrap}.favr-approvals__done{font-size:15px}</style>';
		echo '<div class="wrap favr-approvals"><h1>' . esc_html__( 'Approvals', 'favr-core' ) . '</h1>';
		if ( '' !== $message ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
		}
		$any = false;
		foreach ( $providers as $provider ) {
			$items = (array) call_user_func( $provider['items'] );
			printf( '<h2 class="favr-approvals__queue">%1$s <span class="count">(%2$d)</span></h2>', esc_html( (string) $provider['label'] ), count( $items ) );
			if ( ! $items ) {
				echo '<p class="favr-approvals__empty">' . esc_html__( 'Nothing waiting.', 'favr-core' ) . '</p>';
				continue;
			}
			$any = true;
			foreach ( $items as $item ) {
				self::renderItem( $provider, $item );
			}
		}
		if ( ! $providers ) {
			echo '<p>' . esc_html__( 'You don’t have any approval queues.', 'favr-core' ) . '</p>';
		} elseif ( ! $any ) {
			echo '<p class="favr-approvals__done">✓ ' . esc_html__( 'All caught up.', 'favr-core' ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * One item card with approve/reject.
	 *
	 * @param array<string, mixed> $provider Provider.
	 * @param array<string, mixed> $item     Item.
	 */
	private static function renderItem( array $provider, array $item ): void {
		$fields = (array) ( $item['fields'] ?? array() );
		echo '<form class="favr-approvals__item" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		printf(
			'<div class="favr-approvals__head"><strong>%1$s</strong>%2$s%3$s%4$s</div>',
			esc_html( (string) $item['title'] ),
			! empty( $item['subtitle'] ) ? ' <span class="favr-approvals__sub">' . esc_html( (string) $item['subtitle'] ) . '</span>' : '',
			! empty( $item['time'] ) ? ' <span class="favr-approvals__time">' . esc_html( sprintf( /* translators: %s: time ago. */ __( '%s ago', 'favr-core' ), human_time_diff( (int) $item['time'] ) ) ) . '</span>' : '',
			! empty( $item['edit_url'] ) ? ' <a href="' . esc_url( (string) $item['edit_url'] ) . '">' . esc_html__( 'Open', 'favr-core' ) . '</a>' : ''
		);
		echo '<div class="favr-approvals__details">' . wp_kses_post( (string) $item['details'] ) . '</div>';
		if ( count( $fields ) > 1 ) {
			echo '<fieldset class="favr-approvals__fields"><input type="hidden" name="fields_shown" value="1"><legend>' . esc_html__( 'Approve:', 'favr-core' ) . '</legend>';
			foreach ( $fields as $key => $label ) {
				printf( '<label><input type="checkbox" name="fields[]" value="%1$s" checked> %2$s</label> ', esc_attr( (string) $key ), esc_html( (string) $label ) );
			}
			echo '</fieldset>';
		}
		printf(
			'<p class="favr-approvals__actions"><input type="text" name="note" class="regular-text" placeholder="%1$s"> <button class="button button-primary" name="decision" value="approve">%2$s</button> <button class="button" name="decision" value="reject">%3$s</button></p>',
			esc_attr__( 'Optional note to the person (sent with a rejection)', 'favr-core' ),
			esc_html( count( $fields ) > 1 ? __( 'Approve selected', 'favr-core' ) : __( 'Approve', 'favr-core' ) ),
			esc_html__( 'Reject', 'favr-core' )
		);
		printf(
			'<input type="hidden" name="action" value="favr_approval"><input type="hidden" name="provider" value="%1$s"><input type="hidden" name="item" value="%2$d"><input type="hidden" name="version" value="%3$s">',
			esc_attr( (string) $provider['id'] ),
			(int) $item['id'],
			esc_attr( (string) ( $item['version'] ?? '' ) )
		);
		wp_nonce_field( 'favr_approval_' . $provider['id'] . '_' . (int) $item['id'] );
		echo '</form>';
	}

	/** Handle a decision. */
	public static function handle(): void {
		$provider_id = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$item        = isset( $_POST['item'] ) ? absint( $_POST['item'] ) : 0;
		check_admin_referer( 'favr_approval_' . $provider_id . '_' . $item );
		$provider = null;
		foreach ( self::providers() as $candidate ) {
			if ( $candidate['id'] === $provider_id ) {
				$provider = $candidate;
			}
		}
		if ( ! $provider ) {
			wp_die( esc_html__( 'You are not allowed to review this.', 'favr-core' ), 403 );
		}
		$decision = isset( $_POST['decision'] ) && 'reject' === $_POST['decision'] ? 'reject' : 'approve';
		$fields   = isset( $_POST['fields_shown'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['fields'] ?? array() ) ) : null;
		$note     = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) );
		$version  = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';
		$result   = (string) call_user_func( $provider['decide'], $item, $decision, $fields, $note, $version );
		set_transient( 'favr_approvals_result_' . get_current_user_id(), $result, 120 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/**
	 * Before/after table (values must already be escaped HTML).
	 *
	 * @param list<array{label: string, old: string, new: string}> $rows Rows.
	 */
	public static function diff( array $rows ): string {
		$html = '<table class="favr-diff widefat striped"><thead><tr><th>' . esc_html__( 'Field', 'favr-core' ) . '</th><th>' . esc_html__( 'Now', 'favr-core' ) . '</th><th>' . esc_html__( 'Proposed', 'favr-core' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$html .= '<tr><th scope="row">' . esc_html( $row['label'] ) . '</th><td class="favr-diff__old">' . ( '' !== $row['old'] ? $row['old'] : '<em>—</em>' ) . '</td><td class="favr-diff__new">' . ( '' !== $row['new'] ? $row['new'] : '<em>' . esc_html__( '(removed)', 'favr-core' ) . '</em>' ) . '</td></tr>';
		}
		return $html . '</tbody></table>';
	}
}

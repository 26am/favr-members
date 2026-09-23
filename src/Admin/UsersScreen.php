<?php
/**
 * Users screen integration.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Admin;

use FavrMembers\Model\Repository;

/**
 * Adds a "Memberships" column to Users so staff see who holds or represents what, and records
 * each person's last login.
 */
final class UsersScreen {

	/** Hook. */
	public function hook(): void {
		add_filter( 'manage_users_columns', array( $this, 'columns' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'column' ), 10, 3 );
	}

	/**
	 * Columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		$columns['favr_memberships'] = __( 'Memberships', 'favr-members' );
		$columns['favr_last_login']  = __( 'Last login', 'favr-members' );
		return $columns;
	}

	/**
	 * Column content.
	 *
	 * @param string $output  Output.
	 * @param string $column  Column.
	 * @param int    $user_id User id.
	 */
	public function column( string $output, string $column, int $user_id ): string {
		if ( 'favr_memberships' === $column ) {
			$links = array();
			foreach ( Repository::forUser( $user_id ) as $member ) {
				$links[] = sprintf( '<a href="%s">%s</a>', esc_url( (string) get_edit_post_link( $member->id() ) ), esc_html( $member->name() ) );
			}
			return $links ? implode( ', ', $links ) : '—';
		}
		if ( 'favr_last_login' === $column ) {
			$last = (int) get_user_meta( $user_id, 'favr_members_last_login', true );
			/* translators: %s: human time diff. */
			return $last ? esc_html( sprintf( __( '%s ago', 'favr-members' ), human_time_diff( $last ) ) ) : '—';
		}
		return $output;
	}
}

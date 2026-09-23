<?php
/**
 * Uninstall. Member records are deleted only when the site opted in; logins (users) are
 * never deleted, since they may be used elsewhere on the site.
 *
 * @package FavrMembers
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$favr_settings = get_option( 'favr_members_settings', array() );

wp_clear_scheduled_hook( 'favr_members_daily' );

if ( is_array( $favr_settings ) && '1' === (string) ( $favr_settings['delete_data'] ?? '0' ) ) {
	$favr_ids = get_posts(
		array(
			'post_type'      => 'favr_member',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		)
	);
	foreach ( $favr_ids as $favr_id ) {
		wp_delete_post( (int) $favr_id, true );
	}
	foreach ( array( 'login_page', 'account_page', 'register_page' ) as $favr_key ) {
		if ( ! empty( $favr_settings[ $favr_key ] ) ) {
			wp_delete_post( (int) $favr_settings[ $favr_key ], true );
		}
	}
	delete_option( 'favr_members_settings' );
	delete_option( 'favr_members_version' );

	$favr_plural = 'favr_members';
	$favr_caps   = array( "edit_{$favr_plural}", "edit_others_{$favr_plural}", "edit_private_{$favr_plural}", "edit_published_{$favr_plural}", "publish_{$favr_plural}", "read_private_{$favr_plural}", "delete_{$favr_plural}", "delete_others_{$favr_plural}", "delete_private_{$favr_plural}", "delete_published_{$favr_plural}", 'manage_favr_members' );
	foreach ( wp_roles()->role_objects as $favr_role ) {
		foreach ( $favr_caps as $favr_cap ) {
			$favr_role->remove_cap( $favr_cap );
		}
	}
	remove_role( 'favr_membership_manager' );
	// The Member role is kept when people still have it, so their accounts keep working.
	if ( ! get_users(
		array(
			'role'   => 'favr_member_person',
			'number' => 1,
			'fields' => 'ID',
		)
	) ) {
		remove_role( 'favr_member_person' );
	}
}

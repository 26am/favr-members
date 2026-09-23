<?php
/**
 * The "All Members" list table.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Admin;

use FavrMembers\Model\Member;
use FavrMembers\Model\Repository;
use FavrMembers\Schema\Identifiers as ID;

/**
 * Scannable columns, status views with counts, type/level filters and renewal sorting.
 */
final class ListScreen {

	/** Hook. */
	public function hook(): void {
		add_filter( 'manage_' . ID::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . ID::POST_TYPE . '_posts_custom_column', array( $this, 'column' ), 10, 2 );
		add_filter( 'manage_edit-' . ID::POST_TYPE . '_sortable_columns', array( $this, 'sortable' ) );
		add_filter( 'views_edit-' . ID::POST_TYPE, array( $this, 'views' ) );
		add_action( 'restrict_manage_posts', array( $this, 'filters' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'query' ) );
		add_filter( 'post_row_actions', array( $this, 'rowActions' ), 10, 2 );
		add_action( 'admin_menu', array( $this, 'menuBadge' ), 99 );
	}

	/**
	 * Columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function columns( array $columns ): array {
		return array(
			'cb'           => $columns['cb'] ?? '',
			'title'        => __( 'Member', 'favr-members' ),
			'favr_type'    => __( 'Type', 'favr-members' ),
			'favr_level'   => __( 'Level', 'favr-members' ),
			'favr_status'  => __( 'Status', 'favr-members' ),
			'favr_renewal' => __( 'Renewal', 'favr-members' ),
			'favr_logins'  => __( 'Logins', 'favr-members' ),
			'favr_contact' => __( 'Contact', 'favr-members' ),
		);
	}

	/**
	 * Column content.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post id.
	 */
	public function column( string $column, int $post_id ): void {
		$member = Member::find( $post_id );
		if ( ! $member ) {
			return;
		}
		switch ( $column ) {
			case 'favr_type':
				printf(
					'<span class="favr-type-badge favr-type-badge--%1$s"><span class="dashicons %2$s" aria-hidden="true"></span> %3$s</span>',
					esc_attr( $member->type() ),
					$member->isBusiness() ? 'dashicons-store' : 'dashicons-admin-users',
					esc_html( $member->isBusiness() ? __( 'Business', 'favr-members' ) : __( 'Individual', 'favr-members' ) )
				);
				if ( $member->listingId() ) {
					printf( '<br><a href="%s" class="favr-listing-link">%s</a>', esc_url( (string) get_edit_post_link( $member->listingId() ) ), esc_html__( 'Directory listing', 'favr-members' ) );
				}
				break;
			case 'favr_level':
				$level = $member->level();
				echo esc_html( $level ? $level->name : '—' );
				break;
			case 'favr_status':
				printf( '<span class="favr-status favr-status--%1$s">%2$s</span>', esc_attr( $member->status() ), esc_html( ID::statuses()[ $member->status() ] ) );
				break;
			case 'favr_renewal':
				$date = $member->text( 'renewal_date' );
				if ( '' === $date ) {
					echo '—';
					break;
				}
				$today = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d' );
				printf(
					'<span class="%1$s">%2$s</span>',
					esc_attr( $date < $today ? 'favr-overdue' : '' ),
					esc_html( date_i18n( (string) get_option( 'date_format' ), (int) strtotime( $date ) ) )
				);
				break;
			case 'favr_logins':
				$names = array();
				foreach ( $member->userIds() as $user_id ) {
					$user = get_userdata( $user_id );
					if ( $user ) {
						$names[] = sprintf( '<a href="%s">%s</a>', esc_url( get_edit_user_link( $user_id ) ), esc_html( $user->display_name ) );
					}
				}
				echo $names ? implode( ', ', $names ) : '<span class="favr-muted">' . esc_html__( 'None', 'favr-members' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				break;
			case 'favr_contact':
				$email = $member->text( 'email' );
				$phone = $member->text( 'phone' );
				if ( '' !== $email ) {
					printf( '<a href="mailto:%1$s">%2$s</a><br>', esc_attr( $email ), esc_html( $email ) );
				}
				echo esc_html( $phone );
				break;
		}
	}

	/**
	 * Sortable columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public function sortable( array $columns ): array {
		$columns['favr_renewal'] = 'favr_renewal';
		$columns['favr_status']  = 'favr_status';
		return $columns;
	}

	/**
	 * Status views with counts.
	 *
	 * @param array<string, string> $views Views.
	 * @return array<string, string>
	 */
	public function views( array $views ): array {
		$counts = Repository::statusCounts();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter.
		$current = isset( $_GET['favr_status'] ) ? sanitize_key( wp_unslash( $_GET['favr_status'] ) ) : '';
		foreach ( ID::statuses() as $key => $label ) {
			if ( 0 === $counts[ $key ] ) {
				continue;
			}
			$views[ 'favr_' . $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>',
				esc_url( admin_url( 'edit.php?post_type=' . ID::POST_TYPE . '&favr_status=' . $key ) ),
				$current === $key ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				(int) $counts[ $key ]
			);
		}
		return $views;
	}

	/**
	 * Type and level filters.
	 *
	 * @param string $post_type Post type.
	 * @param string $which     top|bottom.
	 */
	public function filters( string $post_type, string $which = 'top' ): void {
		if ( ID::POST_TYPE !== $post_type || 'top' !== $which ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$type  = isset( $_GET['favr_type'] ) ? sanitize_key( wp_unslash( $_GET['favr_type'] ) ) : '';
		$level = isset( $_GET['favr_level'] ) ? absint( $_GET['favr_level'] ) : 0;
		// phpcs:enable
		printf(
			'<select name="favr_type"><option value="">%1$s</option><option value="individual"%2$s>%3$s</option><option value="business"%4$s>%5$s</option></select>',
			esc_html__( 'All types', 'favr-members' ),
			selected( $type, 'individual', false ),
			esc_html__( 'Individuals', 'favr-members' ),
			selected( $type, 'business', false ),
			esc_html__( 'Businesses', 'favr-members' )
		);
		wp_dropdown_categories(
			array(
				'taxonomy'        => ID::TAX_LEVEL,
				'name'            => 'favr_level',
				'show_option_all' => __( 'All levels', 'favr-members' ),
				'selected'        => $level,
				'hide_empty'      => false,
			)
		);
	}

	/**
	 * Apply filters and sorting.
	 *
	 * @param \WP_Query $query Query.
	 */
	public function query( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || ID::POST_TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$meta = array();
		if ( ! empty( $_GET['favr_status'] ) ) {
			$meta[] = array(
				'key'   => ID::meta( 'status' ),
				'value' => sanitize_key( wp_unslash( $_GET['favr_status'] ) ),
			);
		}
		if ( ! empty( $_GET['favr_type'] ) ) {
			$type = sanitize_key( wp_unslash( $_GET['favr_type'] ) );
			// Records saved before a type was chosen count as individuals.
			$meta[] = ID::TYPE_INDIVIDUAL === $type
				? array(
					'relation' => 'OR',
					array(
						'key'   => ID::meta( 'type' ),
						'value' => ID::TYPE_INDIVIDUAL,
					),
					array(
						'key'     => ID::meta( 'type' ),
						'compare' => 'NOT EXISTS',
					),
				)
				: array(
					'key'   => ID::meta( 'type' ),
					'value' => $type,
				);
		}
		if ( ! empty( $_GET['favr_level'] ) ) {
			$query->set(
				'tax_query',
				array(
					array(
						'taxonomy' => ID::TAX_LEVEL,
						'terms'    => array( absint( $_GET['favr_level'] ) ),
					),
				)
			);
		}
		// phpcs:enable
		$orderby = (string) $query->get( 'orderby' );
		if ( in_array( $orderby, array( 'favr_renewal', 'favr_status' ), true ) ) {
			$key    = 'favr_renewal' === $orderby ? 'renewal_date' : 'status';
			$meta[] = array(
				'relation'  => 'OR',
				'favr_sort' => array(
					'key'     => ID::meta( $key ),
					'compare' => 'EXISTS',
				),
				array(
					'key'     => ID::meta( $key ),
					'compare' => 'NOT EXISTS',
				),
			);
			$query->set( 'orderby', array( 'favr_sort' => $query->get( 'order' ) ?: 'ASC' ) );
		}
		if ( $meta ) {
			$query->set( 'meta_query', array_merge( array( 'relation' => 'AND' ), $meta ) );
		}
	}

	/**
	 * Row actions: no quick edit (it bypasses the type-aware form).
	 *
	 * @param array<string, string> $actions Actions.
	 * @param \WP_Post              $post    Post.
	 * @return array<string, string>
	 */
	public function rowActions( array $actions, \WP_Post $post ): array {
		if ( ID::POST_TYPE === $post->post_type ) {
			unset( $actions['inline hide-if-no-js'], $actions['view'] );
		}
		return $actions;
	}

	/** Pending-members count bubble on the admin menu. */
	public function menuBadge(): void {
		global $menu;
		$pending = Repository::statusCounts()[ ID::STATUS_PENDING ] ?? 0;
		if ( ! $pending || ! is_array( $menu ) ) {
			return;
		}
		foreach ( $menu as $index => $item ) {
			if ( 'edit.php?post_type=' . ID::POST_TYPE === ( $item[2] ?? '' ) ) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- standard way to add a count bubble.
				$menu[ $index ][0] .= sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count">%1$d</span></span>', (int) $pending );
			}
		}
	}
}

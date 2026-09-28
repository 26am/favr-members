<?php
/**
 * Favr dashboard card and quick action (the Favr Sites plugin).
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Integration;

use FavrMembers\Model\Member;
use FavrMembers\Model\Repository;
use FavrMembers\Schema\Identifiers as ID;

/**
 * Contributes plain data to the Favr dashboard; inert when Favr Sites isn't installed.
 */
final class FavrSites {

	/** Hooks. */
	public function hook(): void {
		add_filter( 'favr_sites_quick_actions', array( $this, 'actions' ) );
		add_filter( 'favr_sites_dashboard_cards', array( $this, 'cards' ) );
	}

	/**
	 * Quick action.
	 *
	 * @param array<mixed> $actions Actions.
	 * @return array<mixed>
	 */
	public function actions( array $actions ): array {
		$actions[] = array(
			'id'         => 'add-member',
			'label'      => __( 'Add member', 'favr-members' ),
			'url'        => admin_url( 'post-new.php?post_type=' . ID::POST_TYPE ),
			'capability' => 'edit_' . ID::CAP_PLURAL,
			'icon'       => 'users',
			'priority'   => 50,
		);
		return $actions;
	}

	/**
	 * Card, built only when the dashboard renders.
	 *
	 * @param array<mixed> $cards Cards.
	 * @return array<mixed>
	 */
	public function cards( array $cards ): array {
		$cards[] = array( $this, 'card' );
		return $cards;
	}

	/**
	 * The members card: how many are active and whose renewal is coming up.
	 *
	 * @return array<string, mixed>
	 */
	public function card(): array {
		$active   = (int) ( Repository::statusCounts()[ ID::STATUS_ACTIVE ] ?? 0 );
		$renewing = get_posts(
			array(
				'post_type'      => ID::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => ID::meta( 'renewal_date' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => ID::meta( 'renewal_date' ),
						'value'   => array( wp_date( 'Y-m-d' ), wp_date( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ) ),
						'compare' => 'BETWEEN',
						'type'    => 'DATE',
					),
					array(
						'key'   => ID::meta( 'status' ),
						'value' => ID::STATUS_ACTIVE,
					),
				),
			)
		);
		$list     = admin_url( 'edit.php?post_type=' . ID::POST_TYPE );
		$stats    = array();
		if ( $active ) {
			$stats[] = array(
				'label' => __( 'active', 'favr-members' ),
				'value' => number_format_i18n( $active ),
				'url'   => $list,
			);
		}
		if ( $renewing ) {
			$stats[] = array(
				'label' => __( 'renewing in 30 days', 'favr-members' ),
				'value' => number_format_i18n( count( $renewing ) ),
			);
		}
		$items = array();
		foreach ( array_slice( $renewing, 0, 3 ) as $id ) {
			$member = Member::find( (int) $id );
			if ( $member ) {
				$items[] = array(
					'title' => $member->name(),
					/* translators: %s: renewal date. */
					'meta'  => sprintf( __( 'renews %s', 'favr-members' ), wp_date( 'M j', (int) strtotime( $member->text( 'renewal_date' ) ) ) ),
					'url'   => (string) get_edit_post_link( (int) $id, 'raw' ),
				);
			}
		}
		return array(
			'id'         => 'members',
			'title'      => __( 'Members', 'favr-members' ),
			'capability' => 'edit_' . ID::CAP_PLURAL,
			'priority'   => 40,
			'stats'      => $stats,
			'items'      => $items,
			'link'       => array(
				'label' => __( 'All members', 'favr-members' ),
				'url'   => $list,
			),
			'empty'      => array(
				'text'  => __( 'No members yet.', 'favr-members' ),
				'label' => __( 'Add a member', 'favr-members' ),
				'url'   => admin_url( 'post-new.php?post_type=' . ID::POST_TYPE ),
			),
		);
	}
}

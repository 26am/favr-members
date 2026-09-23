<?php
/**
 * The member add/edit screen.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Admin;

use FavrMembers\Integration\Directory;
use FavrMembers\Model\Accounts;
use FavrMembers\Model\Fields;
use FavrMembers\Model\Member;
use FavrMembers\Schema\Identifiers as ID;
use FavrMembers\Vendor\FavrCore\Admin\FieldRenderer;
use FavrMembers\Vendor\FavrCore\Fields\Sanitizer;

/**
 * One tabbed panel. The member type (Individual / Business) is chosen at the top and every
 * other field shows or hides to match, so staff only ever see what applies. The record's
 * title is derived from the name fields, so there is no separate title box.
 */
final class EditScreen {

	private const INPUT = 'favr_member';

	/**
	 * Field renderer.
	 *
	 * @var FieldRenderer
	 */
	private FieldRenderer $renderer;

	/** Constructor. */
	public function __construct() {
		$this->renderer = new FieldRenderer( self::INPUT );
	}

	/** Hook. */
	public function hook(): void {
		add_filter( 'use_block_editor_for_post_type', array( $this, 'classicEditor' ), 10, 2 );
		add_action( 'edit_form_after_title', array( $this, 'renderPanel' ) );
		add_action( 'add_meta_boxes_' . ID::POST_TYPE, array( $this, 'metaBoxes' ) );
		add_filter( 'wp_insert_post_data', array( $this, 'deriveTitle' ), 10, 2 );
		add_action( 'save_post_' . ID::POST_TYPE, array( $this, 'save' ), 10, 2 );
		add_filter( 'post_updated_messages', array( $this, 'messages' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
	}

	/**
	 * Members are records, not articles: always the classic form.
	 *
	 * @param bool   $use_block Current decision.
	 * @param string $post_type Post type.
	 */
	public function classicEditor( bool $use_block, string $post_type ): bool {
		return ID::POST_TYPE === $post_type ? false : $use_block;
	}

	/**
	 * Sidebar boxes.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function metaBoxes( \WP_Post $post ): void {
		remove_meta_box( 'postcustom', ID::POST_TYPE, 'normal' );
		remove_meta_box( 'slugdiv', ID::POST_TYPE, 'normal' );
		add_meta_box( 'favr-member-level', __( 'Membership level', 'favr-members' ), array( $this, 'renderLevel' ), ID::POST_TYPE, 'side' );
		add_meta_box( 'favr-member-logins', __( 'Login access', 'favr-members' ), array( $this, 'renderLogins' ), ID::POST_TYPE, 'side' );
		if ( Directory::available() ) {
			add_meta_box( 'favr-member-listing', __( 'Directory listing', 'favr-members' ), array( $this, 'renderListing' ), ID::POST_TYPE, 'side' );
		}
	}

	/**
	 * The tabbed panel.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function renderPanel( \WP_Post $post ): void {
		if ( ID::POST_TYPE !== $post->post_type ) {
			return;
		}
		wp_nonce_field( ID::NONCE_META, '_favr_member_nonce' );
		$member = new Member( $post );
		$set    = Fields::set();

		if ( 'auto-draft' !== $post->post_status && '' !== $member->name() ) {
			printf(
				'<h2 class="favr-member-heading"><span class="favr-type-badge favr-type-badge--%1$s">%2$s</span> %3$s <span class="favr-status favr-status--%4$s">%5$s</span></h2>',
				esc_attr( $member->type() ),
				esc_html( $member->isBusiness() ? __( 'Business', 'favr-members' ) : __( 'Individual', 'favr-members' ) ),
				esc_html( $member->name() ),
				esc_attr( $member->status() ),
				esc_html( ID::statuses()[ $member->status() ] )
			);
		}

		echo '<div class="favr-panel" id="favr-member-panel">';
		echo '<nav class="favr-tabs" role="tablist" aria-label="' . esc_attr__( 'Member sections', 'favr-members' ) . '">';
		$first = true;
		foreach ( $set->tabs() as $tab_id => $tab ) {
			printf(
				'<button type="button" role="tab" class="favr-tab%1$s" id="favr-member-tab-%2$s" aria-controls="favr-member-pane-%2$s" aria-selected="%3$s" data-tab="%2$s"><span class="dashicons %4$s" aria-hidden="true"></span><span class="favr-tab__label">%5$s</span></button>',
				$first ? ' is-active' : '',
				esc_attr( (string) $tab_id ),
				$first ? 'true' : 'false',
				esc_attr( (string) $tab['icon'] ),
				esc_html( (string) $tab['label'] )
			);
			$first = false;
		}
		echo '</nav><div class="favr-panes">';
		$first = true;
		foreach ( $set->tabs() as $tab_id => $tab ) {
			printf(
				'<section class="favr-pane%1$s" role="tabpanel" id="favr-member-pane-%2$s" aria-labelledby="favr-member-tab-%2$s"%3$s><h2 class="favr-pane__title">%4$s</h2><div class="favr-grid">',
				$first ? ' is-active' : '',
				esc_attr( (string) $tab_id ),
				$first ? '' : ' hidden',
				esc_html( (string) $tab['label'] )
			);
			foreach ( $set->forTab( (string) $tab_id ) as $field ) {
				$this->renderer->render( $field, $member->text( (string) $field['id'] ) );
			}
			/**
			 * Extra markup at the end of a member tab.
			 *
			 * @param string   $tab_id Tab id.
			 * @param \WP_Post $post   Member post.
			 */
			do_action( 'favr_members_after_tab', $tab_id, $post );
			echo '</div></section>';
			$first = false;
		}
		echo '</div></div>';
	}

	/**
	 * Single-choice level.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function renderLevel( \WP_Post $post ): void {
		$levels  = get_terms(
			array(
				'taxonomy'   => ID::TAX_LEVEL,
				'hide_empty' => false,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- tiny taxonomy.
					'relation'   => 'OR',
					'favr_order' => array(
						'key'     => 'favr_level_order',
						'compare' => 'EXISTS',
						'type'    => 'NUMERIC',
					),
					array(
						'key'     => 'favr_level_order',
						'compare' => 'NOT EXISTS',
					),
				),
				'orderby'    => 'favr_order',
			)
		);
		$current = wp_get_object_terms( $post->ID, ID::TAX_LEVEL, array( 'fields' => 'ids' ) );
		$current = is_array( $current ) && isset( $current[0] ) ? (int) $current[0] : 0;
		echo '<div class="favr-levels">';
		printf( '<label class="favr-level"><input type="radio" name="favr_member_level" value="0"%s> %s</label>', checked( 0, $current, false ), esc_html__( 'None', 'favr-members' ) );
		foreach ( is_array( $levels ) ? $levels : array() as $level ) {
			$color = (string) get_term_meta( $level->term_id, 'favr_level_color', true );
			printf(
				'<label class="favr-level"><input type="radio" name="favr_member_level" value="%1$d"%2$s> <span class="favr-level__swatch" style="background:%3$s"></span> %4$s</label>',
				(int) $level->term_id,
				checked( (int) $level->term_id, $current, false ),
				esc_attr( '' !== $color ? $color : '#94a3b8' ),
				esc_html( $level->name )
			);
		}
		echo '</div>';
		if ( current_user_can( ID::CAP_SETTINGS ) ) {
			printf( '<p><a href="%s">%s</a></p>', esc_url( admin_url( 'edit-tags.php?taxonomy=' . ID::TAX_LEVEL . '&post_type=' . ID::POST_TYPE ) ), esc_html__( 'Manage levels', 'favr-members' ) );
		}
	}

	/**
	 * Who can log in for this membership.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function renderLogins( \WP_Post $post ): void {
		$member = new Member( $post );
		$users  = array_filter( array_map( 'get_userdata', $member->userIds() ) );

		echo '<div class="favr-logins" data-type="' . esc_attr( $member->type() ) . '">';
		echo '<p class="favr-logins__intro favr-only-individual">' . esc_html__( 'The member’s own login to the member dashboard.', 'favr-members' ) . '</p>';
		echo '<p class="favr-logins__intro favr-only-business">' . esc_html__( 'People who can log in and manage this business (owner, office manager…).', 'favr-members' ) . '</p>';

		if ( $users ) {
			echo '<ul class="favr-logins__list">';
			foreach ( $users as $user ) {
				$last    = (int) get_user_meta( $user->ID, 'favr_members_last_login', true );
				$invited = (int) get_user_meta( $user->ID, 'favr_members_invited', true );
				$state   = get_user_meta( $user->ID, Accounts::META_UNVERIFIED, true )
					? __( 'Hasn’t confirmed their email yet', 'favr-members' )
					: ( $last
					/* translators: %s: human time diff. */
					? sprintf( __( 'Last login %s ago', 'favr-members' ), human_time_diff( $last ) )
					: ( $invited ? __( 'Invited, hasn’t logged in yet', 'favr-members' ) : __( 'Never logged in', 'favr-members' ) ) );
				printf(
					'<li><strong>%1$s</strong><br><a href="mailto:%2$s">%3$s</a><br><small>%4$s</small><br><button type="submit" name="favr_member_invite" value="%5$d" class="button-link">%6$s</button> · <label class="favr-logins__remove"><input type="checkbox" name="favr_member_remove_users[]" value="%5$d"> %7$s</label></li>',
					esc_html( $user->display_name ),
					esc_attr( $user->user_email ),
					esc_html( $user->user_email ),
					esc_html( $state ),
					(int) $user->ID,
					esc_html__( 'Send login email', 'favr-members' ),
					esc_html__( 'Remove access', 'favr-members' )
				);
			}
			echo '</ul>';
		} else {
			echo '<p class="favr-logins__none">' . esc_html__( 'No login yet.', 'favr-members' ) . '</p>';
		}

		// Individual: one-click login from the record's own name and email.
		if ( ! $users ) {
			printf(
				'<p class="favr-only-individual"><label><input type="checkbox" name="favr_member_create_login" value="1"> %s</label></p>',
				esc_html__( 'Create a login using the email above', 'favr-members' )
			);
		}

		// Business: add representatives by email.
		printf(
			'<div class="favr-only-business favr-logins__add"><p><strong>%1$s</strong></p><input type="email" name="favr_member_rep[email]" class="widefat" placeholder="%2$s"><div class="favr-logins__names"><input type="text" name="favr_member_rep[first]" placeholder="%3$s"><input type="text" name="favr_member_rep[last]" placeholder="%4$s"></div></div>',
			esc_html__( 'Add a person', 'favr-members' ),
			esc_attr__( 'Email address', 'favr-members' ),
			esc_attr__( 'First name', 'favr-members' ),
			esc_attr__( 'Last name', 'favr-members' )
		);
		printf(
			'<p><label><input type="checkbox" name="favr_member_send_invite" value="1" checked> %s</label></p>',
			esc_html__( 'Email new logins an invite to set their password', 'favr-members' )
		);
		echo '</div>';
	}

	/**
	 * Linked directory listing (business members).
	 *
	 * @param \WP_Post $post Post.
	 */
	public function renderListing( \WP_Post $post ): void {
		$member = new Member( $post );
		echo '<div class="favr-only-business">';
		$listing = $member->listingId();
		if ( $listing ) {
			printf(
				'<p><strong>%1$s</strong> <span class="favr-status favr-status--%2$s">%3$s</span></p><p><a class="button" href="%4$s">%5$s</a> <a href="%6$s" target="_blank" rel="noopener">%7$s</a></p><p class="description">%8$s</p>',
				esc_html( get_the_title( $listing ) ),
				esc_attr( 'publish' === get_post_status( $listing ) ? 'active' : 'pending' ),
				esc_html( 'publish' === get_post_status( $listing ) ? __( 'Published', 'favr-members' ) : __( 'Draft', 'favr-members' ) ),
				esc_url( (string) get_edit_post_link( $listing ) ),
				esc_html__( 'Edit listing', 'favr-members' ),
				esc_url( (string) get_permalink( $listing ) ),
				esc_html__( 'View', 'favr-members' ),
				esc_html__( 'Level, member since, renewal date and member ID are kept in sync from this record.', 'favr-members' )
			);
		} else {
			echo '<p>' . esc_html__( 'A directory listing will be created for this business when you save.', 'favr-members' ) . '</p>';
		}
		echo '</div><p class="favr-only-individual description">' . esc_html__( 'Individual members don’t have a directory listing.', 'favr-members' ) . '</p>';
	}

	/**
	 * Derive the post title from the name fields (there is no title box).
	 *
	 * @param array<string, mixed> $data    Post data.
	 * @param array<string, mixed> $postarr Raw post array.
	 * @return array<string, mixed>
	 */
	public function deriveTitle( array $data, array $postarr ): array {
		if ( ID::POST_TYPE !== ( $data['post_type'] ?? '' ) || ! $this->verified() ) {
			return $data;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified(); sanitized below.
		$input              = isset( $_POST[ self::INPUT ] ) && is_array( $_POST[ self::INPUT ] ) ? wp_unslash( $_POST[ self::INPUT ] ) : array();
		$type               = ID::TYPE_BUSINESS === ( $input['type'] ?? '' ) ? ID::TYPE_BUSINESS : ID::TYPE_INDIVIDUAL;
		$name               = Member::displayName(
			$type,
			sanitize_text_field( (string) ( $input['first_name'] ?? '' ) ),
			sanitize_text_field( (string) ( $input['last_name'] ?? '' ) ),
			sanitize_text_field( (string) ( $input['business_name'] ?? '' ) )
		);
		$data['post_title'] = '' !== $name ? $name : __( '(no name)', 'favr-members' );
		$data['post_name']  = sanitize_title( $data['post_title'] );
		return $data;
	}

	/**
	 * Save fields, level and logins, then announce the change.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 */
	public function save( int $post_id, \WP_Post $post ): void {
		if ( ! $this->verified() || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified().
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field.
		$input    = isset( $_POST[ self::INPUT ] ) && is_array( $_POST[ self::INPUT ] ) ? wp_unslash( $_POST[ self::INPUT ] ) : array();
		$notices  = array();
		$warnings = array();
		$before   = (string) get_post_meta( $post_id, ID::meta( 'status' ), true );

		foreach ( Fields::set()->all() as $id => $field ) {
			$value = Sanitizer::sanitize( $field, $input[ $id ] ?? null );
			if ( 'email' === $field['type'] && is_string( $input[ $id ] ?? null ) && '' !== trim( (string) $input[ $id ] ) && '' === $value ) {
				$warnings[] = $field['label'];
			}
			if ( Sanitizer::isEmpty( $field, $value ) ) {
				delete_post_meta( $post_id, ID::meta( (string) $id ) );
			} else {
				update_post_meta( $post_id, ID::meta( (string) $id ), $value );
			}
		}

		if ( isset( $_POST['favr_member_level'] ) ) {
			$level = absint( wp_unslash( $_POST['favr_member_level'] ) );
			wp_set_object_terms( $post_id, $level ? array( $level ) : array(), ID::TAX_LEVEL );
		}

		$member = new Member( get_post( $post_id ) ?? $post );
		$invite = ! empty( $_POST['favr_member_send_invite'] );

		// Remove access.
		foreach ( array_map( 'absint', (array) wp_unslash( $_POST['favr_member_remove_users'] ?? array() ) ) as $user_id ) {
			$member->removeUser( $user_id );
		}

		// Individual: their own login. Business: add a representative.
		$rep   = isset( $_POST['favr_member_rep'] ) && is_array( $_POST['favr_member_rep'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['favr_member_rep'] ) ) : array();
		$login = null;
		if ( ! $member->isBusiness() && ! empty( $_POST['favr_member_create_login'] ) ) {
			$login = array( $member->text( 'email' ), $member->text( 'first_name' ), $member->text( 'last_name' ) );
		} elseif ( $member->isBusiness() && '' !== trim( (string) ( $rep['email'] ?? '' ) ) ) {
			$login = array( (string) $rep['email'], (string) ( $rep['first'] ?? '' ), (string) ( $rep['last'] ?? '' ) );
		}
		if ( null !== $login ) {
			$resolved = Accounts::resolve( $login[0], $login[1], $login[2] );
			if ( is_wp_error( $resolved ) ) {
				$warnings[] = $resolved->get_error_message();
			} else {
				$member->addUser( $resolved['user_id'] );
				if ( ! $resolved['created'] ) {
					// Existing account: a heads-up only — never a password reset.
					Accounts::sendAccessNotice( $resolved['user_id'], $member->name() );
					$notices[] = __( 'Linked an existing account and emailed them a heads-up.', 'favr-members' );
				} elseif ( $invite && Accounts::sendInvite( $resolved['user_id'], $member->name() ) ) {
					$notices[] = __( 'Login created and invite emailed.', 'favr-members' );
				} else {
					$notices[] = __( 'Login created.', 'favr-members' );
				}
			}
		}

		// Resend a login email (a set-password link) — only for member accounts, never staff.
		$resend = absint( wp_unslash( $_POST['favr_member_invite'] ?? 0 ) );
		if ( $resend && in_array( $resend, $member->userIds(), true ) ) {
			if ( user_can( $resend, 'edit_posts' ) ) {
				$warnings[] = __( 'That person has a staff account; they can reset their own password from the login page.', 'favr-members' );
			} else {
				$notices[] = Accounts::sendInvite( $resend, $member->name() )
					? __( 'Login email sent.', 'favr-members' )
					: __( 'The login email could not be sent.', 'favr-members' );
			}
		}
		// phpcs:enable

		if ( ID::STATUS_PENDING === $before && ID::STATUS_ACTIVE === $member->status() ) {
			foreach ( $member->userIds() as $user_id ) {
				Accounts::sendApproval( $user_id, $member->name() );
			}
			if ( $member->userIds() ) {
				$notices[] = __( 'Approved — the applicant has been emailed.', 'favr-members' );
			}
		}

		wp_cache_delete( 'status_counts', 'favr_members' );
		set_transient(
			'favr_members_notices_' . get_current_user_id(),
			array(
				'notices'  => $notices,
				'warnings' => $warnings,
			),
			60
		);

		/**
		 * After a member record is saved (admin, import or automatic status change).
		 *
		 * @param Member $member  Member.
		 * @param string $context save | import | lapsed | registration.
		 */
		do_action( 'favr_members_member_saved', $member, 'save' );
	}

	/** Whether the current request carries our valid nonce. */
	private function verified(): bool {
		return isset( $_POST['_favr_member_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_favr_member_nonce'] ) ), ID::NONCE_META );
	}

	/** Save results. */
	public function notices(): void {
		$key  = 'favr_members_notices_' . get_current_user_id();
		$data = get_transient( $key );
		if ( ! is_array( $data ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( (array) $data['notices'] as $notice ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( (string) $notice ) );
		}
		foreach ( (array) $data['warnings'] as $warning ) {
			printf( '<div class="notice notice-warning is-dismissible"><p>%s</p></div>', esc_html( (string) $warning ) );
		}
	}

	/**
	 * Update messages.
	 *
	 * @param array<string, array<int, string>> $messages Messages.
	 * @return array<string, array<int, string>>
	 */
	public function messages( array $messages ): array {
		$messages[ ID::POST_TYPE ] = array(
			0  => '',
			1  => __( 'Member updated.', 'favr-members' ),
			4  => __( 'Member updated.', 'favr-members' ),
			6  => __( 'Member added.', 'favr-members' ),
			7  => __( 'Member saved.', 'favr-members' ),
			10 => __( 'Member draft updated.', 'favr-members' ),
		);
		return $messages;
	}
}

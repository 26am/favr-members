<?php
/**
 * Read model for one member record.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Model;

use FavrMembers\Schema\Identifiers as ID;

/**
 * Accessors over a member post. Raw values; templates escape at output.
 */
final class Member {

	/**
	 * The member post.
	 *
	 * @var \WP_Post
	 */
	private \WP_Post $post;

	/**
	 * Constructor.
	 *
	 * @param \WP_Post $post Member post.
	 */
	public function __construct( \WP_Post $post ) {
		$this->post = $post;
	}

	/**
	 * Load by id.
	 *
	 * @param int $id Post id.
	 */
	public static function find( int $id ): ?self {
		$post = get_post( $id );
		return ( $post instanceof \WP_Post && ID::POST_TYPE === $post->post_type ) ? new self( $post ) : null;
	}

	/** Post id. */
	public function id(): int {
		return (int) $this->post->ID;
	}

	/** Underlying post. */
	public function post(): \WP_Post {
		return $this->post;
	}

	/**
	 * Stored field value as a string.
	 *
	 * @param string $id Field id.
	 */
	public function text( string $id ): string {
		$value = get_post_meta( $this->id(), ID::meta( $id ), true );
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/** Member type: individual or business. */
	public function type(): string {
		return ID::TYPE_BUSINESS === $this->text( 'type' ) ? ID::TYPE_BUSINESS : ID::TYPE_INDIVIDUAL;
	}

	/** Whether this is a business membership. */
	public function isBusiness(): bool {
		return ID::TYPE_BUSINESS === $this->type();
	}

	/** Display name (business name, or "First Last"). */
	public function name(): string {
		return self::displayName( $this->type(), $this->text( 'first_name' ), $this->text( 'last_name' ), $this->text( 'business_name' ) );
	}

	/**
	 * Pure name builder.
	 *
	 * @param string $type     Member type.
	 * @param string $first    First name.
	 * @param string $last     Last name.
	 * @param string $business Business name.
	 */
	public static function displayName( string $type, string $first, string $last, string $business ): string {
		if ( ID::TYPE_BUSINESS === $type ) {
			return trim( $business );
		}
		return trim( trim( $first ) . ' ' . trim( $last ) );
	}

	/** Status key. */
	public function status(): string {
		$status = $this->text( 'status' );
		// A record without a valid status is never treated as a current member.
		return array_key_exists( $status, ID::statuses() ) ? $status : ID::STATUS_INACTIVE;
	}

	/** Whether the membership counts as current. */
	public function isActive(): bool {
		return ID::STATUS_ACTIVE === $this->status() && 'publish' === $this->post->post_status;
	}

	/** Membership level, if any. */
	public function level(): ?\WP_Term {
		$terms = get_the_terms( $this->post, ID::TAX_LEVEL );
		return is_array( $terms ) && isset( $terms[0] ) ? $terms[0] : null;
	}

	/**
	 * Linked login user ids (the member themselves, or a business's representatives).
	 *
	 * @return list<int>
	 */
	public function userIds(): array {
		$ids = get_post_meta( $this->id(), ID::META_USER, false );
		return array_values( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) );
	}

	/**
	 * Link a login.
	 *
	 * @param int $user_id User id.
	 */
	public function addUser( int $user_id ): void {
		if ( $user_id > 0 && ! in_array( $user_id, $this->userIds(), true ) ) {
			add_post_meta( $this->id(), ID::META_USER, $user_id );
		}
	}

	/**
	 * Unlink a login.
	 *
	 * @param int $user_id User id.
	 */
	public function removeUser( int $user_id ): void {
		delete_post_meta( $this->id(), ID::META_USER, $user_id );
	}

	/** Linked Favr Directory listing id (0 when none or Directory is inactive). */
	public function listingId(): int {
		$id = (int) get_post_meta( $this->id(), ID::META_LISTING, true );
		return $id && ID::DIRECTORY_POST_TYPE === get_post_type( $id ) ? $id : 0;
	}
}

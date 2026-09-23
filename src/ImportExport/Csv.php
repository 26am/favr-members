<?php
/**
 * Member CSV import and export.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\ImportExport;

use FavrMembers\Model\Accounts;
use FavrMembers\Model\Fields;
use FavrMembers\Model\Member;
use FavrMembers\Schema\Identifiers as ID;
use FavrMembers\Vendor\FavrCore\Fields\Sanitizer;
use FavrMembers\Vendor\FavrCore\Support\CsvFormat;

/**
 * Same conventions as Favr Directory: the export is the import template, only columns present
 * in the file are written, and every value goes through the Sanitizer. Matching order: `id`
 * (when its name agrees), then `member_number`, then `email` within the same member type.
 * `logins` is a "|" list of emails; new people get accounts (invites only when requested).
 */
final class Csv {

	/**
	 * Report.
	 *
	 * @var array{created: int, updated: int, skipped: int, errors: list<string>}
	 */
	private array $report = array(
		'created' => 0,
		'updated' => 0,
		'skipped' => 0,
		'errors'  => array(),
	);

	/**
	 * Constructor.
	 *
	 * @param bool $dry_run      Validate only.
	 * @param bool $send_invites Email new logins an invite.
	 */
	public function __construct( private bool $dry_run = false, private bool $send_invites = false ) {}

	/**
	 * Column headers.
	 *
	 * @return list<string>
	 */
	public static function columns(): array {
		return array_merge( array( 'id', 'level', 'logins' ), array_map( 'strval', array_keys( Fields::set()->all() ) ) );
	}

	/**
	 * Export every member.
	 *
	 * @param resource $handle Stream.
	 */
	public function export( $handle ): int {
		$columns = self::columns();
		fputcsv( $handle, $columns, ',', '"', '' );
		$ids = get_posts(
			array(
				'post_type'      => ID::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		foreach ( $ids as $id ) {
			$member = Member::find( (int) $id );
			if ( ! $member ) {
				continue;
			}
			$level  = $member->level();
			$emails = array_filter( array_map( static fn( int $u ): string => (string) ( get_userdata( $u )->user_email ?? '' ), $member->userIds() ) );
			$row    = array();
			foreach ( $columns as $column ) {
				if ( 'id' === $column ) {
					$row[] = (string) $member->id();
				} elseif ( 'level' === $column ) {
					$row[] = $level ? html_entity_decode( $level->name, ENT_QUOTES, 'UTF-8' ) : '';
				} elseif ( 'logins' === $column ) {
					$row[] = implode( '|', $emails );
				} else {
					$field = Fields::set()->get( $column );
					$row[] = $field ? CsvFormat::encode( $field, $member->text( $column ) ) : '';
				}
			}
			fputcsv( $handle, array_map( array( CsvFormat::class, 'safeCell' ), $row ), ',', '"', '' );
		}
		return count( $ids );
	}

	/**
	 * Import a file.
	 *
	 * @param string $path Path.
	 * @return array{created: int, updated: int, skipped: int, errors: list<string>}
	 */
	public function import( string $path ): array {
		$handle = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			$this->report['errors'][] = __( 'Could not read the file.', 'favr-members' );
			return $this->report;
		}
		$header = fgetcsv( $handle, 0, ',', '"', '' );
		if ( ! is_array( $header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->report['errors'][] = __( 'The file is empty.', 'favr-members' );
			return $this->report;
		}
		$header = array_map( static fn( $h ): string => sanitize_key( str_replace( ' ', '_', (string) preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h ) ) ), $header );
		$line   = 1;
		while ( ( $cells = fgetcsv( $handle, 0, ',', '"', '' ) ) !== false ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			++$line;
			if ( array( null ) === $cells ) {
				continue;
			}
			$row = array();
			foreach ( $header as $i => $key ) {
				$row[ $key ] = CsvFormat::unsafeCell( (string) ( $cells[ $i ] ?? '' ) );
			}
			$this->row( $row, $line );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $this->report;
	}

	/**
	 * Import one row.
	 *
	 * @param array<string, string> $row  Row.
	 * @param int                   $line Line number.
	 */
	public function row( array $row, int $line ): void {
		$type = ID::TYPE_BUSINESS === strtolower( trim( $row['type'] ?? '' ) ) ? ID::TYPE_BUSINESS : ID::TYPE_INDIVIDUAL;
		$name = Member::displayName( $type, $row['first_name'] ?? '', $row['last_name'] ?? '', $row['business_name'] ?? '' );
		$id   = $this->match( $row, $type, $name );

		if ( ! $id && '' === $name ) {
			++$this->report['skipped'];
			/* translators: %d: line number. */
			$this->report['errors'][] = sprintf( __( 'Line %d: skipped — needs a first/last name (individual) or business_name (business).', 'favr-members' ), $line );
			return;
		}
		foreach ( array( 'type', 'status' ) as $key ) {
			$field = Fields::set()->get( $key );
			$cell  = trim( (string) ( $row[ $key ] ?? '' ) );
			if ( $field && '' !== $cell && '' === Sanitizer::sanitize( $field, self::choice( $field, $cell ) ) ) {
				++$this->report['skipped'];
				/* translators: 1: line, 2: column, 3: value, 4: allowed values. */
				$this->report['errors'][] = sprintf( __( 'Line %1$d: skipped — "%3$s" is not a valid %2$s (use %4$s).', 'favr-members' ), $line, $key, $cell, implode( ', ', array_keys( (array) $field['options'] ) ) );
				return;
			}
		}

		if ( $this->dry_run ) {
			++$this->report[ $id ? 'updated' : 'created' ];
			return;
		}

		$postarr = array( 'post_type' => ID::POST_TYPE );
		if ( $id ) {
			$postarr['ID'] = $id; // Updates keep the record's current post status.
		} else {
			$postarr['post_status'] = 'publish';
		}
		if ( '' !== $name ) {
			$postarr['post_title'] = $name;
		}
		$post_id = $id ? wp_update_post( $postarr, true ) : wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) ) {
			++$this->report['skipped'];
			$this->report['errors'][] = sprintf( 'Line %d: %s', $line, $post_id->get_error_message() );
			return;
		}
		$post_id = (int) $post_id;
		if ( ! $id ) {
			// New records always get an explicit type and status.
			$row['type']   = $row['type'] ?? $type;
			$row['status'] = ( $row['status'] ?? '' ) !== '' ? $row['status'] : ID::STATUS_ACTIVE;
		}
		foreach ( Fields::set()->all() as $key => $field ) {
			if ( ! array_key_exists( (string) $key, $row ) ) {
				continue;
			}
			$value = Sanitizer::sanitize( $field, self::choice( $field, CsvFormat::decode( $field, $row[ $key ] ) ) );
			Sanitizer::isEmpty( $field, $value ) ? delete_post_meta( $post_id, ID::meta( (string) $key ) ) : update_post_meta( $post_id, ID::meta( (string) $key ), $value );
		}
		if ( array_key_exists( 'level', $row ) ) {
			$level = trim( $row['level'] );
			$term  = '' === $level ? null : ( term_exists( $level, ID::TAX_LEVEL ) ?: wp_insert_term( $level, ID::TAX_LEVEL ) );
			wp_set_object_terms( $post_id, is_array( $term ) ? array( (int) $term['term_id'] ) : array(), ID::TAX_LEVEL );
		}
		$member = Member::find( $post_id );
		if ( $member && ! empty( $row['logins'] ) ) {
			foreach ( CsvFormat::splitList( $row['logins'] ) as $email ) {
				$resolved = Accounts::resolve( $email, $member->isBusiness() ? '' : $member->text( 'first_name' ), $member->isBusiness() ? '' : $member->text( 'last_name' ) );
				if ( is_wp_error( $resolved ) ) {
					$this->report['errors'][] = sprintf( 'Line %d: %s', $line, $resolved->get_error_message() );
					continue;
				}
				$is_new = ! in_array( $resolved['user_id'], $member->userIds(), true );
				$member->addUser( $resolved['user_id'] );
				// Invites (password links) only ever go to accounts created by this import.
				if ( $is_new && $this->send_invites && $resolved['created'] ) {
					Accounts::sendInvite( $resolved['user_id'], $member->name() );
				}
			}
		}
		if ( $member ) {
			/** This action is documented in src/Admin/EditScreen.php */
			do_action( 'favr_members_member_saved', $member, 'import' );
		}
		++$this->report[ $id ? 'updated' : 'created' ];
	}

	/**
	 * Map a spreadsheet value onto a select/radio option key: case-insensitive, and option
	 * labels are accepted too ("Lapsed", "Business").
	 *
	 * @param array<string, mixed> $field Field.
	 * @param mixed                $value Cell value.
	 * @return mixed
	 */
	public static function choice( array $field, $value ) {
		if ( ! in_array( $field['type'], array( 'select', 'radio' ), true ) || ! is_string( $value ) ) {
			return $value;
		}
		$needle = strtolower( trim( $value ) );
		foreach ( (array) $field['options'] as $key => $label ) {
			if ( strtolower( (string) $key ) === $needle || strtolower( (string) $label ) === $needle ) {
				return (string) $key;
			}
		}
		return $value;
	}

	/**
	 * Find an existing member for a row.
	 *
	 * @param array<string, string> $row  Row.
	 * @param string                $type Member type.
	 * @param string                $name Display name.
	 */
	private function match( array $row, string $type, string $name ): int {
		$id = absint( $row['id'] ?? 0 );
		if ( $id && ID::POST_TYPE === get_post_type( $id ) && ( '' === $name || 0 === strcasecmp( $name, html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) ) ) ) {
			return $id;
		}
		foreach ( array( 'member_number', 'email' ) as $key ) {
			$value = trim( $row[ $key ] ?? '' );
			if ( '' === $value ) {
				continue;
			}
			$found = get_posts(
				array(
					'post_type'      => ID::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- import.
						array(
							'key'   => ID::meta( $key ),
							'value' => $value,
						),
						array(
							'key'   => ID::meta( 'type' ),
							'value' => $type,
						),
					),
				)
			);
			if ( $found ) {
				return (int) $found[0];
			}
		}
		return 0;
	}
}

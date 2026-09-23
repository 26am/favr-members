<?php
/**
 * Front-end image uploads for people without media-library access.
 *
 * @package FavrCore
 */

declare(strict_types=1);

namespace FavrMembers\Vendor\FavrCore\Moderation;

/**
 * Images only, size-limited, owned by the uploader. Plugins expose this through their own
 * REST route (with their own permission check) and must validate submitted attachment ids with
 * `usable()` before saving them, so nobody can attach someone else's media.
 */
final class Uploads {

	public const MIMES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
		'gif'          => 'image/gif',
	);

	/**
	 * Handle one uploaded file from $_FILES.
	 *
	 * @param array<string, mixed> $file      One $_FILES entry.
	 * @param int                  $user_id   Uploader (becomes the attachment author).
	 * @param int                  $post_id   Post the image belongs to.
	 * @param int                  $max_bytes Size limit.
	 * @return int|\WP_Error Attachment id.
	 */
	public static function handle( array $file, int $user_id, int $post_id, int $max_bytes = 8388608 ) {
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return new \WP_Error( 'favr_upload', __( 'The upload failed. Please try again.', 'favr-core' ), array( 'status' => 400 ) );
		}
		if ( (int) ( $file['size'] ?? 0 ) > $max_bytes ) {
			/* translators: %s: size limit, e.g. "8 MB". */
			return new \WP_Error( 'favr_upload_size', sprintf( __( 'Images must be smaller than %s.', 'favr-core' ), size_format( $max_bytes ) ), array( 'status' => 413 ) );
		}
		$check = wp_check_filetype_and_ext( (string) $file['tmp_name'], (string) $file['name'], self::MIMES );
		if ( empty( $check['type'] ) || ! in_array( $check['type'], self::MIMES, true ) ) {
			return new \WP_Error( 'favr_upload_type', __( 'Please upload a JPG, PNG, WebP or GIF image.', 'favr-core' ), array( 'status' => 415 ) );
		}

		// The bytes must really be a decodable image, not just carry an image signature.
		$size   = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( (string) $file['tmp_name'] ) : false;
		$editor = wp_get_image_editor( (string) $file['tmp_name'] );
		// A PHP open tag has no business in an image (the chance of "<?php" occurring in compressed
		// image data by accident is negligible, unlike shorter tags).
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local temp file.
		$bytes = (string) file_get_contents( (string) $file['tmp_name'] );
		if ( ! is_array( $size ) || empty( $size[0] ) || empty( $size[1] ) || is_wp_error( $editor ) || false !== stripos( $bytes, '<?php' ) ) {
			return new \WP_Error( 'favr_upload_type', __( 'Please upload a JPG, PNG, WebP or GIF image.', 'favr-core' ), array( 'status' => 415 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$moved = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => self::MIMES,
			)
		);
		if ( isset( $moved['error'] ) ) {
			return new \WP_Error( 'favr_upload', (string) $moved['error'], array( 'status' => 400 ) );
		}
		$attachment = wp_insert_attachment(
			array(
				'post_mime_type' => $moved['type'],
				'post_title'     => sanitize_text_field( pathinfo( (string) $file['name'], PATHINFO_FILENAME ) ),
				'post_status'    => 'inherit',
				'post_author'    => $user_id,
			),
			$moved['file'],
			$post_id,
			true
		);
		if ( is_wp_error( $attachment ) ) {
			wp_delete_file( $moved['file'] );
			return $attachment;
		}
		wp_update_attachment_metadata( (int) $attachment, wp_generate_attachment_metadata( (int) $attachment, $moved['file'] ) );
		return (int) $attachment;
	}

	/**
	 * Whether a person may put this attachment on this post: they uploaded it, or it is
	 * already attached to / used by the post.
	 *
	 * @param int       $attachment Attachment id.
	 * @param int       $user_id    Person.
	 * @param int       $post_id    Target post.
	 * @param list<int> $current    Attachment ids the post already uses.
	 */
	public static function usable( int $attachment, int $user_id, int $post_id, array $current = array() ): bool {
		$post = get_post( $attachment );
		if ( ! $post || 'attachment' !== $post->post_type || ! wp_attachment_is_image( $attachment ) ) {
			return false;
		}
		return in_array( $attachment, $current, true ) || (int) $post->post_author === $user_id || ( $post_id && (int) $post->post_parent === $post_id );
	}

	/**
	 * Filter a list of ids down to usable ones.
	 *
	 * @param list<int> $ids     Ids.
	 * @param int       $user_id Person.
	 * @param int       $post_id Target post.
	 * @param list<int> $current Currently used ids.
	 * @return list<int>
	 */
	public static function filterUsable( array $ids, int $user_id, int $post_id, array $current = array() ): array {
		return array_values( array_filter( $ids, static fn( int $id ): bool => self::usable( $id, $user_id, $post_id, $current ) ) );
	}
}

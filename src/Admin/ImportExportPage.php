<?php
/**
 * Members → Import / Export.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Admin;

use FavrMembers\ImportExport\Csv;
use FavrMembers\Schema\Identifiers as ID;

/**
 * CSV download and upload (with a test run).
 */
final class ImportExportPage {

	public const SLUG = 'favr-members-import-export';

	/** Hook. */
	public function hook(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_favr_members_export', array( $this, 'export' ) );
		add_action( 'admin_post_favr_members_import', array( $this, 'import' ) );
	}

	/** Menu. */
	public function menu(): void {
		add_submenu_page( 'edit.php?post_type=' . ID::POST_TYPE, __( 'Import & Export Members', 'favr-members' ), __( 'Import / Export', 'favr-members' ), ID::CAP_SETTINGS, self::SLUG, array( $this, 'render' ) );
	}

	/** Download. */
	public function export(): void {
		check_admin_referer( 'favr_members_export' );
		if ( ! current_user_can( ID::CAP_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to export members.', 'favr-members' ), 403 );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="members-' . gmdate( 'Y-m-d' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $out ) {
			fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM for Excel.
			( new Csv() )->export( $out );
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		exit;
	}

	/** Upload. */
	public function import(): void {
		check_admin_referer( 'favr_members_import' );
		if ( ! current_user_can( ID::CAP_SETTINGS ) ) {
			wp_die( esc_html__( 'You are not allowed to import members.', 'favr-members' ), 403 );
		}
		$file   = $_FILES['favr_csv'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below.
		$report = array( 'errors' => array( __( 'Please choose a CSV file to upload.', 'favr-members' ) ) );
		if ( is_array( $file ) && UPLOAD_ERR_OK === (int) ( $file['error'] ?? 1 ) && is_uploaded_file( (string) $file['tmp_name'] ) && 'csv' === wp_check_filetype( sanitize_file_name( (string) $file['name'] ), array( 'csv' => 'text/csv' ) )['ext'] ) {
			$dry               = ! empty( $_POST['dry_run'] );
			$report            = ( new Csv( $dry, ! empty( $_POST['send_invites'] ) ) )->import( (string) $file['tmp_name'] );
			$report['dry_run'] = $dry;
		}
		set_transient( 'favr_members_import_' . get_current_user_id(), $report, 300 );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . ID::POST_TYPE . '&page=' . self::SLUG ) );
		exit;
	}

	/** Render. */
	public function render(): void {
		$report = get_transient( 'favr_members_import_' . get_current_user_id() );
		delete_transient( 'favr_members_import_' . get_current_user_id() );
		?>
		<div class="wrap favr-settings">
			<h1><?php esc_html_e( 'Import & Export Members', 'favr-members' ); ?></h1>
			<?php if ( is_array( $report ) ) : ?>
				<div class="notice <?php echo esc_attr( empty( $report['errors'] ) ? 'notice-success' : 'notice-warning' ); ?>">
					<?php if ( isset( $report['created'] ) ) : ?>
						<p><strong><?php echo esc_html( ! empty( $report['dry_run'] ) ? __( 'Test run — nothing was saved.', 'favr-members' ) : __( 'Import complete.', 'favr-members' ) ); ?></strong>
						<?php
						/* translators: 1: created, 2: updated, 3: skipped. */
						echo esc_html( sprintf( __( 'Created %1$d, updated %2$d, skipped %3$d.', 'favr-members' ), (int) $report['created'], (int) $report['updated'], (int) $report['skipped'] ) );
						?>
						</p>
					<?php endif; ?>
					<?php foreach ( array_slice( (array) ( $report['errors'] ?? array() ), 0, 50 ) as $error ) : ?>
						<p><?php echo esc_html( (string) $error ); ?></p>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<div class="favr-card">
				<h2><?php esc_html_e( 'Import members from a spreadsheet', 'favr-members' ); ?></h2>
				<p><?php esc_html_e( 'Columns: type (individual or business), first_name, last_name, business_name, email, phone, status, level, member_since, renewal_date, member_number, logins (emails separated by |). Export first to get a template.', 'favr-members' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<input type="hidden" name="action" value="favr_members_import">
					<?php wp_nonce_field( 'favr_members_import' ); ?>
					<p><input type="file" name="favr_csv" accept=".csv,text/csv" required></p>
					<p><label><input type="checkbox" name="dry_run" value="1" checked> <?php esc_html_e( 'Test run first (save nothing)', 'favr-members' ); ?></label></p>
					<p><label><input type="checkbox" name="send_invites" value="1"> <?php esc_html_e( 'Email new logins an invite', 'favr-members' ); ?></label></p>
					<?php submit_button( __( 'Import CSV', 'favr-members' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="favr-card">
				<h2><?php esc_html_e( 'Export members', 'favr-members' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="favr_members_export">
					<?php wp_nonce_field( 'favr_members_export' ); ?>
					<?php submit_button( __( 'Download CSV', 'favr-members' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<?php
	}
}

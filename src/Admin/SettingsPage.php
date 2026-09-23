<?php
/**
 * Members → Settings.
 *
 * @package FavrMembers
 */

declare(strict_types=1);

namespace FavrMembers\Admin;

use FavrMembers\Integration\Directory;
use FavrMembers\Schema\Identifiers as ID;
use FavrMembers\Support\Settings;

/**
 * Pages, registration, directory behaviour, renewals and email.
 */
final class SettingsPage {

	public const SLUG = 'favr-members-settings';

	/** Hook. */
	public function hook(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( FAVR_MEMBERS_FILE ), array( $this, 'actionLinks' ) );
	}

	/** Menu. */
	public function menu(): void {
		add_submenu_page( 'edit.php?post_type=' . ID::POST_TYPE, __( 'Membership Levels', 'favr-members' ), __( 'Levels', 'favr-members' ), ID::CAP_SETTINGS, 'edit-tags.php?taxonomy=' . ID::TAX_LEVEL . '&post_type=' . ID::POST_TYPE );
		add_submenu_page( 'edit.php?post_type=' . ID::POST_TYPE, __( 'Member Settings', 'favr-members' ), __( 'Settings', 'favr-members' ), ID::CAP_SETTINGS, self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Plugins-screen link.
	 *
	 * @param array<string, string> $links Links.
	 * @return array<string, string>
	 */
	public function actionLinks( array $links ): array {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'edit.php?post_type=' . ID::POST_TYPE . '&page=' . self::SLUG ) ), esc_html__( 'Settings', 'favr-members' ) ) );
		return $links;
	}

	/** Register the option. */
	public function register(): void {
		register_setting(
			'favr_members',
			ID::OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	/**
	 * Sanitize.
	 *
	 * @param mixed $input Raw.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$pick  = static fn( string $key, array $allowed, string $fallback ): string => in_array( $input[ $key ] ?? '', $allowed, true ) ? (string) $input[ $key ] : $fallback;
		$out   = array(
			'login_page'      => absint( $input['login_page'] ?? 0 ),
			'account_page'    => absint( $input['account_page'] ?? 0 ),
			'register_page'   => absint( $input['register_page'] ?? 0 ),
			'registration'    => $pick( 'registration', array( 'invite', 'open' ), 'invite' ),
			'listing_status'  => $pick( 'listing_status', array( 'publish', 'draft' ), 'publish' ),
			'lapsed_listing'  => $pick( 'lapsed_listing', array( 'keep', 'hide' ), 'keep' ),
			'auto_lapse'      => empty( $input['auto_lapse'] ) ? '0' : '1',
			'lapse_grace'     => min( 365, absint( $input['lapse_grace'] ?? 30 ) ),
			'email_from_name' => sanitize_text_field( (string) ( $input['email_from_name'] ?? '' ) ),
			'delete_data'     => empty( $input['delete_data'] ) ? '0' : '1',
		);
		Settings::flush();
		return $out;
	}

	/**
	 * Page dropdown.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param string $code  Shortcode it should contain.
	 */
	private function pageRow( string $key, string $label, string $code ): void {
		$value = (int) Settings::get( $key );
		echo '<tr><th scope="row"><label for="favr-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_dropdown_pages() escapes; args escaped here.
		wp_dropdown_pages(
			array(
				'name'              => esc_attr( ID::OPTION_SETTINGS . '[' . $key . ']' ),
				'id'                => 'favr-' . esc_attr( $key ),
				'selected'          => $value,
				'show_option_none'  => esc_html__( '— Select a page —', 'favr-members' ),
				'option_none_value' => '0',
				'post_status'       => array( 'publish', 'draft' ),
			)
		);
		// phpcs:enable
		if ( $value && get_post( $value ) ) {
			printf( ' <a href="%s">%s</a> · <a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( (string) get_edit_post_link( $value ) ), esc_html__( 'Edit', 'favr-members' ), esc_url( (string) get_permalink( $value ) ), esc_html__( 'View', 'favr-members' ) );
		}
		/* translators: %s: shortcode. */
		echo '<p class="description">' . sprintf( esc_html__( 'The page must contain %s.', 'favr-members' ), '<code>' . esc_html( $code ) . '</code>' ) . '</p></td></tr>';
	}

	/** Render. */
	public function render(): void {
		if ( ! current_user_can( ID::CAP_SETTINGS ) ) {
			return;
		}
		$s   = Settings::all();
		$opt = ID::OPTION_SETTINGS;
		?>
		<div class="wrap favr-settings">
			<h1><?php esc_html_e( 'Member Settings', 'favr-members' ); ?></h1>
			<form method="post" action="options.php" class="favr-settings__form">
				<?php settings_fields( 'favr_members' ); ?>

				<div class="favr-card">
					<h2><?php esc_html_e( 'Member pages', 'favr-members' ); ?></h2>
					<table class="form-table" role="presentation">
						<?php
						$this->pageRow( 'login_page', __( 'Login page', 'favr-members' ), '[favr_login]' );
						$this->pageRow( 'account_page', __( 'Member dashboard', 'favr-members' ), '[favr_account]' );
						$this->pageRow( 'register_page', __( 'Join page', 'favr-members' ), '[favr_register]' );
						?>
					</table>
				</div>

				<div class="favr-card">
					<h2><?php esc_html_e( 'Joining', 'favr-members' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'New members', 'favr-members' ); ?></th>
							<td>
								<fieldset class="favr-radios">
									<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[registration]" value="invite" <?php checked( $s['registration'], 'invite' ); ?>> <?php esc_html_e( 'Staff add members and send invites (recommended)', 'favr-members' ); ?></label>
									<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[registration]" value="open" <?php checked( $s['registration'], 'open' ); ?>> <?php esc_html_e( 'Anyone can apply on the Join page; staff approve', 'favr-members' ); ?></label>
								</fieldset>
								<p class="description"><?php esc_html_e( 'Applications arrive as Pending members. Set their status to Active to approve them.', 'favr-members' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="favr-card">
					<h2><?php esc_html_e( 'Renewals', 'favr-members' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Lapsed memberships', 'favr-members' ); ?></th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[auto_lapse]" value="1" <?php checked( $s['auto_lapse'], '1' ); ?>> <?php esc_html_e( 'Mark active members as Lapsed automatically after their renewal date', 'favr-members' ); ?></label>
								<p>
									<?php
									printf(
										/* translators: %s: number input. */
										esc_html__( 'Grace period: %s days', 'favr-members' ),
										'<input type="number" min="0" max="365" class="small-text" name="' . esc_attr( $opt ) . '[lapse_grace]" value="' . esc_attr( (string) $s['lapse_grace'] ) . '">'
									);
									?>
								</p>
							</td>
						</tr>
					</table>
				</div>

				<?php if ( Directory::available() ) : ?>
					<div class="favr-card">
						<h2><?php esc_html_e( 'Business directory', 'favr-members' ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><?php esc_html_e( 'New business members', 'favr-members' ); ?></th>
								<td>
									<fieldset class="favr-radios">
										<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[listing_status]" value="publish" <?php checked( $s['listing_status'], 'publish' ); ?>> <?php esc_html_e( 'Publish their directory listing right away', 'favr-members' ); ?></label>
										<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[listing_status]" value="draft" <?php checked( $s['listing_status'], 'draft' ); ?>> <?php esc_html_e( 'Create it as a draft for staff to complete', 'favr-members' ); ?></label>
									</fieldset>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Lapsed or inactive businesses', 'favr-members' ); ?></th>
								<td>
									<fieldset class="favr-radios">
										<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[lapsed_listing]" value="keep" <?php checked( $s['lapsed_listing'], 'keep' ); ?>> <?php esc_html_e( 'Keep their listing in the directory', 'favr-members' ); ?></label>
										<label><input type="radio" name="<?php echo esc_attr( $opt ); ?>[lapsed_listing]" value="hide" <?php checked( $s['lapsed_listing'], 'hide' ); ?>> <?php esc_html_e( 'Hide it until they renew (restored automatically)', 'favr-members' ); ?></label>
									</fieldset>
								</td>
							</tr>
						</table>
					</div>
				<?php endif; ?>

				<div class="favr-card">
					<h2><?php esc_html_e( 'Email & data', 'favr-members' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="favr-from"><?php esc_html_e( 'Emails come from', 'favr-members' ); ?></label></th>
							<td><input type="text" id="favr-from" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[email_from_name]" value="<?php echo esc_attr( (string) $s['email_from_name'] ); ?>" placeholder="<?php echo esc_attr( wp_strip_all_tags( (string) get_bloginfo( 'name' ) ) ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'On uninstall', 'favr-members' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[delete_data]" value="1" <?php checked( $s['delete_data'], '1' ); ?>> <?php esc_html_e( 'Permanently delete all member records and settings when the plugin is deleted (logins are kept)', 'favr-members' ); ?></label></td>
						</tr>
					</table>
				</div>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

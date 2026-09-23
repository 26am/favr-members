<?php
/**
 * Success message and errors for a form.
 *
 * @package FavrMembers
 *
 * @var string $form Form id.
 */

use FavrMembers\Frontend\Auth;
use FavrMembers\Frontend\Pages;

defined( 'ABSPATH' ) || exit;

$favr_message = Pages::currentMessage();
$favr_errors  = Auth::errors( $form );
?>
<?php if ( '' !== $favr_message ) : ?>
	<div class="favr-m-notice favr-m-notice--success" role="status"><?php echo esc_html( $favr_message ); ?></div>
<?php endif; ?>
<?php if ( $favr_errors ) : ?>
	<div class="favr-m-notice favr-m-notice--error" role="alert">
		<?php foreach ( $favr_errors as $favr_error ) : ?>
			<p><?php echo esc_html( $favr_error ); ?></p>
		<?php endforeach; ?>
	</div>
<?php endif; ?>

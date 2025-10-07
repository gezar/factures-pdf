<?php
/**
 * Default template used for Paid Member Subscriptions PDF receipts.
 *
 * The variables available in this template are:
 * - $payment      : array containing sanitized payment data.
 * - $subscription : array containing sanitized subscription data.
 * - $plan         : array containing sanitized subscription plan data.
 * - $user         : WP_User instance (or null when not available).
 */

$member_name  = $user instanceof WP_User ? $user->display_name : __( 'Unknown member', 'send-pdf-for-contact-form-7' );
$member_email = $user instanceof WP_User ? $user->user_email : '';
$plan_name    = isset( $plan['name'] ) ? $plan['name'] : __( 'Subscription plan', 'send-pdf-for-contact-form-7' );
$payment_id   = isset( $payment['id'] ) ? $payment['id'] : ( isset( $payment['payment_id'] ) ? $payment['payment_id'] : '' );
$amount       = isset( $payment['amount'] ) ? $payment['amount'] : '';
$currency     = isset( $payment['currency'] ) ? strtoupper( $payment['currency'] ) : '';

$payment_date = '';
if ( ! empty( $payment['date'] ) ) {
    $timestamp    = is_numeric( $payment['date'] ) ? (int) $payment['date'] : strtotime( $payment['date'] );
    $payment_date = $timestamp ? wp_date( get_option( 'date_format' ), $timestamp ) : '';
}

$start_date = '';
if ( ! empty( $subscription['start_date'] ) ) {
    $start_timestamp = strtotime( $subscription['start_date'] );
    $start_date      = $start_timestamp ? wp_date( get_option( 'date_format' ), $start_timestamp ) : '';
}

$expiry_date = '';
if ( ! empty( $subscription['expiration_date'] ) ) {
    $expiry_timestamp = strtotime( $subscription['expiration_date'] );
    $expiry_date      = $expiry_timestamp ? wp_date( get_option( 'date_format' ), $expiry_timestamp ) : '';
}

$status = isset( $payment['status'] ) ? $payment['status'] : '';
$gateway = isset( $payment['payment_gateway'] ) ? $payment['payment_gateway'] : '';
$plan_status = isset( $subscription['status'] ) ? $subscription['status'] : '';

?>
<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <style>
            body { font-family: sans-serif; color: #333; font-size: 12pt; }
            h1 { font-size: 20pt; margin-bottom: 10px; }
            h2 { font-size: 14pt; margin-bottom: 5px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            th, td { text-align: left; border: 1px solid #e0e0e0; padding: 8px; }
            th { background-color: #f5f5f5; }
            .section { margin-bottom: 20px; }
            .amount { font-size: 16pt; font-weight: bold; }
        </style>
    </head>
    <body>
        <h1><?php esc_html_e( 'Subscription receipt', 'send-pdf-for-contact-form-7' ); ?></h1>

        <div class="section">
            <h2><?php esc_html_e( 'Member details', 'send-pdf-for-contact-form-7' ); ?></h2>
            <table>
                <tr>
                    <th><?php esc_html_e( 'Name', 'send-pdf-for-contact-form-7' ); ?></th>
                    <td><?php echo esc_html( $member_name ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Email', 'send-pdf-for-contact-form-7' ); ?></th>
                    <td><?php echo esc_html( $member_email ); ?></td>
                </tr>
                <?php if ( $user instanceof WP_User ) : ?>
                    <tr>
                        <th><?php esc_html_e( 'Username', 'send-pdf-for-contact-form-7' ); ?></th>
                        <td><?php echo esc_html( $user->user_login ); ?></td>
                    </tr>
                <?php endif; ?>
            </table>
        </div>

        <div class="section">
            <h2><?php esc_html_e( 'Subscription details', 'send-pdf-for-contact-form-7' ); ?></h2>
            <table>
                <tr>
                    <th><?php esc_html_e( 'Plan', 'send-pdf-for-contact-form-7' ); ?></th>
                    <td><?php echo esc_html( $plan_name ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Status', 'send-pdf-for-contact-form-7' ); ?></th>
                    <td><?php echo esc_html( $plan_status ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Start date', 'send-pdf-for-contact-form-7' ); ?></th>
                    <td><?php echo esc_html( $start_date ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Expiration date', 'send-pdf-for-contact-form-7' ); ?></th>
                    <td><?php echo esc_html( $expiry_date ); ?></td>
                </tr>
            </table>
        </div>

        <div class="section">
            <h2><?php esc_html_e( 'Payment details', 'send-pdf-for-contact-form-7' ); ?></h2>
            <table>
                <tr>
                    <th><?php esc_html_e( 'Payment ID', 'send-pdf-for-contact-form-7' ); ?></th>
                    <td><?php echo esc_html( $payment_id ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Date', 'send-pdf-for-contact-form-7' ); ?></th>
                    <td><?php echo esc_html( $payment_date ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Payment gateway', 'send-pdf-for-contact-form-7' ); ?></th>
                    <td><?php echo esc_html( $gateway ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Status', 'send-pdf-for-contact-form-7' ); ?></th>
                    <td><?php echo esc_html( $status ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Amount', 'send-pdf-for-contact-form-7' ); ?></th>
                    <td class="amount">
                        <?php
                        if ( $amount !== '' ) {
                            echo esc_html( number_format_i18n( (float) $amount, 2 ) . ' ' . $currency );
                        }
                        ?>
                    </td>
                </tr>
            </table>
        </div>

        <?php
        /**
         * Fires after the default subscription tables have been rendered.
         *
         * @param array $payment      Payment data.
         * @param array $subscription Subscription data.
         * @param array $plan         Plan data.
         * @param WP_User|null $user  Member object.
         */
        do_action( 'wpcf7pdf_mps_template_after_tables', $payment, $subscription, $plan, $user );
        ?>
    </body>
</html>

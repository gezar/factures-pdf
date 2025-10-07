<?php

defined( 'ABSPATH' ) || exit;

/**
 * Integration with the Paid Member Subscriptions plugin.
 *
 * This integration exposes a shortcode that lists the current member payments
 * and lets them download a PDF invoice generated with the Send PDF for Contact
 * Form 7 infrastructure. Invoices are produced on demand using the default
 * template located in `views/member-paid-subscriptions-pdf.php` (customisable
 * through the `wpcf7pdf_mps_template_path` filter).
 */
class WPCF7PDF_Member_Paid_Subscriptions {

    /**
     * Singleton instance.
     *
     * @var WPCF7PDF_Member_Paid_Subscriptions|null
     */
    protected static $instance = null;

    /**
     * Bootstraps the integration.
     *
     * @return WPCF7PDF_Member_Paid_Subscriptions|null
     */
    public static function init() {
        if ( null === self::$instance && self::is_paid_member_subscriptions_active() ) {
            self::$instance = new self();
            self::$instance->hooks();
        }

        return self::$instance;
    }

    /**
     * Register hooks with WordPress and Paid Member Subscriptions.
     */
    protected function hooks() {
        add_action( 'init', array( $this, 'register_shortcodes' ) );
        add_action( 'template_redirect', array( $this, 'maybe_output_invoice' ) );
    }

    /**
     * Verifies that Paid Member Subscriptions helper functions are available.
     *
     * @return bool
     */
    protected static function is_paid_member_subscriptions_active() {
        return function_exists( 'pms_get_payment' ) || class_exists( 'PMS_Payment' );
    }

    /**
     * Registers the shortcode used to display the payments table.
     */
    public function register_shortcodes() {
        add_shortcode( 'wpcf7pdf_mps_invoices', array( $this, 'render_invoices_shortcode' ) );
    }

    /**
     * Renders the shortcode output listing the member payments.
     *
     * @param array $atts Shortcode attributes.
     *
     * @return string
     */
    public function render_invoices_shortcode( $atts ) {
        if ( ! is_user_logged_in() ) {
            return '<p>' . esc_html__( 'You need to be logged in to view your invoices.', 'send-pdf-for-contact-form-7' ) . '</p>';
        }

        $atts = shortcode_atts(
            array(
                'status' => 'completed',
            ),
            $atts,
            'wpcf7pdf_mps_invoices'
        );

        $user_id  = get_current_user_id();
        $payments = $this->get_user_payments( $user_id, $atts['status'] );

        if ( empty( $payments ) ) {
            return '<p>' . esc_html__( 'No payments were found for your account.', 'send-pdf-for-contact-form-7' ) . '</p>';
        }

        ob_start();
        ?>
        <div class="wpcf7pdf-mps-invoices">
            <table class="wpcf7pdf-mps-invoices__table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Date', 'send-pdf-for-contact-form-7' ); ?></th>
                        <th><?php esc_html_e( 'Subscription', 'send-pdf-for-contact-form-7' ); ?></th>
                        <th><?php esc_html_e( 'Amount', 'send-pdf-for-contact-form-7' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'send-pdf-for-contact-form-7' ); ?></th>
                        <th><?php esc_html_e( 'Invoice', 'send-pdf-for-contact-form-7' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $payments as $payment ) :
                        $context      = $this->build_context( $payment['object'] );
                        $payment_data = isset( $context['payment'] ) ? $context['payment'] : array();
                        $subscription = isset( $context['subscription'] ) ? $context['subscription'] : array();
                        $plan         = isset( $context['plan'] ) ? $context['plan'] : array();

                        $payment_id = isset( $payment_data['id'] ) ? absint( $payment_data['id'] ) : ( isset( $payment_data['payment_id'] ) ? absint( $payment_data['payment_id'] ) : 0 );
                        $plan_name  = isset( $plan['name'] ) ? $plan['name'] : ( isset( $subscription['subscription_plan_id'] ) ? $subscription['subscription_plan_id'] : '' );

                        $date = '';
                        if ( ! empty( $payment_data['date'] ) ) {
                            $timestamp = is_numeric( $payment_data['date'] ) ? (int) $payment_data['date'] : strtotime( $payment_data['date'] );
                            $date      = $timestamp ? wp_date( get_option( 'date_format' ), $timestamp ) : '';
                        }

                        $raw_amount = isset( $payment_data['amount'] ) ? $payment_data['amount'] : '';
                        $amount     = '' === $raw_amount ? '' : (float) $raw_amount;
                        $currency   = isset( $payment_data['currency'] ) ? strtoupper( $payment_data['currency'] ) : '';

                        $status = isset( $payment_data['status'] ) ? $payment_data['status'] : '';

                        $download_url = $this->get_download_url( $payment_id );
                        ?>
                        <tr>
                            <td><?php echo esc_html( $date ); ?></td>
                            <td><?php echo esc_html( $plan_name ); ?></td>
                            <td>
                                <?php
                                if ( '' !== $raw_amount ) {
                                    echo esc_html( number_format_i18n( $amount, 2 ) . ( $currency ? ' ' . $currency : '' ) );
                                }
                                ?>
                            </td>
                            <td><?php echo esc_html( $status ); ?></td>
                            <td>
                                <?php if ( $download_url ) : ?>
                                    <a class="button wpcf7pdf-mps-invoices__download" href="<?php echo esc_url( $download_url ); ?>">
                                        <?php esc_html_e( 'Download invoice', 'send-pdf-for-contact-form-7' ); ?>
                                    </a>
                                <?php else : ?>
                                    <?php esc_html_e( 'Not available', 'send-pdf-for-contact-form-7' ); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php

        return ob_get_clean();
    }

    /**
     * Fetches payments for the current member.
     *
     * @param int    $user_id User identifier.
     * @param string $status  Payment status filter.
     *
     * @return array[] Array of associative arrays containing the payment object under the `object` key.
     */
    protected function get_user_payments( $user_id, $status = '' ) {
        $args = array(
            'user_id' => absint( $user_id ),
            'order'   => 'DESC',
        );

        if ( ! empty( $status ) ) {
            $args['status'] = $status;
        }

        $payments = array();

        if ( function_exists( 'pms_get_payments' ) ) {
            $payments = pms_get_payments( $args );
        } elseif ( class_exists( 'PMS_Payment' ) && method_exists( 'PMS_Payment', 'get_payments' ) ) {
            $payments = PMS_Payment::get_payments( $args );
        }

        if ( empty( $payments ) || ! is_array( $payments ) ) {
            return array();
        }

        $items = array();

        foreach ( $payments as $payment ) {
            if ( ! empty( $payment ) ) {
                $items[] = array(
                    'object' => $payment,
                );
            }
        }

        return $items;
    }

    /**
     * Builds an associative array with all relevant data for the template.
     *
     * @param object $payment Payment object.
     *
     * @return array
     */
    protected function build_context( $payment ) {
        $payment_data = $this->object_to_array( $payment );

        $subscription = array();
        if ( ! empty( $payment_data['subscription_id'] ) && function_exists( 'pms_get_subscription' ) ) {
            $subscription_object = pms_get_subscription( $payment_data['subscription_id'] );
            $subscription        = $this->object_to_array( $subscription_object );
        }

        if ( empty( $subscription ) && ! empty( $payment_data['subscription_id'] ) && class_exists( 'PMS_Member_Subscription' ) ) {
            $subscription_object = new PMS_Member_Subscription( $payment_data['subscription_id'] );
            $subscription        = $this->object_to_array( $subscription_object );
        }

        $plan = array();
        if ( ! empty( $subscription['subscription_plan_id'] ) && function_exists( 'pms_get_subscription_plan' ) ) {
            $plan_object = pms_get_subscription_plan( $subscription['subscription_plan_id'] );
            $plan        = $this->object_to_array( $plan_object );
        }

        $user = null;
        if ( ! empty( $payment_data['user_id'] ) ) {
            $user = get_userdata( (int) $payment_data['user_id'] );
        }

        return array(
            'payment'      => $payment_data,
            'subscription' => $subscription,
            'plan'         => $plan,
            'user'         => $user,
        );
    }

    /**
     * Converts any object/array into a sanitised associative array.
     *
     * @param mixed $data Raw data to sanitise.
     *
     * @return array
     */
    protected function object_to_array( $data ) {
        if ( empty( $data ) ) {
            return array();
        }

        $array = json_decode( wp_json_encode( $data ), true );

        if ( ! is_array( $array ) ) {
            return array();
        }

        foreach ( $array as $key => $value ) {
            if ( is_array( $value ) ) {
                $array[ $key ] = $this->object_to_array( $value );
            } elseif ( is_scalar( $value ) ) {
                $array[ $key ] = wp_strip_all_tags( (string) $value );
            } else {
                unset( $array[ $key ] );
            }
        }

        return $array;
    }

    /**
     * Generates the download URL for a specific payment.
     *
     * @param int $payment_id Payment identifier.
     *
     * @return string
     */
    protected function get_download_url( $payment_id ) {
        if ( empty( $payment_id ) ) {
            return '';
        }

        $args = array(
            'wpcf7pdf_mps_invoice' => 1,
            'payment_id'           => absint( $payment_id ),
        );

        $url = add_query_arg( $args, home_url( '/' ) );

        /**
         * Filters the download URL before the nonce is appended.
         *
         * @param string $url        Generated download URL.
         * @param int    $payment_id Payment identifier.
         */
        $url = apply_filters( 'wpcf7pdf_mps_download_url', $url, $payment_id );

        return wp_nonce_url( $url, $this->get_nonce_action( $payment_id ) );
    }

    /**
     * Handles the invoice download request.
     */
    public function maybe_output_invoice() {
        if ( ! isset( $_GET['wpcf7pdf_mps_invoice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked below.
            return;
        }

        $payment_id = isset( $_GET['payment_id'] ) ? absint( $_GET['payment_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if ( empty( $payment_id ) ) {
            wp_die( esc_html__( 'Invalid payment identifier.', 'send-pdf-for-contact-form-7' ) );
        }

        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'You must be logged in to download this invoice.', 'send-pdf-for-contact-form-7' ) );
        }

        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), $this->get_nonce_action( $payment_id ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            wp_die( esc_html__( 'Security check failed. Please try again.', 'send-pdf-for-contact-form-7' ) );
        }

        $payment = $this->get_payment_object( $payment_id );

        if ( empty( $payment ) ) {
            wp_die( esc_html__( 'Payment not found.', 'send-pdf-for-contact-form-7' ) );
        }

        $payment_data = $this->object_to_array( $payment );

        if ( empty( $payment_data['user_id'] ) || (int) $payment_data['user_id'] !== get_current_user_id() ) {
            wp_die( esc_html__( 'You are not allowed to access this invoice.', 'send-pdf-for-contact-form-7' ) );
        }

        $context = $this->build_context( $payment );
        $context = apply_filters( 'wpcf7pdf_mps_context', $context, $payment );

        $html = $this->get_template_html( $context );

        if ( empty( $html ) ) {
            wp_die( esc_html__( 'Unable to render the invoice template.', 'send-pdf-for-contact-form-7' ) );
        }

        $filename = $this->get_invoice_filename( $context );
        $content  = $this->generate_pdf_content( $html, $context );

        if ( empty( $content ) ) {
            wp_die( esc_html__( 'Unable to generate the invoice PDF.', 'send-pdf-for-contact-form-7' ) );
        }

        /**
         * Fires once the PDF has been generated.
         *
         * @param string $content Binary PDF content.
         * @param array  $context Contextual data used to render the PDF.
         */
        do_action( 'wpcf7pdf_mps_pdf_created', $content, $context );

        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( $content ) );

        echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF output.
        exit;
    }

    /**
     * Attempts to load the payment using the helper functions/classes provided by Paid Member Subscriptions.
     *
     * @param int|object $payment_id Payment identifier or object.
     *
     * @return object|null
     */
    protected function get_payment_object( $payment_id ) {
        if ( empty( $payment_id ) ) {
            return null;
        }

        if ( is_object( $payment_id ) ) {
            return $payment_id;
        }

        if ( function_exists( 'pms_get_payment' ) ) {
            $payment = pms_get_payment( $payment_id );

            if ( ! empty( $payment ) ) {
                return $payment;
            }
        }

        if ( class_exists( 'PMS_Payment' ) ) {
            $payment = new PMS_Payment( $payment_id );

            if ( ! empty( $payment ) && ! empty( $payment->id ) ) {
                return $payment;
            }
        }

        return null;
    }

    /**
     * Loads the HTML template used to create the PDF.
     *
     * @param array $context Template context.
     *
     * @return string
     */
    protected function get_template_html( array $context ) {
        $template = apply_filters(
            'wpcf7pdf_mps_template_path',
            WPCF7PDF_DIR . 'views/member-paid-subscriptions-pdf.php',
            $context
        );

        $html = '';

        if ( ! empty( $template ) && file_exists( $template ) && is_readable( $template ) ) {
            ob_start();
            $payment      = isset( $context['payment'] ) ? $context['payment'] : array();
            $subscription = isset( $context['subscription'] ) ? $context['subscription'] : array();
            $plan         = isset( $context['plan'] ) ? $context['plan'] : array();
            $user         = isset( $context['user'] ) ? $context['user'] : null;
            include $template;
            $html = ob_get_clean();
        }

        if ( empty( $html ) ) {
            $html = $this->get_fallback_html( $context );
        }

        return apply_filters( 'wpcf7pdf_mps_pdf_html', $html, $context );
    }

    /**
     * Fallback HTML used when the template cannot be loaded.
     *
     * @param array $context Template context.
     *
     * @return string
     */
    protected function get_fallback_html( array $context ) {
        $payment      = isset( $context['payment'] ) ? $context['payment'] : array();
        $subscription = isset( $context['subscription'] ) ? $context['subscription'] : array();
        $plan         = isset( $context['plan'] ) ? $context['plan'] : array();
        $user         = isset( $context['user'] ) && $context['user'] instanceof WP_User ? $context['user'] : null;

        $member_name  = $user ? $user->display_name : __( 'Unknown member', 'send-pdf-for-contact-form-7' );
        $member_email = $user ? $user->user_email : '';
        $plan_name    = isset( $plan['name'] ) ? $plan['name'] : __( 'Subscription plan', 'send-pdf-for-contact-form-7' );
        $amount       = isset( $payment['amount'] ) ? $payment['amount'] : '';
        $currency     = isset( $payment['currency'] ) ? $payment['currency'] : '';
        $payment_id   = isset( $payment['id'] ) ? $payment['id'] : ( isset( $payment['payment_id'] ) ? $payment['payment_id'] : '' );

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

        ob_start();
        ?>
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
                            <th><?php esc_html_e( 'Amount', 'send-pdf-for-contact-form-7' ); ?></th>
                            <td>
                                <?php
                                if ( $amount !== '' ) {
                                    $formatted_amount = number_format_i18n( (float) $amount, 2 );
                                    echo esc_html( $formatted_amount . ' ' . strtoupper( $currency ) );
                                }
                                ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Status', 'send-pdf-for-contact-form-7' ); ?></th>
                            <td><?php echo isset( $payment['status'] ) ? esc_html( $payment['status'] ) : ''; ?></td>
                        </tr>
                    </table>
                </div>
            </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Generates the PDF content.
     *
     * @param string $html    HTML to render.
     * @param array  $context Contextual data.
     *
     * @return string
     */
    protected function generate_pdf_content( $html, array $context ) {
        if ( empty( $html ) ) {
            return '';
        }

        require_once WPCF7PDF_DIR . 'mpdf/vendor/autoload.php';

        $temp_dir = get_option( 'wpcf7pdf_path_temp', '' );

        $config = array(
            'mode'          => 'utf-8',
            'format'        => 'A4',
            'margin_top'    => 30,
            'margin_bottom' => 30,
            'margin_left'   => 15,
            'margin_right'  => 15,
        );

        if ( ! empty( $temp_dir ) ) {
            $config['tempDir'] = $temp_dir;
        }

        /**
         * Filters the mPDF configuration used for generating the subscription PDF.
         *
         * @param array $config  Default configuration array passed to the Mpdf constructor.
         * @param array $context Contextual data used to render the PDF.
         */
        $config = apply_filters( 'wpcf7pdf_mps_mpdf_config', $config, $context );

        try {
            $mpdf = new \Mpdf\Mpdf( $config );
            $mpdf->WriteHTML( $html );

            /**
             * Fires before the PDF content is returned.
             *
             * @param \Mpdf\Mpdf $mpdf    Mpdf instance.
             * @param array       $context Contextual data used to render the PDF.
             */
            do_action( 'wpcf7pdf_mps_before_output', $mpdf, $context );

            return $mpdf->Output( '', \Mpdf\Output\Destination::STRING_RETURN );
        } catch ( \Mpdf\MpdfException $exception ) {
            do_action( 'wpcf7pdf_mps_pdf_error', $exception, $context );
        }

        return '';
    }

    /**
     * Computes the filename used when downloading the invoice.
     *
     * @param array $context Contextual data.
     *
     * @return string
     */
    protected function get_invoice_filename( array $context ) {
        $payment   = isset( $context['payment'] ) ? $context['payment'] : array();
        $reference = isset( $payment['id'] ) ? $payment['id'] : ( isset( $payment['payment_id'] ) ? $payment['payment_id'] : uniqid( 'mps_', true ) );

        $filename = apply_filters( 'wpcf7pdf_mps_filename', sprintf( 'subscription-%s.pdf', $reference ), $context );

        return sanitize_file_name( $filename );
    }

    /**
     * Builds the nonce action used when downloading the PDF.
     *
     * @param int $payment_id Payment identifier.
     *
     * @return string
     */
    protected function get_nonce_action( $payment_id ) {
        return 'wpcf7pdf_mps_invoice_' . absint( $payment_id );
    }
}

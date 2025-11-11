<?php


use SolidGate\API\Api;


if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

if ( ! class_exists( 'WC_Solid_Gateway' ) ) {

    class WC_Solid_Gateway extends WC_Payment_Gateway {

        /**
         * Class constructor, more about it in Step 3
         */
        public function __construct() {

            $this->id = 'solid'; // payment gateway plugin ID
            $this->icon = ''; // URL of the icon that will be displayed on checkout page near your gateway name
            $this->has_fields = false; // in case you need a custom credit card form
            $this->method_title = 'Visa/Mastercard';
            $this->method_description = 'Visa/Mastercard'; // will be displayed on the options page
            //$this->order_button_text = 'Pay via Solid';
            $this->supports = array(
                'products',
                'tokenization',
                'refunds'
            );
            $this->hooks = new WC_Solid_Subscribe_Webhook_Handler();
            // Method with all the options fields
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();

            $this->title = $this->get_option( 'title' );
            $this->google_pay_merchant_id = $this->get_option( 'google_pay_merchant_id' );

            $this->logging = $this->get_option( 'logging' );
            $this->description = $this->get_option( 'description' );
            $this->payment_public_name = $this->get_option( 'payment_public_name' );
            $this->payment_methods = $this->get_option( 'payment_methods' );
            $this->enabled = $this->get_option( 'enabled' );

            $this->private_key = $this->get_option( 'private_key' );
            $this->integration_type =  $this->get_option( 'integration_type' );
            $this->public_key = $this->get_option( 'public_key' );

            $this->webhook_private_key = $this->get_option( 'webhook_private_key' );
            $this->webhook_public_key = $this->get_option( 'webhook_public_key' );

            $this->api = new Api($this->public_key, $this->private_key);
            if ('form' == $this->integration_type) {
                // $this->init_scripts();
            }

            // This action hook saves the settings
            //add_action( 'woocommerce_receipt_'.$this->id, array(&$this, 'receipt_page'));

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

            add_action( 'woocommerce_api_'.$this->id.'_success' , array( $this, 'solid_order_success_callback' ) );
            add_action( 'woocommerce_api_'.$this->id.'_hook', [ $this, 'check_for_webhook' ] );
            add_action( 'woocommerce_api_'.$this->id.'_refund' , array( $this, 'solid_wh_refund_callback' ) );
            add_action( 'woocommerce_api_'.$this->id.'_failture' , array( $this, 'solid_order_failture_callback' ) );
        }
        /**
         * Init required js and css assets
         */
        protected function init_scripts() {
            add_action( 'wp_enqueue_scripts', array( $this, 'wc_solid_enqueue_scripts' ) );
        }
        /**
         * Add script to load card form
         */
        public function wc_solid_enqueue_scripts() {
            wp_register_style( 'solid-custom-style', WC_SOLID_PLUGIN_URL . 'assets/css/style.css', array(), WOOCOMMERCE_GATEWAY_SOLID_SUBSCRIBE_VERSION );
            wp_enqueue_style( 'solid-custom-style' );
            wp_enqueue_style( 'jquery-modal-style', 'https://cdnjs.cloudflare.com/ajax/libs/jquery-modal/0.9.1/jquery.modal.min.css');
            wp_enqueue_script( 'jquery' );
            wp_enqueue_script(
                'solid-form-script',
                'https://cdn.solidgate.com/js/solid-form.js',
                array(),
                null,
                true
            );
            wp_enqueue_script(
                'jquery-modal',
                'https://cdnjs.cloudflare.com/ajax/libs/jquery-modal/0.9.1/jquery.modal.min.js',
                array('jquery'),
                '0.9.1',
                true
            );
            wp_enqueue_script(
                'solid-woocommerce',
                WC_SOLID_PLUGIN_URL . 'assets/js/solid.js',
                array('jquery', 'solid-form-script'),
                WOOCOMMERCE_GATEWAY_SOLID_SUBSCRIBE_VERSION,
                true
            );
        }
        function validate_signature(string $jsonString): string {
            return base64_encode (
                hash_hmac('sha512',
                    $this->webhook_public_key . $jsonString . $this->webhook_public_key,
                    $this->webhook_private_key)
            );
        }
        /**
         * Plugin options, we deal with it in Step 3 too
         */
        public function init_form_fields(){
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Enable Solid Gateway',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'logging' => array(
                    'title'       => 'Logging',
                    'label'       => 'Enable Logging',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'integration_type' => array(
                    'title'             => 'Integration type',
                    'type'              => 'select',
                    'default'           => 'page',
                    'options'           => array('form' => 'Integrated form', 'page' => 'Payment page')
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default'     => 'Visa/Mastercard',
                    'desc_tip'    => true,
                ),
                'payment_methods' => array(
                    'title'             => 'Custom payment methods',
                    'type'              => 'multiselect',
                    'class'             => 'wc-enhanced-select',
                    'css'               => 'width: 400px;',
                    'default'           => '',
                    'options'           => array('paypal' => 'PayPal'),
                    'desc_tip'          => true,
                    'custom_attributes' => array(
                        'data-placeholder' => __( 'Select payment methods', 'woocommerce' ),
                    )
                ),
                "google_pay_merchant_id" => array(
                    'title'       => 'Google Pay merchant ID',
                    'type'        => 'text',
                    'description' => 'Type here your google_pay_merchant_id to enable google pay button',
                    'desc_tip'    => true,
                ),
                'payment_public_name' => array(
                    'title'       => 'Merchant',
                    'type'        => 'text',
                    'default'     => 'Merchant',
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default'     => 'Visa/Mastercard.',
                ),
                'public_key' => array(
                    'title'       => 'Public Key',
                    'type'        => 'text'
                ),
                'private_key' => array(
                    'title'       => 'Private Key',
                    'type'        => 'password'
                ),
                'webhook_public_key' => array(
                    'title'       => 'Webhook Public Key',
                    'type'        => 'text'
                ),
                'webhook_private_key' => array(
                    'title'       => 'Webhook Private Key',
                    'type'        => 'password'
                )
            );
        }

        public function check_for_webhook() {
            if ( ! isset( $_SERVER['REQUEST_METHOD'] )
                || ( 'POST' !== $_SERVER['REQUEST_METHOD'] )
                || ! isset( $_GET['wc-api'] )
                || ( 'solid_hook' !== $_GET['wc-api'] )
            ) {
                return;
            }

            $request_body    = file_get_contents( 'php://input' );
            $request_headers = array_change_key_case( $this->hooks->get_request_headers(), CASE_UPPER );


            if ($request_headers['SIGNATURE'] == $this->validate_signature($request_body)) {
                WC_Solid_Subscribe_Logger::debug( 'Incoming webhook: ' . print_r($_GET['type'], true ) ."\n". print_r( $request_headers, true )."\n". print_r($request_body,true));
                $this->hooks->process_webhook($_GET['type'], $request_body);
                status_header( 200 );
            } else {
                WC_Solid_Subscribe_Logger::debug( 'Incoming webhook failed validation: ' . print_r( $request_body, true ) );

                status_header( 204 );
            }
            exit;

        }
        /**
         * Process refunds for WC 2.2+
         *
         * @param  int        $order_id The order ID.
         * @param  float|null $amount The amount to refund. Default null.
         * @param  string     $reason The reason for the refund. Default null.
         * @return bool|WP_Error
         */
        public function process_refund( $order_id, $amount = null, $reason = null ) {
            $order = wc_get_order( $order_id );
            if ( ! is_a( $order, 'WC_Order' ) ) {
                return new WP_Error( 'solid_refund_error', __( 'Order not valid', 'wc-solid' ) );
            }

            $transction_id = get_post_meta( $order->get_id(), '_uniq_order_id', true );

            if ( ! $transction_id || empty( $transction_id ) ) {
                return new WP_Error( 'solid_refund_error', __( 'No valid Order ID found', 'wc-solid' ) );
            }

            if ( is_null( $amount ) || $amount <= 0 ) {
                return new WP_Error( 'solid_refund_error', __( 'Amount not valid', 'wc-solid' ) );
            }

            if ( is_null( $reason ) || '' === $reason ) {
                $reason = sprintf( __( 'Refund for Order # %s', 'wc-solid' ), $order->get_order_number() );
            }

            try {
                $response = $this->api->refund([
                    'order_id' => $transction_id,
                    'amount' => intval( $amount * 100 ) ,
                    'refund_reason_code' => '0021'
                ]);

                WC_Solid_Subscribe_Logger::debug( 'Refund response: ' . print_r( $response, true ) );

                if( !is_wp_error( $response ) ) {
                    $body = json_decode( $response['body'], true );
                    if ( $body['order'] ) {
                        return true;
                    } else {
                        return new WP_Error( 'solid_refund_error', 'Refunding failed' );
                    }

                } else {
                    return new WP_Error( 'solid_refund_error', 'Refunding failed' );
                }
            } catch ( Exception $e ) {
                return new WP_Error( 'solid_refund_error', $e->getMessage() );
            }
        }

        public function payment_fields() {
            $user        = wp_get_current_user();
            $description = $this->get_description();
            echo '<div class="status-box">';
            echo '<div id="solid-checkout-modal" class="modal"><div id="solid-payment-form-container"></div></div>';
            if ($description) {
                echo $description;
            }
            echo "</div>";
        }
        public function verify_nonce($plugin_id, $nonce_id = '') {
            $nonce = (isset($_REQUEST['_wpnonce']) ? sanitize_text_field($_REQUEST['_wpnonce']) : '');
            $nonce = (is_array($nonce) ? $nonce[0] : $nonce);
            $nonce_id = ($nonce_id == "" ? $plugin_id : $nonce_id);
            if(!(wp_verify_nonce($nonce, $nonce_id))) {
                return false;
            } else {
                return true;
            }
        }

        public function get_solid_order_body($order_id) {
            $order = wc_get_order( $order_id );
            $uniq_order_id = get_post_meta( $order_id, '_uniq_order_id', true );
            WC_Solid_Subscribe_Logger::debug( 'used $uniq_order_id:' . print_r( $uniq_order_id , true ));
            $items_str = '';
            $order_description = '';
            foreach ($order->get_items() as $item_id => $item) {
                $items_str = $items_str. esc_html($item->get_name()).', ';
                $order_description = $order_description . esc_html($item->get_name()).' ( '.$item->get_quantity().' ); ';
            }
            return [
                'order_id'  => $uniq_order_id,
                'currency' => $order->get_currency(),
                'amount' => round($order->get_total() * 100),
                'order_description' => $order_description,
                'website' => get_home_url(),
                'order_items' => $items_str,
                "google_pay_merchant_id" => $this->google_pay_merchant_id,
                'type' => 'auth',
                'order_number' => $order_id,
                'settle_interval' => 0,
                'customer_email' => $order->get_billing_email(),
                'customer_first_name' => $order->get_billing_first_name(),
                'customer_last_name' => $order->get_billing_last_name(),
//                'ip_address' => '8.8.8.8',
                //'success_url'=> home_url().'/?wc-api=solid_success'.'&order_id='.$order_id.'&_wpnonce='.wp_create_nonce('s_checkout_nonce'),
                //'fail_url' => home_url().'/?wc-api=solid_failture'.'&_wpnonce='.wp_create_nonce('s_checkout_nonce').'&order_id='.$order_id
            ];
        }

        /*
         * We're processing the payments here
         */
        public function process_payment( $order_id ) {

            global $woocommerce;


            // we need it to get any order detailes
            $order = wc_get_order( $order_id );

            $order_title = 'Your order';

            $uniq_order_id = $order->get_id().'_'.time();

            $order->add_order_note( 'Payment was started (Order ID: ' . $uniq_order_id . ').' );

            // used for indentify order in solid
            update_post_meta( $order->get_id(), '_uniq_order_id', $uniq_order_id );

            // used only for display on order page
            update_post_meta( $order->get_id(), 'uniq_order_id', $uniq_order_id );


            if ('form' == $this->integration_type) {
                $order_body = $this->get_solid_order_body($order_id);
                $response = $this->api->formMerchantData($order_body)->toArray();
                return [
                    'result' => 'success',
                    "form" => $response,
                    "redirects" => [
                        'success_url'=> home_url().'/?wc-api=solid_subscribe_success'.'&order_id='.$order_id.'&_wpnonce='.wp_create_nonce('s_checkout_nonce'),
                        'fail_url'=> home_url().'/?wc-api=solid_subscribe_failture'.'&order_id='.$order_id.'&_wpnonce='.wp_create_nonce('s_checkout_nonce'),
                    ]
                    //'redirect' => $order->get_checkout_payment_url(true)//add_query_arg('key', $order->get_order_key(), $order->get_checkout_payment_url(true));//$order->get_checkout_payment_url(true)
                ];

            } else {

                $order_body = $this->get_solid_order_body($order_id);
                $order_body['success_url'] =  home_url().'/?wc-api=solid_success'.'&order_id='.$order_id.'&_wpnonce='.wp_create_nonce('s_checkout_nonce');
                $order_body['fail_url'] =  home_url().'/?wc-api=solid_failture'.'&_wpnonce='.wp_create_nonce('s_checkout_nonce').'&order_id='.$order_id;
                $page_customization = [
                    'public_name' => $this->payment_public_name,
                    'order_title' => $order_title,
                    'order_description' => $order_body['order_description']
                ];

                // add custom methods parameters to page customization
                if (!empty($this->payment_methods)) {
                    $page_customization['payment_methods'] = $this->payment_methods;
                }

                $request_body = json_encode([
                    'order' => $order_body,
                    'page_customization'=> $page_customization
                ]);

                $signature = $this->api->generateSignature($request_body);

                /*
                 * Array with parameters for API interaction
                 */
                $args = [
                    'headers' => [
                        'merchant' => $this->public_key,
                        'Signature' => $signature,
                        'Content-Type' => 'application/json'
                    ],
                    'body' => $request_body
                ];


                $response = wp_remote_post( 'https://payment-page.solidgate.com/api/v1/init', $args );


                if( !is_wp_error( $response ) ) {
                    $response_body = json_decode( $response['body'], true );
                    if ( $response_body['url'] ) {
                        return array(
                            'result' => 'success',
                            'redirect' => $response_body['url']
                        );

                    } elseif ($response_body['error']['code']) {
                        wc_add_notice(  'Connection error. [' . $response_body['error']['code'] .']', 'error' );

                    } else {
                        wc_add_notice(  'Please try again.' , 'error' );
                    }

                    WC_Solid_Subscribe_Logger::debug( 'Init form url error: \n\n Response:' . print_r( $response_body, true ) . '\n\n Request:'. print_r( $request_body, true ) );
                    return;
                } else {
                    wc_add_notice( 'Connection error.', 'error' );
                    return;
                }

            }

        }


        public function solid_order_success_callback() {
            if(!$this->verify_nonce($this->id, 's_checkout_nonce'))
            {
                die('Access Denied');
            }

            $order = wc_get_order( intval($_GET['order_id'] ));
            $order->payment_complete();
            $order->reduce_order_stock();
            $uniq_order_id = get_post_meta( $order->get_id(), '_uniq_order_id', true );

            $order->add_order_note(sprintf( __( 'Payment has been successfully completed (Order ID: %s)', 'wc-solid' ), $uniq_order_id ));

            wc_add_notice(__('Payment successful. Thank you for your payment.'), 'success');
            wp_safe_redirect($this->get_return_url($order));
            exit;

        }

        public function solid_order_failture_callback(){
            if(!$this->verify_nonce($this->id, 's_checkout_nonce'))
            {
                die('Access Denied');
            }

            $order = wc_get_order( intval($_GET['order_id'] ));
            $uniq_order_id = get_post_meta( $order->get_id(), '_uniq_order_id', true );

            $order->update_status( 'failed', sprintf( __( 'Payment failed (Order ID: %s)', 'wc-solid' ), $uniq_order_id ) );

            $errorMessage = __('You have cancelled. Please try to process your order again.', 'wc-solid');

            try {
                $response = $order_satus = $this->api->status([
                    'order_id'  => $uniq_order_id
                ]);

                $body = json_decode( $response, true );
                if ( $body['error']['code'] ) {
                    $order->add_order_note( 'Customer attempted to pay, but the payment failed or got declined. (Error code: ' . $body['error']['code'] . ')' );
                    $errorMessage = $this->error_code_lookup( $body['error']['code']);
                }
            } catch ( Exception $e ) {
                $order->add_order_note( 'Customer attempted to pay, but the payment failed or got declined. (Error: ' . $e->getMessage() . ')' );
                WC_Solid_Subscribe_Logger::debug( 'Status Exception: ' . print_r( $e, true ) );
            }
            wc_add_notice($errorMessage, 'error');
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        public function error_code_lookup( $code) {
            $messages = array(
                '3.02' => 'Not enough funds for payment on the card. Please try to use another card or choose another payment method.',
                '2.06' => 'CVV code is wrong. CVV code is the last three digits on the back of the card. Please, try again.',
                '2.08' => 'Card number is wrong. Enter the card number exactly as it is written on your bank card.',
                '2.09' => 'This card has expired. Try using another card.',
                '3.06' => 'Unfortunately, debit cards do not support online payments. Try using a credit card or choose another payment method. ',
                '4.09' => 'Your payment was declined due to technical error. Please contact support team.',
            );
            return isset( $messages[ $code ] ) ? $messages[ $code ] : 'Card is blocked for the Internet payments. Contact support of your bank to unblock your card or use another card.';
        }

    }
}


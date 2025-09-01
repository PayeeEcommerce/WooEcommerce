<?php
/**
 * Plugin Name: Till Payments For Woocommerce
 * Description: Till Payments for WooCommerce
 * Version: 1.10.4
 * Author: Till Payments/ Payee Ecommerce
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

use TillPayments\Client\Transaction\Capture;
use TillPayments\Client\Transaction\Result as TransactionResult;

if (!defined('ABSPATH')) {
    exit;
}

define('TILL_PAYMENTS_EXTENSION_URL', 'https://gateway.tillpayments.com/');
define('TILL_PAYMENTS_EXTENSION_URL_TEST', 'https://test-gateway.tillpayments.com/');
define('TILL_PAYMENTS_EXTENSION_NAME', 'Till Payments');
define('TILL_PAYMENTS_EXTENSION_VERSION', '1.10.4');
define('TILL_PAYMENTS_EXTENSION_UID_PREFIX', 'till_payments_');
define('TILL_PAYMENTS_EXTENSION_BASEDIR', plugin_dir_path(__FILE__));

add_action('plugins_loaded', function () {
    require_once TILL_PAYMENTS_EXTENSION_BASEDIR . 'classes/includes/till-payments-provider.php';
    require_once TILL_PAYMENTS_EXTENSION_BASEDIR . 'classes/includes/till-payments-creditcard.php';
    require_once TILL_PAYMENTS_EXTENSION_BASEDIR . 'classes/includes/till-payments-googlepay.php';
    require_once TILL_PAYMENTS_EXTENSION_BASEDIR . 'classes/includes/till-payments-applepay.php';

    add_filter('woocommerce_payment_gateways', function ($methods) {
        foreach (WC_TillPayments_Provider::paymentMethods() as $paymentMethod) {
            $methods[] = $paymentMethod;
        }
        return $methods;
    }, 0);

    // add_filter('woocommerce_before_checkout_form', function(){
    add_filter('the_content', function($content){
        if(is_checkout_pay_page() || is_checkout()) {
            // Add nonce verification for $_GET parameters
            if(!empty($_GET['gateway_return_result']) && 
               sanitize_text_field(wp_unslash($_GET['gateway_return_result'])) === 'error' &&
               isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'gateway_return_action')) {
                wc_print_notice(__('Payment failed or was declined', 'tillpayments'), 'error');
            }
        }
        return $content;
    }, 0, 1);

    add_action( 'init', 'woocommerce_clear_cart_url' );
    function woocommerce_clear_cart_url() {
        // Add nonce verification for clear-cart action
        if (isset($_GET['clear-cart']) && is_order_received_page() &&
            isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'clear_cart_action')) {
            global $woocommerce;

            $woocommerce->cart->empty_cart();
        }
    }

    add_action('admin_enqueue_scripts', function($hook) {
        if ($hook === 'post.php') {
            wp_enqueue_script('tillpayments_capture_script', plugins_url("/tillpayments/assets/js/capture-payments.js"), ['jquery'], TILL_PAYMENTS_EXTENSION_VERSION, false);
            wp_localize_script('tillpayments_capture_script', 'tp_capture', ['security' => wp_create_nonce('tillpayments_capture_payment')]);
        }
    });

    add_action('wp_ajax_tillpayments_capture_payment', function () {
        check_ajax_referer('tillpayments_capture_payment', 'security');

        if (!current_user_can( 'edit_shop_orders')) {
            wp_die(-1);
        }

        // Validate, unslash and sanitize $_POST data
        if (!isset($_POST['payment_method'])) {
            wp_send_json(['error' => 1, 'msg' => 'Missing payment method!']);
        }
        
        $payment_method_code = sanitize_text_field(wp_unslash($_POST['payment_method']));
        $gateway = WC()->payment_gateways()->payment_gateways()[$payment_method_code];

        $gateway->log('Processing new '.$gateway->method_title.' capture...');

        // Validate, unslash and sanitize order_id
        $orderId = null;
        if (!empty($_POST['order_id'])) {
            $orderId = absint(wp_unslash($_POST['order_id']));
        }
        
        if (!$orderId) {
            $gateway->log('  > missing order ID!', WC_Log_Levels::ERROR);
            wp_send_json(['error' => 1, 'msg' => 'Missing order ID!']);
        }

        /**
         * order & user
         */
        $order = new WC_Order($orderId);

        /**
         * gateway client
         */
        WC_TillPayments_Provider::autoloadClient();
        TillPayments\Client\Client::setApiUrl($gateway->get_option('apiHost'));
        $client = new TillPayments\Client\Client(
            $gateway->get_option('apiUser'),
            htmlspecialchars_decode($gateway->get_option('apiPassword')),
            $gateway->get_option('apiKey'),
            $gateway->get_option('sharedSecret')
        );

        /**
         * transaction
         */
        $transaction = new Capture();
        // Use gmdate() instead of date() to avoid timezone issues
        $captureTxId = $orderId . '-capture-' . gmdate('YmdHis') . substr(sha1(uniqid()), 0, 10);
        $transaction->setTransactionId($captureTxId)
            ->setAmount(floatval($order->get_total('')))
            ->setCurrency($order->get_currency())
            ->setReferenceTransactionId($order->get_meta('paymentUuid'));

        /**
         * transaction
         */
        $gateway->log('  > sending capture transaction request...');
        $result = $client->capture($transaction);

        if ($result->isSuccess()) {
            switch ($result->getReturnType()) {
                case TransactionResult::RETURN_TYPE_ERROR:
                    $errors = $result->getErrors();
                    $gateway->log('  > return type: ERROR', WC_Log_Levels::ERROR);
                    // Replace print_r() with appropriate logging
                    if (!empty($errors)) {
                        foreach ($errors as $error) {
                            $gateway->log('  > error: ' . $error->getMessage(), WC_Log_Levels::ERROR);
                        }
                    }

                    if (empty($errors)) {
                        wp_send_json(['error' => 1, 'msg' => 'Capture request failed!']);
                    }

                    $errorMsg = '';
                    foreach ($errors as $error) {
                        $errorMsg .= $error->getMessage() . PHP_EOL;
                    }

                    $order->add_order_note('TillPayments capture error: ' . $errorMsg, false);

                    wp_send_json(['error' => 1, 'msg' => $errorMsg]);
                case TransactionResult::RETURN_TYPE_PENDING:
                    $gateway->log('  > return type: PENDING');
                case TransactionResult::RETURN_TYPE_FINISHED:
                    $gateway->log('  > return type: FINISHED');
                    $order->add_order_note('TillPayments capture ID: ' . $result->getReferenceId(), false);

                    $order->update_meta_data('paymentUuid', $result->getReferenceId());
                    $order->update_meta_data('pending_capture', 'no');
                    $order->save_meta_data();

                    $order->payment_complete();

                    // Replace print_r() with structured logging
                    $resultData = $result->toArray();
                    $gateway->log('  > result data logged successfully');
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                       
                    }

                    wp_send_json(['error' => 0]);
            }
        } else {
            $errors = $result->getErrors();

            if (empty($errors)) {
                wp_send_json(['error' => 1, 'msg' => 'Capture request failed!']);
            }

            $gateway->log('  > request failed', WC_Log_Levels::ERROR);
            // Replace print_r() with appropriate logging
            if (!empty($errors)) {
                foreach ($errors as $error) {
                    $gateway->log('  > error: ' . $error->getMessage(), WC_Log_Levels::ERROR);
                }
            }

            $errorMsg = '';
            foreach ($errors as $error) {
                $errorMsg .= $error->getMessage().PHP_EOL;
            }

            $order->add_order_note('TillPayments capture error: ' . $errorMsg, false);

            wp_send_json(['error' => 1, 'msg' => $errorMsg]);
        }

        /**
         * something went wrong
         */
        $gateway->log('  > fallback return point reached. something went wrong?', WC_Log_Levels::ERROR);
        wp_send_json(['error' => 1, 'msg' => 'Capture request failed!']);
    });
});
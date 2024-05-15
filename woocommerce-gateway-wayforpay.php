<?php
/*
Plugin Name: WooCommerce WayForPay Payments
Description: WayForPay Payment Gateway for WooCommerce.
Version: 1.4.8
Author: Dev team WayForPay
Author: support@wayforpay.com
Requires Plugins: woocommerce
Plugin URI: https://github.com/wayforpay/Word-Press-Woocommerce
Author URI: https://wayforpay.com/
License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

add_action('plugins_loaded', 'woocommerce_wayforpay_init', 0);
define('IMGDIR', WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/assets/img/');
load_plugin_textdomain( 'woocommerce-wayforpay-payments', false, plugin_basename(dirname(__FILE__)) . '/languages/' );

function woocommerce_wayforpay_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    if (isset($_GET['msg']) && !empty($_GET['msg'])) {
        add_action('the_content', 'showWayForPayMessage');
    }
    function showWayForPayMessage($content)
    {
        return '<div class="' . htmlentities($_GET['type']) . '">' . htmlentities(urldecode($_GET['msg'])) . '</div>' . $content;
    }

    /**
     * Gateway class
     */
    class WC_wayforpay extends WC_Payment_Gateway
    {
        protected $url = 'https://secure.wayforpay.com/pay';

        const ORDER_APPROVED = 'Approved';
        const ORDER_REFUNDED = 'Refunded';
        const ORDER_DECLINED = 'Declined';
        const SIGNATURE_SEPARATOR = ';';
        const ORDER_SEPARATOR = ":";
        const ORDER_SUFFIX = '_woo_w4p_';
        const ORDER_PREFIX  = 'wc_order';

        protected $keysForResponseSignature = array(
            'merchantAccount',
            'orderReference',
            'amount',
            'currency',
            'authCode',
            'cardPan',
            'transactionStatus',
            'reasonCode'
        );

        /** @var array */
        protected $keysForSignature = array(
            'merchantAccount',
            'merchantDomainName',
            'orderReference',
            'orderDate',
            'amount',
            'currency',
            'productName',
            'productCount',
            'productPrice'
        );


        public function __construct()
        {
            $this->id = 'wayforpay';
            $this->method_title = 'WayForPay';
            $this->method_description = __('Card payments, Apple Pay and Google Pay.', 'woocommerce-wayforpay-payments');
            $this->has_fields = false;
            $this->init_form_fields();
            $this->init_settings();
            if ($this->settings['showlogo'] == "yes") {
                $this->icon = IMGDIR . 'w4p.png';
            }
            $this->title = $this->settings['title'];
            $this->redirect_page_id = $this->settings['returnUrl'];

            $this->serviceUrl = $this->settings['returnUrl'];

            $this->merchant_id = $this->settings['merchant_account'];
            $this->secretKey = $this->settings['secret_key'];
            $this->description = $this->settings['description'];

            $this->msg['message'] = "";
            $this->msg['class'] = "";

            $script = 'var wfp_title = "' . $this->settings['title'] . '";';
            $script .= 'var wfp_description = "' . $this->settings['description'] . '";';
            wp_add_inline_script('jquery', $script);
            if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
                /* 2.0.0 */
                add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'check_wayforpay_response'));
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            } else {
                /* 1.6.6 */
                add_action('init', array(&$this, 'check_wayforpay_response'));
                add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
            }

            add_action('woocommerce_receipt_wayforpay', array(&$this, 'receipt_page'));
            add_filter( 'template_redirect', array(&$this, 'is_failed_payment'), 10, 1);
            add_action( 'init', array(&$this,'clear_cart_on_redirect'));
        }

        /**
         * @return void
        */
        public function clear_cart_on_redirect() {
            if (!session_id()) {
                session_start();
            }
            if (isset( $_SESSION['is_need_cart_clear'] ) && $_SESSION['is_need_cart_clear'] == 1) {
                WC()->cart->empty_cart();
                $_SESSION['is_need_cart_clear'] = 0;
            }
        }

        /**
         * @param $template
         *
         * @return mixed
         */
        public function is_failed_payment($template){
            static $called = false;
            if($called)
                return $template;

            if(isset($_GET['order-received']) && isset($_GET['key']) && str_starts_with( $_GET['key'], self::ORDER_PREFIX ) ) {
                $paymentInfo = $this->isPaymentValid($_POST);
                if($paymentInfo === true) {
                    wc_add_notice( __(
                        'Thank you for shopping with us. Your account has been charged and your transaction is successful.',
                        'woocommerce-wayforpay-payments'
                    ));
                }else{
                    $order = new WC_Order(absint($_GET['order-received']));
                    $error_message = __('A server error occurred while processing the payment. Error:', 'woocommerce-wayforpay-payments')
                    . $order->get_meta('reasonCode') . '(' . $order->get_meta('reason') . ")<br/>"
                    . '<a href="' .  $this->getUrlBySlug('checkout') . '">' . __('Return to checkout', 'woocommerce-wayforpay-payments') . '</a>';
                    wc_add_notice($error_message, 'error');
                }
                wc_print_notices();
            }
            $called = true;

            return $template;
        }

        /**
         * @param $page_slug
         *
         * @return string
         */
        private function getUrlBySlug($page_slug){
            $page = get_page_by_path($page_slug);

            return $page ? get_permalink($page->ID) : home_url();
        }

        /**
         * @return void
         */
        function init_form_fields()
        {
            $this->form_fields = array('enabled' => array('title' => __('Enable/Disable', 'woocommerce-wayforpay-payments'),
                'type' => 'checkbox',
                'label' => __('Enable WayForPay Payment Module.', 'woocommerce-wayforpay-payments'),
                'default' => 'no',
                'description' => __('Show in the Payment List as a payment option', 'woocommerce-wayforpay-payments')),
                'title' => array('title' => __('Title:', 'woocommerce-wayforpay-payments'),
                    'type' => 'text',
                    'default' => __('Internet acquiring', 'woocommerce-wayforpay-payments'),
                    'description' => __('This controls the title which the user sees during checkout.', 'woocommerce-wayforpay-payments'),
                    'desc_tip' => true),
                'description' => array('title' => __('Description:', 'woocommerce-wayforpay-payments'),
                    'type' => 'textarea',
                    'default' => __('Pay securely by Credit or Debit Card or Internet Banking through wayforpay.com service.', 'woocommerce-wayforpay-payments'),
                    'description' => __('This controls the description which the user sees during checkout.', 'woocommerce-wayforpay-payments'),
                    'desc_tip' => true),
                'merchant_account' => array('title' => __('Merchant Login', 'woocommerce-wayforpay-payments'),
                    'type' => 'text',
                    'description' => __('Given to Merchant by wayforpay.com', 'woocommerce-wayforpay-payments'),
                    'default' => 'test_merch_n1',
                    'desc_tip' => true
                ),
                'secret_key' => array('title' => __('Merchant Secret key', 'woocommerce-wayforpay-payments'),
                    'type' => 'text',
                    'description' => __('Given to Merchant by wayforpay.com', 'woocommerce-wayforpay-payments'),
                    'desc_tip' => true,
                    'default' => 'flk3409refn54t54t*FNJRET',
                ),
                'showlogo' => array('title' => __('Show Wayforpay logo for classic checkout', 'woocommerce-wayforpay-payments'),
                    'type' => 'checkbox',
                    'label' => __('Show the wayforpay.com logo in the Payment Method section for the user', 'woocommerce-wayforpay-payments'),
                    'default' => 'yes',
                    'description' => __('Tick to show wayforpay.com logo', 'woocommerce-wayforpay-payments'),
                    'desc_tip' => true),
                'returnUrl' => array('title' => __('Return URL', 'woocommerce-wayforpay-payments'),
                    'type' => 'select',
                    'options' => $this->wayforpay_get_pages(__('Select Page', 'woocommerce-wayforpay-payments')),
                    'description' => __('URL of success page', 'woocommerce-wayforpay-payments'),
                    'desc_tip' => true),
                'returnUrl_m' => array('title' => __('or specify', 'woocommerce-wayforpay-payments'),
                    'type' => 'text',
                    'description' => __('URL of success page', 'woocommerce-wayforpay-payments'),
                    'default' => '',
                    'desc_tip' => true
                ),
                'serviceUrl' => array('title' => __('Service URL', 'woocommerce-wayforpay-payments'),
                    'options' => $this->wayforpay_get_pages(__('Select Page', 'woocommerce-wayforpay-payments')),
                    'type' => 'select',
                    'description' => __('URL with result of transaction page', 'woocommerce-wayforpay-payments'),
                    'desc_tip' => true)
            );
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         **/
        public function admin_options()
        {
            echo '<h3>' . __('WayForPay.com', 'woocommerce-wayforpay-payments') . '</h3>';
            echo '<p>' . __('Payment gateway', 'woocommerce-wayforpay-payments') . '</p>';
            echo '<table class="form-table">';
            // Generate the HTML For the settings form.
            $this->generate_settings_html();
            echo '</table>';
        }

        /**
         *  There are no payment fields for techpro, but we want to show the description if set.
         **/
        function payment_fields()
        {
            if ($this->description) {
                echo wpautop(wptexturize($this->description));
            }
        }

        /**
         * Receipt Page
         **/
        function receipt_page($order)
        {
            global $woocommerce;

            echo '<p>' . __('Thank you for your order, you will now be redirected to the WayForPay payment page.', 'woocommerce-wayforpay-payments') . '</p>';
            echo $this->generate_wayforpay_form($order);

//            $woocommerce->cart->empty_cart();// no need to clear cart if payment error
        }

        /**
         * @param $options
         * @return string
         */
        public function getRequestSignature($options)
        {
            return $this->getSignature($options, $this->keysForSignature);
        }

        /**
         * @param $options
         * @return string
         */
        public function getResponseSignature($options)
        {
            return $this->getSignature($options, $this->keysForResponseSignature);
        }

        /**
         * @param $option
         * @param $keys
         * @param $hashOnly
         * @return string
         */
        public function getSignature($option, $keys, $hashOnly = false)
        {
            $hash = array();
            foreach ($keys as $dataKey) {
                if (!isset($option[$dataKey])) {
                    continue;
                }
                if (is_array($option[$dataKey])) {
                    foreach ($option[$dataKey] as $v) {
                        $hash[] = $v;
                    }
                } else {
                    $hash [] = $option[$dataKey];
                }
            }
            $hash = implode(';', $hash);
            if ($hashOnly) {
		return base64_encode($hash);
	    } else {
                return hash_hmac('md5', $hash, $this->secretKey);
	    }
        }

        /**
         * @return $this
         */
        public function fillPayForm($data)
        {
            $data['merchantAccount'] = $this->merchant_id;
            $data['merchantAuthType'] = 'simpleSignature';
            $data['merchantDomainName'] = $_SERVER['SERVER_NAME'];
            $data['merchantTransactionSecureType'] = 'AUTO';

            $data['merchantSignature'] = $this->getRequestSignature($data);
	    $data['signString'] = $this->getSignature($data, $this->keysForSignature, true);
            return $this->generateForm($data);
        }


        /**
         * Generate form with fields
         *
         * @param $data
         * @return string
         */
        protected function generateForm($data)
        {
            $form = '<form method="post" id="form_wayforpay" action="' . $this->url . '" accept-charset="utf-8">';
            foreach ($data as $k => $v) $form .= $this->printInput($k, $v);
            $button = "<img style='position:absolute; top:50%; left:47%; margin-top:-125px; margin-left:-60px;' src='' >
	<script>
		function submitWayForPayForm()
		{
			document.getElementById('form_wayforpay').submit();
		}
		setTimeout( submitWayForPayForm, 200 );
	</script>";

            return $form .
            "<input type='submit' style='display:none;' /></form>"
            . $button;
        }

        /**
         * Print inputs in form
         *
         * @param $name
         * @param $val
         * @return string
         */
        protected function printInput($name, $val)
        {
            $str = "";
            if (!is_array($val)) return '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars($val) . '">' . "\n<br />";
            foreach ($val as $v) $str .= $this->printInput($name . '[]', $v);
            return $str;
        }


        /**
         * @param $inputData
         * @return mixed|string|void
         */
        public function checkResponse($inputData)
        {
            global $wpdb;
            $ref = $inputData['orderReference'];
            $sessID = explode("_", $ref);
            $sessionId = $sessID[1];

            $sign = $this->getResponseSignature($inputData);
            if (!empty($inputData["merchantSignature"]) && $inputData["merchantSignature"] == $sign) {
                if ($inputData['transactionStatus'] == self::ORDER_APPROVED) {

                    $notes = "WayForPay : orderReference:" . $inputData['transactionStatus'] . " \n\n recToken: " . $inputData['recToken'];

                    $data = array(
                        'processed' => 3,
                        'transactid' => $ref,
                        'date' => time(),
                        'notes' => $notes
                    );


                    $where = array('transactid' => $ref);
                    $format = array('%d', '%s', '%s', '%s');
                    $wpdb->update(WPSC_TABLE_PURCHASE_LOGS, $data, $where, $format);
                    transaction_results($sessionId, false, $ref);
                    return $this->getAnswerToGateWay($inputData);
                }

            }
            return $this->getAnswerToGateWay($inputData);

        }


        /**
         * @param $data
         * @return mixed|string|void
         */
        public function getAnswerToGateWay($data)
        {
            $time = time();
            $responseToGateway = array(
                'orderReference' => $data['orderReference'],
                'status' => 'accept',
                'time' => $time
            );
            $sign = array();
            foreach ($responseToGateway as $dataKey => $dataValue) {
                $sign [] = $dataValue;
            }
            $sign = implode(';', $sign);
            $sign = hash_hmac('md5', $sign, $this->secretKey);
            $responseToGateway['signature'] = $sign;

            return json_encode($responseToGateway);
        }


        /**
         * Generate wayforpay button link
         **/
        function generate_wayforpay_form($order_id)
        {
            $order = new WC_Order($order_id);

            $orderDate = isset($order->post->post_date)? $order->post->post_date : $order->order_date;
            
            $currency = str_replace(
            	array('ГРН','uah'),
            	array('UAH','UAH'),
            	get_woocommerce_currency()
    	    );

            $wayforpay_args = array(
                'orderReference' => $order_id . self::ORDER_SUFFIX.time(),
                'orderDate' => strtotime($orderDate),
                'currency' => $currency,
                'amount' => $order->get_total(),
                'returnUrl' => $this->getResponseUrl($order),
                'serviceUrl' => $this->getCallbackUrl(),
                'language' => $this->getLanguage()
            );

            $items = $order->get_items();
	    if (
		is_array($items) &&
                !empty($items)
	    ) {
                foreach ($items as $item) {
//		    $wayforpay_args['productName'][] = esc_html($item['name']);
		    $wayforpay_args['productName'][] = $item['name'];
		    $wayforpay_args['productCount'][] = $item['qty'];
//		    $wayforpay_args['productPrice'][] = $item['line_total'];
		    $wayforpay_args['productPrice'][] = round($item['line_total']/$item['qty'],2);
		}
	    } else {
//		$wayforpay_args['productName'][] = esc_html($wayforpay_args['orderReference']);
		$wayforpay_args['productName'][] = $wayforpay_args['orderReference'];
		$wayforpay_args['productCount'][] = 1;
		$wayforpay_args['productPrice'][] = $wayforpay_args['amount'];
	    }
            $phone = $order->billing_phone;
            $phone = str_replace(array('+', ' ', '(', ')'), array('', '', '', ''), $phone);
            if (strlen($phone) == 10) {
                $phone = '38' . $phone;
            } elseif (strlen($phone) == 11) {
                $phone = '3' . $phone;
            }
            $client = array(
                "clientFirstName" => $order->billing_first_name,
                "clientLastName" => $order->billing_last_name,
                "clientAddress" => $order->billing_address_1 . ' ' . $order->billing_address_2,
                "clientCity" => $order->billing_city,
                "clientPhone" => $phone,
                "clientEmail" => $order->billing_email,
                "clientCountry" => strlen($order->billing_country) != 3 ? 'UKR' : $order->billing_country,
                "clientZipCode" => $order->billing_postcode
            );
            $wayforpay_args = array_merge($wayforpay_args, $client);

            return $this->fillPayForm($wayforpay_args);
        }

        /**
         * Process the payment and return the result
         **/
        function process_payment($order_id)
        {
            $order = new WC_Order($order_id);

            if (version_compare(WOOCOMMERCE_VERSION, '2.1.0', '>=')) {
                /* 2.1.0 */
                $checkout_payment_url = $order->get_checkout_payment_url(true);
            } else {
                /* 2.0.0 */
                $checkout_payment_url = get_permalink(get_option('woocommerce_pay_page_id'));
            }

            return array('result' => 'success',
                'redirect' => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, $checkout_payment_url)));
        }

        /**
         * @param bool $service
         * @return bool|string
         */
        private function getCallbackUrl(){
            return wc_get_endpoint_url('wc-api', strtolower(get_class($this)), get_site_url());
        }

        /**
         * @param $order
         *
         * @return false|string
         */
        public function getResponseUrl($order){
            return $this->redirect_page_id ? get_permalink($this->redirect_page_id) : $this->get_return_url($order);
        }

        private function getLanguage()
        {
            return substr(get_bloginfo('language'), 0, 2);
        }


        protected function isPaymentValid($response)
        {
            list($orderId,) = explode(self::ORDER_SUFFIX, $response['orderReference']);
            $order = new WC_Order($orderId);
            if ($order === FALSE) {
                return __('An error has occurred during payment. Please contact us to ensure your order has submitted.', 'woocommerce-wayforpay-payments');
            }

            if ($this->merchant_id != $response['merchantAccount']) {
                return __('An error has occurred during payment. Merchant data is incorrect.', 'woocommerce-wayforpay-payments');
            }

            $responseSignature = $response['merchantSignature'];


            if ($this->getResponseSignature($response) != $responseSignature) {
                die( __('An error has occurred during payment. Signature is not valid.', 'woocommerce-wayforpay-payments'));
            }

            if ($response['transactionStatus'] == self::ORDER_APPROVED) {

//                $order->update_status('processing');
                $order->update_status('completed');
                $order->payment_complete();
                $order->add_order_note( __('WayForPay payment successful.<br/>WayForPay ID: ', 'woocommerce-wayforpay-payments') . ' (' . (isset($response['orderReference'])?$response['orderReference']:'-') . ')');
                $_SESSION['is_need_cart_clear'] = 1;//clear cart after successfully payment on next init hook
                if(isset($_POST['reason']) && empty($order->get_meta('reason'))){
                    $order->add_meta_data('reason', $_POST['reason']);
                }else{
                    $order->update_meta_data('reason', $_POST['reason']);
                }
                if(isset($_POST['reasonCode']) && empty($order->get_meta('reasonCode'))){
                    $order->add_meta_data('reasonCode', absint($_POST['reasonCode']));
                }else{
                    $order->update_meta_data('reasonCode', absint($_POST['reasonCode']));
                }
                if(isset($_POST['orderReference']) && empty($order->get_meta('orderReference'))){
                    $order->add_meta_data('orderReference', $_POST['orderReference']);
                }else{
                    $order->update_meta_data('orderReference', $_POST['orderReference']);
                }
                $order->save();
                return true;
            } elseif ($response['transactionStatus'] == self::ORDER_REFUNDED) {
                $order->update_status('cancelled');
                $order->add_order_note(__('Refund payment.', 'woocommerce-wayforpay-payments'));
                return true;
            }elseif ($response['transactionStatus'] == self::ORDER_DECLINED){
                $order->update_status('failed');
                if(isset($_POST['reason']) && empty($order->get_meta('reason'))){
                    $order->add_meta_data('reason', $_POST['reason']);
                }else{
                    $order->update_meta_data('reason', $_POST['reason']);
                }
                if(isset($_POST['reasonCode']) && empty($order->get_meta('reasonCode'))){
                    $order->add_meta_data('reasonCode', absint($_POST['reasonCode']));
                }else{
                    $order->update_meta_data('reasonCode', absint($_POST['reasonCode']));
                }
                if(isset($_POST['orderReference']) && empty($order->get_meta('orderReference'))){
                    $order->add_meta_data('orderReference', $_POST['orderReference']);
                }else{
                    $order->update_meta_data('orderReference', $_POST['orderReference']);
                }
                $order->save();
                return false;
            }

            WC()->cart->empty_cart();
            return false;
        }

        /**
         * Check response on service url
         */
        function check_wayforpay_response()
        {
            $data = json_decode(file_get_contents("php://input"), true);
            $paymentInfo = $this->isPaymentValid($data);
            if ($paymentInfo === true) {
                echo $this->getAnswerToGateWay($data);

                $this->msg['message'] = __('Thank you for shopping with us. Your account has been charged and your transaction is successful.', 'woocommerce-wayforpay-payments');
                $this->msg['class'] = 'woocommerce-message';
            }
            exit;
        }

        // get all pages
        function wayforpay_get_pages($title = false, $indent = true)
        {
            $wp_pages = get_pages('sort_column=menu_order');
            $page_list = array();
            if ($title) {
                $page_list[] = $title;
            }
            foreach ($wp_pages as $page) {
                $prefix = '';
                // show indented child pages?
                if ($indent) {
                    $has_parent = $page->post_parent;
                    while ($has_parent) {
                        $prefix .= ' - ';
                        $next_page = get_page($has_parent);
                        $has_parent = $next_page->post_parent;
                    }
                }
                // add to page list array array
                $page_list[$page->ID] = $prefix . $page->post_title;
            }
            return $page_list;
        }
    }

    /**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_wayforpay_gateway($methods)
    {
        $methods[] = 'WC_wayforpay';
        return $methods;
    }

    function declare_cart_checkout_blocks_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
        }
    }

    function oawoo_register_order_approval_payment_method_type() {
        if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
            return;
        }
        require_once plugin_dir_path(__FILE__) . 'class-block.php';
        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
                $payment_method_registry->register( new My_Custom_Gateway_Blocks );
            }
        );
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_wayforpay_gateway');
    add_action('before_woocommerce_init', 'declare_cart_checkout_blocks_compatibility');
    add_action( 'woocommerce_blocks_loaded', 'oawoo_register_order_approval_payment_method_type' );
}

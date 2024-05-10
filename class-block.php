<?php

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class My_Custom_Gateway_Blocks extends AbstractPaymentMethodType{
    private $gateway;
    protected $name = 'wayforpay';// your payment gateway name

	public function initialize(){
	    $this->gateway = new WC_wayforpay();
	    $this->settings = $this->gateway->settings;
    }

    public function is_active() {
        return $this->gateway->is_available();
    }

    public function get_payment_method_script_handles(){
        wp_register_script(
            'my_custom_gateway-blocks-integration',
            plugin_dir_url(__FILE__) . 'checkout.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            null,
            true
        );
        if( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( 'my_custom_gateway-blocks-integration');
        }
        return [ 'my_custom_gateway-blocks-integration' ];
    }

    public function get_payment_method_data(){
        return [
            'title' => $this->gateway->title,
            'description' => $this->gateway->description,
        ];
    }
}

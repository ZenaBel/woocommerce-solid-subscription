<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_Payment_Token_Solid extends WC_Payment_Token
{
    protected $type = 'solid';

    protected $extra_data = [
        'subscription_id' => '',
    ];

    public function set_subscription_id( $subscription_id ) {
        $this->set_prop( 'subscription_id', $subscription_id );
    }

    public function get_subscription_id(): string
    {
        return $this->get_prop( 'subscription_id' );
    }

    protected function get_hook_prefix(): string
    {
        return 'woocommerce_payment_token_solid_get_';
    }
}

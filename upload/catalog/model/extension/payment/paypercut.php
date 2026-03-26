<?php
class ModelExtensionPaymentPaypercut extends Model
{
    public function getMethod($address, $total)
    {
        $this->load->language('extension/payment/paypercut');

        $status = true;
        $debug_info = array();

        // Check if payment method is enabled
        $payment_status = $this->config->get('paypercut_status');
        $debug_info['paypercut_status'] = $payment_status;
        if (!$payment_status) {
            $status = false;
            $debug_info['fail_reason'] = 'status_disabled';
        }

        // Check if API key is configured
        $api_key = $this->config->get('paypercut_api_key');
        $debug_info['has_api_key'] = !empty($api_key);
        if (!$api_key) {
            $status = false;
            $debug_info['fail_reason'] = 'no_api_key';
        }

        // Check if currency is supported
        $currency = isset($this->session->data['currency']) ? $this->session->data['currency'] : $this->config->get('config_currency');
        $debug_info['currency'] = $currency;
        if (!$this->isCurrencySupported($currency)) {
            $status = false;
            $debug_info['fail_reason'] = 'unsupported_currency';
        }

        // Log debug info
        $log = new Log('paypercut_debug.log');
        $log->write('getMethod called - status: ' . ($status ? 'true' : 'false') . ' - debug: ' . json_encode($debug_info));

        $method_data = array();

        if ($status) {
            $method_data = array(
                'code'       => 'paypercut',
                'title'      => $this->language->get('text_title'),
                'terms'      => '',
                'sort_order' => $this->config->get('paypercut_sort_order')
            );
        }

        return $method_data;
    }

    /**
     * Check if the provided currency is supported by Paypercut
     */
    private function isCurrencySupported($currency_code)
    {
        $supported_currencies = array('BGN', 'DKK', 'SEK', 'NOK', 'GBP', 'EUR', 'USD', 'CHF', 'CZK', 'HUF', 'PLN', 'RON');
        return in_array(strtoupper($currency_code), $supported_currencies);
    }
}

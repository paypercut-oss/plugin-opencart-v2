<?php
class ControllerExtensionPaymentPaypercut extends Controller
{
    private $error = array();

    public function index()
    {
        $this->load->language('extension/payment/paypercut');

        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->model_setting_setting->editSetting('paypercut', $this->request->post);

            $apple_file_status = $this->ensureAppleDomainAssociationFile();
            if (empty($apple_file_status['ok'])) {
                $apple_warning = sprintf(
                    $this->language->get('error_apple_domain_write'),
                    isset($apple_file_status['path']) ? $apple_file_status['path'] : ''
                );
                // validate() may have already set a domain-registration warning; preserve it.
                if (!empty($this->session->data['warning'])) {
                    $this->session->data['warning'] .= ' ' . $apple_warning;
                } else {
                    $this->session->data['warning'] = $apple_warning;
                }
            }

            $this->session->data['success'] = $this->language->get('text_success');

            $this->response->redirect($this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=payment', true));
        }

        if (isset($this->error['warning'])) {
            $data['error_warning'] = $this->error['warning'];
        } else {
            $data['error_warning'] = '';
        }

        if (isset($this->error['api_key'])) {
            $data['error_api_key'] = $this->error['api_key'];
        } else {
            $data['error_api_key'] = '';
        }

        // Language variables
        $data['heading_title'] = $this->language->get('heading_title');
        $data['text_edit'] = $this->language->get('text_edit');
        $data['text_enabled'] = $this->language->get('text_enabled');
        $data['text_disabled'] = $this->language->get('text_disabled');
        $data['text_mode_test'] = $this->language->get('text_mode_test');
        $data['text_mode_live'] = $this->language->get('text_mode_live');
        $data['text_mode_unknown'] = $this->language->get('text_mode_unknown');
        $data['text_hosted'] = $this->language->get('text_hosted');
        $data['text_embedded'] = $this->language->get('text_embedded');
        $data['text_statement_preview'] = $this->language->get('text_statement_preview');
        $data['text_webhook_info'] = $this->language->get('text_webhook_info');
        $data['text_webhook_configured'] = $this->language->get('text_webhook_configured');
        $data['text_webhook_not_configured'] = $this->language->get('text_webhook_not_configured');
        $data['text_webhook_create'] = $this->language->get('text_webhook_create');
        $data['text_webhook_delete'] = $this->language->get('text_webhook_delete');
        $data['text_webhook_creating'] = $this->language->get('text_webhook_creating');
        $data['text_webhook_deleting'] = $this->language->get('text_webhook_deleting');
        $data['text_wallet_settings'] = $this->language->get('text_wallet_settings');
        $data['text_testing_connection'] = $this->language->get('text_testing_connection');
        $data['text_connection_success'] = $this->language->get('text_connection_success');
        $data['text_connection_failed'] = $this->language->get('text_connection_failed');

        $data['entry_api_key'] = $this->language->get('entry_api_key');
        $data['entry_operating_account'] = $this->language->get('entry_operating_account');
        $data['entry_statement_descriptor'] = $this->language->get('entry_statement_descriptor');
        $data['entry_google_pay'] = $this->language->get('entry_google_pay');
        $data['entry_apple_pay'] = $this->language->get('entry_apple_pay');
        $data['entry_checkout_mode'] = $this->language->get('entry_checkout_mode');
        $data['entry_webhook_url'] = $this->language->get('entry_webhook_url');
        $data['entry_order_status'] = $this->language->get('entry_order_status');
        $data['entry_status'] = $this->language->get('entry_status');
        $data['entry_sort_order'] = $this->language->get('entry_sort_order');
        $data['entry_logging'] = $this->language->get('entry_logging');
        $data['entry_payment_method_config'] = $this->language->get('entry_payment_method_config');

        $data['help_api_key'] = $this->language->get('help_api_key');
        $data['help_operating_account'] = $this->language->get('help_operating_account');
        $data['help_statement_descriptor'] = $this->language->get('help_statement_descriptor');
        $data['help_google_pay'] = $this->language->get('help_google_pay');
        $data['help_apple_pay'] = $this->language->get('help_apple_pay');
        $data['help_checkout_mode'] = $this->language->get('help_checkout_mode');
        $data['help_webhook_url'] = $this->language->get('help_webhook_url');
        $data['help_logging'] = $this->language->get('help_logging');
        $data['help_payment_method_config'] = $this->language->get('help_payment_method_config');

        $data['button_save'] = $this->language->get('button_save');
        $data['button_cancel'] = $this->language->get('button_cancel');
        $data['button_test_connection'] = $this->language->get('button_test_connection');

        $data['breadcrumbs'] = array();

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'token=' . $this->session->data['token'], true)
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=payment', true)
        );

        $data['breadcrumbs'][] = array(
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link('extension/payment/paypercut', 'token=' . $this->session->data['token'], true)
        );

        $data['action'] = $this->url->link('extension/payment/paypercut', 'token=' . $this->session->data['token'], true);

        $data['cancel'] = $this->url->link('extension/extension', 'token=' . $this->session->data['token'] . '&type=payment', true);

        if (isset($this->request->post['paypercut_api_key'])) {
            $data['paypercut_api_key'] = $this->request->post['paypercut_api_key'];
        } else {
            $data['paypercut_api_key'] = $this->config->get('paypercut_api_key');
        }

        // Detect test/live mode from API key
        $api_key = isset($this->request->post['paypercut_api_key']) ? $this->request->post['paypercut_api_key'] : $this->config->get('paypercut_api_key');
        $data['paypercut_mode'] = $this->detectApiKeyMode($api_key);

        // Statement descriptor
        if (isset($this->request->post['paypercut_statement_descriptor'])) {
            $data['paypercut_statement_descriptor'] = $this->request->post['paypercut_statement_descriptor'];
        } else {
            $data['paypercut_statement_descriptor'] = $this->config->get('paypercut_statement_descriptor');
        }

        // Wallet options
        if (isset($this->request->post['paypercut_google_pay'])) {
            $data['paypercut_google_pay'] = $this->request->post['paypercut_google_pay'];
        } else {
            $data['paypercut_google_pay'] = $this->config->get('paypercut_google_pay');
        }

        if (isset($this->request->post['paypercut_apple_pay'])) {
            $data['paypercut_apple_pay'] = $this->request->post['paypercut_apple_pay'];
        } else {
            $data['paypercut_apple_pay'] = $this->config->get('paypercut_apple_pay');
        }

        // Checkout mode
        if (isset($this->request->post['paypercut_checkout_mode'])) {
            $data['paypercut_checkout_mode'] = $this->request->post['paypercut_checkout_mode'];
        } else {
            $data['paypercut_checkout_mode'] = $this->config->get('paypercut_checkout_mode') ?: 'hosted';
        }

        // Webhook URL
        $data['paypercut_webhook_url'] = HTTPS_CATALOG . 'index.php?route=extension/payment/paypercut/webhook';

        // Check webhook status
        $data['webhook_status'] = $this->checkWebhookStatus();

        // Payment method configuration
        if (isset($this->request->post['paypercut_payment_method_config'])) {
            $data['paypercut_payment_method_config'] = $this->request->post['paypercut_payment_method_config'];
        } else {
            $data['paypercut_payment_method_config'] = $this->config->get('paypercut_payment_method_config');
        }

        // Load available payment method configurations
        $data['payment_method_configs'] = array();
        if (!empty($api_key)) {
            $configs = $this->getPaymentMethodConfigurations();
            if ($configs) {
                $data['payment_method_configs'] = $configs;
            }
        }

        if (isset($this->request->post['paypercut_order_status_id'])) {
            $data['paypercut_order_status_id'] = $this->request->post['paypercut_order_status_id'];
        } else {
            $configured_status = $this->config->get('paypercut_order_status_id');
            // Default to "Processing" status if not configured
            $data['paypercut_order_status_id'] = $configured_status ? $configured_status : $this->getProcessingOrderStatusId();
        }

        $this->load->model('localisation/order_status');

        $data['order_statuses'] = $this->model_localisation_order_status->getOrderStatuses();

        if (isset($this->request->post['paypercut_status'])) {
            $data['paypercut_status'] = $this->request->post['paypercut_status'];
        } else {
            $data['paypercut_status'] = $this->config->get('paypercut_status');
        }

        if (isset($this->request->post['paypercut_sort_order'])) {
            $data['paypercut_sort_order'] = $this->request->post['paypercut_sort_order'];
        } else {
            $data['paypercut_sort_order'] = $this->config->get('paypercut_sort_order');
        }

        // Logging enabled
        if (isset($this->request->post['paypercut_logging'])) {
            $data['paypercut_logging'] = $this->request->post['paypercut_logging'];
        } else {
            $data['paypercut_logging'] = $this->config->get('paypercut_logging');
        }

        // Check currency support
        $store_currency = $this->getStoreCurrency();
        $data['store_currency'] = $store_currency;
        $data['currency_supported'] = $this->isCurrencySupported($store_currency);

        if (!$data['currency_supported']) {
            $data['error_currency'] = sprintf($this->language->get('error_unsupported_currency'), $store_currency);
        } else {
            $data['error_currency'] = '';
        }

        // Apple Pay domain association file status (for the wallet panel banner)
        $data['apple_domain_status'] = $this->getAppleDomainAssociationStatus();
        $data['text_apple_domain_file_ok'] = $this->language->get('text_apple_domain_file_ok');
        $data['text_apple_domain_file_missing'] = $this->language->get('text_apple_domain_file_missing');
        $data['text_apple_domain_file_unreachable'] = $this->language->get('text_apple_domain_file_unreachable');
        $data['text_apple_domain_file_path'] = $this->language->get('text_apple_domain_file_path');
        $data['text_apple_domain_file_refreshing'] = $this->language->get('text_apple_domain_file_refreshing');
        $data['button_apple_domain_refresh'] = $this->language->get('button_apple_domain_refresh');

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        // Add user token for AJAX requests
        $data['token'] = $this->session->data['token'];

        $this->response->setOutput($this->load->view('extension/payment/paypercut', $data));
    }

    protected function validate()
    {
        if (!$this->user->hasPermission('modify', 'extension/payment/paypercut')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        if (!$this->request->post['paypercut_api_key']) {
            $this->error['api_key'] = $this->language->get('error_api_key');
        }

        // Ensure payment method domain is registered for wallet payments
        if (!empty($this->request->post['paypercut_api_key'])) {
            $domain_status = $this->ensurePaymentMethodDomain();
            if (!$domain_status['success']) {
                // Don't block saving, just show a warning
                $this->session->data['warning'] = 'Settings saved, but domain registration failed: ' . $domain_status['message'] . '. Wallet payment methods (Apple Pay, Google Pay) may not work until the domain is properly registered in your Paypercut dashboard.';
            }
        }

        return !$this->error;
    }

    private function detectApiKeyMode($api_key)
    {
        if (empty($api_key)) {
            return '';
        }

        // Paypercut uses sk_test prefix for test keys and sk_live for live keys
        if (strpos($api_key, 'sk_test') === 0) {
            return 'test';
        } elseif (strpos($api_key, 'sk_live') === 0) {
            return 'live';
        }

        return 'unknown';
    }

    /**
     * Check if the provided currency is supported by Paypercut
     */
    private function isCurrencySupported($currency_code)
    {
        $supported_currencies = array('BGN', 'DKK', 'SEK', 'NOK', 'GBP', 'EUR', 'USD', 'CHF', 'CZK', 'HUF', 'PLN', 'RON');
        return in_array(strtoupper($currency_code), $supported_currencies);
    }

    /**
     * Get the store's default currency
     */
    private function getStoreCurrency()
    {
        return $this->config->get('config_currency');
    }

    /**
     * Get the order status ID for "Processing" status
     * Looks up the status by name to avoid hardcoding the ID
     */
    private function getProcessingOrderStatusId()
    {
        $query = $this->db->query("
            SELECT order_status_id 
            FROM `" . DB_PREFIX . "order_status` 
            WHERE name = 'Processing' 
            AND language_id = '" . (int)$this->config->get('config_language_id') . "'
            LIMIT 1
        ");

        if ($query->num_rows) {
            return $query->row['order_status_id'];
        }

        // Fallback to ID 2 if "Processing" status not found
        return 2;
    }

    private function checkWebhookStatus()
    {
        $api_key = $this->config->get('paypercut_api_key');

        if (empty($api_key)) {
            return array(
                'configured' => false,
                'message' => 'Please configure your API key first'
            );
        }

        $webhook_url = HTTPS_CATALOG . 'index.php?route=extension/payment/paypercut/webhook';
        $webhook_id = $this->config->get('paypercut_webhook_id');

        // If we have a stored webhook ID, verify it still exists
        if ($webhook_id) {
            $webhook = $this->getWebhook($webhook_id);
            if ($webhook && $webhook['url'] === $webhook_url && $webhook['status'] === 'enabled') {
                return array(
                    'configured' => true,
                    'webhook_id' => $webhook_id,
                    'message' => 'Webhook is configured and active',
                    'enabled_events' => $webhook['enabled_events']
                );
            }
        }

        // Check if webhook exists but we don't have the ID stored
        $existing_webhook = $this->findWebhookByUrl($webhook_url);
        if ($existing_webhook) {
            // Store the webhook ID
            $this->load->model('setting/setting');
            $settings = $this->model_setting_setting->getSetting('paypercut');
            $settings['paypercut_webhook_id'] = $existing_webhook['id'];
            $this->model_setting_setting->editSetting('paypercut', $settings);

            return array(
                'configured' => true,
                'webhook_id' => $existing_webhook['id'],
                'message' => 'Webhook found and linked',
                'enabled_events' => $existing_webhook['enabled_events']
            );
        }

        return array(
            'configured' => false,
            'message' => 'Webhook not configured'
        );
    }

    private function getWebhook($webhook_id)
    {
        $api_key = $this->config->get('paypercut_api_key');
        $api_url = 'https://api.paypercut.io/v1/webhooks/' . $webhook_id;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json'
        ));

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code == 200) {
            return json_decode($response, true);
        }

        return null;
    }

    private function findWebhookByUrl($webhook_url)
    {
        $api_key = $this->config->get('paypercut_api_key');
        $api_url = 'https://api.paypercut.io/v1/webhooks';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json'
        ));

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code == 200) {
            $result = json_decode($response, true);
            if (isset($result['items'])) {
                foreach ($result['items'] as $webhook) {
                    if ($webhook['url'] === $webhook_url) {
                        return $webhook;
                    }
                }
            }
        }

        return null;
    }

    public function createWebhook()
    {
        $this->load->language('extension/payment/paypercut');

        $json = array();

        if (!$this->user->hasPermission('modify', 'extension/payment/paypercut')) {
            $json['error'] = $this->language->get('error_permission');
        } else {
            $api_key = $this->config->get('paypercut_api_key');

            if (empty($api_key)) {
                $json['error'] = 'API key not configured';
            } else {
                $webhook_url = HTTPS_CATALOG . 'index.php?route=extension/payment/paypercut/webhook';

                // Check if webhook already exists
                $existing = $this->findWebhookByUrl($webhook_url);
                if ($existing) {
                    $json['error'] = 'Webhook already exists for this URL';
                    $json['webhook_id'] = $existing['id'];
                } else {
                    $api_url = 'https://api.paypercut.io/v1/webhooks';

                    // Create webhook with all events enabled
                    $payload = array(
                        'name' => 'OpenCart - ' . HTTP_CATALOG,
                        'url' => $webhook_url,
                        'enabled_events' => array('checkout_session.completed')
                    );

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $api_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_POST, true);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
                    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                        'Authorization: Bearer ' . $api_key,
                        'Content-Type: application/json'
                    ));

                    $response = curl_exec($ch);
                    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($http_code == 201 || $http_code == 200) {
                        $result = json_decode($response, true);

                        // Store webhook ID and secret
                        $this->load->model('setting/setting');
                        $settings = $this->model_setting_setting->getSetting('paypercut');
                        $settings['paypercut_webhook_id'] = $result['id'];
                        $settings['paypercut_webhook_secret'] = $result['secret'];
                        $this->model_setting_setting->editSetting('paypercut', $settings);

                        $json['success'] = 'Webhook created successfully';
                        $json['webhook_id'] = $result['id'];
                    } else {
                        $error_data = json_decode($response, true);
                        $json['error'] = isset($error_data['message']) ? $error_data['message'] : 'Failed to create webhook';
                    }
                }
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function deleteWebhook()
    {
        $this->load->language('extension/payment/paypercut');

        $json = array();

        if (!$this->user->hasPermission('modify', 'extension/payment/paypercut')) {
            $json['error'] = $this->language->get('error_permission');
        } else {
            $webhook_id = $this->config->get('paypercut_webhook_id');

            if (empty($webhook_id)) {
                $json['error'] = 'No webhook configured';
            } else {
                $api_key = $this->config->get('paypercut_api_key');
                $api_url = 'https://api.paypercut.io/v1/webhooks/' . $webhook_id;

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $api_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Authorization: Bearer ' . $api_key,
                    'Content-Type: application/json'
                ));

                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($http_code == 200) {
                    // Remove webhook ID from settings
                    $this->load->model('setting/setting');
                    $settings = $this->model_setting_setting->getSetting('paypercut');
                    unset($settings['paypercut_webhook_id']);
                    unset($settings['paypercut_webhook_secret']);
                    $this->model_setting_setting->editSetting('paypercut', $settings);

                    $json['success'] = 'Webhook deleted successfully';
                } else {
                    $json['error'] = 'Failed to delete webhook';
                }
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /**
     * Ensure payment method domain is registered for wallet payments
     */
    private function ensurePaymentMethodDomain()
    {
        $api_key = $this->config->get('paypercut_api_key');

        if (empty($api_key)) {
            return array('success' => false, 'message' => 'API key not configured');
        }

        // Extract domain from catalog URL
        $domain = $this->extractDomain(HTTPS_CATALOG);

        if (empty($domain)) {
            return array('success' => false, 'message' => 'Could not extract domain from store URL');
        }

        // Check if domain is already registered
        $existing_domain = $this->getPaymentMethodDomain($domain);

        if ($existing_domain) {
            // Domain exists, check if it's enabled
            if ($existing_domain['enabled']) {
                return array(
                    'success' => true,
                    'message' => 'Domain already registered and enabled',
                    'domain_id' => $existing_domain['id']
                );
            } else {
                return array(
                    'success' => false,
                    'message' => 'Domain registered but not enabled. Please verify domain ownership in Paypercut Dashboard.'
                );
            }
        }

        // Register the domain
        return $this->registerPaymentMethodDomain($domain);
    }

    /**
     * Extract domain name from URL
     */
    private function extractDomain($url)
    {
        $parsed = parse_url($url);
        return isset($parsed['host']) ? $parsed['host'] : '';
    }

    /**
     * Get payment method domain from Paypercut
     */
    private function getPaymentMethodDomain($domain_name)
    {
        $api_key = $this->config->get('paypercut_api_key');
        $api_url = 'https://api.paypercut.io/v1/payment_method_domains';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json'
        ));

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code == 200) {
            $result = json_decode($response, true);
            if (isset($result['items'])) {
                foreach ($result['items'] as $domain) {
                    if ($domain['domain_name'] === $domain_name) {
                        return $domain;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Register payment method domain with Paypercut
     */
    private function registerPaymentMethodDomain($domain_name)
    {
        $api_key = $this->config->get('paypercut_api_key');
        $api_url = 'https://api.paypercut.io/v1/payment_method_domains';

        $payload = array(
            'domain_name' => $domain_name
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json'
        ));

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code == 201 || $http_code == 200) {
            $result = json_decode($response, true);

            // Store domain ID for reference
            $this->load->model('setting/setting');
            $settings = $this->model_setting_setting->getSetting('paypercut');
            $settings['paypercut_domain_id'] = $result['id'];
            $this->model_setting_setting->editSetting('paypercut', $settings);

            return array(
                'success' => true,
                'message' => 'Domain registered successfully. Verification may be required.',
                'domain_id' => $result['id'],
                'enabled' => isset($result['enabled']) ? $result['enabled'] : false
            );
        } else {
            $error_data = json_decode($response, true);
            $error_message = 'Failed to register domain';

            // Provide more specific error messages
            if ($http_code == 403) {
                $error_message = 'Permission denied (403). The API key may not have access to register domains, or the domain may already be registered in another account.';
            } elseif ($http_code == 400) {
                $error_message = 'Invalid domain name (400). Please check your store URL configuration.';
            } elseif ($http_code == 409) {
                $error_message = 'Domain already exists (409). Please check your Paypercut dashboard.';
            } elseif (isset($error_data['error']['message'])) {
                $error_message = $error_data['error']['message'];
            } elseif (isset($error_data['message'])) {
                $error_message = $error_data['message'];
            }

            // Log the error for debugging
            $this->log->write('Paypercut domain registration failed: HTTP ' . $http_code . ' - ' . $error_message . ' | Response: ' . $response);

            return array(
                'success' => false,
                'message' => $error_message . ' (HTTP ' . $http_code . ')',
                'http_code' => $http_code
            );
        }
    }

    /**
     * Test API connection and get account information
     */
    public function testConnection()
    {
        $this->load->language('extension/payment/paypercut');

        $json = array();

        if (!$this->user->hasPermission('modify', 'extension/payment/paypercut')) {
            $json['error'] = $this->language->get('error_permission');
        } else {
            $api_key = $this->request->post['api_key'] ?? '';

            if (empty($api_key)) {
                $json['error'] = 'API key is required';
            } else {
                // Test connection by verifying account
                $api_url = 'https://api.paypercut.io/v1/account';

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $api_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Authorization: Bearer ' . $api_key,
                    'Content-Type: application/json'
                ));

                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($http_code == 200) {
                    $result = json_decode($response, true);

                    $mode = $this->detectApiKeyMode($api_key);
                    $json['success'] = true;
                    $json['message'] = 'Connection successful!';
                    $json['mode'] = $mode;
                    if (isset($result['business_name'])) {
                        $json['account_name'] = $result['business_name'];
                    }
                } elseif ($http_code == 401) {
                    $json['error'] = 'Authentication failed. Please check your API key.';
                } else {
                    $error_data = json_decode($response, true);
                    $json['error'] = isset($error_data['message']) ? $error_data['message'] : 'Connection failed with HTTP ' . $http_code;
                }
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /**
     * Get payment method configurations from Paypercut
     */
    private function getPaymentMethodConfigurations()
    {
        $api_key = $this->config->get('paypercut_api_key');

        if (empty($api_key)) {
            return array();
        }

        $api_url = 'https://api.paypercut.io/v1/payment-configs';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json'
        ));

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code == 200) {
            $result = json_decode($response, true);
            return isset($result['items']) ? $result['items'] : array();
        }

        return array();
    }

    /**
     * Install method - Called when extension is installed
     * Creates database tables and registers events
     */
    public function install()
    {
        // Create database tables
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "paypercut_customer` (
                `paypercut_customer_id` int(11) NOT NULL AUTO_INCREMENT,
                `customer_id` int(11) NOT NULL,
                `paypercut_id` varchar(255) NOT NULL,
                `email` varchar(255) NOT NULL,
                `created_at` datetime NOT NULL,
                `updated_at` datetime NOT NULL,
                PRIMARY KEY (`paypercut_customer_id`),
                UNIQUE KEY `customer_id` (`customer_id`),
                UNIQUE KEY `paypercut_id` (`paypercut_id`),
                KEY `email` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "paypercut_transaction` (
                `paypercut_transaction_id` int(11) NOT NULL AUTO_INCREMENT,
                `order_id` int(11) NOT NULL,
                `payment_id` varchar(255) NOT NULL,
                `payment_intent` varchar(255) DEFAULT NULL,
                `payment_link_id` varchar(255) DEFAULT NULL,
                `checkout_id` varchar(255) DEFAULT NULL,
                `customer_id` int(11) DEFAULT NULL,
                `paypercut_customer_id` varchar(255) DEFAULT NULL,
                `amount` decimal(15,4) NOT NULL,
                `currency` varchar(3) NOT NULL,
                `status` varchar(50) NOT NULL,
                `payment_method_type` varchar(50) DEFAULT NULL,
                `payment_method_details` text,
                `created_at` datetime NOT NULL,
                `updated_at` datetime NOT NULL,
                PRIMARY KEY (`paypercut_transaction_id`),
                UNIQUE KEY `payment_id` (`payment_id`),
                KEY `order_id` (`order_id`),
                KEY `customer_id` (`customer_id`),
                KEY `checkout_id` (`checkout_id`),
                KEY `payment_intent` (`payment_intent`),
                KEY `status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "paypercut_refund` (
                `paypercut_refund_id` int(11) NOT NULL AUTO_INCREMENT,
                `order_id` int(11) NOT NULL,
                `transaction_id` int(11) NOT NULL,
                `payment_id` varchar(255) NOT NULL,
                `refund_id` varchar(255) NOT NULL,
                `amount` decimal(15,4) NOT NULL,
                `currency` varchar(3) NOT NULL,
                `reason` varchar(255) DEFAULT NULL,
                `status` varchar(50) NOT NULL,
                `created_at` datetime NOT NULL,
                `updated_at` datetime NOT NULL,
                PRIMARY KEY (`paypercut_refund_id`),
                UNIQUE KEY `refund_id` (`refund_id`),
                KEY `order_id` (`order_id`),
                KEY `transaction_id` (`transaction_id`),
                KEY `payment_id` (`payment_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
        ");

        $this->db->query("
            CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "paypercut_webhook_log` (
                `log_id` int(11) NOT NULL AUTO_INCREMENT,
                `event_type` varchar(100) NOT NULL,
                `event_id` varchar(255) DEFAULT NULL,
                `payload` text NOT NULL,
                `processed` tinyint(1) NOT NULL DEFAULT 0,
                `error` text,
                `created_at` datetime NOT NULL,
                PRIMARY KEY (`log_id`),
                KEY `event_type` (`event_type`),
                KEY `event_id` (`event_id`),
                KEY `processed` (`processed`),
                KEY `created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
        ");

        // Place the Apple Pay domain-association file under <opencart_root>/.well-known/.
        // Failure here does not abort install — the admin settings banner surfaces it.
        $this->ensureAppleDomainAssociationFile();

        // Register event for order info page to display Paypercut payment information
        $this->load->model('extension/event');
        $this->model_extension_event->addEvent(
            'paypercut_order_info',
            'admin/view/sale/order_info/after',
            'sale/paypercut_order/info'
        );
    }

    /**
     * Uninstall method - Called when extension is uninstalled
     * Removes events (but preserves database tables for data integrity)
     */
    public function uninstall()
    {
        // Remove event
        $this->load->model('extension/event');
        $this->model_extension_event->deleteEvent('paypercut_order_info');

        // Note: We intentionally don't drop database tables to preserve transaction history.
        // We also intentionally leave <opencart_root>/.well-known/apple-developer-merchantid-domain-association
        // in place — the file is non-sensitive, and removing it would break Apple Pay
        // verification if the merchant reinstalls the extension or another tool relies on
        // .well-known/ (e.g. ACME challenges).
        // If you want to completely remove all data, manually drop these tables:
        // - oc_paypercut_customer
        // - oc_paypercut_transaction
        // - oc_paypercut_refund
        // - oc_paypercut_webhook_log
    }

    /**
     * AJAX endpoint: re-fetch the Apple Pay domain-association file from the CDN
     * (or fall back to the bundled copy) and write it to the storefront webroot.
     */
    public function refreshAppleDomainFile()
    {
        $this->load->language('extension/payment/paypercut');

        $json = array();

        if (!$this->user->hasPermission('modify', 'extension/payment/paypercut')) {
            $json['error'] = $this->language->get('error_permission');
        } else {
            $result = $this->ensureAppleDomainAssociationFile();
            if (!empty($result['ok'])) {
                $json['success'] = true;
                $json['path'] = $result['path'];
                $json['source'] = $result['source'];
                $json['reachable'] = $result['reachable'];
                $json['bytes'] = $result['bytes'];
            } else {
                $json['error'] = sprintf(
                    $this->language->get('error_apple_domain_write'),
                    isset($result['path']) ? $result['path'] : ''
                );
                $json['reason'] = isset($result['reason']) ? $result['reason'] : 'unknown';
                $json['path'] = isset($result['path']) ? $result['path'] : '';
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /**
     * Read current state of the Apple Pay domain-association file for view rendering.
     * No network calls — safe to invoke on every page render.
     */
    private function getAppleDomainAssociationStatus()
    {
        $target_file = dirname(DIR_APPLICATION) . '/.well-known/apple-developer-merchantid-domain-association';

        return array(
            'present' => is_file($target_file),
            'path' => $target_file,
            'last_refreshed' => $this->config->get('paypercut_apple_domain_file_at'),
            'source' => $this->config->get('paypercut_apple_domain_file_source'),
            'reachable' => $this->config->get('paypercut_apple_domain_file_reachable')
        );
    }

    /**
     * Place the Apple Pay domain-association file at
     * <opencart_root>/.well-known/apple-developer-merchantid-domain-association.
     *
     * Hybrid source: try the PayPerCut CDN first, fall back to the bundled copy
     * shipped under upload/system/library/paypercut/apple-pay/ when the CDN is
     * unreachable. Idempotent — safe to call from install() and from every
     * settings save. Never throws; always returns a status array.
     */
    private function ensureAppleDomainAssociationFile()
    {
        $target_dir = dirname(DIR_APPLICATION) . '/.well-known';
        $target_file = $target_dir . '/apple-developer-merchantid-domain-association';
        $bundled = DIR_SYSTEM . 'library/paypercut/apple-pay/apple-developer-merchantid-domain-association';
        $cdn_url = 'https://cdn.paypercut.io/.well-known/apple-developer-merchantid-domain-association';

        // 1. Source bytes — CDN first (3s budget), bundled fallback.
        $source = 'bundled';
        $bytes = false;

        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $cdn_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code == 200 && is_string($response)) {
                $len = strlen($response);
                if ($len >= 50 && $len <= 4096) {
                    $bytes = $response;
                    $source = 'cdn';
                }
            }
        }

        if ($bytes === false && is_readable($bundled)) {
            $bundled_bytes = file_get_contents($bundled);
            if ($bundled_bytes !== false && $bundled_bytes !== '') {
                $bytes = $bundled_bytes;
            }
        }

        if ($bytes === false || $bytes === '') {
            $this->log->write('Paypercut Apple Pay: no source bytes available (CDN failed and bundled file missing at ' . $bundled . ')');
            return array(
                'ok' => false,
                'reason' => 'no_source',
                'path' => $target_file
            );
        }

        // 2. Ensure target directory exists.
        if (!is_dir($target_dir)) {
            if (!@mkdir($target_dir, 0755, true) && !is_dir($target_dir)) {
                $this->log->write('Paypercut Apple Pay: failed to create directory ' . $target_dir);
                return array(
                    'ok' => false,
                    'reason' => 'mkdir_failed',
                    'path' => $target_file
                );
            }
        }

        // 3. Drop a permissive .htaccess if none exists. Some shared-hosting Apache
        // configs deny dotfile directories by default; this keeps Apple's verifier
        // from getting a 403. We do not overwrite an existing .htaccess (the merchant
        // or another tool — e.g. Let's Encrypt — may already manage it).
        $htaccess = $target_dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            $htaccess_body = "# PayPerCut: allow public access to .well-known/ for Apple Pay domain verification.\n"
                           . "<IfModule mod_authz_core.c>\n"
                           . "    Require all granted\n"
                           . "</IfModule>\n"
                           . "<IfModule !mod_authz_core.c>\n"
                           . "    Order allow,deny\n"
                           . "    Allow from all\n"
                           . "</IfModule>\n";
            @file_put_contents($htaccess, $htaccess_body);
            @chmod($htaccess, 0644);
        }

        // 4. Write the file atomically.
        $written = @file_put_contents($target_file, $bytes, LOCK_EX);
        if ($written === false) {
            $this->log->write('Paypercut Apple Pay: failed to write ' . $target_file);
            return array(
                'ok' => false,
                'reason' => 'write_failed',
                'path' => $target_file
            );
        }
        @chmod($target_file, 0644);

        // 5. Best-effort self-test — does the catalog hostname actually serve it?
        // Failure here is non-fatal; the file may still be reachable from Apple's
        // verifier even when the OpenCart admin host can't reach the catalog host.
        $reachable = null;
        if (defined('HTTPS_CATALOG') && function_exists('curl_init')) {
            $catalog_host = parse_url(HTTPS_CATALOG, PHP_URL_HOST);
            if ($catalog_host) {
                $verify_url = 'https://' . $catalog_host . '/.well-known/apple-developer-merchantid-domain-association';
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $verify_url);
                curl_setopt($ch, CURLOPT_NOBODY, true);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
                curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                curl_exec($ch);
                $verify_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                $reachable = ($verify_code == 200);
            }
        }

        // 6. Persist metadata so the settings page banner can show last-refreshed/source/reachable.
        $this->load->model('setting/setting');
        $settings = $this->model_setting_setting->getSetting('paypercut');
        $settings['paypercut_apple_domain_file_at'] = date('c');
        $settings['paypercut_apple_domain_file_source'] = $source;
        $settings['paypercut_apple_domain_file_reachable'] = $reachable === null ? '' : ($reachable ? '1' : '0');
        $this->model_setting_setting->editSetting('paypercut', $settings);

        return array(
            'ok' => true,
            'path' => $target_file,
            'source' => $source,
            'reachable' => $reachable,
            'bytes' => strlen($bytes)
        );
    }
}

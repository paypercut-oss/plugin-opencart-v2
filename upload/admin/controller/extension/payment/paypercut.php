<?php
class ControllerExtensionPaymentPaypercut extends Controller
{
    private $error = array();

    /**
     * Absolute Paypercut API URL for the store's connection environment.
     */
    private function apiUrl($path)
    {
        require_once DIR_SYSTEM . 'library/paypercut/environment.php';

        return PaypercutEnvironment::apiUrl($this->config->get('paypercut_environment'), $path);
    }

    /**
     * Load the telemetry library and point it at this authenticated admin request.
     */
    private function telemetry()
    {
        require_once DIR_SYSTEM . 'library/paypercut/telemetry/bootstrap.php';

        PaypercutTelemetry::boot($this->registry, true);
    }

    /**
     * Report a diagnostic event. A no-op unless a debug session is running.
     */
    private function report($event)
    {
        $this->telemetry();
        PaypercutTelemetry::record($event);
    }

    public function index()
    {
        $this->load->language('extension/payment/paypercut');

        $this->document->setTitle($this->language->get('heading_title'));

        $this->load->model('setting/setting');

        $this->telemetry();

        if (($this->request->server['REQUEST_METHOD'] == 'POST') && $this->validate()) {
            $this->endSessionIfConnectionChanged();

            $this->model_setting_setting->editSetting('paypercut', $this->request->post);

            // The session record lives outside the `paypercut` setting code, so
            // it survives the delete-and-reinsert editSetting() performs - but a
            // configuration changed mid-session would otherwise be read against
            // the snapshot taken before it, and the timeline would lie.
            $this->resendConfigurationSnapshot();

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

        // Connection environment. Both the payment API host and the telemetry
        // edge host are derived from this one value.
        require_once DIR_SYSTEM . 'library/paypercut/environment.php';

        $environment = isset($this->request->post['paypercut_environment'])
            ? $this->request->post['paypercut_environment']
            : $this->config->get('paypercut_environment');

        // stored(), not normalize(): the form must show the environment the
        // debug session will actually resolve, or a store that never re-saved
        // reads `production` here and is refused a session.
        $data['paypercut_environment'] = PaypercutEnvironment::stored($environment);
        $data['paypercut_environments'] = PaypercutEnvironment::all();
        $data['paypercut_api_base'] = PaypercutEnvironment::apiBaseUri($data['paypercut_environment']);
        $data['entry_environment'] = $this->language->get('entry_environment');
        $data['help_environment'] = $this->language->get('help_environment');

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

        $data = array_merge($data, $this->debugSessionViewData());

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        // Add user token for AJAX requests
        $data['token'] = $this->session->data['token'];

        $this->response->setOutput($this->load->view('extension/payment/paypercut', $data));
    }

    protected function validate()
    {
        require_once DIR_SYSTEM . 'library/paypercut/environment.php';

        if (!$this->user->hasPermission('modify', 'extension/payment/paypercut')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        if (!$this->request->post['paypercut_api_key']) {
            $this->error['api_key'] = $this->language->get('error_api_key');
        }

        // Ensure payment method domain is registered for wallet payments
        if (!empty($this->request->post['paypercut_api_key'])) {
            $domain_status = $this->ensurePaymentMethodDomain();

            $this->report(PaypercutEvent::of('connection.validated', array(
                'source' => 'settings_save',
                'is_bnpl' => false,
                'environment' => PaypercutEnvironment::stored(
                    isset($this->request->post['paypercut_environment']) ? $this->request->post['paypercut_environment'] : ''
                ),
                'api_key_mode' => $this->detectApiKeyMode($this->request->post['paypercut_api_key'])
            )));

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
        $api_url = $this->apiUrl('v1/webhooks/' . $webhook_id);

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
        $api_url = $this->apiUrl('v1/webhooks');

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

            return null;
        }

        $this->report(PaypercutEvent::failure('settings.webhooks_unreadable', 'lookup_failed', array(
            'http_status' => (int)$http_code
        )));

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

                    $this->report(PaypercutEvent::failure(
                        'connection.webhook_registration_failed',
                        'already_exists',
                        array('source' => 'settings')
                    ));
                } else {
                    $api_url = $this->apiUrl('v1/webhooks');

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

                        $this->report(PaypercutEvent::of('webhook.registered'));
                        $this->report(PaypercutEvent::of('connection.webhook_registered', array('source' => 'settings')));
                    } else {
                        $error_data = json_decode($response, true);
                        $json['error'] = isset($error_data['message']) ? $error_data['message'] : 'Failed to create webhook';

                        $this->report(PaypercutEvent::apiFailure(
                            'webhook.registration_failed',
                            $http_code,
                            is_array($error_data) ? $error_data : array()
                        ));
                        $this->report(PaypercutEvent::failure(
                            'connection.webhook_registration_failed',
                            'rejected',
                            array('source' => 'settings')
                        ));
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
                $api_url = $this->apiUrl('v1/webhooks/' . $webhook_id);

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

                    $this->report(PaypercutEvent::of('webhook.deleted'));
                } else {
                    $json['error'] = 'Failed to delete webhook';

                    $this->report(PaypercutEvent::failure('webhook.delete_failed', 'rejected', array(
                        'http_status' => (int)$http_code
                    )));
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
        $api_url = $this->apiUrl('v1/payment_method_domains');

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

            return null;
        }

        $this->report(PaypercutEvent::failure('settings.payment_domains_unreadable', 'lookup_failed', array(
            'http_status' => (int)$http_code
        )));

        return null;
    }

    /**
     * Register payment method domain with Paypercut
     */
    private function registerPaymentMethodDomain($domain_name)
    {
        $api_key = $this->config->get('paypercut_api_key');
        $api_url = $this->apiUrl('v1/payment_method_domains');

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

            $this->report(PaypercutEvent::of('payment_domain.registered'));
            $this->report(PaypercutEvent::of('connection.payment_domain_registered', array('source' => 'settings')));

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

            // $error_message may quote the submitted domain back, so only the
            // status and the platform's own code travel.
            $this->report(PaypercutEvent::apiFailure(
                'payment_domain.registration_failed',
                $http_code,
                is_array($error_data) ? $error_data : array()
            ));
            $this->report(PaypercutEvent::failure(
                'connection.payment_domain_registration_failed',
                'rejected',
                array('source' => 'settings')
            ));

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

        require_once DIR_SYSTEM . 'library/paypercut/environment.php';

        $json = array();

        if (!$this->user->hasPermission('modify', 'extension/payment/paypercut')) {
            $json['error'] = $this->language->get('error_permission');
        } else {
            $api_key = $this->request->post['api_key'] ?? '';

            if (empty($api_key)) {
                $json['error'] = 'API key is required';
            } else {
                // Test connection by verifying account
                $api_url = $this->apiUrl('v1/account');

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

                $mode = $this->detectApiKeyMode($api_key);
                $environment = PaypercutEnvironment::stored($this->config->get('paypercut_environment'));

                if ($http_code == 200) {
                    $result = json_decode($response, true);

                    $json['success'] = true;
                    $json['message'] = 'Connection successful!';
                    $json['mode'] = $mode;
                    if (isset($result['business_name'])) {
                        $json['account_name'] = $result['business_name'];
                    }

                    $this->report(PaypercutEvent::of('connection.tested', array(
                        'is_bnpl' => false,
                        'ok' => true,
                        'environment' => $environment,
                        'api_key_mode' => $mode
                    )));
                } elseif ($http_code == 401) {
                    $json['error'] = 'Authentication failed. Please check your API key.';

                    $this->report(PaypercutEvent::failure('connection.tested', 'credentials_rejected', array(
                        'is_bnpl' => false,
                        'ok' => false,
                        'environment' => $environment,
                        'http_status' => 401
                    )));
                } else {
                    $error_data = json_decode($response, true);
                    $json['error'] = isset($error_data['message']) ? $error_data['message'] : 'Connection failed with HTTP ' . $http_code;

                    // $error_data['message'] is the platform's prose, never sent.
                    $this->report(PaypercutEvent::apiFailure(
                        'connection.tested',
                        $http_code,
                        is_array($error_data) ? $error_data : array(),
                        array('is_bnpl' => false, 'ok' => false, 'environment' => $environment)
                    ));
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

        $api_url = $this->apiUrl('v1/payment-configs');

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

        $this->report(PaypercutEvent::failure('settings.payment_configs_unreadable', 'lookup_failed', array(
            'http_status' => (int)$http_code
        )));

        return array();
    }


    /**
     * Whether the Start button is offered at all.
     *
     * Only `start` is gated: "the feature is off" must mean no new session can
     * be minted, while stop and status stay reachable so a session that is
     * already running can always be ended.
     */
    private function debugSessionStartEnabled()
    {
        return !defined('PAYPERCUT_TELEMETRY_DISABLED') || !constant('PAYPERCUT_TELEMETRY_DISABLED');
    }

    /**
     * Panel data for the settings view, plus the backstop flush.
     *
     * The server paints the current state so the panel is correct with no round
     * trip; the script then keeps the countdown and counters live.
     */
    private function debugSessionViewData()
    {
        $this->telemetry();

        PaypercutTelemetrySession::reap();

        // The panel's poll is the primary delivery trigger; this covers a
        // merchant who started a session and then reloaded the settings page.
        if (PaypercutTelemetrySession::isActiveFast() && PaypercutEventQueue::size() > 0) {
            $flusher = new PaypercutFlusher();
            $flusher->flushOnce();
        }

        $state = PaypercutTelemetrySession::describe();

        $entries = PaypercutSentLog::all();
        $log = array();

        foreach ($entries as $entry) {
            $log[] = array(
                'occurred_at' => isset($entry['occurred_at']) ? (string)$entry['occurred_at'] : '',
                'event' => isset($entry['event']) ? (string)$entry['event'] : '',
                'detail' => $this->debugSessionEventDetail($entry)
            );
        }

        // The panel and its script are rendered from the view, which only sees
        // what is handed to it - so every string it uses is listed here.
        $strings = array();

        foreach (array(
            'heading_telemetry',
            'text_telemetry_idle_lead', 'text_telemetry_idle_help',
            'text_telemetry_running', 'text_telemetry_started_by',
            'text_telemetry_session_id', 'text_telemetry_last_session_id',
            'text_telemetry_counters', 'text_telemetry_ended', 'text_telemetry_ended_help',
            'text_telemetry_reference', 'text_telemetry_copied',
            'text_telemetry_log_summary', 'text_telemetry_log_help',
            'text_telemetry_log_time', 'text_telemetry_log_event',
            'text_telemetry_log_detail', 'text_telemetry_log_raw',
            'text_telemetry_modal_title', 'text_telemetry_modal_lead', 'text_telemetry_modal_duration',
            'text_telemetry_starting', 'text_telemetry_stopping', 'text_telemetry_session_ended',
            'text_telemetry_network_error', 'text_telemetry_admin_unreachable',
            'button_telemetry_start', 'button_telemetry_start_confirm',
            'button_telemetry_stop', 'button_telemetry_retry', 'button_telemetry_copy'
        ) as $key) {
            $strings[$key] = $this->language->get($key);
        }

        return array_merge($strings, array(
            'telemetry_disclosure' => $this->debugSessionDisclosure(),
            'telemetry_state' => $state,
            'telemetry_now' => time(),
            'telemetry_ends_at' => $state['expires_at'] > 0 ? date('H:i', $state['expires_at']) : '',
            'telemetry_start_enabled' => $this->debugSessionStartEnabled(),
            'telemetry_poll_seconds' => PaypercutTelemetrySession::POLL_INTERVAL_SECONDS,
            'telemetry_log' => $log,
            'telemetry_log_max' => PaypercutSentLog::MAX_ENTRIES,
            'telemetry_log_raw' => json_encode($entries, defined('JSON_PRETTY_PRINT') ? JSON_PRETTY_PRINT : 0)
        ));
    }

    /**
     * The "what is shared" disclosure, rendered once and reused.
     *
     * The panel and the consent modal must show the same words, and those words
     * must stay identical to the block in docs/telemetry.md and the store
     * listing: a merchant agreeing to one thing while the documentation says
     * another is a real problem regardless of who is reading.
     */
    private function debugSessionDisclosure()
    {
        return '<div class="well well-sm">'
            . '<p><strong>' . $this->language->get('text_telemetry_disclosure_heading') . '</strong></p>'
            . '<p>' . $this->language->get('text_telemetry_disclosure_shared') . '</p>'
            . '<p><strong>' . $this->language->get('text_telemetry_disclosure_not_shared_label') . '</strong> '
            . $this->language->get('text_telemetry_disclosure_not_shared') . '</p>'
            . '<p>' . $this->language->get('text_telemetry_disclosure_key') . '</p>'
            . '<p>' . $this->language->get('text_telemetry_disclosure_retention') . '</p>'
            . '</div>';
    }

    /**
     * One line summarising an event, so the table is scannable without the JSON.
     */
    private function debugSessionEventDetail($entry)
    {
        $parts = array();
        $error = isset($entry['error']) && is_array($entry['error']) ? $entry['error'] : array();

        if (isset($error['code'])) {
            $parts[] = (string)$error['code'];
        }

        foreach (array('order_ref', 'payment_id', 'payment_intent_id') as $key) {
            if (!empty($entry[$key])) {
                $parts[] = $key . '=' . (string)$entry[$key];
            }
        }

        $attrs = isset($entry['attrs']) && is_array($entry['attrs']) ? $entry['attrs'] : array();

        foreach (array('origin_plugin', 'http_status', 'reason', 'webhook') as $key) {
            if (isset($attrs[$key]) && is_scalar($attrs[$key])) {
                $parts[] = $key . '=' . (string)$attrs[$key];
            }
        }

        // Lifecycle events carry none of the keys above, and a row of dashes
        // tells the merchant nothing. Fall back to whatever the event does have.
        if (empty($parts)) {
            foreach ($attrs as $key => $value) {
                if (count($parts) >= 3) {
                    break;
                }

                if (is_scalar($value)) {
                    $parts[] = $key . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : (string)$value);
                }
            }
        }

        return empty($parts) ? '-' : implode(' . ', $parts);
    }

    /**
     * End a live session when the credential or environment is about to change.
     *
     * end() is the single teardown path, so a re-key cannot leave a token
     * behind that no later request knows to destroy.
     */
    private function endSessionIfConnectionChanged()
    {
        $this->telemetry();

        $record = PaypercutTelemetrySession::record();

        if (!isset($record['status']) || $record['status'] !== 'active') {
            return;
        }

        require_once DIR_SYSTEM . 'library/paypercut/environment.php';

        $api_key = isset($this->request->post['paypercut_api_key']) ? (string)$this->request->post['paypercut_api_key'] : '';
        $environment = PaypercutEnvironment::stored(
            isset($this->request->post['paypercut_environment']) ? $this->request->post['paypercut_environment'] : ''
        );

        if (PaypercutTelemetrySession::fingerprint($api_key) !== (string)$record['key_fingerprint']) {
            PaypercutTelemetrySession::end('key_changed');
            return;
        }

        if ($environment !== (string)$record['environment']) {
            PaypercutTelemetrySession::end('environment_changed');
        }
    }

    /**
     * Re-send the configuration snapshot after a mid-session settings save.
     */
    private function resendConfigurationSnapshot()
    {
        $this->telemetry();

        if (!PaypercutTelemetrySession::isActiveFast()) {
            return;
        }

        // OpenCart does not reload $config after editSetting(), so the snapshot
        // would otherwise describe the settings as they were before this save.
        foreach ($this->request->post as $key => $value) {
            if (strpos($key, 'paypercut_') === 0 && is_scalar($value)) {
                $this->config->set($key, $value);
            }
        }

        PaypercutEventQueue::append(array(
            PaypercutEvent::environmentConfiguration(PaypercutEnvironmentSnapshot::values())->envelope()
        ));
    }

    /**
     * Start a debug session: mint a token and publish the session.
     *
     * OpenCart's admin `token` query parameter is the CSRF token and is checked
     * by the admin startup controller before this route runs.
     */
    public function startDebugSession()
    {
        $this->load->language('extension/payment/paypercut');
        $this->telemetry();

        if (!PaypercutTelemetryContext::canManage()) {
            $this->respondJson(array('message' => $this->language->get('error_permission')), 403);
            return;
        }

        if (!$this->debugSessionStartEnabled()) {
            $this->respondJson(array('message' => $this->language->get('error_telemetry_disabled')), 403);
            return;
        }

        PaypercutTelemetrySession::reap();

        $state = PaypercutTelemetrySession::describe();

        if ($state['state'] === 'running') {
            $state['already_running'] = true;
            $state['now'] = time();

            $this->respondJson(array('success' => true, 'data' => $state), 200);
            return;
        }

        if (!PaypercutTelemetrySession::claimStartLock()) {
            $this->respondJson(array('message' => $this->language->get('error_telemetry_start_locked')), 409);
            return;
        }

        // Built inside the guard and emitted outside it: respondJson() ends the
        // request, and a response sent before the release would strand the
        // start lock for its full TTL and block the merchant's next attempt.
        try {
            $result = $this->mintDebugSession();
        } catch (Exception $e) {
            PaypercutTelemetrySession::releaseStartLock();

            throw $e;
        }

        PaypercutTelemetrySession::releaseStartLock();

        $this->respondJson(
            $result['ok'] ? array('success' => true, 'data' => $result['data']) : $result['data'],
            $result['status']
        );
    }

    private function mintDebugSession()
    {
        require_once DIR_SYSTEM . 'library/paypercut/environment.php';

        $connection = PaypercutTelemetrySession::connection();

        if ($connection['secret'] === '') {
            return $this->debugSessionError(
                array('message' => $this->language->get('error_telemetry_no_key')),
                400
            );
        }

        /*
         * Both hosts come from this one environment value, resolved here in one
         * sequence. A token minted for one environment is rejected by every
         * other environment's edge, so they are never resolved independently.
         */
        $mint_base = PaypercutEnvironment::apiBaseUri($connection['environment']);
        $edge_base = PaypercutEnvironment::telemetryBaseUri($connection['environment']);

        if ($edge_base === '') {
            return $this->debugSessionError(
                array(
                    'message' => $connection['environment'] === ''
                        ? $this->language->get('error_telemetry_no_environment')
                        : $this->language->get('error_telemetry_environment_unsupported')
                ),
                400
            );
        }

        $minter = new PaypercutTokenMinter();
        $response = $minter->mint($connection['secret'], $mint_base);
        $status = (int)$response['status'];

        if ($status !== 200) {
            return $this->rejectDebugSession(PaypercutMintErrorMapper::map($status, $response['body']), $response, $status);
        }

        if ($response['token'] === '' || $response['expires_at'] === '') {
            return $this->rejectDebugSession(PaypercutMintErrorMapper::badResponse(), $response, 502);
        }

        $now = time();
        $lifetime = PaypercutTokenMinter::deriveLifetime($response['expires_at'], $response['date'], $now);
        $skew = PaypercutTokenMinter::skew($response['date'], $now);

        if ($lifetime < PaypercutTelemetrySession::MIN_LIFETIME_SECONDS) {
            return $this->rejectDebugSession(PaypercutMintErrorMapper::clockSkew($skew), $response, 400);
        }

        $expires_at = $now
            + min($lifetime, PaypercutTelemetrySession::SESSION_MAX_SECONDS)
            - PaypercutTelemetrySession::SKEW_SECONDS;

        /*
         * Re-check under the lock: if anything published a session while the
         * mint was in flight, discard this token rather than store a second
         * one. An unreferenced token cannot be deleted by any teardown path.
         */
        $existing = PaypercutTelemetrySession::describe();

        if ($existing['state'] === 'running') {
            $existing['already_running'] = true;
            $existing['now'] = time();

            return array('ok' => true, 'data' => $existing, 'status' => 200);
        }

        $session_id = PaypercutTelemetrySession::newSessionId();

        PaypercutTelemetrySession::begin(
            array(
                'status' => 'active',
                'session_id' => $session_id,
                'environment' => $connection['environment'],
                'edge_base' => $edge_base,
                'started_at' => $now,
                'expires_at' => $expires_at,
                'started_by' => (int)$this->user->getId(),
                'started_by_name' => (string)$this->user->getUserName(),
                'key_fingerprint' => PaypercutTelemetrySession::fingerprint($connection['secret']),
                'ended_at' => 0,
                'reason_code' => '',
                'trace_id' => PaypercutEvent::identifier($response['trace_id']),
                'request_id' => PaypercutEvent::identifier($response['request_id'])
            ),
            $response['token']
        );

        $snapshot = PaypercutEnvironmentSnapshot::values();

        $envelopes = array(
            PaypercutEvent::sessionStarted($session_id, $connection['environment'], $expires_at)->envelope(),
            PaypercutEvent::environmentSnapshot($snapshot)->envelope(),
            PaypercutEvent::environmentConfiguration($snapshot)->envelope()
        );

        // The list support compares against a working store when a conflict is
        // suspected; chunked because a store can run more extensions than one
        // event has room for.
        foreach (PaypercutEvent::environmentPlugins(PaypercutActiveExtensions::values()) as $event) {
            $envelopes[] = $event->envelope();
        }

        PaypercutEventQueue::append($envelopes);

        PaypercutTelemetrySession::audit(
            'Telemetry: debug session started',
            array(
                'session_id' => $session_id,
                'environment' => $connection['environment'],
                'expires_at' => $expires_at,
                'clock_skew_s' => $skew
            )
        );

        $state = PaypercutTelemetrySession::describe();
        $state['now'] = time();

        return array('ok' => true, 'data' => $state, 'status' => 200);
    }

    /**
     * Record a start that did not happen, so the merchant can see why.
     */
    private function rejectDebugSession($mapped, $response, $status)
    {
        $trace_id = PaypercutEvent::identifier($response['trace_id']);
        $request_id = PaypercutEvent::identifier($response['request_id']);

        PaypercutTelemetrySession::fail($mapped, $trace_id, $request_id);

        PaypercutTelemetrySession::audit(
            'Telemetry: mint rejected',
            array(
                'status' => (int)$response['status'],
                'reason_code' => $mapped['reason_code'],
                'trace_id' => $trace_id,
                'request_id' => $request_id
            )
        );

        return $this->debugSessionError(
            array(
                'message' => $mapped['message'],
                'reason_code' => $mapped['reason_code'],
                'retryable' => $mapped['retryable'],
                'trace_id' => $trace_id,
                'request_id' => $request_id
            ),
            $status
        );
    }

    private function debugSessionError($data, $status)
    {
        return array(
            'ok' => false,
            'data' => $data,
            'status' => $status >= 400 && $status < 600 ? $status : 502
        );
    }

    /**
     * End the session early at the merchant's request.
     */
    public function stopDebugSession()
    {
        $this->load->language('extension/payment/paypercut');
        $this->telemetry();

        if (!PaypercutTelemetryContext::canManage()) {
            $this->respondJson(array('message' => $this->language->get('error_permission')), 403);
            return;
        }

        $record = PaypercutTelemetrySession::record();
        $runtime = PaypercutTelemetrySession::runtime();

        if (isset($record['status']) && $record['status'] === 'active') {
            PaypercutEventQueue::append(array(
                PaypercutEvent::sessionStopped(
                    (string)$record['session_id'],
                    'merchant_stopped',
                    (int)(isset($runtime['events_sent']) ? $runtime['events_sent'] : 0),
                    (int)(isset($runtime['events_dropped']) ? $runtime['events_dropped'] : 0)
                )->envelope()
            ));

            /*
             * Twice: the first pass clears anything already parked in flight,
             * the second carries the stop event itself. Without it, end() would
             * delete the queue holding the event that announces the stop.
             * Bounded on purpose - each pass can block for up to the edge
             * timeout, and this is a button click.
             */
            $flusher = new PaypercutFlusher();

            for ($attempt = 0; $attempt < 2; $attempt++) {
                if (!$flusher->flushOnce()) {
                    break;
                }
            }
        }

        PaypercutTelemetrySession::end('merchant_stopped');

        $state = PaypercutTelemetrySession::describe();
        $state['now'] = time();

        $this->respondJson(array('success' => true, 'data' => $state), 200);
    }

    /**
     * The panel's poll, which doubles as the delivery trigger.
     *
     * An authenticated admin request is the only place events are sent from, so
     * while the merchant has this screen open, this is what drains the queue.
     */
    public function debugSessionStatus()
    {
        $this->load->language('extension/payment/paypercut');
        $this->telemetry();

        if (!PaypercutTelemetryContext::canManage()) {
            $this->respondJson(array('message' => $this->language->get('error_permission')), 403);
            return;
        }

        PaypercutTelemetrySession::reap();

        $flusher = new PaypercutFlusher();
        $flusher->flushOnce();

        // `now` travels with `expires_at` so the countdown is driven by the
        // server's clock; a browser with a wrong clock would otherwise show a
        // remaining time that does not match when the session actually ends.
        $state = PaypercutTelemetrySession::describe();
        $state['now'] = time();

        $this->respondJson(array('success' => true, 'data' => $state), 200);
    }

    /**
     * Tell every administrator that this store is currently sending diagnostics.
     *
     * Fired from admin/view/common/header/after. The permission is held by more
     * than one user and the extension's own logger is gated on a merchant
     * preference, so without this a session could run with no visible trace for
     * anyone but the person who started it.
     */
    public function debugSessionNotice(&$route, &$data, &$output)
    {
        $this->telemetry();

        if (!PaypercutTelemetryContext::canManage()) {
            return;
        }

        $record = PaypercutTelemetrySession::record();

        if (!isset($record['status']) || $record['status'] !== 'active'
            || (int)$record['expires_at'] <= time()) {
            return;
        }

        $this->load->language('extension/payment/paypercut');

        $output .= '<div class="container-fluid"><div class="alert alert-info" style="margin-top:15px;">'
            . '<i class="fa fa-info-circle"></i> '
            . sprintf(
                $this->language->get('text_telemetry_notice'),
                htmlspecialchars((string)$record['started_by_name'], ENT_QUOTES, 'UTF-8'),
                date('H:i', (int)$record['expires_at'])
            )
            . ' <a href="' . htmlspecialchars(
                $this->url->link('extension/payment/paypercut', 'token=' . $this->session->data['token'], true),
                ENT_QUOTES,
                'UTF-8'
            ) . '">' . $this->language->get('text_telemetry_manage') . '</a>'
            . '</div></div>';
    }

    private function respondJson($payload, $status)
    {
        $protocol = isset($this->request->server['SERVER_PROTOCOL']) ? $this->request->server['SERVER_PROTOCOL'] : 'HTTP/1.1';

        $this->response->addHeader($protocol . ' ' . (int)$status . ' ' . self::statusText($status));
        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($payload));
    }

    private static function statusText($status)
    {
        $texts = array(
            200 => 'OK',
            400 => 'Bad Request',
            403 => 'Forbidden',
            409 => 'Conflict',
            502 => 'Bad Gateway'
        );

        return isset($texts[(int)$status]) ? $texts[(int)$status] : 'OK';
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

        // Debug-session storage. Created here and re-checked lazily at runtime,
        // so a store that upgrades without re-installing still gets them.
        $this->telemetry();
        PaypercutTelemetryStore::ensureTables();

        // Register event for order info page to display Paypercut payment information
        $this->load->model('extension/event');
        $this->model_extension_event->addEvent(
            'paypercut_order_info',
            'admin/view/sale/order_info/after',
            'sale/paypercut_order/info'
        );

        // Every admin page, so a running debug session is visible to everyone
        // who can manage the extension - not only whoever started it.
        $this->model_extension_event->addEvent(
            'paypercut_debug_session_notice',
            'admin/view/common/header/after',
            'extension/payment/paypercut/debugSessionNotice'
        );
    }

    /**
     * Uninstall method - Called when extension is uninstalled
     * Removes events (but preserves database tables for data integrity)
     */
    public function uninstall()
    {
        // Remove events
        $this->load->model('extension/event');
        $this->model_extension_event->deleteEvent('paypercut_order_info');
        $this->model_extension_event->deleteEvent('paypercut_debug_session_notice');

        $this->removeTelemetryData();

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
     * Destroy every trace of a debug session on uninstall.
     *
     * end() is the single teardown path and removes the token, the queue, the
     * inflight buffer and the runtime record; the sent log and both lock rows
     * are removed here because nothing else references them afterwards.
     */
    private function removeTelemetryData()
    {
        $this->telemetry();

        PaypercutTelemetrySession::end('deactivated');

        PaypercutSentLog::clear();
        PaypercutTelemetryStore::deleteRecord();

        foreach (array(
            PaypercutTelemetrySession::TOKEN_KEY,
            PaypercutTelemetrySession::QUEUE_KEY,
            PaypercutTelemetrySession::INFLIGHT_KEY,
            PaypercutTelemetrySession::RUNTIME_KEY,
            PaypercutSentLog::KEY
        ) as $key) {
            PaypercutTelemetryStore::delete($key);
        }

        $this->db->query("DELETE FROM `" . DB_PREFIX . "paypercut_telemetry_lock`");
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

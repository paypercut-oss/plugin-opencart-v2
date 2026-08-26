<?php
define('PAYPERCUT_PLUGIN_VERSION', '1.0.5');

class ControllerExtensionPaymentPaypercut extends Controller
{
    /**
     * Absolute Paypercut API URL for the store's connection environment.
     */
    private function apiUrl($path)
    {
        require_once DIR_SYSTEM . 'library/paypercut/environment.php';

        return PaypercutEnvironment::apiUrl($this->config->get('paypercut_environment'), $path);
    }

    /**
     * Report a diagnostic event. A no-op unless a debug session is running.
     *
     * This runs on anonymous checkout and webhook requests, so it must stay
     * cheap: the recorder reads one already-loaded setting and returns.
     */
    private function report($event)
    {
        require_once DIR_SYSTEM . 'library/paypercut/telemetry/bootstrap.php';

        PaypercutTelemetry::boot($this->registry, false);
        PaypercutTelemetry::record($event);
    }

    /**
     * The order reference a shopper and a merchant would both recognise.
     */
    private function orderRef($order_id)
    {
        return $order_id ? (string)$order_id : '';
    }

    /**
     * Build the /v1/checkouts payload for the current cart: line items with
     * tax_rates_data, shipping via shipping_options, reconciled against the
     * order total (matches the Magento/PrestaShop Paypercut schema).
     */
    private function buildCheckoutData($order_id, $order_info)
    {
        $currency_code = $order_info['currency_code'];
        $currency_value = $order_info['currency_value'];

        $total_amount = (int)round($this->currency->format($order_info['total'], $currency_code, $currency_value, false) * 100);

        $products = $this->cart->getProducts();
        $line_items = array();
        $line_items_total = 0;

        foreach ($products as $product) {
            $quantity = (int)$product['quantity'];
            $unit_price_excl_tax = (float)$this->currency->format($product['price'], $currency_code, $currency_value, false);
            $unit_tax = (float)$this->currency->format($this->tax->getTax($product['price'], $product['tax_class_id']), $currency_code, $currency_value, false);

            $tax_rate_data = null;
            $unit_amount = (int)round($unit_price_excl_tax * 100);

            if ($unit_price_excl_tax > 0 && $unit_tax > 0) {
                $tax_percentage = ($unit_tax / $unit_price_excl_tax) * 100;

                // The API rejects a percentage outside 0-100; fall back to unspecified
                // tax_behavior for that line rather than let a single item break checkout.
                if ($tax_percentage <= 100) {
                    $unit_amount = (int)round(($unit_price_excl_tax + $unit_tax) * 100);
                    $tax_rate_data = array(
                        'display_name' => 'Tax',
                        'percentage' => number_format($tax_percentage, 2, '.', ''),
                        'inclusive' => true
                    );
                }
            }

            $line_item = array(
                'quantity' => $quantity,
                'price_data' => array(
                    'currency' => $currency_code,
                    'unit_amount' => $unit_amount,
                    'type' => 'one_time',
                    'tax_behavior' => $tax_rate_data === null ? 'unspecified' : 'inclusive',
                    'product_data' => array(
                        'name' => $product['name']
                    )
                )
            );

            if ($tax_rate_data !== null) {
                $line_item['tax_rates_data'] = array($tax_rate_data);
            }

            $line_items[] = $line_item;
            $line_items_total += $unit_amount * $quantity;
        }

        // Shipping is sent via shipping_options (not as a line item) so the checkout UI
        // renders it in its own row, matching the Magento/PrestaShop integrations.
        $shipping_options = array();
        $shipping_amount = 0;

        // Same check as Cart::hasShipping(), against the $products already fetched
        // above -- avoids re-running its full getProducts() query battery again.
        $cart_has_shipping = false;
        foreach ($products as $product) {
            if ($product['shipping']) {
                $cart_has_shipping = true;
                break;
            }
        }

        if ($cart_has_shipping && isset($this->session->data['shipping_method'])) {
            $shipping_method = $this->session->data['shipping_method'];
            $shipping_cost_excl_tax = (float)$this->currency->format($shipping_method['cost'], $currency_code, $currency_value, false);
            $shipping_tax = 0;

            if (!empty($shipping_method['tax_class_id'])) {
                $shipping_tax = (float)$this->currency->format($this->tax->getTax($shipping_method['cost'], $shipping_method['tax_class_id']), $currency_code, $currency_value, false);
            }

            $shipping_amount = (int)round(($shipping_cost_excl_tax + $shipping_tax) * 100);

            if ($shipping_amount > 0) {
                $shipping_options[] = array(
                    'shipping_rate_data' => array(
                        'display_name' => $shipping_method['title'],
                        'type' => 'fixed_amount',
                        'fixed_amount' => array(
                            'amount' => $shipping_amount,
                            'currency' => $currency_code
                        ),
                        'tax_behavior' => 'inclusive'
                    )
                );
            }
        }

        $line_items_delta = $total_amount - $shipping_amount - $line_items_total;

        if ($line_items_delta > 0) {
            $line_items[] = array(
                'quantity' => 1,
                'price_data' => array(
                    'currency' => $currency_code,
                    'unit_amount' => $line_items_delta,
                    'type' => 'one_time',
                    'product_data' => array(
                        'name' => 'Order adjustment'
                    )
                )
            );
        } elseif ($line_items_delta < 0) {
            $line_items = array();
            $shipping_options = array();
        }

        $data = array(
            'amount' => $total_amount,
            'currency' => $currency_code,
            'order_id' => $order_id,
            'return_url' => $this->url->link('extension/payment/paypercut/callback', '', true),
            'cancel_url' => $this->url->link('checkout/checkout', '', true),
            'customer' => array(
                'email' => $order_info['email'],
                'firstname' => $order_info['firstname'],
                'lastname' => $order_info['lastname']
            )
        );

        if (!empty($line_items)) {
            $data['line_items'] = $line_items;
        }

        if (!empty($shipping_options)) {
            $data['shipping_options'] = $shipping_options;
        }

        return $data;
    }

    public function index()
    {
        $this->load->language('extension/payment/paypercut');

        $data['button_confirm'] = $this->language->get('button_confirm');
        $data['text_loading'] = $this->language->get('text_loading');
        $data['text_payment_methods'] = $this->language->get('text_payment_methods');
        $data['text_secure_payment'] = $this->language->get('text_secure_payment');

        // Get enabled payment methods
        $data['payment_methods'] = array();

        // Always show cards
        $data['payment_methods'][] = array(
            'type' => 'card',
            'name' => 'Credit/Debit Card',
            'icon' => 'fa-credit-card'
        );

        // Check if Google Pay is enabled
        if ($this->config->get('paypercut_google_pay')) {
            $data['payment_methods'][] = array(
                'type' => 'google_pay',
                'name' => 'Google Pay',
                'icon' => 'fa-google'
            );
        }

        // Check if Apple Pay is enabled
        if ($this->config->get('paypercut_apple_pay')) {
            $data['payment_methods'][] = array(
                'type' => 'apple_pay',
                'name' => 'Apple Pay',
                'icon' => 'fa-apple'
            );
        }

        // Fetch additional payment methods from Payment Method Configuration if set
        $payment_method_config_id = $this->config->get('paypercut_payment_method_config');
        if ($payment_method_config_id) {
            $additional_methods = $this->getPaymentMethodsFromConfig($payment_method_config_id);
            if ($additional_methods) {
                $data['payment_methods'] = array_merge($data['payment_methods'], $additional_methods);
            }
        }

        // Check if embedded mode is enabled
        $checkout_mode = $this->config->get('paypercut_checkout_mode');
        $data['checkout_mode'] = $checkout_mode;

        // Build wallet_options array for embedded checkout
        $wallet_options = array();
        if ($this->config->get('paypercut_apple_pay')) {
            $wallet_options[] = 'apple_pay';
        }
        if ($this->config->get('paypercut_google_pay')) {
            $wallet_options[] = 'google_pay';
        }
        $data['wallet_options'] = $wallet_options;

        // For embedded mode, create checkout session immediately
        if ($checkout_mode === 'embedded') {
            try {
                $checkout_data = $this->createCheckoutSession();
                $order_ref = $this->orderRef(isset($this->session->data['order_id']) ? $this->session->data['order_id'] : '');

                if (isset($checkout_data['checkout_id'])) {
                    $data['checkout_id'] = $checkout_data['checkout_id'];
                    // Store checkout_id in session for later verification
                    $this->session->data['paypercut_checkout_id'] = $checkout_data['checkout_id'];

                    $this->report(PaypercutEvent::of('checkout.embedded.session_created')->about(array(
                        'payment_intent_id' => $checkout_data['checkout_id'],
                        'order_ref' => $order_ref
                    )));
                } else {
                    $data['checkout_error'] = 'Failed to initialize payment form';

                    $this->report(PaypercutEvent::failure('checkout.embedded.create_failed', 'no_session_id')->about(array(
                        'order_ref' => $order_ref
                    )));
                }
            } catch (Exception $e) {
                $this->logError('Failed to create embedded checkout: ' . $e->getMessage());
                $data['checkout_error'] = 'Failed to initialize payment form';

                $this->report(PaypercutEvent::failure('checkout.embedded.create_failed', 'session_create', array(), $e));
            }
        }

        $data['continue'] = $this->url->link('checkout/success');

        return $this->load->view('extension/payment/paypercut', $data);
    }

    private function getPaymentMethodsFromConfig($config_id)
    {
        $api_key = $this->config->get('paypercut_api_key');
        $api_url = $this->apiUrl('v1/payment-configs/' . $config_id);

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
            // Parse payment methods from config
            // This will depend on the API response structure
            return array();
        }

        return array();
    }

    private function createCheckoutSession()
    {
        // Load the order
        $this->load->model('checkout/order');
        $order_id = isset($this->session->data['order_id']) ? $this->session->data['order_id'] : null;

        if (!$order_id) {
            throw new Exception('Order ID not found');
        }

        $order_info = $this->model_checkout_order->getOrder($order_id);

        if (!$order_info) {
            throw new Exception('Order not found');
        }

        $data = $this->buildCheckoutData($order_id, $order_info);

        // Make API call to Paypercut
        return $this->sendPaymentRequest($data);
    }

    public function send()
    {
        $this->load->language('extension/payment/paypercut');

        $json = array();

        try {
            // Load the order
            $this->load->model('checkout/order');
            $order_id = isset($this->session->data['order_id']) ? $this->session->data['order_id'] : null;

            if (!$order_id) {
                throw new Exception($this->language->get('error_no_order'));
            }

            $order_info = $this->model_checkout_order->getOrder($order_id);

            if (!$order_info) {
                throw new Exception($this->language->get('error_order'));
            }

            $data = $this->buildCheckoutData($order_id, $order_info);

            // Make API call to Paypercut
            $response = $this->sendPaymentRequest($data);

            $order_ref = $this->orderRef($order_id);

            if (isset($response['error'])) {
                // sendPaymentRequest() has already reported the API-side detail;
                // the message itself is upstream prose and is never sent.
                throw new Exception($response['error']);
            }

            if (isset($response['mode'])) {
                if ($response['mode'] === 'embedded') {
                    // For embedded mode, return checkout ID for JavaScript integration
                    if (isset($response['checkout_id'])) {
                        $json['checkout_id'] = $response['checkout_id'];
                        $json['mode'] = 'embedded';

                        $this->report(PaypercutEvent::of('checkout.embedded.session_created')->about(array(
                            'payment_intent_id' => $response['checkout_id'],
                            'order_ref' => $order_ref
                        )));
                    } else {
                        $this->report(PaypercutEvent::failure('checkout.embedded.create_failed', 'no_session_id')->about(array(
                            'order_ref' => $order_ref
                        )));

                        throw new Exception($this->language->get('error_checkout'));
                    }
                } else {
                    // For hosted mode, return redirect URL
                    if (isset($response['payment_url'])) {
                        $json['redirect'] = $response['payment_url'];
                        // Store checkout_id in session for callback verification
                        if (isset($response['checkout_id'])) {
                            $this->session->data['paypercut_checkout_id'] = $response['checkout_id'];
                        } elseif (isset($response['id'])) {
                            $this->session->data['paypercut_checkout_id'] = $response['id'];
                        }

                        $this->report(PaypercutEvent::of(
                            'checkout.hosted.redirected',
                            array('order_status' => (string)$order_info['order_status_id'])
                        )->about(array(
                            'payment_id' => isset($response['checkout_id']) ? $response['checkout_id'] : '',
                            'order_ref' => $order_ref
                        )));
                    } else {
                        $this->report(PaypercutEvent::failure('checkout.hosted.redirect_missing', 'redirect_absent')->about(array(
                            'order_ref' => $order_ref
                        )));

                        throw new Exception($this->language->get('error_checkout'));
                    }
                }
            } else {
                throw new Exception($this->language->get('error_checkout'));
            }
        } catch (Exception $e) {
            $this->logError('Payment send error: ' . $e->getMessage());
            $json['error'] = $e->getMessage();

            // The message may be the platform's own prose quoting the rejected
            // request back, so name the exception type instead of sending it.
            $this->report(
                PaypercutEvent::failure('checkout.session_create_failed', 'transport')
                    ->because('threw ' . PaypercutEvent::shortClassName($e))
            );
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function confirm()
    {
        $this->load->language('extension/payment/paypercut');
        $this->load->model('checkout/order');

        $json = array();

        try {
            // Get order from session
            $order_id = isset($this->session->data['order_id']) ? $this->session->data['order_id'] : null;

            if (!$order_id) {
                throw new Exception('Order ID not found in session');
            }

            $order_info = $this->model_checkout_order->getOrder($order_id);

            if (!$order_info) {
                throw new Exception('Order not found');
            }

            // Get checkout_id from session
            $checkout_id = isset($this->session->data['paypercut_checkout_id']) ? $this->session->data['paypercut_checkout_id'] : '';

            if (!$checkout_id) {
                throw new Exception('Checkout ID not found in session');
            }

            // Verify checkout status with Paypercut API
            $checkout_data = $this->verifyCheckout($checkout_id);

            if (!$checkout_data) {
                throw new Exception('Failed to verify checkout status');
            }

            // Check if checkout is complete
            if ($checkout_data['status'] !== 'complete') {
                throw new Exception('Checkout is not complete. Status: ' . $checkout_data['status']);
            }

            // Extract payment_id and payment_intent from checkout data
            $payment_id = isset($checkout_data['id']) ? $checkout_data['id'] : $checkout_id;

            // Store checkout and payment details in database
            $this->storeCheckoutTransaction(
                $order_id,
                $checkout_id,
                $payment_id,
                $checkout_data
            );

            // Add order history with completed status
            $comment = 'Payment completed via Paypercut (Embedded Checkout)' . PHP_EOL;
            $comment .= 'Checkout ID: ' . $checkout_id . PHP_EOL;
            if ($payment_id) {
                $comment .= 'Payment ID: ' . $payment_id . PHP_EOL;
            }

            $comment .= 'Amount: ' . number_format($checkout_data['amount_total'] / 100, 2) . ' ' . strtoupper($checkout_data['currency']);

            // Use completed order status
            $order_status_id = $this->config->get('paypercut_order_status_id');

            // Add order history to confirm the order
            $this->model_checkout_order->addOrderHistory(
                $order_id,
                $order_status_id,
                $comment,
                true
            );

            // Clear paypercut-specific session data
            unset($this->session->data['paypercut_checkout_id']);

            $json['success'] = true;
            $json['order_id'] = $order_id;
            $json['redirect'] = $this->url->link('checkout/success', '', true);

            $this->logDebug('Order confirmed for embedded checkout: Order #' . $order_id . ', Checkout ID: ' . $checkout_id . ', Payment ID: ' . $payment_id);

            $this->report(PaypercutEvent::of(
                'checkout.embedded.order_created',
                array(
                    'order_status' => (string)$order_status_id,
                    'session_matched' => true,
                    'verified_status' => PaypercutEvent::identifier($checkout_data['status'])
                )
            )->about(array(
                'payment_id' => (string)$payment_id,
                'order_ref' => $this->orderRef($order_id)
            )));
        } catch (Exception $e) {
            $this->logError('Order confirmation error: ' . $e->getMessage());
            $json['error'] = $e->getMessage();

            $this->report(
                PaypercutEvent::failure('checkout.return.unverifiable', 'lookup_failed')
                    ->because('threw ' . PaypercutEvent::shortClassName($e))
            );
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function callback()
    {
        $this->load->language('extension/payment/paypercut');
        $this->load->model('checkout/order');

        try {
            // Debug: Log all incoming parameters
            $this->logDebug('Callback received - GET params: ' . json_encode($this->request->get));

            // Get checkout_id from URL parameter first, then fall back to session
            $checkout_id = isset($this->request->get['checkout_id']) ? $this->request->get['checkout_id'] : '';

            // If not in URL, try session (used for hosted checkout)
            if (empty($checkout_id) && isset($this->session->data['paypercut_checkout_id'])) {
                $checkout_id = $this->session->data['paypercut_checkout_id'];
                $this->logDebug('Using checkout_id from session: ' . $checkout_id);
            }

            $this->logDebug('Callback checkout_id: ' . ($checkout_id ?: 'empty'));

            // Check checkout mode to determine how to handle the callback
            $checkout_mode = $this->config->get('paypercut_checkout_mode') ?: 'hosted';

            if ($checkout_id && $checkout_mode === 'embedded') {
                // Handle embedded checkout callback
                return $this->callbackEmbedded();
            }

            // For hosted mode, verify checkout session if checkout_id is provided
            if ($checkout_id) {
                return $this->callbackHosted($checkout_id);
            }

            // Fallback: Get parameters from Paypercut redirect (legacy mode)
            $payment_id = isset($this->request->get['payment_id']) ? $this->request->get['payment_id'] : '';
            $status = isset($this->request->get['status']) ? $this->request->get['status'] : '';
            $order_id = isset($this->session->data['order_id']) ? $this->session->data['order_id'] : '';

            if (!$order_id) {
                throw new Exception('Order ID not found');
            }

            if ($payment_id) {
                // Verify payment status with Paypercut API
                $payment_data = $this->verifyPayment($payment_id);

                if (!$payment_data || !is_array($payment_data)) {
                    throw new Exception('Unable to verify payment status');
                }

                $payment_status = $payment_data['status'];

                // Store payment details in session for display
                $this->session->data['paypercut_payment'] = array(
                    'payment_id' => $payment_id,
                    'status' => $payment_status,
                    'payment_method' => $payment_data['payment_method'] ?? null,
                    'amount' => $payment_data['formatted_amount'] ?? null
                );

                $order_ref = $this->orderRef($order_id);

                // Handle different payment statuses
                switch ($payment_status) {
                    case 'succeeded':
                        $comment = 'Payment completed via Paypercut' . PHP_EOL;
                        $comment .= 'Transaction ID: ' . $payment_id . PHP_EOL;
                        $comment .= 'Amount: ' . ($payment_data['formatted_amount'] ?? '');

                        if (isset($payment_data['payment_method_details'])) {
                            $comment .= $this->formatPaymentMethodDetails($payment_data['payment_method_details']);
                        }

                        $this->model_checkout_order->addOrderHistory(
                            $order_id,
                            $this->config->get('paypercut_order_status_id'),
                            $comment,
                            true
                        );

                        $this->report(PaypercutEvent::of(
                            'payment.succeeded',
                            array(
                                'session_status' => PaypercutEvent::identifier($payment_status),
                                'order_status' => (string)$this->config->get('paypercut_order_status_id'),
                                'order_updated' => true
                            )
                        )->about(array('payment_id' => (string)$payment_id, 'order_ref' => $order_ref)));

                        $this->response->redirect($this->url->link('extension/payment/paypercut/success', '', true));
                        break;

                    case 'pending':
                    case 'processing':
                        $comment = 'Payment pending via Paypercut' . PHP_EOL;
                        $comment .= 'Transaction ID: ' . $payment_id;

                        $order_status_id = $this->getOrderStatusForPaymentStatus('pending');
                        $this->model_checkout_order->addOrderHistory($order_id, $order_status_id, $comment, false);

                        $this->report(PaypercutEvent::of(
                            'checkout.return.pending',
                            array(
                                'payment_status' => PaypercutEvent::identifier($payment_status),
                                'session_status' => PaypercutEvent::identifier($payment_status),
                                'order_status' => (string)$order_status_id
                            )
                        )->about(array('payment_id' => (string)$payment_id, 'order_ref' => $order_ref)));

                        $this->response->redirect($this->url->link('extension/payment/paypercut/pending', '', true));
                        break;

                    case 'failed':
                    case 'canceled':
                        $comment = 'Payment failed via Paypercut' . PHP_EOL;
                        $comment .= 'Transaction ID: ' . $payment_id . PHP_EOL;
                        $comment .= 'Reason: ' . ($payment_data['failure_message'] ?? 'Payment was not completed');

                        $order_status_id = $this->getOrderStatusForPaymentStatus('failed');
                        $this->model_checkout_order->addOrderHistory($order_id, $order_status_id, $comment, false);

                        $this->report(PaypercutEvent::failure(
                            'payment.failed',
                            PaypercutEvent::identifier($payment_status) ?: 'unknown',
                            array(
                                'payment_status' => PaypercutEvent::identifier($payment_status),
                                'session_status' => PaypercutEvent::identifier($payment_status),
                                'order_status' => (string)$order_status_id,
                                'order_updated' => true
                            )
                        )->about(array('payment_id' => (string)$payment_id, 'order_ref' => $order_ref)));

                        $this->report(PaypercutEvent::of(
                            'order.marked_failed',
                            array(
                                'source' => 'checkout_return_legacy',
                                'payment_status' => PaypercutEvent::identifier($payment_status),
                                'from_status' => (string)$order_info['order_status_id'],
                                'to_status' => (string)$order_status_id
                            )
                        )->about(array('order_ref' => $order_ref)));

                        $this->response->redirect($this->url->link('extension/payment/paypercut/failure', '', true));
                        break;

                    default:
                        $this->logError('Unknown payment status: ' . $payment_status);

                        $this->report(PaypercutEvent::failure(
                            'order.status_unhandled',
                            'unknown_payment_status',
                            array(
                                'source' => 'checkout_return_legacy',
                                'payment_status' => PaypercutEvent::identifier($payment_status),
                                'order_status' => (string)$order_info['order_status_id']
                            )
                        )->about(array('payment_id' => (string)$payment_id, 'order_ref' => $order_ref)));

                        $this->response->redirect($this->url->link('extension/payment/paypercut/pending', '', true));
                }
            } else {
                $this->report(PaypercutEvent::failure('checkout.return.unverifiable', 'no_session_meta')
                    ->about(array('order_ref' => $this->orderRef($order_id))));

                throw new Exception('Payment ID not provided');
            }
        } catch (Exception $e) {
            $this->logError('Callback error: ' . $e->getMessage());

            $this->report(PaypercutEvent::failure('checkout.return.unverifiable', 'lookup_failed', array(), $e));

            $this->response->redirect($this->url->link('extension/payment/paypercut/failure', '', true));
        }
    }

    /**
     * Handle hosted checkout callback
     */
    private function callbackHosted($checkout_id)
    {
        try {
            // Get order from session
            $order_id = isset($this->session->data['order_id']) ? $this->session->data['order_id'] : null;

            if (!$order_id) {
                throw new Exception('Order ID not found in session');
            }

            $order_info = $this->model_checkout_order->getOrder($order_id);

            if (!$order_info) {
                throw new Exception('Order not found: ' . $order_id);
            }

            // Verify checkout status with Paypercut API
            $checkout_data = $this->verifyCheckout($checkout_id);

            if (!$checkout_data) {
                throw new Exception('Failed to verify checkout status - API returned null');
            }

            // Check if checkout is complete
            if (!isset($checkout_data['status'])) {
                throw new Exception('Checkout status not found in API response');
            }

            $checkout_status = $checkout_data['status'];

            // Handle different checkout statuses
            if ($checkout_status === 'complete') {
                // Extract payment_id from checkout data
                $payment_id = isset($checkout_data['payment_intent']) ? $checkout_data['payment_intent'] : '';
                if (empty($payment_id)) {
                    $payment_id = isset($checkout_data['id']) ? $checkout_data['id'] : $checkout_id;
                }

                // Store checkout and payment details in database
                $this->storeCheckoutTransaction(
                    $order_id,
                    $checkout_id,
                    $payment_id,
                    $checkout_data
                );

                // Add order history with completed status
                $comment = 'Payment completed via Paypercut (Hosted Checkout)' . PHP_EOL;
                $comment .= 'Checkout ID: ' . $checkout_id . PHP_EOL;
                if ($payment_id && $payment_id !== $checkout_id) {
                    $comment .= 'Payment ID: ' . $payment_id . PHP_EOL;
                }

                if (isset($checkout_data['amount_total'])) {
                    // Extract currency - handle both string and array formats
                    $currency = '';
                    if (isset($checkout_data['currency'])) {
                        if (is_string($checkout_data['currency'])) {
                            $currency = strtoupper($checkout_data['currency']);
                        } elseif (is_array($checkout_data['currency']) && isset($checkout_data['currency']['iso'])) {
                            $currency = strtoupper($checkout_data['currency']['iso']);
                        }
                    }

                    if ($currency) {
                        $comment .= 'Amount: ' . number_format($checkout_data['amount_total'] / 100, 2) . ' ' . $currency;
                    }
                }

                // Use completed order status
                $order_status_id = $this->config->get('paypercut_order_status_id');

                if (!$order_status_id) {
                    $order_status_id = 2; // Default to Processing
                }

                // Add order history to confirm the order
                $this->model_checkout_order->addOrderHistory(
                    $order_id,
                    $order_status_id,
                    $comment,
                    true
                );

                // Store payment details in session for success page
                $currency_for_session = '';
                if (isset($checkout_data['currency'])) {
                    if (is_string($checkout_data['currency'])) {
                        $currency_for_session = strtoupper($checkout_data['currency']);
                    } elseif (is_array($checkout_data['currency']) && isset($checkout_data['currency']['iso'])) {
                        $currency_for_session = strtoupper($checkout_data['currency']['iso']);
                    }
                }

                $this->session->data['paypercut_payment'] = array(
                    'checkout_id' => $checkout_id,
                    'payment_id' => $payment_id,
                    'status' => 'succeeded',
                    'amount' => isset($checkout_data['amount_total']) && $currency_for_session
                        ? number_format($checkout_data['amount_total'] / 100, 2) . ' ' . $currency_for_session
                        : null
                );

                $this->report(PaypercutEvent::of(
                    'payment.succeeded',
                    array(
                        'session_status' => $checkout_status,
                        'order_status' => (string)$order_status_id,
                        'order_updated' => true
                    )
                )->about(array(
                    'payment_id' => (string)$payment_id,
                    'order_ref' => $this->orderRef($order_id)
                )));

                $this->report(PaypercutEvent::of(
                    'order.marked_paid',
                    array(
                        'source' => 'checkout_return_hosted',
                        'from_status' => (string)$order_info['order_status_id'],
                        'to_status' => (string)$order_status_id,
                        'target_status' => (string)$order_status_id
                    )
                )->about(array('order_ref' => $this->orderRef($order_id))));

                // Redirect to success page
                $this->response->redirect($this->url->link('checkout/success', '', true));
            } elseif ($checkout_status === 'expired') {
                $this->logError('Checkout expired: ' . $checkout_id);

                // An expired session is unambiguously a failure, unlike a
                // session that is merely still open - see payment.closed_unpaid.
                $this->report(PaypercutEvent::failure(
                    'payment.failed',
                    'expired',
                    array(
                        'payment_status' => 'unpaid',
                        'session_status' => $checkout_status,
                        'order_status' => (string)$order_info['order_status_id'],
                        'order_updated' => false
                    )
                )->about(array(
                    'payment_id' => (string)$checkout_id,
                    'order_ref' => $this->orderRef($order_id)
                )));

                $this->response->redirect($this->url->link('extension/payment/paypercut/failure', '', true));
            } elseif ($checkout_status === 'open') {
                // Checkout is still open - payment not completed
                $this->logError('Checkout still open (payment not completed): ' . $checkout_id);

                // Not a decline: the shopper returned before the session
                // settled. Reporting this as payment.failed would put a false
                // failure in front of a merchant whose payment may still land.
                $this->report(PaypercutEvent::failure(
                    'payment.closed_unpaid',
                    'open',
                    array(
                        'payment_status' => 'unpaid',
                        'session_status' => $checkout_status,
                        'order_status' => (string)$order_info['order_status_id'],
                        'order_updated' => false
                    )
                )->about(array(
                    'payment_id' => (string)$checkout_id,
                    'order_ref' => $this->orderRef($order_id)
                )));

                $this->response->redirect($this->url->link('extension/payment/paypercut/failure', '', true));
            } else {
                $this->report(PaypercutEvent::failure(
                    'order.status_unhandled',
                    'unknown_payment_status',
                    array(
                        'source' => 'checkout_return_hosted',
                        'payment_status' => PaypercutEvent::identifier($checkout_status),
                        'order_status' => (string)$order_info['order_status_id']
                    )
                )->about(array('order_ref' => $this->orderRef($order_id))));

                throw new Exception('Unknown checkout status: ' . $checkout_status);
            }
        } catch (Exception $e) {
            $this->logError('Hosted checkout callback error: ' . $e->getMessage());

            $this->report(
                PaypercutEvent::failure('checkout.return.unverifiable', 'lookup_failed', array(), $e)
            );

            $this->response->redirect($this->url->link('extension/payment/paypercut/failure', '', true));
        }
    }

    public function callbackEmbedded()
    {
        $this->load->language('extension/payment/paypercut');
        $this->load->model('checkout/order');

        try {
            // Get checkout_id from URL parameter
            $checkout_id = isset($this->request->get['checkout_id']) ? $this->request->get['checkout_id'] : '';

            if (!$checkout_id) {
                throw new Exception('Checkout ID not provided');
            }

            // Get order from session
            $order_id = isset($this->session->data['order_id']) ? $this->session->data['order_id'] : null;

            if (!$order_id) {
                throw new Exception('Order ID not found in session');
            }

            $order_info = $this->model_checkout_order->getOrder($order_id);

            if (!$order_info) {
                throw new Exception('Order not found');
            }

            // Verify checkout status with Paypercut API
            $checkout_data = $this->verifyCheckout($checkout_id);

            if (!$checkout_data) {
                throw new Exception('Failed to verify checkout status');
            }

            // Check if checkout is complete
            if ($checkout_data['status'] !== 'complete') {
                throw new Exception('Checkout is not complete. Status: ' . $checkout_data['status']);
            }

            // Extract payment_id and payment_intent from checkout data
            $payment_id = isset($checkout_data['id']) ? $checkout_data['id'] : $checkout_id;
            $payment_intent = '';
            if (isset($checkout_data['payment_intent'])) {
                if (is_string($checkout_data['payment_intent'])) {
                    $payment_intent = $checkout_data['payment_intent'];
                } elseif (is_array($checkout_data['payment_intent']) && isset($checkout_data['payment_intent']['id'])) {
                    $payment_intent = $checkout_data['payment_intent']['id'];
                }
            }

            // Store checkout and payment details in database
            $this->storeCheckoutTransaction(
                $order_id,
                $checkout_id,
                $payment_id,
                $checkout_data
            );

            // Add order history with completed status
            $comment = 'Payment completed via Paypercut (Embedded Checkout)' . PHP_EOL;
            $comment .= 'Checkout ID: ' . $checkout_id . PHP_EOL;
            if ($payment_id) {
                $comment .= 'Payment ID: ' . $payment_id . PHP_EOL;
            }

            if (isset($checkout_data['amount_total'])) {
                // Extract currency - handle both string and array formats
                $currency = '';
                if (isset($checkout_data['currency'])) {
                    if (is_string($checkout_data['currency'])) {
                        $currency = strtoupper($checkout_data['currency']);
                    } elseif (is_array($checkout_data['currency']) && isset($checkout_data['currency']['code'])) {
                        $currency = strtoupper($checkout_data['currency']['code']);
                    }
                }

                if ($currency) {
                    $comment .= 'Amount: ' . number_format($checkout_data['amount_total'] / 100, 2) . ' ' . $currency;
                }
            }

            // Use completed order status
            $order_status_id = $this->config->get('paypercut_order_status_id');

            if (!$order_status_id) {
                $order_status_id = 2; // Default to Processing
            }

            // Add order history to confirm the order
            $this->model_checkout_order->addOrderHistory(
                $order_id,
                $order_status_id,
                $comment,
                true
            );

            // Clear paypercut-specific session data
            unset($this->session->data['paypercut_checkout_id']);

            // Store payment details in session for success page
            $currency_for_session = '';
            if (isset($checkout_data['currency'])) {
                if (is_string($checkout_data['currency'])) {
                    $currency_for_session = strtoupper($checkout_data['currency']);
                } elseif (is_array($checkout_data['currency']) && isset($checkout_data['currency']['code'])) {
                    $currency_for_session = strtoupper($checkout_data['currency']['code']);
                }
            }

            $this->session->data['paypercut_payment'] = array(
                'checkout_id' => $checkout_id,
                'payment_id' => $payment_id,
                'status' => 'succeeded',
                'amount' => isset($checkout_data['amount_total']) && $currency_for_session
                    ? number_format($checkout_data['amount_total'] / 100, 2) . ' ' . $currency_for_session
                    : null
            );

            $this->report(PaypercutEvent::of(
                'checkout.embedded.order_created',
                array(
                    'order_status' => (string)$order_status_id,
                    'session_matched' => true,
                    'verified_status' => PaypercutEvent::identifier($checkout_data['status'])
                )
            )->about(array(
                'payment_intent_id' => (string)$payment_intent,
                'payment_id' => (string)$payment_id,
                'order_ref' => $this->orderRef($order_id)
            )));

            $this->report(PaypercutEvent::of(
                'order.marked_paid',
                array(
                    'source' => 'checkout_return_embedded',
                    'from_status' => (string)$order_info['order_status_id'],
                    'to_status' => (string)$order_status_id,
                    'target_status' => (string)$order_status_id
                )
            )->about(array('order_ref' => $this->orderRef($order_id))));

            // Redirect to success page
            $this->response->redirect($this->url->link('checkout/success', '', true));
        } catch (Exception $e) {
            // Log error
            $log = new Log('paypercut_error.log');
            $log->write('Embedded checkout callback error: ' . $e->getMessage());

            $this->report(PaypercutEvent::failure('checkout.return.unverifiable', 'lookup_failed', array(), $e));

            // Redirect to failure page
            $this->response->redirect($this->url->link('checkout/failure', '', true));
        }
    }

    public function success()
    {
        $this->load->language('extension/payment/paypercut');

        $data['heading_title'] = $this->language->get('heading_success');
        $data['text_message'] = $this->language->get('text_success_message');

        // Get payment details from session
        if (isset($this->session->data['paypercut_payment'])) {
            $payment = $this->session->data['paypercut_payment'];
            $data['payment_id'] = $payment['payment_id'];
            $data['payment_method'] = $payment['payment_method'];
            $data['amount'] = $payment['amount'];
        }

        $data['continue'] = $this->url->link('common/home');
        $data['order_history'] = $this->url->link('account/order', '', true);

        $data['column_left'] = $this->load->controller('common/column_left');
        $data['column_right'] = $this->load->controller('common/column_right');
        $data['content_top'] = $this->load->controller('common/content_top');
        $data['content_bottom'] = $this->load->controller('common/content_bottom');
        $data['footer'] = $this->load->controller('common/footer');
        $data['header'] = $this->load->controller('common/header');

        $this->response->setOutput($this->load->view('extension/payment/paypercut_success', $data));
    }

    public function failure()
    {
        $this->load->language('extension/payment/paypercut');

        $data['heading_title'] = $this->language->get('heading_failure');
        $data['text_message'] = $this->language->get('text_failure_message');
        $data['text_home'] = $this->language->get('text_home');

        // Get payment details from session if available
        $data['payment_id'] = '';
        if (isset($this->session->data['paypercut_payment'])) {
            $payment = $this->session->data['paypercut_payment'];
            $data['payment_id'] = isset($payment['payment_id']) ? $payment['payment_id'] : '';
        }

        $data['continue'] = $this->url->link('common/home');
        $data['retry'] = $this->url->link('checkout/checkout', '', true);
        $data['contact'] = $this->url->link('information/contact', '', true);

        $data['column_left'] = $this->load->controller('common/column_left');
        $data['column_right'] = $this->load->controller('common/column_right');
        $data['content_top'] = $this->load->controller('common/content_top');
        $data['content_bottom'] = $this->load->controller('common/content_bottom');
        $data['footer'] = $this->load->controller('common/footer');
        $data['header'] = $this->load->controller('common/header');

        $this->response->setOutput($this->load->view('extension/payment/paypercut_failure', $data));
    }

    public function pending()
    {
        $this->load->language('extension/payment/paypercut');

        $data['heading_title'] = $this->language->get('heading_pending');
        $data['text_message'] = $this->language->get('text_pending_message');

        // Get payment details from session if available
        if (isset($this->session->data['paypercut_payment'])) {
            $payment = $this->session->data['paypercut_payment'];
            $data['payment_id'] = $payment['payment_id'];
        }

        $data['continue'] = $this->url->link('common/home');
        $data['order_history'] = $this->url->link('account/order', '', true);
        $data['contact'] = $this->url->link('information/contact', '', true);

        $data['column_left'] = $this->load->controller('common/column_left');
        $data['column_right'] = $this->load->controller('common/column_right');
        $data['content_top'] = $this->load->controller('common/content_top');
        $data['content_bottom'] = $this->load->controller('common/content_bottom');
        $data['footer'] = $this->load->controller('common/footer');
        $data['header'] = $this->load->controller('common/header');

        $this->response->setOutput($this->load->view('extension/payment/paypercut_pending', $data));
    }

    public function webhook()
    {
        // Get the raw POST data
        $payload = file_get_contents('php://input');
        $signature = isset($_SERVER['HTTP_X_PAYPERCUT_SIGNATURE']) ? $_SERVER['HTTP_X_PAYPERCUT_SIGNATURE'] : '';

        // Verify webhook signature
        if (!$this->verifyWebhookSignature($payload, $signature)) {
            $this->log('Webhook signature verification failed');

            // A merchant whose orders never leave "pending" is almost always
            // looking at one of these, and none of it is visible from our side.
            $this->report(PaypercutEvent::failure(
                'webhook.rejected',
                $signature === '' ? 'missing_signature' : 'invalid_signature',
                array('http_status' => 401)
            ));

            http_response_code(401);
            return;
        }

        $data = json_decode($payload, true);

        if (!$data) {
            $this->log('Invalid webhook payload');

            $this->report(PaypercutEvent::failure(
                'webhook.payload_invalid',
                $payload === '' ? 'empty_body' : 'empty_or_unparsable',
                array('http_status' => 400)
            ));

            http_response_code(400);
            return;
        }

        // Log webhook event
        $this->log('Webhook received: ' . ($data['type'] ?? 'unknown'));

        $this->report(PaypercutEvent::of('webhook.received', array(
            'type' => PaypercutEvent::identifier(isset($data['type']) ? $data['type'] : ''),
            'duplicate' => false
        )));

        // Store webhook event in database if logging is enabled
        if ($this->config->get('paypercut_logging')) {
            $this->logWebhookEvent($data, $payload);
        }

        // Handle different event types
        if (isset($data['type'])) {
            switch ($data['type']) {
                case 'payment_intent.captured':
                    $this->handlePaymentIntentSucceeded($data);
                    break;
                case 'checkout_session.completed':
                    $this->handleCheckoutSessionCompleted($data);
                    break;
                default:
                    $this->log('Unhandled webhook event type: ' . $data['type']);

                    $this->report(PaypercutEvent::of('webhook.skipped', array(
                        'webhook' => PaypercutEvent::identifier($data['type']),
                        'reason' => 'unhandled_type'
                    )));

                    http_response_code(501);
                    return;
            }
        }

        http_response_code(200);
    }

    private function verifyWebhookSignature($payload, $signature)
    {
        $webhook_secret = $this->config->get('paypercut_webhook_secret');

        if (empty($webhook_secret)) {
            // If no secret configured, skip verification (not recommended for production)
            $this->log('Warning: Webhook secret not configured, skipping signature verification');

            $this->report(PaypercutEvent::of('webhook.skipped', array(
                'webhook' => 'signature_check',
                'reason' => 'webhook_secret_not_configured'
            )));

            return true;
        }

        // Verify signature using HMAC SHA256
        $expected_signature = hash_hmac('sha256', $payload, $webhook_secret);

        return hash_equals($expected_signature, $signature);
    }

    private function handlePaymentIntentSucceeded($data)
    {
        if (!isset($data['data']['object'])) {
            return;
        }

        $intent = $data['data']['object'];
        $checkout_id = $intent['checkout_id'] ?? null;

        if (!$checkout_id) {
            $this->log('Payment intent event missing checkout_id');

            $this->report(PaypercutEvent::failure('webhook.unresolved', 'order_not_found', array(
                'http_status' => 503,
                'has_client_reference_id' => false,
                'has_metadata' => isset($intent['metadata'])
            )));

            return;
        }

        // Look up order_id via the stored transaction
        $query = $this->db->query("
            SELECT order_id FROM `" . DB_PREFIX . "paypercut_transaction`
            WHERE checkout_id = '" . $this->db->escape($checkout_id) . "'
            LIMIT 1
        ");

        if ($query->num_rows === 0) {
            $this->log('No transaction found for checkout_id: ' . $checkout_id);

            $this->report(PaypercutEvent::failure('webhook.unresolved', 'order_not_found', array(
                'http_status' => 503,
                'has_client_reference_id' => false,
                'has_metadata' => isset($intent['metadata'])
            ))->about(array('payment_id' => (string)(isset($intent['id']) ? $intent['id'] : ''))));

            return;
        }

        $order_id = $query->row['order_id'];

        if ($this->isWebhookProcessed($data['id'] ?? '', $order_id, $data['type'])) {
            $this->log('Webhook already processed for order #' . $order_id);

            $this->report(PaypercutEvent::of('webhook.received', array(
                'duplicate' => true
            ))->about(array('order_ref' => $this->orderRef($order_id))));

            return;
        }

        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($order_id);

        if ($order_info) {
            $order_status_id = $this->getOrderStatusForPaymentStatus('succeeded');
            $comment = 'Payment ' . str_replace('payment_intent.', '', $data['type']) . ' via Paypercut' . PHP_EOL;
            $comment .= 'Payment Intent ID: ' . ($intent['id'] ?? 'N/A') . PHP_EOL;
            $comment .= 'Amount: ' . number_format((int)($intent['amount'] ?? 0) / 100, 2) . ' ' . ($intent['currency'] ?? '');

            $this->model_checkout_order->addOrderHistory($order_id, $order_status_id, $comment, true);
            $this->log($data['type'] . ' processed for order #' . $order_id);

            $this->report(PaypercutEvent::of('webhook.order_updated', array(
                'payment_status' => 'succeeded',
                'order_status' => (string)$order_status_id
            ))->about(array(
                'payment_id' => (string)(isset($intent['id']) ? $intent['id'] : ''),
                'order_ref' => $this->orderRef($order_id)
            )));

            $this->report(PaypercutEvent::of('order.marked_paid', array(
                'source' => 'webhook_payment_intent',
                'from_status' => (string)$order_info['order_status_id'],
                'to_status' => (string)$order_status_id,
                'target_status' => (string)$order_status_id
            ))->about(array('order_ref' => $this->orderRef($order_id))));
        }
    }

    private function handleCheckoutSessionCompleted($data)
    {
        if (!isset($data['data']['object'])) {
            return;
        }

        $session = $data['data']['object'];
        $order_id = $session['client_reference_id'] ?? null;

        if (!$order_id) {
            $this->log('checkout_session.completed missing client_reference_id');

            $this->report(PaypercutEvent::failure('webhook.unresolved', 'order_not_found', array(
                'http_status' => 503,
                'has_client_reference_id' => false,
                'has_metadata' => isset($session['metadata'])
            ))->about(array('payment_id' => (string)(isset($session['id']) ? $session['id'] : ''))));

            return;
        }

        if ($this->isWebhookProcessed($data['id'] ?? '', $order_id, 'checkout_session.completed')) {
            $this->log('Webhook already processed for order #' . $order_id);

            $this->report(PaypercutEvent::of('webhook.received', array(
                'duplicate' => true
            ))->about(array('order_ref' => $this->orderRef($order_id))));

            return;
        }

        // Only process if payment_status is paid and status is complete
        $payment_status = $session['payment_status'] ?? '';
        $status = $session['status'] ?? '';

        if ($status !== 'complete' || $payment_status !== 'paid') {
            $this->log('checkout_session.completed skipped: status=' . $status . ' payment_status=' . $payment_status);

            $this->report(PaypercutEvent::of('webhook.skipped', array(
                'webhook' => 'checkout_session.completed',
                'reason' => 'not_paid',
                'payment_status' => PaypercutEvent::identifier($payment_status),
                'session_status' => PaypercutEvent::identifier($status)
            ))->about(array(
                'payment_id' => (string)(isset($session['id']) ? $session['id'] : ''),
                'order_ref' => $this->orderRef($order_id)
            )));

            return;
        }

        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($order_id);

        if ($order_info) {
            $order_status_id = $this->getOrderStatusForPaymentStatus('succeeded');
            $comment = 'Payment completed via Paypercut (Webhook)' . PHP_EOL;
            $comment .= 'Checkout ID: ' . ($session['id'] ?? 'N/A') . PHP_EOL;
            $comment .= 'Amount: ' . number_format((int)($session['amount_total'] ?? 0) / 100, 2) . ' ' . ($session['currency'] ?? '');

            $this->model_checkout_order->addOrderHistory($order_id, $order_status_id, $comment, true);
            $this->log('checkout_session.completed processed for order #' . $order_id);

            $this->report(PaypercutEvent::of('webhook.order_updated', array(
                'payment_status' => PaypercutEvent::identifier($payment_status),
                'order_status' => (string)$order_status_id
            ))->about(array(
                'payment_id' => (string)(isset($session['id']) ? $session['id'] : ''),
                'order_ref' => $this->orderRef($order_id)
            )));

            $this->report(PaypercutEvent::of('order.marked_paid', array(
                'source' => 'webhook_checkout_session',
                'from_status' => (string)$order_info['order_status_id'],
                'to_status' => (string)$order_status_id,
                'target_status' => (string)$order_status_id
            ))->about(array('order_ref' => $this->orderRef($order_id))));
        }
    }

    private function log($message)
    {
        // Check if logging is enabled
        if (!$this->config->get('paypercut_logging')) {
            return;
        }

        $log = new Log('paypercut.log');
        $log->write($message);
    }

    private function logWebhookEvent($data, $payload)
    {
        $event_id = $data['id'] ?? uniqid('webhook_', true);
        $event_type = $data['type'] ?? 'unknown';

        // Check if event already logged (idempotency)
        $check = $this->db->query("
            SELECT log_id FROM `" . DB_PREFIX . "paypercut_webhook_log` 
            WHERE event_id = '" . $this->db->escape($event_id) . "'
            LIMIT 1
        ");

        if ($check->num_rows > 0) {
            return; // Already processed
        }

        // Insert webhook log
        $this->db->query("
            INSERT INTO `" . DB_PREFIX . "paypercut_webhook_log` 
            SET event_id = '" . $this->db->escape($event_id) . "',
                event_type = '" . $this->db->escape($event_type) . "',
                payload = '" . $this->db->escape($payload) . "',
                processed = 1,
                created_at = NOW()
        ");
    }

    /**
     * Check if webhook has already been processed (idempotency)
     */
    private function isWebhookProcessed($event_id, $order_id, $event_type)
    {
        if (empty($event_id)) {
            return false;
        }

        // Check webhook log
        $query = $this->db->query("
            SELECT log_id FROM `" . DB_PREFIX . "paypercut_webhook_log` 
            WHERE event_id = '" . $this->db->escape($event_id) . "'
            AND event_type = '" . $this->db->escape($event_type) . "'
            AND processed = 1
            LIMIT 1
        ");

        if ($query->num_rows > 0) {
            return true;
        }

        // Also check order history to prevent duplicate status updates
        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($order_id);

        if (!$order_info) {
            return false;
        }

        // Check if order already has the target status
        $target_status = $this->getOrderStatusForPaymentStatus(str_replace('payment.', '', $event_type));

        if ($order_info['order_status_id'] == $target_status) {
            // Order already in target status, likely already processed
            return true;
        }

        return false;
    }

    /**
     * Map Paypercut payment status to OpenCart order status
     */
    private function getOrderStatusForPaymentStatus($payment_status)
    {
        // Default OpenCart statuses:
        // 1 = Pending
        // 2 = Processing  
        // 3 = Shipped
        // 5 = Complete
        // 7 = Canceled
        // 10 = Failed
        // 14 = Expired
        // 15 = Processed

        $status_map = array(
            'succeeded' => $this->config->get('paypercut_order_status_id') ?: 2, // Use configured status or Processing
            'pending' => 1,  // Pending
            'failed' => 10,  // Failed
            'canceled' => 7, // Canceled
            'cancelled' => 7, // Canceled (alternative spelling)
            'expired' => 14, // Expired
            'requires_capture' => 1, // Pending (authorized but not captured)
            'processing' => 1, // Pending
        );

        return $status_map[$payment_status] ?? 1; // Default to Pending if unknown
    }

    private function logError($message)
    {
        // Check if logging is enabled
        if (!$this->config->get('paypercut_logging')) {
            return;
        }

        $log = new Log('paypercut_error.log');
        $timestamp = date('Y-m-d H:i:s');
        $log->write('[' . $timestamp . '] ' . $message);
    }

    private function logDebug($message)
    {
        // Check if logging is enabled
        if (!$this->config->get('paypercut_logging')) {
            return;
        }

        $log = new Log('paypercut.log');
        $timestamp = date('Y-m-d H:i:s');
        $log->write('[' . $timestamp . '] ' . $message);
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
     * Get Paypercut locale from OpenCart language settings
     * Returns locale code if supported, null otherwise
     */
    private function getPaypercutLocale()
    {
        // Supported Paypercut locales
        $supported_locales = array('bg', 'en', 'el', 'ro', 'hr', 'pl', 'cs', 'sl', 'sk');

        // Get browser language from Accept-Language header
        $accept_language = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';

        if ($accept_language) {
            // Parse Accept-Language header (e.g., "en-US,en;q=0.9,bg;q=0.8")
            $languages = explode(',', $accept_language);

            foreach ($languages as $language) {
                // Remove quality value (e.g., ";q=0.8")
                $lang_parts = explode(';', trim($language));
                $lang_code = strtolower(trim($lang_parts[0]));

                // Extract primary language code (e.g., 'bg' from 'bg-BG')
                $primary_parts = explode('-', $lang_code);
                $primary_code = $primary_parts[0];

                // Check if this language is supported
                if (in_array($primary_code, $supported_locales)) {
                    return $primary_code;
                }
            }
        }

        return null;
    }

    private function formatPaymentMethodDetails($payment_method_details)
    {
        $details = '';

        if (isset($payment_method_details['card'])) {
            $card = $payment_method_details['card'];
            $details .= PHP_EOL . 'Card: ' . ucfirst($card['brand'] ?? 'Card');
            if (isset($card['last4'])) {
                $details .= ' ****' . $card['last4'];
            }
            if (isset($card['exp_month']) && isset($card['exp_year'])) {
                $details .= ' (Exp: ' . $card['exp_month'] . '/' . $card['exp_year'] . ')';
            }
        } elseif (isset($payment_method_details['type'])) {
            $type = $payment_method_details['type'];
            if ($type === 'google_pay') {
                $details .= PHP_EOL . 'Payment Method: Google Pay';
            } elseif ($type === 'apple_pay') {
                $details .= PHP_EOL . 'Payment Method: Apple Pay';
            } else {
                $details .= PHP_EOL . 'Payment Method: ' . ucfirst($type);
            }
        }

        return $details;
    }

    private function sendPaymentRequest($data)
    {
        $api_key = $this->config->get('paypercut_api_key');
        $api_url = $this->apiUrl('v1/checkouts');

        if (!$api_key) {
            return array('error' => $this->language->get('error_api_key_missing'));
        }

        // Validate currency is supported
        $currency = strtoupper($data['currency']);
        if (!$this->isCurrencySupported($currency)) {
            $this->logError('Unsupported currency attempted: ' . $currency);

            $this->report(PaypercutEvent::failure('checkout.session_create_failed', 'unsupported_currency', array(
                'store_currency' => PaypercutEvent::identifier($currency)
            )));

            // Disable payment method
            $this->load->model('setting/setting');
            $settings = $this->model_setting_setting->getSetting('paypercut');
            $settings['paypercut_status'] = 0;
            $this->model_setting_setting->editSetting('paypercut', $settings);

            return array('error' => $this->language->get('error_unsupported_currency'));
        }

        try {
            // Get or create Paypercut customer
            $customer_id = $this->getOrCreateCustomer($data['customer']);

            // Prepare statement descriptor
            $statement_descriptor = $this->config->get('paypercut_statement_descriptor');

            // Determine UI mode based on checkout mode setting
            $checkout_mode = $this->config->get('paypercut_checkout_mode') ?: 'hosted';
            $ui_mode = $checkout_mode === 'embedded' ? 'embedded' : 'hosted';

            // buildCheckoutData() already returns amount in minor units (cents) so it
            // can be reconciled against line_items/shipping_options, which are minor
            // units by definition -- do not convert again here.
            $payload = array(
                'amount' => (int)$data['amount'],
                'currency' => strtoupper($data['currency']),
                'mode' => 'payment',
                'ui_mode' => $ui_mode,
                'success_url' => $data['return_url'],
                'cancel_url' => $data['cancel_url'],
                'client_reference_id' => (string)$data['order_id']
            );

            // customer and customer_email are mutually exclusive
            // Use customer ID if available, otherwise use email
            if ($customer_id) {
                $payload['customer'] = $customer_id;
            } else {
                $payload['customer_email'] = $data['customer']['email'];
            }

            // Prepare wallet options
            $google_pay = $this->config->get('paypercut_google_pay');
            $apple_pay = $this->config->get('paypercut_apple_pay');

            $wallet_options = array();
            if ($google_pay !== null) {
                $wallet_options['google_pay'] = array('display' => $google_pay ? 'auto' : 'never');
            }
            if ($apple_pay !== null) {
                $wallet_options['apple_pay'] = array('display' => $apple_pay ? 'auto' : 'never');
            }

            if (!empty($wallet_options)) {
                $payload['wallet_options'] = $wallet_options;
            }

            // Prepare payment intent data
            $payment_intent_data = array();

            if (!empty($statement_descriptor)) {
                $payment_intent_data['statement_descriptor'] = substr($statement_descriptor, 0, 22);
            }

            if (!empty($payment_intent_data)) {
                $payload['payment_intent_data'] = $payment_intent_data;
            }

            // Add line items if provided
            if (!empty($data['line_items'])) {
                $payload['line_items'] = $data['line_items'];
            }

            // Add shipping options if provided
            if (!empty($data['shipping_options'])) {
                $payload['shipping_options'] = $data['shipping_options'];
            }

            // Add locale if supported
            $locale = $this->getPaypercutLocale();
            if ($locale) {
                $payload['locale'] = $locale;
            }

            // Platform metadata
            $payload['metadata'] = array(
                'platform'                     => 'opencart',
                'platform_version'             => VERSION,
                'plugin_version'               => PAYPERCUT_PLUGIN_VERSION,
                'php_version'                  => PHP_VERSION,
                'site_url'                     => defined('HTTPS_SERVER') ? HTTPS_SERVER : (defined('HTTP_SERVER') ? HTTP_SERVER : ''),
                'paypercut_checkout_mode'      => $ui_mode,
                'paypercut_checkout_operation' => 'payment',
            );

            // Make API request with timeout handling
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $api_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Authorization: Bearer ' . $api_key,
                'Content-Type: application/json'
            ));
            curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30 seconds timeout
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // 10 seconds connection timeout

            $started = microtime(true);
            $response = curl_exec($ch);
            $curl_error = curl_error($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $connected = (float)curl_getinfo($ch, CURLINFO_CONNECT_TIME) > 0;
            curl_close($ch);

            $duration_ms = (int)round((microtime(true) - $started) * 1000);

            // Handle cURL errors
            if ($curl_error) {
                $this->logError('cURL Error: ' . $curl_error);

                // A connect failure that took the full timeout is a network
                // black hole; one that returned at once is DNS or a refused
                // port - which is why duration_ms travels with every one.
                $this->report(PaypercutEvent::failure(
                    'api.request_failed',
                    $connected ? 'transport' : 'connect',
                    array('api_context' => 'checkout_create', 'duration_ms' => $duration_ms)
                ));

                return array('error' => $this->language->get('error_connection'));
            }

            // Handle timeout
            if ($http_code == 0) {
                $this->logError('API Timeout: No response from Paypercut API');

                $this->report(PaypercutEvent::failure(
                    'api.request_failed',
                    $connected ? 'transport' : 'connect',
                    array('api_context' => 'checkout_create', 'duration_ms' => $duration_ms)
                ));

                return array('error' => $this->language->get('error_timeout'));
            }

            // Only slow calls are timed as events - timing every call would
            // fill the queue with the requests nobody is investigating.
            if ($duration_ms >= 3000) {
                $this->report(PaypercutEvent::of('api.request_slow', array(
                    'api_context' => 'checkout_create',
                    'method' => 'POST',
                    'duration_ms' => $duration_ms
                )));
            }

            // Parse response
            $result = json_decode($response, true);

            if ($http_code == 201 || $http_code == 200) {
                $checkout_mode = $this->config->get('paypercut_checkout_mode') ?: 'hosted';

                if ($checkout_mode === 'embedded') {
                    // For embedded mode, return the checkout ID
                    if (isset($result['id'])) {
                        return array(
                            'checkout_id' => $result['id'],
                            'mode' => 'embedded'
                        );
                    } else {
                        $this->logError('Invalid API response: Missing checkout ID');
                        return array('error' => $this->language->get('error_invalid_response'));
                    }
                } else {
                    // For hosted mode, return the redirect URL
                    if (isset($result['url']) && isset($result['id'])) {
                        // Store checkout_id in session for callback verification
                        $this->session->data['paypercut_checkout_id'] = $result['id'];

                        return array(
                            'payment_url' => $result['url'],
                            'checkout_id' => $result['id'],
                            'mode' => 'hosted'
                        );
                    } else {
                        $this->logError('Invalid API response: Missing payment URL');
                        return array('error' => $this->language->get('error_invalid_response'));
                    }
                }
            }

            // Handle API errors
            $error_message = $this->language->get('error_payment_failed');
            if (isset($result['error'])) {
                $error_message = $result['error']['message'] ?? $error_message;
            }

            $this->logError('API Error (HTTP ' . $http_code . '): ' . $error_message . ' | Response: ' . $response);

            // Never $error_message: the platform quotes submitted input back, so
            // a rejected key arrives inside it. api_code and trace_id diagnose.
            $this->report(PaypercutEvent::apiFailure(
                'api.request_failed',
                $http_code,
                is_array($result) ? $result : array(),
                array(
                    'api_context' => 'checkout_create',
                    'duration_ms' => $duration_ms,
                    'body_parsable' => is_array($result)
                )
            ));

            return array('error' => $error_message);
        } catch (Exception $e) {
            $this->logError('Exception in sendPaymentRequest: ' . $e->getMessage());

            $this->report(PaypercutEvent::failure('checkout.session_create_failed', 'session_create', array(), $e));

            return array('error' => $this->language->get('error_payment'));
        }
    }

    private function verifyPayment($payment_id)
    {
        $api_key = $this->config->get('paypercut_api_key');
        $api_url = $this->apiUrl('v1/payments/' . $payment_id);

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
            return $result; // Return full payment data
        }

        return null;
    }

    private function verifyCheckout($checkout_id)
    {
        $api_key = $this->config->get('paypercut_api_key');

        $api_url = $this->apiUrl('v1/checkouts/' . $checkout_id);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json'
        ));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            $this->logError('Checkout verification cURL Error: ' . $curl_error);

            $this->report(PaypercutEvent::failure('api.request_failed', 'transport', array(
                'api_context' => 'checkout_lookup'
            )));

            return null;
        }

        if ($http_code == 200) {
            $result = json_decode($response, true);
            return $result; // Return full checkout data
        }

        $this->logError('Checkout verification failed (HTTP ' . $http_code . '): ' . $response);

        $decoded = json_decode($response, true);

        $this->report(PaypercutEvent::apiFailure(
            'api.request_failed',
            $http_code,
            is_array($decoded) ? $decoded : array(),
            array('api_context' => 'checkout_lookup', 'body_parsable' => is_array($decoded))
        ));

        return null;
    }

    private function storeCheckoutTransaction($order_id, $checkout_id, $payment_id, $checkout_data)
    {
        // Get OpenCart customer ID if available
        $customer_id = $this->customer->isLogged() ? $this->customer->getId() : null;

        // Extract payment_intent from checkout data
        $payment_intent = isset($checkout_data['payment_intent']) ? $checkout_data['payment_intent'] : '';

        // Extract payment method details if available
        $payment_method_type = null;
        $payment_method_details = null;

        if (isset($checkout_data['payment_method_types']) && is_array($checkout_data['payment_method_types'])) {
            $payment_method_type = $checkout_data['payment_method_types'][0];
        }

        // Extract currency - handle both string and array formats
        $currency = '';
        if (isset($checkout_data['currency'])) {
            if (is_string($checkout_data['currency'])) {
                $currency = strtoupper($checkout_data['currency']);
            } elseif (is_array($checkout_data['currency']) && isset($checkout_data['currency']['iso'])) {
                $currency = strtoupper($checkout_data['currency']['iso']);
            }
        }

        if (empty($currency)) {
            $this->logError('Currency not found in checkout data for Order #' . $order_id);
            $currency = 'USD'; // Fallback
        }

        // Store transaction in database
        $this->db->query("
            INSERT INTO `" . DB_PREFIX . "paypercut_transaction` 
            SET order_id = '" . (int)$order_id . "',
                payment_id = '" . $this->db->escape($payment_id) . "',
                payment_intent = " . ($payment_intent ? "'" . $this->db->escape($payment_intent) . "'" : "NULL") . ",
                checkout_id = '" . $this->db->escape($checkout_id) . "',
                customer_id = " . ($customer_id ? "'" . (int)$customer_id . "'" : "NULL") . ",
                amount = '" . (float)($checkout_data['amount_total'] / 100) . "',
                currency = '" . $this->db->escape($currency) . "',
                status = 'succeeded',
                payment_method_type = " . ($payment_method_type ? "'" . $this->db->escape($payment_method_type) . "'" : "NULL") . ",
                payment_method_details = " . ($payment_method_details ? "'" . $this->db->escape(json_encode($payment_method_details)) . "'" : "NULL") . ",
                created_at = NOW(),
                updated_at = NOW()
        ");

        $this->logDebug('Stored checkout transaction: Order #' . $order_id . ', Checkout ID: ' . $checkout_id . ', Payment ID: ' . $payment_id . ', Payment Intent: ' . $payment_intent . ', Currency: ' . $currency);
    }

    private function getOrCreateCustomer($customer_data)
    {
        $api_key = $this->config->get('paypercut_api_key');

        // Get OpenCart customer ID if logged in
        $customer_id = $this->customer->isLogged() ? $this->customer->getId() : null;

        // Check if we have stored Paypercut customer ID for this OpenCart customer
        if ($customer_id) {
            $paypercut_customer = $this->getPaypercutCustomerId($customer_id);
            if ($paypercut_customer) {
                // Verify the customer still exists on PayPerCut API
                if ($this->verifyPaypercutCustomerExists($paypercut_customer['paypercut_id'])) {
                    // Update customer if email changed
                    if ($paypercut_customer['email'] !== $customer_data['email']) {
                        $this->updatePaypercutCustomer($paypercut_customer['paypercut_id'], $customer_data);
                    }
                    return $paypercut_customer['paypercut_id'];
                } else {
                    // Customer no longer exists on API, delete local mapping
                    $this->deletePaypercutCustomerMapping($paypercut_customer['paypercut_id']);
                }
            }
        }

        // Also check by email for guest checkouts
        $paypercut_customer = $this->getPaypercutCustomerByEmail($customer_data['email']);
        if ($paypercut_customer) {
            // Verify the customer still exists on PayPerCut API
            if ($this->verifyPaypercutCustomerExists($paypercut_customer['paypercut_id'])) {
                // Associate with customer ID if logged in and not yet associated
                if ($customer_id && !$paypercut_customer['customer_id']) {
                    $this->updatePaypercutCustomerMapping($paypercut_customer['paypercut_customer_id'], $customer_id);
                }
                return $paypercut_customer['paypercut_id'];
            } else {
                // Customer no longer exists on API, delete local mapping
                $this->deletePaypercutCustomerMapping($paypercut_customer['paypercut_id']);
            }
        }

        // Create new Paypercut customer
        $api_url = $this->apiUrl('v1/customers');

        $payload = array(
            'email' => $customer_data['email'],
            'name' => $customer_data['firstname'] . ' ' . $customer_data['lastname']
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
            $paypercut_id = $result['id'];

            // Store mapping in database
            $this->storePaypercutCustomer($customer_id, $paypercut_id, $customer_data['email']);

            return $paypercut_id;
        }

        return null;
    }

    private function getPaypercutCustomerId($customer_id)
    {
        $query = $this->db->query("
            SELECT * FROM `" . DB_PREFIX . "paypercut_customer` 
            WHERE customer_id = '" . (int)$customer_id . "'
        ");

        if ($query->num_rows) {
            return $query->row;
        }

        return null;
    }

    private function getPaypercutCustomerByEmail($email)
    {
        $query = $this->db->query("
            SELECT * FROM `" . DB_PREFIX . "paypercut_customer` 
            WHERE email = '" . $this->db->escape($email) . "'
        ");

        if ($query->num_rows) {
            return $query->row;
        }

        return null;
    }

    private function storePaypercutCustomer($customer_id, $paypercut_id, $email)
    {
        // Skip storing for guest customers - no OpenCart customer to map to
        // The Paypercut customer ID is still used for the checkout, just not stored locally
        if (!$customer_id || $customer_id <= 0) {
            return;
        }

        $this->db->query("
            INSERT INTO `" . DB_PREFIX . "paypercut_customer` 
            SET customer_id = '" . (int)$customer_id . "',
                paypercut_id = '" . $this->db->escape($paypercut_id) . "',
                email = '" . $this->db->escape($email) . "',
                created_at = NOW(),
                updated_at = NOW()
        ");
    }

    private function updatePaypercutCustomerMapping($paypercut_customer_id, $customer_id)
    {
        $this->db->query("
            UPDATE `" . DB_PREFIX . "paypercut_customer` 
            SET customer_id = '" . (int)$customer_id . "',
                updated_at = NOW()
            WHERE paypercut_customer_id = '" . (int)$paypercut_customer_id . "'
        ");
    }

    private function updatePaypercutCustomer($paypercut_id, $customer_data)
    {
        $api_key = $this->config->get('paypercut_api_key');
        $api_url = $this->apiUrl('v1/customers/' . $paypercut_id);

        $payload = array(
            'email' => $customer_data['email'],
            'name' => $customer_data['firstname'] . ' ' . $customer_data['lastname']
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json'
        ));

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Update email in our database
        if ($http_code == 200) {
            $this->db->query("
                UPDATE `" . DB_PREFIX . "paypercut_customer` 
                SET email = '" . $this->db->escape($customer_data['email']) . "',
                    updated_at = NOW()
                WHERE paypercut_id = '" . $this->db->escape($paypercut_id) . "'
            ");
        }

        return $http_code == 200;
    }

    /**
     * Verify that a PayPerCut customer ID still exists on the API
     * 
     * @param string $paypercut_id The PayPerCut customer ID to verify
     * @return bool True if customer exists, false otherwise
     */
    private function verifyPaypercutCustomerExists($paypercut_id)
    {
        $api_key = $this->config->get('paypercut_api_key');
        $api_url = $this->apiUrl('v1/customers/' . $paypercut_id);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json'
        ));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Customer exists if we get a 200 response
        if ($http_code == 200) {
            return true;
        }

        // Log if customer not found or error occurred
        if ($http_code == 404) {
            $this->logError('PayPerCut customer not found on API: ' . $paypercut_id . ' - will create new customer');
        } else if ($http_code != 200) {
            $this->logError('Error verifying PayPerCut customer ' . $paypercut_id . ': HTTP ' . $http_code);
        }

        return false;
    }

    /**
     * Delete a local PayPerCut customer mapping when the customer no longer exists on the API
     * 
     * @param string $paypercut_id The PayPerCut customer ID to delete
     */
    private function deletePaypercutCustomerMapping($paypercut_id)
    {
        $this->db->query("
            DELETE FROM `" . DB_PREFIX . "paypercut_customer` 
            WHERE paypercut_id = '" . $this->db->escape($paypercut_id) . "'
        ");

        $this->logError('Deleted stale PayPerCut customer mapping: ' . $paypercut_id);
    }
}

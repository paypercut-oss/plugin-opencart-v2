<?php
// Heading
$_['heading_title'] = 'Paypercut Payments';

// Text
$_['text_extension'] = 'Extensions';
$_['text_success'] = 'Success: You have modified Paypercut payment module!';
$_['text_edit'] = 'Edit Paypercut';
$_['text_paypercut'] = '<img src="view/image/payment/paypercut.png" alt="Paypercut" title="Paypercut" style="border: 1px solid #EEEEEE;" />';

// Entry
$_['entry_api_key'] = 'API Key';
$_['entry_operating_account'] = 'Operating Account ID';
$_['entry_statement_descriptor'] = 'Statement Descriptor';
$_['entry_google_pay'] = 'Google Pay';
$_['entry_apple_pay'] = 'Apple Pay';
$_['entry_checkout_mode'] = 'Checkout Mode';
$_['entry_webhook_url'] = 'Webhook URL';
$_['entry_order_status'] = 'Order Status';
$_['entry_status'] = 'Status';
$_['entry_sort_order'] = 'Sort Order';
$_['entry_logging'] = 'Enable Logging';
$_['entry_payment_method_config'] = 'Payment Method Configuration';

// Help
$_['help_api_key'] = 'Enter your Paypercut API Key from the dashboard';
$_['help_operating_account'] = 'Enter your Operating Account ID (found in Paypercut Dashboard)';
$_['help_statement_descriptor'] = 'Text that appears on customer\'s bank statement (max 22 characters). Leave empty to use default.';
$_['help_google_pay'] = 'Enable Google Pay as a payment option';
$_['help_apple_pay'] = 'Enable Apple Pay as a payment option';
$_['help_checkout_mode'] = 'Choose between hosted (redirect to Paypercut page) or embedded (checkout on your site) payment experience';
$_['help_webhook_url'] = 'Copy this URL and configure it in your Paypercut Dashboard under Developers > Webhooks';
$_['help_logging'] = 'Enable logging of API requests, webhook events, and errors. Disable in production unless debugging. Logs may contain sensitive data.';
$_['help_payment_method_config'] = 'Select a payment method configuration (payment profile) to control which payment methods are available to customers. Leave empty to use default.';

// Error
$_['error_permission'] = 'Warning: You do not have permission to modify Paypercut payment module!';
$_['error_api_key'] = 'API Key Required!';
$_['error_statement_descriptor'] = 'Statement descriptor must be 22 characters or less!';
$_['error_unsupported_currency'] = 'Warning: Your store currency (%s) is not supported by Paypercut. Supported currencies: BGN, DKK, SEK, NOK, GBP, EUR, USD, CHF, CZK, HUF, PLN, RON';
$_['error_apple_domain_write'] = 'Could not write the Apple Pay verification file (target path: %s). Check filesystem permissions for OpenCart\'s webroot or upload the file manually.';

// Text
$_['text_mode_test'] = 'Test Mode';
$_['text_mode_live'] = 'Live Mode';
$_['text_mode_unknown'] = 'Unknown Mode';
$_['text_enabled'] = 'Enabled';
$_['text_disabled'] = 'Disabled';
$_['text_hosted'] = 'Hosted (Redirect)';
$_['text_embedded'] = 'Embedded (On-site)';
$_['text_statement_preview'] = 'Preview';
$_['text_webhook_info'] = 'Configure this webhook URL in your <a href="https://dashboard.paypercut.io/developers/webhooks" target="_blank">Paypercut Dashboard</a>';
$_['text_webhook_configured'] = 'Webhook is configured and active';
$_['text_webhook_not_configured'] = 'Webhook not configured';
$_['text_webhook_create'] = 'Create Webhook Automatically';
$_['text_webhook_delete'] = 'Delete Webhook';
$_['text_webhook_creating'] = 'Creating webhook...';
$_['text_webhook_deleting'] = 'Deleting webhook...';
$_['text_wallet_settings'] = 'Wallet Settings';
$_['text_testing_connection'] = 'Testing connection...';
$_['text_connection_success'] = 'Connection successful!';
$_['text_connection_failed'] = 'Connection failed';
$_['text_apple_domain_file_ok'] = 'Apple Pay domain verification file is in place.';
$_['text_apple_domain_file_missing'] = 'Apple Pay domain verification file is missing. Save the form or click Refresh to create it.';
$_['text_apple_domain_file_unreachable'] = 'File is on disk but the storefront did not return it over HTTPS. See the Apple Pay runbook.';
$_['text_apple_domain_file_path'] = 'Path: %s';
$_['text_apple_domain_file_refreshing'] = 'Refreshing from PayPerCut CDN...';

// Refund
$_['text_refund_success'] = 'Refund processed successfully!';
$_['error_order_id'] = 'Order ID is required!';
$_['error_no_transaction'] = 'No Paypercut transaction found for this order.';
$_['error_payment_not_succeeded'] = 'Only succeeded payments can be refunded.';
$_['error_already_refunded'] = 'This payment has already been fully refunded.';
$_['error_invalid_amount'] = 'Please enter a valid refund amount.';
$_['error_exceeds_payment'] = 'Refund amount exceeds the remaining payment amount.';
$_['error_api_key_missing'] = 'Paypercut API key is not configured.';
$_['error_connection'] = 'Could not connect to Paypercut API. Please try again.';
$_['error_timeout'] = 'Connection to Paypercut API timed out. Please try again.';
$_['error_refund_failed'] = 'Refund failed. Please try again or contact support.';

// Button
$_['button_test_connection'] = 'Test Connection';
$_['button_apple_domain_refresh'] = 'Refresh from PayPerCut CDN';

// Connection environment
$_['entry_environment'] = 'Environment';
$_['help_environment'] = 'Which Paypercut environment this store connects to. Leave on Production unless Paypercut support asked you to change it. Both the payment API and the debug-session service are chosen by this setting.';

// Debug session (client telemetry)
$_['heading_telemetry'] = 'Debug session';
$_['text_telemetry_idle_lead'] = 'Off. Nothing is sent to Paypercut until you start a session.';
$_['text_telemetry_idle_help'] = 'Turn on detailed diagnostics for about an hour so Paypercut support can see what your store is doing. The session ends by itself.';
$_['text_telemetry_running'] = 'Debug session running - %s remaining';
$_['text_telemetry_started_by'] = 'Started by %1$s . ends at %2$s';
$_['text_telemetry_session_id'] = 'Session ID';
$_['text_telemetry_last_session_id'] = 'Last session ID %s - quote this in your support ticket.';
$_['text_telemetry_counters'] = '%1$s events sent . %2$s dropped (approximate)';
$_['text_telemetry_ended'] = 'Debug session ended.';
$_['text_telemetry_ended_help'] = 'Paypercut stops receiving data from this store.';
$_['text_telemetry_reference'] = 'Support reference';
$_['text_telemetry_notice'] = 'Paypercut: a debug session started by %1$s is running until %2$s.';
$_['text_telemetry_manage'] = 'Manage it';
$_['text_telemetry_log_summary'] = 'Show the %s event(s) sent';
$_['text_telemetry_log_help'] = 'Exactly what was sent to Paypercut, newest last. The most recent %s are kept on this store and cleared when a new session starts.';
$_['text_telemetry_log_time'] = 'Time (UTC)';
$_['text_telemetry_log_event'] = 'Event';
$_['text_telemetry_log_detail'] = 'Detail';
$_['text_telemetry_log_raw'] = 'Show raw JSON';

// Disclosure - kept word-identical with docs/telemetry.md and the store listing.
$_['text_telemetry_disclosure_heading'] = 'What is shared';
$_['text_telemetry_disclosure_shared'] = 'Extension, OpenCart, PHP and theme versions; the extensions installed on this store and their versions; how this store has the Paypercut extension configured (which checkout mode is selected and which options are switched on - never the values of your credentials); a record of each checkout, refund and payment notification the extension handled and whether it succeeded, identified by OpenCart order number and Paypercut payment reference; when something fails, the error message, the file and line it came from, and which extension or theme raised it; and when the session started and stopped.';
$_['text_telemetry_disclosure_not_shared_label'] = 'Not shared:';
$_['text_telemetry_disclosure_not_shared'] = 'customer names, email addresses, billing or shipping addresses, order totals, line items, payment card data, the reason text you type when issuing a refund, or any API key, webhook secret or password.';
$_['text_telemetry_disclosure_key'] = 'Your API key is never sent to the telemetry service. It is used once, over HTTPS, to obtain a short-lived diagnostic token from api.paypercut.io.';
$_['text_telemetry_disclosure_retention'] = 'Paypercut keeps this diagnostic data for 30 days.';

// Consent modal
$_['text_telemetry_modal_title'] = 'Start a debug session?';
$_['text_telemetry_modal_lead'] = 'While the session is running, this store sends the diagnostic information below to Paypercut so support can see what is happening.';
$_['text_telemetry_modal_duration'] = 'The session lasts about 60 minutes and then stops by itself. You can stop it sooner at any time.';

$_['button_telemetry_start'] = 'Start debug session';
$_['button_telemetry_start_confirm'] = 'Start session';
$_['button_telemetry_stop'] = 'Stop now';
$_['button_telemetry_retry'] = 'Try again';
$_['button_telemetry_copy'] = 'Copy';
$_['text_telemetry_copied'] = 'Copied';
$_['text_telemetry_starting'] = 'Starting...';
$_['text_telemetry_stopping'] = 'Stopping...';
$_['text_telemetry_session_ended'] = 'Debug session ended.';
$_['text_telemetry_network_error'] = 'Could not reach this store\'s admin. Please reload the page and try again.';
$_['text_telemetry_admin_unreachable'] = 'This store stopped answering the debug session panel. Reload the page to resume.';

$_['error_telemetry_disabled'] = 'Debug sessions are switched off on this store.';
$_['error_telemetry_start_locked'] = 'A debug session is already being started.';
$_['error_telemetry_no_key'] = 'Enter and save your Paypercut API key before starting a debug session.';
$_['error_telemetry_no_environment'] = 'This store\'s Paypercut connection does not record which environment it uses, so a debug session cannot be started. Save the settings form once, then try again.';
$_['error_telemetry_environment_unsupported'] = 'Debug sessions are not available on this store\'s Paypercut environment.';

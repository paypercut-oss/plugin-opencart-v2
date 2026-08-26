<?php
/**
 * Builds the one-off environment and configuration snapshots sent at start.
 *
 * Reads the store's own configuration only. Every value it collects is named
 * explicitly here and cast by PaypercutEvent, which walks its OWN schema;
 * nothing is harvested by iterating a settings array, which is how a credential
 * would end up on the wire.
 */
class PaypercutEnvironmentSnapshot
{
    private static $registry = null;

    public static function boot($registry)
    {
        self::$registry = $registry;
    }

    public static function values()
    {
        $config = self::$registry->get('config');

        $api_key = (string)$config->get('paypercut_api_key');

        return array(
            'plugin_version' => defined('PAYPERCUT_PLUGIN_VERSION') ? (string)constant('PAYPERCUT_PLUGIN_VERSION') : 'dev',
            'opencart_version' => defined('VERSION') ? (string)constant('VERSION') : '',
            'php_version' => PHP_VERSION,
            'theme_name' => (string)$config->get('config_template'),
            'is_multistore' => self::isMultistore(),
            'is_ssl' => self::isSsl(),
            'checkout_mode' => (string)$config->get('paypercut_checkout_mode'),
            'order_status_id' => (string)(int)$config->get('paypercut_order_status_id'),
            'google_pay_enabled' => (bool)$config->get('paypercut_google_pay'),
            'apple_pay_enabled' => (bool)$config->get('paypercut_apple_pay'),
            'statement_descriptor_set' => (string)$config->get('paypercut_statement_descriptor') !== '',
            'logging_enabled' => (bool)$config->get('paypercut_logging'),
            'card_enabled' => (bool)$config->get('paypercut_status'),
            'connection_environment' => PaypercutEnvironment::normalize($config->get('paypercut_environment')),
            'api_key_mode' => self::apiKeyMode($api_key),
            // Presence booleans derived from secret-bearing settings: the value
            // never travels, only whether one exists.
            'webhook_configured' => (string)$config->get('paypercut_webhook_secret') !== '',
            'payment_domain_registered' => (string)$config->get('paypercut_domain_id') !== '',
            'payment_method_config_set' => (string)$config->get('paypercut_payment_method_config') !== '',
            'store_currency' => (string)$config->get('config_currency'),
            'apple_domain_file_present' => self::appleDomainFilePresent()
        );
    }

    private static function apiKeyMode($api_key)
    {
        if ($api_key === '') {
            return '';
        }

        if (strpos($api_key, 'sk_test') === 0) {
            return 'test';
        }

        if (strpos($api_key, 'sk_live') === 0) {
            return 'live';
        }

        return 'unknown';
    }

    private static function isMultistore()
    {
        if (!self::$registry->has('db')) {
            return false;
        }

        $query = self::$registry->get('db')->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "store`");

        return $query->num_rows && (int)$query->row['total'] > 0;
    }

    private static function isSsl()
    {
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        return isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https';
    }

    private static function appleDomainFilePresent()
    {
        if (!defined('DIR_APPLICATION')) {
            return false;
        }

        return is_file(dirname(rtrim(constant('DIR_APPLICATION'), '/')) . '/.well-known/apple-developer-merchantid-domain-association');
    }
}

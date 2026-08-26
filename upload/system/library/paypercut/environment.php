<?php
/**
 * Resolves Paypercut environment-specific service URLs.
 *
 * One stored `paypercut_environment` value decides BOTH the payment API host
 * and the telemetry edge host. They must never be resolved independently: a
 * telemetry token minted against one environment is rejected by every other
 * environment's edge with a 401 that is indistinguishable from a forged token.
 */
class PaypercutEnvironment
{
    const DEFAULT_API_BASE_URI = 'https://api.paypercut.io/';

    const DEFAULT_ENVIRONMENT = 'production';

    /**
     * The environments a merchant may select.
     */
    public static function all()
    {
        return array('production', 'stage', 'dev');
    }

    /**
     * Reduce a stored value to a known environment, or '' when it is neither.
     */
    public static function normalize($environment)
    {
        if (!is_string($environment)) {
            return '';
        }

        $environment = strtolower(trim($environment));

        return in_array($environment, self::all(), true) ? $environment : '';
    }

    /**
     * Base URI of the Paypercut payment API.
     *
     * Falls back to production for an unknown or unset environment: this is the
     * host every existing store already uses, and refusing to resolve it would
     * break checkout on stores that predate the setting.
     */
    public static function apiBaseUri($environment = '')
    {
        $map = array(
            'dev' => 'https://api.dev.paypercut.net/',
            'stage' => 'https://api.stage.paypercut.net/',
            'production' => self::DEFAULT_API_BASE_URI
        );

        $environment = self::normalize($environment);

        if ($environment !== '' && isset($map[$environment])) {
            $base = self::allowedPaypercutBase($map[$environment]);

            if ($base !== '') {
                return $base;
            }
        }

        return self::DEFAULT_API_BASE_URI;
    }

    /**
     * Absolute URL for an API path, e.g. apiUrl('production', 'v1/checkouts').
     */
    public static function apiUrl($environment, $path)
    {
        return self::apiBaseUri($environment) . ltrim((string)$path, '/');
    }

    /**
     * Base URI of the telemetry edge, or '' when there is none for this store.
     *
     * Unlike the payment API this does NOT fall back to production. An unknown
     * environment must yield no debug session rather than a confusing one.
     */
    public static function telemetryBaseUri($environment = '')
    {
        $environment = self::normalize($environment);

        if ($environment === '') {
            return '';
        }

        // Ignored on production: a constant left in config.php after debugging
        // must not retarget a live store's telemetry, and the mint host - which
        // is resolved from the same value below - would not follow it anyway.
        if ($environment !== 'production' && defined('PAYPERCUT_TELEMETRY_BASE_URI')) {
            return self::allowedPaypercutBase(constant('PAYPERCUT_TELEMETRY_BASE_URI'));
        }

        $map = array(
            'dev' => 'https://telemetry.dev.paypercut.net/',
            'stage' => 'https://telemetry.stage.paypercut.net/',
            'production' => 'https://telemetry.paypercut.io/'
        );

        return isset($map[$environment]) ? self::allowedPaypercutBase($map[$environment]) : '';
    }

    /**
     * Accept a base URI only on an https Paypercut host.
     *
     * The store's API key travels on the mint request, so the destination is
     * validated rather than trusted. The \z/D anchor is load-bearing: it
     * rejects https://paypercut.io.evil.com/, https://notpaypercut.io/ and
     * https://paypercut.io.co/, as well as any http:// URL.
     */
    public static function allowedPaypercutBase($url)
    {
        $url = is_string($url) ? trim($url) : '';

        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        $host = isset($parts['host']) ? strtolower($parts['host']) : '';

        if ($scheme !== 'https' || $host === '' || !preg_match('/(^|\.)paypercut\.(net|io)\z/D', $host)) {
            return '';
        }

        return rtrim($url, '/') . '/';
    }
}

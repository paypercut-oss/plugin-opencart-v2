<?php
/**
 * The raw HTTP client the telemetry paths use.
 *
 * Deliberately not the extension's ordinary API calls: those log request and
 * response bodies (which would write the minted token into the store's log
 * files), share their timeout budget with the payment paths, and treat a
 * non-2xx as an error rather than a branch. Every status here is returned to
 * the caller to be branched on, and nothing is ever logged.
 */
class PaypercutTelemetryHttp
{
    /**
     * POST a JSON body and report exactly what came back.
     *
     * @param string      $url
     * @param array       $headers   Raw header lines.
     * @param string|null $json_body null means no body at all - the mint takes none.
     * @param int         $timeout
     * @param int         $connect_timeout
     *
     * @return array status (0 == the request never completed), headers, body, duration_ms
     */
    public static function postJson($url, $headers, $json_body, $timeout, $connect_timeout)
    {
        $started = microtime(true);

        $failure = array(
            'status' => 0,
            'headers' => array(),
            'body' => '',
            'duration_ms' => 0
        );

        if (!function_exists('curl_init')) {
            return $failure;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int)$timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int)$connect_timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // An empty string, not null: cURL must send a POST with no body at all,
        // because the mint endpoint takes none.
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_body === null ? '' : $json_body);

        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header_size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        $duration_ms = (int)round((microtime(true) - $started) * 1000);

        // status 0 is the sentinel for "no answer at all" - DNS failure, refused
        // connection, TLS failure, timeout, or a host policy blocking outbound
        // requests. It is distinct from every real HTTP status.
        if ($curl_error !== '' || !is_string($raw) || $status === 0) {
            $failure['duration_ms'] = $duration_ms;

            return $failure;
        }

        return array(
            'status' => $status,
            'headers' => self::parseHeaders(substr($raw, 0, $header_size)),
            'body' => (string)substr($raw, $header_size),
            'duration_ms' => $duration_ms
        );
    }

    /**
     * Header lines to a lowercased name => value map, last value winning.
     */
    private static function parseHeaders($raw)
    {
        $headers = array();

        foreach (preg_split('/\r?\n/', (string)$raw) as $line) {
            $parts = explode(':', $line, 2);

            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return $headers;
    }

    public static function header($headers, $name)
    {
        $name = strtolower($name);

        return isset($headers[$name]) ? (string)$headers[$name] : '';
    }
}

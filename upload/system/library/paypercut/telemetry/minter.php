<?php
/**
 * Exchanges the store's API key for a short-lived telemetry token.
 */
class PaypercutTokenMinter
{
    const PATH = 'v1/telemetry/tokens';

    /**
     * Request a telemetry token.
     *
     * The store's long-lived API key travels on this request, so the
     * destination is re-validated here rather than trusted from whatever the
     * environment map returned.
     *
     * @param string $secret    The store's API key.
     * @param string $mint_base Base URI of the payment API for this environment.
     */
    public function mint($secret, $mint_base)
    {
        $mint_base = PaypercutEnvironment::allowedPaypercutBase($mint_base);

        if ($mint_base === '') {
            return self::failure();
        }

        $response = PaypercutTelemetryHttp::postJson(
            $mint_base . self::PATH,
            array(
                'Authorization: Bearer ' . $secret,
                'Accept: application/json'
            ),
            // The endpoint takes no body at all - not [], not {}.
            null,
            PaypercutTelemetrySession::MINT_TIMEOUT_SECONDS,
            PaypercutTelemetrySession::MINT_CONNECT_TIMEOUT_SECONDS
        );

        $decoded = json_decode($response['body'], true);
        $body = is_array($decoded) ? $decoded : array();

        $trace_id = PaypercutTelemetryHttp::header($response['headers'], 'Trace-Id');

        if ($trace_id === '' && isset($body['trace_id']) && is_string($body['trace_id'])) {
            // On an error the gateway repeats the id in the body, which is the
            // more reliable of the two.
            $trace_id = $body['trace_id'];
        }

        return array(
            'status' => (int)$response['status'],
            'body' => $body,
            'token' => isset($body['token']) && is_string($body['token']) ? $body['token'] : '',
            'expires_at' => isset($body['expires_at']) && is_string($body['expires_at']) ? $body['expires_at'] : '',
            'date' => PaypercutTelemetryHttp::header($response['headers'], 'Date'),
            'trace_id' => $trace_id,
            'request_id' => PaypercutTelemetryHttp::header($response['headers'], 'X-Request-Id')
        );
    }

    /**
     * How long the token is good for, measured on the MINT's clock.
     *
     * `expires_at` is stamped by the mint; time() is this server's idea of now.
     * Stores routinely drift by minutes, so the two are not comparable: copying
     * the timestamp would either overrun the token (clock behind) or make Start
     * permanently impossible (clock ahead). Measuring the mint's own
     * expires_at - Date yields a duration, which is portable to any clock.
     *
     * @return int Lifetime in seconds; 0 when expires_at cannot be parsed.
     */
    public static function deriveLifetime($expires_at, $date_header, $now)
    {
        $expiry = strtotime($expires_at);

        if ($expiry === false) {
            return 0;
        }

        $issued = $date_header !== '' ? strtotime($date_header) : false;

        if ($issued === false) {
            $issued = (int)$now;
        }

        return (int)$expiry - (int)$issued;
    }

    /**
     * Signed difference between the mint's clock and this server's, in seconds.
     *
     * Logged on every successful mint so support can spot a drifting store
     * before it turns into an unexplainable failure.
     */
    public static function skew($date_header, $now)
    {
        if ($date_header === '') {
            return 0;
        }

        $issued = strtotime($date_header);

        return $issued === false ? 0 : (int)$issued - (int)$now;
    }

    private static function failure()
    {
        return array(
            'status' => 0,
            'body' => array(),
            'token' => '',
            'expires_at' => '',
            'date' => '',
            'trace_id' => '',
            'request_id' => ''
        );
    }
}

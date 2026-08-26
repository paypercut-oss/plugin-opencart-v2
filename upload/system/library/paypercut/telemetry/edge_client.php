<?php
/**
 * Delivers a batch of diagnostic events to the public telemetry edge.
 *
 * The edge verifies the bearer token offline and never calls back into the
 * platform, so a request never blocks on the payment platform.
 *
 * The body is worth reading. A 202 carries {"accepted":N,"dropped":M} - the
 * only way a client learns the edge discarded part of a batch it accepted - and
 * a 413 carries the `limits` a batch must be split to satisfy.
 */
class PaypercutEdgeClient
{
    const PATH = 'v1/telemetry';

    /**
     * The edge's own responses are a few dozen bytes; anything larger is not one.
     */
    const MAX_RESPONSE_BYTES = 4096;

    /**
     * POST one batch.
     *
     * @return array status (0 == the request never completed), retry_after, body
     */
    public function send($edge_base, $jwt, $json_body)
    {
        $edge_base = PaypercutEnvironment::allowedPaypercutBase($edge_base);

        if ($edge_base === '') {
            return array('status' => 0, 'retry_after' => 0, 'body' => array());
        }

        $response = PaypercutTelemetryHttp::postJson(
            $edge_base . self::PATH,
            array(
                'Authorization: Bearer ' . $jwt,
                'Content-Type: application/json'
            ),
            $json_body,
            PaypercutTelemetrySession::EDGE_TIMEOUT_SECONDS,
            PaypercutTelemetrySession::EDGE_CONNECT_TIMEOUT_SECONDS
        );

        return array(
            'status' => (int)$response['status'],
            'retry_after' => (int)PaypercutTelemetryHttp::header($response['headers'], 'Retry-After'),
            'body' => self::decode($response['body'])
        );
    }

    /**
     * Anything that is not a JSON object is no answer at all.
     *
     * A 413 from a proxy in front of the edge is an HTML page, and a captive
     * portal will happily return 200 with a login form.
     */
    private static function decode($body)
    {
        $body = (string)$body;

        if ($body === '' || strlen($body) > self::MAX_RESPONSE_BYTES) {
            return array();
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : array();
    }
}

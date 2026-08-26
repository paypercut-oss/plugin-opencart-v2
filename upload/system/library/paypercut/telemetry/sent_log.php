<?php
/**
 * A local copy of the events this store actually delivered.
 *
 * The queue is emptied as it drains, so by the time anyone looks there is
 * nothing left to see: the panel could report "37 events sent" and offer no way
 * to find out what they were. Consent to send diagnostics is worth more when
 * the sender can inspect what left.
 *
 * Nothing here runs on a storefront request. The flusher delivers only from
 * authenticated admin requests, so this write lands in the same places the
 * settings page already writes and the checkout path is untouched.
 */
class PaypercutSentLog
{
    const KEY = 'paypercut_telemetry_sent_log';

    /**
     * Entries kept before the oldest are discarded.
     *
     * A session is an hour; a busy store can deliver far more than this, so the
     * log is a tail rather than a transcript. The panel says so.
     */
    const MAX_ENTRIES = 100;

    const MAX_BYTES = 131072;

    /**
     * Record envelopes the edge accepted, newest last.
     */
    public static function append($envelopes)
    {
        if (empty($envelopes)) {
            return;
        }

        $entries = array_merge(self::all(), $envelopes);

        if (count($entries) > self::MAX_ENTRIES) {
            $entries = array_slice($entries, -self::MAX_ENTRIES);
        }

        while (count($entries) > 1 && self::bytes($entries) > self::MAX_BYTES) {
            array_shift($entries);
        }

        PaypercutTelemetryStore::put(self::KEY, $entries);
    }

    public static function all()
    {
        return PaypercutTelemetryStore::get(self::KEY);
    }

    public static function clear()
    {
        PaypercutTelemetryStore::delete(self::KEY);
    }

    private static function bytes($entries)
    {
        $json = json_encode($entries);

        return is_string($json) ? strlen($json) : 0;
    }
}

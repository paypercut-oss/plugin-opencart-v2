<?php
/**
 * The buffered, best-effort store of diagnostic events awaiting delivery.
 *
 * Storefront requests only ever append here; delivery happens later, from an
 * authenticated admin request. Everything is capped, because a queue that can
 * grow without bound on a busy store is a denial of service against the store.
 */
class PaypercutEventQueue
{
    /**
     * Append envelopes, dropping the oldest if that overflows the caps.
     */
    public static function append($envelopes)
    {
        $envelopes = self::assertSafe($envelopes);

        if (empty($envelopes)) {
            return;
        }

        $capped = self::cap(array_merge(self::read(PaypercutTelemetrySession::QUEUE_KEY), $envelopes));

        self::write(PaypercutTelemetrySession::QUEUE_KEY, $capped['envelopes']);

        // Counted only from admin requests. A storefront request must make at
        // most one write, and the queue write above is it - the panel already
        // presents this counter as approximate.
        if ($capped['dropped'] > 0 && PaypercutTelemetryContext::isAdminRequest()) {
            $runtime = PaypercutTelemetrySession::runtime();

            PaypercutTelemetrySession::updateRuntime(array(
                'events_dropped' => (int)(isset($runtime['events_dropped']) ? $runtime['events_dropped'] : 0) + $capped['dropped']
            ));
        }
    }

    /**
     * The last gate before anything is persisted for delivery.
     *
     * Every producer funnels through here - the storefront recorder and the
     * admin-side lifecycle events alike - so the deny assertion cannot be
     * bypassed by adding a new call site. A tripped assertion drops the whole
     * event, not the offending field: an event assembled wrongly cannot be
     * trusted in its other parts either.
     */
    private static function assertSafe($envelopes)
    {
        if (empty($envelopes)) {
            return array();
        }

        $secrets = PaypercutTelemetrySession::credentials();
        $safe = array();

        foreach ($envelopes as $envelope) {
            // `error` is a top-level sibling of `attrs`, so it has to be named
            // here or it bypasses the one gate every producer funnels through.
            $screened = array();

            foreach (array('attrs', 'error') as $field) {
                if (isset($envelope[$field]) && is_array($envelope[$field])) {
                    $screened[$field] = $envelope[$field];
                }
            }

            if (PaypercutEvent::isDenied($screened, $secrets)) {
                // The event NAME only - never the envelope.
                PaypercutTelemetrySession::audit(
                    'Telemetry: event dropped by the deny assertion',
                    array('event' => (string)(isset($envelope['event']) ? $envelope['event'] : 'unknown'))
                );

                continue;
            }

            $safe[] = $envelope;
        }

        return $safe;
    }

    /**
     * Enforce the queue caps, dropping the OLDEST entries first.
     */
    public static function cap($envelopes)
    {
        $dropped = 0;

        if (count($envelopes) > PaypercutTelemetrySession::MAX_QUEUE_EVENTS) {
            $dropped = count($envelopes) - PaypercutTelemetrySession::MAX_QUEUE_EVENTS;
            $envelopes = array_slice($envelopes, -PaypercutTelemetrySession::MAX_QUEUE_EVENTS);
        }

        // Stop at one, mirroring splitBatch(): a single oversized envelope must
        // not empty the queue behind it.
        while (count($envelopes) > 1 && self::bytes($envelopes) > PaypercutTelemetrySession::MAX_QUEUE_BYTES) {
            array_shift($envelopes);
            $dropped++;
        }

        return array(
            'envelopes' => $envelopes,
            'dropped' => $dropped
        );
    }

    /**
     * Split a batch off the front of the queue, within both edge bounds.
     *
     * Always takes at least one envelope: a single oversized envelope would
     * otherwise wedge the queue forever, and the edge rejecting it once is a
     * cheaper outcome than never draining. Never drops and never reorders -
     * batch + remainder === input.
     */
    public static function splitBatch($envelopes, $max_bytes, $max_events)
    {
        $batch = array();

        foreach ($envelopes as $envelope) {
            if (count($batch) >= $max_events) {
                break;
            }

            $candidate = array_merge($batch, array($envelope));

            if (!empty($batch) && self::bytes($candidate) > $max_bytes) {
                break;
            }

            $batch = $candidate;
        }

        return array(
            'batch' => $batch,
            'remainder' => array_slice($envelopes, count($batch))
        );
    }

    /**
     * Take a batch, shortening the stored queue immediately.
     *
     * The remainder is written back BEFORE the network call, and the batch is
     * parked under its own key. Holding the remainder across the request would
     * discard anything storefront requests appended while the POST was in
     * flight, and could resurrect an already-delivered batch.
     */
    public static function takeBatch($max_bytes, $max_events)
    {
        $split = self::splitBatch(self::read(PaypercutTelemetrySession::QUEUE_KEY), $max_bytes, $max_events);

        if (empty($split['batch'])) {
            return array();
        }

        self::write(PaypercutTelemetrySession::QUEUE_KEY, $split['remainder']);
        self::write(PaypercutTelemetrySession::INFLIGHT_KEY, $split['batch']);

        return $split['batch'];
    }

    /**
     * A batch that was taken but whose delivery has not been settled.
     */
    public static function inflight()
    {
        return self::read(PaypercutTelemetrySession::INFLIGHT_KEY);
    }

    public static function clearInflight()
    {
        PaypercutTelemetryStore::delete(PaypercutTelemetrySession::INFLIGHT_KEY);
    }

    /**
     * Shorten the parked batch to what is left to deliver.
     *
     * The flusher may only ever SHORTEN inflight, never write the queue: the
     * flush lock excludes other flushers, but append() is an unlocked
     * read-modify-write from anonymous storefront requests, and takeBatch() has
     * already removed this batch from the queue.
     */
    public static function retainInflight($envelopes)
    {
        self::write(PaypercutTelemetrySession::INFLIGHT_KEY, $envelopes);
    }

    public static function size()
    {
        return count(self::read(PaypercutTelemetrySession::QUEUE_KEY))
            + count(self::read(PaypercutTelemetrySession::INFLIGHT_KEY));
    }

    public static function bytes($envelopes)
    {
        $json = json_encode($envelopes);

        return is_string($json) ? strlen($json) : 0;
    }

    private static function read($key)
    {
        return PaypercutTelemetryStore::get($key);
    }

    private static function write($key, $envelopes)
    {
        PaypercutTelemetryStore::put($key, $envelopes, self::ttl());
    }

    /**
     * Outlive the session slightly, so a final flush still finds its batch.
     */
    private static function ttl()
    {
        $record = PaypercutTelemetrySession::record();
        $expires_at = (int)(isset($record['expires_at']) ? $record['expires_at'] : 0);

        return max(300, ($expires_at - time()) + 300);
    }
}

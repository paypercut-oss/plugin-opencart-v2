<?php
/**
 * The only surface the rest of the extension uses to report a diagnostic event.
 *
 * Call sites hand events here from anywhere, including anonymous checkout and
 * webhook requests. The contract those call sites rely on is that this method
 * is nearly free and never reaches the network: when no session is running it
 * reads one already-loaded setting and returns.
 */
class PaypercutEventRecorder
{
    private static $buffer = array();

    private static $registered = false;

    /**
     * Buffer one event for later delivery.
     *
     * Never sends, and never tears a session down: that belongs to admin
     * requests, because this runs on the checkout path. The request's whole
     * contribution is one queue write at shutdown, however many it buffered.
     */
    public static function record($event)
    {
        if (!($event instanceof PaypercutEvent) || !PaypercutTelemetrySession::isActiveFast()) {
            return;
        }

        // The deny assertion lives in PaypercutEventQueue::append() so that it
        // covers every producer, including the admin-side lifecycle events.
        // Nothing here may write a row: this runs on checkout requests.
        self::$buffer[] = $event->envelope();

        if (!self::$registered) {
            self::$registered = true;
            register_shutdown_function(array('PaypercutEventRecorder', 'persist'));
        }
    }

    /**
     * Write the request's buffered events to the queue, once, at shutdown.
     *
     * One capped write per request rather than one per event: concurrent
     * storefront requests read-modify-write the same row, so fewer writes means
     * fewer lost updates. Delivery is best-effort by design and the panel
     * reports a dropped count; this is diagnostic data, never an audit trail.
     */
    public static function persist()
    {
        if (empty(self::$buffer)) {
            return;
        }

        $buffer = self::$buffer;
        self::$buffer = array();

        PaypercutEventQueue::append($buffer);
    }

    /**
     * Test seam: forget anything buffered by the current request.
     */
    public static function reset()
    {
        self::$buffer = array();
        self::$registered = false;
    }
}

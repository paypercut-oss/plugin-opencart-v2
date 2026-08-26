<?php
/**
 * Loads the telemetry library and wires it to an OpenCart registry.
 *
 * Every controller that reports events requires this file once and calls
 * PaypercutTelemetry::boot($this->registry, $is_admin_request).
 */
require_once dirname(__DIR__) . '/environment.php';

require_once __DIR__ . '/event.php';
require_once __DIR__ . '/context.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/recorder.php';
require_once __DIR__ . '/sent_log.php';
require_once __DIR__ . '/http.php';
require_once __DIR__ . '/mint_errors.php';
require_once __DIR__ . '/minter.php';
require_once __DIR__ . '/edge_client.php';
require_once __DIR__ . '/flusher.php';
require_once __DIR__ . '/snapshot.php';
require_once __DIR__ . '/extensions.php';
require_once __DIR__ . '/fatal_watch.php';

class PaypercutTelemetry
{
    private static $booted = false;

    /**
     * @param object $registry OpenCart's registry.
     * @param bool   $admin    True only for the admin entry point.
     */
    public static function boot($registry, $admin)
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        PaypercutTelemetryContext::boot($registry, $admin);
        PaypercutTelemetrySession::boot($registry);
        PaypercutEnvironmentSnapshot::boot($registry);
        PaypercutActiveExtensions::boot($registry);

        PaypercutFatalErrorWatch::register();
    }

    /**
     * Report an event. A no-op when no session is running.
     */
    public static function record($event)
    {
        PaypercutEventRecorder::record($event);
    }
}

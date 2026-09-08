<?php
/**
 * Reports the fatal errors a debug session would otherwise never see.
 *
 * A fatal on the checkout page breaks our payment form whichever extension
 * raised it, and it never reaches a catch block - so the session sees nothing
 * at all unless the shutdown handler looks.
 */
class PaypercutFatalErrorWatch
{
    private static $registered = false;

    public static function register()
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        // Registered after the recorder's own shutdown hook, so it still runs
        // once that has persisted whatever the request buffered.
        register_shutdown_function(array('PaypercutFatalErrorWatch', 'report'));
    }

    /**
     * Record the fatal that ended this request, if there was one.
     */
    public static function report()
    {
        $error = error_get_last();

        // The levels that end a request. A warning is noise; these are the bug.
        $fatal_levels = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);

        if ($error === null || !in_array(isset($error['type']) ? $error['type'] : 0, $fatal_levels, true)) {
            return;
        }

        if (!PaypercutTelemetrySession::isActiveFast()) {
            return;
        }

        $event = PaypercutEvent::fatal(
            (string)(isset($error['message']) ? $error['message'] : ''),
            (string)(isset($error['file']) ? $error['file'] : ''),
            (int)(isset($error['line']) ? $error['line'] : 0),
            (int)(isset($error['type']) ? $error['type'] : 0)
        );

        // The recorder's own shutdown hook has already run, so this writes
        // directly rather than buffering for a flush that will never come.
        PaypercutEventQueue::append(array($event->envelope()));
    }

    public static function reset()
    {
        self::$registered = false;
    }
}

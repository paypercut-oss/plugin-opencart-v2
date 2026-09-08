<?php
/**
 * Which kind of request is running, for the guards that must be stricter than
 * "is this the admin".
 *
 * The admin flag is set explicitly by whichever controller booted telemetry
 * rather than sniffed from constants: OpenCart's admin and catalog entry points
 * share DIR_SYSTEM, and getting this wrong would put a telemetry POST on the
 * checkout critical path.
 */
class PaypercutTelemetryContext
{
    private static $registry = null;

    private static $admin = false;

    public static function boot($registry, $admin)
    {
        self::$registry = $registry;
        self::$admin = (bool)$admin;
    }

    /**
     * An authenticated admin request: the only place a session may be started,
     * flushed or torn down.
     */
    public static function isAdminRequest()
    {
        if (!self::$admin || self::$registry === null || !self::$registry->has('user')) {
            return false;
        }

        return (bool)self::$registry->get('user')->isLogged();
    }

    /**
     * ...and one whose user may manage the extension.
     */
    public static function canManage()
    {
        if (!self::isAdminRequest()) {
            return false;
        }

        return (bool)self::$registry->get('user')->hasPermission('modify', 'extension/payment/paypercut');
    }
}

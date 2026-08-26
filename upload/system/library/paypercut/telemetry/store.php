<?php
/**
 * The three storage primitives every other telemetry unit depends on.
 *
 * OpenCart has no transient concept and no core lock primitive, so both are
 * built here on module-owned tables. The one thing that must NOT live in a
 * table is the session record: it is read on every anonymous storefront
 * request, so it goes in `oc_setting`, which OpenCart already loads wholesale
 * into `$config` on every request.
 *
 * It is stored under its own setting code (`paypercut_telemetry`) rather than
 * `paypercut`, because saving the settings form deletes and re-inserts every
 * row with code `paypercut` - which would silently drop a live session's
 * record and strand its token.
 */
class PaypercutTelemetryStore
{
    const SETTING_CODE = 'paypercut_telemetry';

    const RECORD_KEY = 'paypercut_telemetry_session';

    private static $registry = null;

    private static $record_memo = null;

    private static $tables_ready = false;

    private static $lock_owners = array();

    public static function boot($registry)
    {
        self::$registry = $registry;
    }

    public static function ready()
    {
        return self::$registry !== null;
    }

    private static function db()
    {
        return self::$registry->get('db');
    }

    private static function table($name)
    {
        return '`' . DB_PREFIX . 'paypercut_telemetry_' . $name . '`';
    }

    /**
     * Create the module-owned tables if they are not there yet.
     *
     * Called lazily from every accessor. It only ever fires while a session is
     * live (nothing else reads the queue), so the storefront pays for it only
     * on a store whose merchant has explicitly started one.
     */
    public static function ensureTables()
    {
        if (self::$tables_ready || !self::ready()) {
            return;
        }

        self::$tables_ready = true;

        self::db()->query("
            CREATE TABLE IF NOT EXISTS " . self::table('store') . " (
                `store_key` varchar(64) NOT NULL,
                `store_value` longtext NOT NULL,
                `expires_at` int(11) NOT NULL DEFAULT '0',
                PRIMARY KEY (`store_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
        ");

        self::db()->query("
            CREATE TABLE IF NOT EXISTS " . self::table('lock') . " (
                `lock_name` varchar(64) NOT NULL,
                `owner` varchar(32) NOT NULL,
                `claimed_at` int(11) NOT NULL,
                PRIMARY KEY (`lock_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;
        ");
    }

    /**
     * The durable session record. Free on the storefront: OpenCart has already
     * loaded every `oc_setting` row into $config by the time this runs.
     */
    public static function getRecord()
    {
        if (self::$record_memo !== null) {
            return self::$record_memo;
        }

        if (!self::ready()) {
            return array();
        }

        $record = self::$registry->get('config')->get(self::RECORD_KEY);

        self::$record_memo = is_array($record) ? $record : array();

        return self::$record_memo;
    }

    public static function putRecord($record)
    {
        if (!self::ready()) {
            return;
        }

        $db = self::db();

        $db->query("
            DELETE FROM `" . DB_PREFIX . "setting`
            WHERE `code` = '" . $db->escape(self::SETTING_CODE) . "'
            AND `key` = '" . $db->escape(self::RECORD_KEY) . "'
        ");

        $db->query("
            INSERT INTO `" . DB_PREFIX . "setting`
            SET store_id = '0',
                `code` = '" . $db->escape(self::SETTING_CODE) . "',
                `key` = '" . $db->escape(self::RECORD_KEY) . "',
                `value` = '" . $db->escape(json_encode($record)) . "',
                serialized = '1'
        ");

        self::$record_memo = $record;
    }

    public static function deleteRecord()
    {
        if (!self::ready()) {
            return;
        }

        $db = self::db();

        $db->query("
            DELETE FROM `" . DB_PREFIX . "setting`
            WHERE `code` = '" . $db->escape(self::SETTING_CODE) . "'
            AND `key` = '" . $db->escape(self::RECORD_KEY) . "'
        ");

        self::$record_memo = array();
    }

    /**
     * Read a keyed blob, honouring its expiry.
     *
     * The stored TTL is a backstop, never the authority - every caller
     * re-validates what it reads against the session record.
     */
    public static function get($key)
    {
        if (!self::ready()) {
            return array();
        }

        self::ensureTables();

        $db = self::db();

        $query = $db->query("
            SELECT store_value FROM " . self::table('store') . "
            WHERE store_key = '" . $db->escape($key) . "'
            AND (expires_at = '0' OR expires_at > '" . (int)time() . "')
            LIMIT 1
        ");

        if (!$query->num_rows) {
            return array();
        }

        $value = json_decode($query->row['store_value'], true);

        return is_array($value) ? $value : array();
    }

    /**
     * Write a keyed blob. An empty value deletes the key, so an empty queue
     * leaves no row behind.
     *
     * @param int $ttl Seconds until expiry, or 0 for no expiry.
     */
    public static function put($key, $value, $ttl = 0)
    {
        if (!self::ready()) {
            return;
        }

        if (empty($value)) {
            self::delete($key);
            return;
        }

        self::ensureTables();

        $db = self::db();
        $expires_at = $ttl > 0 ? time() + (int)$ttl : 0;

        $db->query("
            REPLACE INTO " . self::table('store') . "
            SET store_key = '" . $db->escape($key) . "',
                store_value = '" . $db->escape(json_encode($value)) . "',
                expires_at = '" . (int)$expires_at . "'
        ");
    }

    public static function delete($key)
    {
        if (!self::ready()) {
            return;
        }

        self::ensureTables();

        $db = self::db();

        $db->query("
            DELETE FROM " . self::table('store') . "
            WHERE store_key = '" . $db->escape($key) . "'
        ");
    }

    /**
     * Take a lock that genuinely fails under contention.
     *
     * A plain INSERT against a primary key: exactly one concurrent caller sees
     * one affected row. Deliberately not a read-then-write, and deliberately
     * not an `oc_setting` write - two callers reading an absent row would both
     * believe they had won.
     */
    public static function claimLock($name, $ttl)
    {
        if (!self::ready()) {
            return false;
        }

        self::ensureTables();

        $db = self::db();
        $owner = self::randomToken(16);

        $db->query("
            INSERT IGNORE INTO " . self::table('lock') . "
            SET lock_name = '" . $db->escape($name) . "',
                owner = '" . $db->escape($owner) . "',
                claimed_at = '" . (int)time() . "'
        ");

        if ((int)$db->countAffected() === 1) {
            self::$lock_owners[$name] = $owner;

            return true;
        }

        if (!self::lockIsStale($name, $ttl)) {
            return false;
        }

        // An abandoned lock: clear it and try exactly once more, so a crashed
        // request cannot block the feature forever and a live holder is never
        // displaced by an unbounded retry loop.
        self::forceReleaseLock($name);

        return self::claimLock($name, $ttl);
    }

    /**
     * Release a lock only if this request is still the holder.
     *
     * A request that overran the TTL and had its lock stolen must not delete
     * the new holder's lock on the way out.
     */
    public static function releaseLock($name)
    {
        if (!isset(self::$lock_owners[$name]) || !self::ready()) {
            return;
        }

        $owner = self::$lock_owners[$name];
        unset(self::$lock_owners[$name]);

        $held = self::readLock($name);

        if ($held !== null && $held['owner'] !== $owner) {
            return;
        }

        self::forceReleaseLock($name);
    }

    private static function lockIsStale($name, $ttl)
    {
        $held = self::readLock($name);

        if ($held === null) {
            return true;
        }

        return (time() - (int)$held['claimed_at']) > (int)$ttl;
    }

    private static function readLock($name)
    {
        $db = self::db();

        $query = $db->query("
            SELECT owner, claimed_at FROM " . self::table('lock') . "
            WHERE lock_name = '" . $db->escape($name) . "'
            LIMIT 1
        ");

        return $query->num_rows ? $query->row : null;
    }

    private static function forceReleaseLock($name)
    {
        $db = self::db();

        $db->query("
            DELETE FROM " . self::table('lock') . "
            WHERE lock_name = '" . $db->escape($name) . "'
        ");
    }

    /**
     * Write a log line whatever the merchant's logging preference is.
     *
     * Starting and stopping a session is an audit event: a store with logging
     * switched off must still leave a record that data left it.
     */
    public static function audit($message, $context = array())
    {
        if (!self::ready()) {
            return;
        }

        self::$registry->get('log')->write($message . ' ' . json_encode($context));
    }

    public static function randomToken($bytes)
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes($bytes));
        }

        if (function_exists('openssl_random_pseudo_bytes')) {
            return bin2hex(openssl_random_pseudo_bytes($bytes));
        }

        $token = '';

        for ($i = 0; $i < $bytes * 2; $i++) {
            $token .= substr('0123456789abcdef', mt_rand(0, 15), 1);
        }

        return $token;
    }

    /**
     * Test seam: forget the per-request memo after a state transition.
     */
    public static function flushRecordMemo()
    {
        self::$record_memo = null;
    }
}

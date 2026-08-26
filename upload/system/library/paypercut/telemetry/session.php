<?php
/**
 * Owns the debug (telemetry) session: its state, its token custody, its teardown.
 *
 * A debug session is a merchant-granted, self-expiring window during which the
 * store may send diagnostic events to Paypercut. The deadline is an absolute
 * unix timestamp in a durable, cheaply-readable record, and every read
 * recomputes liveness against it. That is what makes the session end on time
 * with no scheduled job: there is no timer to miss and nothing to orphan if the
 * process dies. The token's own `exp` is the matching bound on the server side.
 */
class PaypercutTelemetrySession
{
    /**
     * Hard ceiling on a session, independent of what the mint hands back.
     *
     * With no revocation anywhere, this ceiling IS the consent: the merchant is
     * told "about 60 minutes", so the extension must not run for longer even if
     * a future deployment issues longer-lived tokens.
     */
    const SESSION_MAX_SECONDS = 3600;

    /**
     * Give up this many seconds before the token actually expires, so we always
     * stop before the edge would start rejecting it.
     */
    const SKEW_SECONDS = 30;

    const MIN_LIFETIME_SECONDS = 60;

    const MAX_QUEUE_EVENTS = 200;

    const MAX_QUEUE_BYTES = 65536;

    /**
     * Kept well under the edge's 64 KiB body cap: the edge does not
     * deduplicate, so a bigger batch only means losing more per failed POST.
     */
    const MAX_BATCH_BYTES = 16384;

    /**
     * The edge's own MaxEventsPerBatch. A batch over it is refused with 413.
     */
    const MAX_BATCH_EVENTS = 50;

    const MAX_CONSECUTIVE_SEND_FAILURES = 4;

    const MINT_TIMEOUT_SECONDS = 10;

    const MINT_CONNECT_TIMEOUT_SECONDS = 5;

    const EDGE_TIMEOUT_SECONDS = 5;

    const EDGE_CONNECT_TIMEOUT_SECONDS = 3;

    const START_LOCK_TTL = 60;

    const FLUSH_LOCK_TTL = 60;

    const FAILED_NOTICE_TTL = 600;

    const ENDED_NOTICE_TTL = 86400;

    const POLL_INTERVAL_SECONDS = 60;

    const SLOW_REQUEST_MS = 3000;

    const TOKEN_KEY = 'paypercut_telemetry_token';

    const QUEUE_KEY = 'paypercut_telemetry_queue';

    const INFLIGHT_KEY = 'paypercut_telemetry_inflight';

    const RUNTIME_KEY = 'paypercut_telemetry_runtime';

    const START_LOCK = 'paypercut_telemetry_start_lock';

    const FLUSH_LOCK = 'paypercut_telemetry_flush_lock';

    /**
     * Per-request memo for the storefront gate.
     */
    private static $active_memo = null;

    private static $registry = null;

    public static function boot($registry)
    {
        self::$registry = $registry;
        PaypercutTelemetryStore::boot($registry);
    }

    private static function config()
    {
        return self::$registry->get('config');
    }

    /**
     * The storefront gate: is a session live right now?
     *
     * Reads one already-loaded setting and nothing else - no queries, no
     * writes, no HTTP. This runs on anonymous checkout requests, so anything
     * more expensive belongs behind an admin guard.
     */
    public static function isActiveFast()
    {
        if (self::$active_memo !== null) {
            return self::$active_memo;
        }

        if (self::$registry === null) {
            return false;
        }

        $record = self::record();

        self::$active_memo = isset($record['status'])
            && $record['status'] === 'active'
            && (int)(isset($record['expires_at']) ? $record['expires_at'] : 0) > time();

        return self::$active_memo;
    }

    public static function flushMemo()
    {
        self::$active_memo = null;
    }

    public static function record()
    {
        return PaypercutTelemetryStore::getRecord();
    }

    /**
     * The session state as the admin UI should present it.
     */
    public static function describe()
    {
        $record = self::record();
        $runtime = self::runtime();
        $now = time();

        $status = isset($record['status']) ? (string)$record['status'] : '';
        $ended_at = (int)(isset($record['ended_at']) ? $record['ended_at'] : 0);
        $state = 'idle';

        if ($status === 'active') {
            $state = (int)(isset($record['expires_at']) ? $record['expires_at'] : 0) > $now ? 'running' : 'ended';
        } elseif ($status === 'failed') {
            $state = ($now - $ended_at) < self::FAILED_NOTICE_TTL ? 'failed' : 'idle';
        } elseif ($status === 'stopped' || $status === 'expired') {
            $state = ($now - $ended_at) < self::ENDED_NOTICE_TTL ? 'ended' : 'idle';
        }

        return array(
            'state' => $state,
            'session_id' => isset($record['session_id']) ? (string)$record['session_id'] : '',
            'expires_at' => (int)(isset($record['expires_at']) ? $record['expires_at'] : 0),
            'started_at' => (int)(isset($record['started_at']) ? $record['started_at'] : 0),
            'ended_at' => $ended_at,
            'started_by_name' => isset($record['started_by_name']) ? (string)$record['started_by_name'] : '',
            'reason_code' => isset($record['reason_code']) ? (string)$record['reason_code'] : '',
            'trace_id' => isset($record['trace_id']) ? (string)$record['trace_id'] : '',
            'request_id' => isset($record['request_id']) ? (string)$record['request_id'] : '',
            'retryable' => (bool)(isset($record['retryable']) ? $record['retryable'] : false),
            'message' => isset($record['message']) ? (string)$record['message'] : '',
            'events_sent' => (int)(isset($runtime['events_sent']) ? $runtime['events_sent'] : 0),
            'events_dropped' => (int)(isset($runtime['events_dropped']) ? $runtime['events_dropped'] : 0),
            'queued' => PaypercutEventQueue::size()
        );
    }

    /**
     * The telemetry token, or '' when there is not a usable one.
     *
     * Every condition here is a reason the token must not be used, and each is
     * checked rather than assumed: the stored TTL is a backstop, never the
     * authority.
     */
    public static function token()
    {
        $record = self::record();

        if (!isset($record['status']) || $record['status'] !== 'active') {
            return '';
        }

        $expires_at = (int)(isset($record['expires_at']) ? $record['expires_at'] : 0);

        if ($expires_at <= time()) {
            return '';
        }

        $stored = PaypercutTelemetryStore::get(self::TOKEN_KEY);

        if (empty($stored) || !isset($stored['token']) || !is_string($stored['token'])) {
            return '';
        }

        if ((int)(isset($stored['expires_at']) ? $stored['expires_at'] : 0) !== $expires_at) {
            return '';
        }

        if (!self::credentialMatches($record)) {
            return '';
        }

        $decoded = base64_decode($stored['token'], true);

        return is_string($decoded) ? $decoded : '';
    }

    /**
     * Does the stored record still describe the connection the store has today?
     */
    public static function credentialMatches($record)
    {
        $connection = self::connection();
        $fingerprint = self::fingerprint($connection['secret']);

        if ($fingerprint === '' || $fingerprint !== (string)(isset($record['key_fingerprint']) ? $record['key_fingerprint'] : '')) {
            return false;
        }

        return $connection['environment'] === (string)(isset($record['environment']) ? $record['environment'] : '');
    }

    /**
     * The extension's stored credential and environment.
     */
    public static function connection()
    {
        $config = self::config();

        return array(
            'secret' => (string)$config->get('paypercut_api_key'),
            'environment' => PaypercutEnvironment::stored($config->get('paypercut_environment'))
        );
    }

    /**
     * Every credential the store holds, for the deny assertion to compare against.
     *
     * This list must enumerate every credential-bearing setting: comparing a
     * value against the actual secret is the only screen that catches a format
     * nobody anticipated, and it is silently useless for a setting not named
     * here. A future gateway adding its own credential setting breaks it.
     */
    public static function credentials()
    {
        if (self::$registry === null) {
            return array();
        }

        $config = self::config();
        $secrets = array(self::token());

        foreach (array('paypercut_api_key', 'paypercut_webhook_secret') as $key) {
            $value = $config->get($key);

            if (is_string($value) && $value !== '') {
                $secrets[] = $value;
            }
        }

        // An empty secret would match every string, so filter before returning.
        return array_values(array_filter($secrets));
    }

    /**
     * A short, non-reversing marker for "the same API key as before".
     */
    public static function fingerprint($secret)
    {
        return $secret === '' ? '' : substr(hash('sha256', $secret), 0, 12);
    }

    /**
     * Publish a new session and store its token.
     */
    public static function begin($record, $jwt)
    {
        $expires_at = (int)$record['expires_at'];

        PaypercutTelemetryStore::put(
            self::TOKEN_KEY,
            array(
                'token' => base64_encode($jwt),
                'expires_at' => $expires_at
            ),
            max(60, $expires_at - time())
        );

        // Never inherit a previous session's buffer: those events were gathered
        // under a different consent and would ship under this session's id.
        PaypercutTelemetryStore::delete(self::QUEUE_KEY);
        PaypercutTelemetryStore::delete(self::INFLIGHT_KEY);

        // The log shows what this session sent, so a previous one's tail would
        // misattribute events the merchant is reading to decide what happened.
        PaypercutSentLog::clear();

        PaypercutTelemetryStore::putRecord($record);

        PaypercutTelemetryStore::put(
            self::RUNTIME_KEY,
            array(
                'events_sent' => 0,
                'events_dropped' => 0,
                'consecutive_edge_failures' => 0,
                'next_attempt_at' => 0,
                'last_error' => ''
            )
        );

        self::flushMemo();
    }

    /**
     * Record a start that never happened, so the merchant sees why.
     */
    public static function fail($mapped, $trace_id = '', $request_id = '')
    {
        // Never overwrite a live session with a failure notice: a concurrent
        // start that loses a race would otherwise erase the winner's record and
        // strand its token beyond the reach of every teardown path.
        $record = self::record();

        if (isset($record['status']) && $record['status'] === 'active') {
            return;
        }

        PaypercutTelemetryStore::putRecord(array(
            'status' => 'failed',
            'ended_at' => time(),
            'reason_code' => $mapped['reason_code'],
            'message' => $mapped['message'],
            'retryable' => $mapped['retryable'],
            'trace_id' => $trace_id,
            'request_id' => $request_id
        ));

        self::flushMemo();
    }

    /**
     * End the session and destroy every trace of its credential.
     *
     * Idempotent, and the single teardown path: expiry, the Stop button, a
     * re-key, an environment change and uninstall all arrive here, so there is
     * exactly one place that can forget something.
     */
    public static function end($reason)
    {
        $record = self::record();

        PaypercutTelemetryStore::delete(self::TOKEN_KEY);
        PaypercutTelemetryStore::delete(self::QUEUE_KEY);
        PaypercutTelemetryStore::delete(self::INFLIGHT_KEY);

        if (empty($record) || !isset($record['status']) || $record['status'] !== 'active') {
            PaypercutTelemetryStore::delete(self::RUNTIME_KEY);
            self::flushMemo();
            return;
        }

        $runtime = self::runtime();

        PaypercutTelemetryStore::putRecord(array(
            'status' => $reason === 'expired' ? 'expired' : 'stopped',
            'session_id' => (string)(isset($record['session_id']) ? $record['session_id'] : ''),
            'environment' => (string)(isset($record['environment']) ? $record['environment'] : ''),
            'started_at' => (int)(isset($record['started_at']) ? $record['started_at'] : 0),
            'expires_at' => (int)(isset($record['expires_at']) ? $record['expires_at'] : 0),
            'started_by' => (int)(isset($record['started_by']) ? $record['started_by'] : 0),
            'started_by_name' => (string)(isset($record['started_by_name']) ? $record['started_by_name'] : ''),
            'ended_at' => time(),
            'reason_code' => $reason,
            'events_sent' => (int)(isset($runtime['events_sent']) ? $runtime['events_sent'] : 0),
            'events_dropped' => (int)(isset($runtime['events_dropped']) ? $runtime['events_dropped'] : 0)
        ));

        PaypercutTelemetryStore::delete(self::RUNTIME_KEY);

        self::flushMemo();

        self::audit(
            'Telemetry: debug session ended',
            array(
                'session_id' => (string)(isset($record['session_id']) ? $record['session_id'] : ''),
                'reason' => $reason
            )
        );
    }

    /**
     * Tear down a session whose deadline has passed, or whose connection changed.
     *
     * Admin context only - it writes. This is what turns "the gate is closed"
     * into "the token is gone": the gate flips the instant the deadline passes,
     * but the stored copy is removed by the next admin request that runs this.
     */
    public static function reap()
    {
        $record = self::record();

        if (!isset($record['status']) || $record['status'] !== 'active') {
            /*
             * No live session, but a stored token means the record was lost
             * without one. The credential is now referenced by nothing, so
             * destroy it here rather than leave it to expire.
             */
            if (!empty(PaypercutTelemetryStore::get(self::TOKEN_KEY))) {
                self::end('token_orphaned');
            }

            return;
        }

        if ((int)(isset($record['expires_at']) ? $record['expires_at'] : 0) <= time()) {
            self::end('expired');
            return;
        }

        if (!self::credentialMatches($record)) {
            self::end('connection_changed');
            return;
        }

        if (self::token() === '') {
            self::end('token_lost');
        }
    }

    public static function runtime()
    {
        return PaypercutTelemetryStore::get(self::RUNTIME_KEY);
    }

    public static function updateRuntime($values)
    {
        PaypercutTelemetryStore::put(self::RUNTIME_KEY, array_merge(self::runtime(), $values));
    }

    /**
     * Claim an exclusive right to mint.
     *
     * Without a real mutex, two clicks in two tabs both mint. The loser's token
     * is then either overwritten in storage or discarded by the re-check - and
     * either way one fully valid credential exists that no teardown path knows
     * about and nothing can revoke.
     */
    public static function claimStartLock()
    {
        return PaypercutTelemetryStore::claimLock(self::START_LOCK, self::START_LOCK_TTL);
    }

    public static function releaseStartLock()
    {
        PaypercutTelemetryStore::releaseLock(self::START_LOCK);
    }

    public static function claimFlushLock()
    {
        return PaypercutTelemetryStore::claimLock(self::FLUSH_LOCK, self::FLUSH_LOCK_TTL);
    }

    public static function releaseFlushLock()
    {
        PaypercutTelemetryStore::releaseLock(self::FLUSH_LOCK);
    }

    public static function audit($message, $context = array())
    {
        PaypercutTelemetryStore::audit($message, $context);
    }

    /**
     * A new session identifier: "dbg_" plus 16 random alphanumerics.
     */
    public static function newSessionId()
    {
        return 'dbg_' . substr(PaypercutTelemetryStore::randomToken(16), 0, 16);
    }
}

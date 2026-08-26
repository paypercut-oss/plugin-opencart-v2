<?php
/**
 * A single diagnostic event, and the allow-list that defines what may leave the store.
 *
 * There is deliberately no generic "record these fields" constructor. Every
 * event is built by a named constructor with declared scalar parameters, so the
 * set of things that can ever be transmitted is fixed in this file rather than
 * at each call site. is_scalar() is explicitly NOT the boundary: every secret
 * this extension holds (paypercut_api_key, paypercut_webhook_secret) is a
 * scalar string living in the same settings row as the values we do report.
 */
class PaypercutEvent
{
    /**
     * Longest string any single field may carry, in BYTES.
     *
     * Bytes rather than codepoints because the edge bounds the raw Go string:
     * a 128-codepoint CJK theme name is 384 bytes and would be dropped whole.
     */
    const MAX_TEXT_BYTES = 256;

    /**
     * The edge keeps the first 32 attributes in sorted key order and drops the
     * rest, so a single over-wide event would silently lose its version fields.
     */
    const MAX_ATTRS = 16;

    /**
     * Enough frames to see where a failure came from, never a full dump.
     */
    const MAX_STACK_FRAMES = 8;

    /**
     * Shortest tail of a clamped value compared against a credential's opening
     * bytes. See endsMidSecret().
     */
    const MIN_SECRET_FRAGMENT = 4;

    /**
     * Field names that must never appear in an event, whatever their value.
     */
    private static $denied_key_pattern = '/secret|token|password|credential|nonce|auth|_key$/i';

    /**
     * Value shapes that must never appear in an event, whatever their field name.
     *
     * Not anchored to the start of the string, because a stack frame or an HTTP
     * error carries the credential mid-string every time. Not left unanchored
     * either: bare sk_/pk_ also matches `disk_usage` and `risk_free`, and a
     * tripped assertion bins the whole event.
     */
    private static $denied_value_pattern = '/(?:^|[^A-Za-z0-9_])(ppc_|sk_|pk_|whsec_|eyJ[A-Za-z0-9_-]+\.)/i';

    /**
     * Host and platform versions. Read by environmentSnapshot().
     *
     * Both snapshot lists are iterated INSTEAD of the caller's array: pulling
     * keys from a settings array is how a credential ends up on the wire.
     */
    private static $snapshot_fields = array(
        'plugin_version' => 'text',
        'opencart_version' => 'text',
        'php_version' => 'text',
        'theme_name' => 'text',
        'is_multistore' => 'bool',
        'is_ssl' => 'bool'
    );

    /**
     * Extension settings. Read by environmentConfiguration().
     */
    private static $configuration_fields = array(
        'checkout_mode' => 'identifier',
        'order_status_id' => 'identifier',
        'google_pay_enabled' => 'bool',
        'apple_pay_enabled' => 'bool',
        'statement_descriptor_set' => 'bool',
        'logging_enabled' => 'bool',
        'card_enabled' => 'bool',
        'connection_environment' => 'identifier',
        'api_key_mode' => 'identifier',
        'webhook_configured' => 'bool',
        'payment_domain_registered' => 'bool',
        'payment_method_config_set' => 'bool',
        'store_currency' => 'identifier',
        'apple_domain_file_present' => 'bool'
    );

    private $name;

    private $fields;

    /**
     * Contract-level correlation fields, sent outside `attrs`.
     */
    private $correlation = array();

    private $error = array();

    private function __construct($name, $fields)
    {
        $this->name = $name;
        $this->fields = $fields;
    }

    /**
     * Report something that happened and did not fail.
     *
     * Failures alone cannot answer the commonest support question, which is
     * whether the shopper ever reached us: a session with no checkout.* events
     * at all and one with a silent early return look identical.
     */
    public static function of($name, $attrs = array())
    {
        return new self($name, self::cleanAttrs($attrs));
    }

    /**
     * Report a failure, under whichever event name describes where it happened.
     *
     * The named constructors fix their attributes here in this file. This one
     * cannot: the code comes from the failing call site. The bound is enforced
     * instead of declared - $code is a slug, an exception contributes only its
     * type and a file/line stack, and prose reaches the wire only when a call
     * site authors it through ->because().
     */
    public static function failure($name, $code, $attrs = array(), $exception = null)
    {
        $event = new self($name, self::cleanAttrs($attrs));

        $code = self::text((string)$code);
        $event->error = array('code' => $code !== '' ? $code : 'unknown');

        if ($exception instanceof Exception) {
            // Never the exception's own message. OpenCart's database layer puts
            // the failing SQL and the connection's 'user'@'host' into it, and
            // the API quotes submitted input back - a rejected key arrives
            // inside the prose. The type, the stack, `api_code`/`trace_id` and
            // an authored ->because() carry the diagnosis instead.
            $event->error['type'] = self::shortClassName($exception);
            $event->error['stack'] = self::stack($exception);

            $event->fields = array_merge(self::origin(self::frameFiles($exception)), $event->fields);
        }

        return $event;
    }

    /**
     * Report a Paypercut API failure with the fields the platform returned.
     *
     * The platform quotes submitted input back, so a rejected key arrives
     * inside the error prose. `api_code` and `trace_id` carry the diagnosis.
     *
     * @param array $body Decoded error body from the API, or an empty array.
     */
    public static function apiFailure($name, $status, $body = array(), $attrs = array())
    {
        $status = (int)$status;
        $event = new self($name, self::cleanAttrs($attrs));

        $event->error = array('code' => 'http_' . $status);

        $error = isset($body['error']) && is_array($body['error']) ? $body['error'] : $body;

        // The one string here this extension does not author is `message`, and
        // it is deliberately never read.
        $type = self::text((string)self::pick($error, array('type')));

        if ($type !== '') {
            $event->error['type'] = $type;
        }

        $mapped = array(
            'api_code' => self::pick($error, array('code')),
            'api_param' => self::pick($error, array('param')),
            'trace_id' => self::pick($body, array('trace_id', 'request_id'))
        );

        foreach ($mapped as $key => $value) {
            $clean = self::text((string)$value);

            if ($clean !== '') {
                $event->fields[$key] = $clean;
            }
        }

        $event->fields['http_status'] = $status;

        return $event;
    }

    private static function pick($body, $keys)
    {
        if (!is_array($body)) {
            return '';
        }

        foreach ($keys as $key) {
            if (isset($body[$key]) && is_scalar($body[$key])) {
                return (string)$body[$key];
            }
        }

        return '';
    }

    /**
     * Report the fatal that ended a request.
     *
     * Built from error_get_last(), which carries no exception and no trace -
     * the file that died is the only attribution available.
     */
    public static function fatal($message, $file, $line, $level)
    {
        $event = new self('php.fatal', array('level' => (int)$level));

        $event->fields = array_merge(self::origin(array($file)), $event->fields);

        $event->error = array(
            'code' => 'php_fatal',
            'type' => 'FatalError',
            'message' => self::text(self::fatalMessage($message)),
            'stack' => array(self::relativePath($file) . ':' . (int)$line)
        );

        return $event;
    }

    /**
     * Reduce PHP's fatal message to the part that is not already reported.
     *
     * An uncaught Error arrives with its whole stack trace inlined and every
     * path absolute. Left alone it spends the clamp on frames the `stack` field
     * already carries, and puts the server's filesystem layout on the wire.
     */
    private static function fatalMessage($message)
    {
        $message = (string)$message;
        $trace = strpos($message, 'Stack trace:');

        if ($trace !== false) {
            $message = rtrim(substr($message, 0, $trace));
        }

        // OpenCart's database layer reports a failure as one line carrying the
        // whole statement after a <br />, so keep the first line only.
        $message = rtrim(substr($message, 0, strcspn($message, "\r\n")));
        $tag = strpos($message, '<br');

        if ($tag !== false) {
            $message = rtrim(substr($message, 0, $tag));
        }

        foreach (self::roots() as $prefix) {
            $message = str_replace(rtrim($prefix, '/') . '/', '', $message);
        }

        // "Access denied for user 'store'@'db.internal'" names the store's
        // database account and an internal host; an address is never shared
        // either. Neither adds anything the error code does not already say.
        return (string)preg_replace('/[^\s@]{1,64}@[^\s@]{1,64}/', '[redacted]', $message);
    }

    /**
     * Attribute a failure to the code that raised it.
     *
     * The commonest support case is another extension breaking ours, and the
     * answer is in the stack: the first frame outside our own files names it.
     * The wire values stay plugin/theme/core/paypercut across every platform so
     * support can compare stores; only merchant-facing copy says "extension".
     *
     * @param array $files Absolute paths, innermost first.
     */
    public static function origin($files)
    {
        foreach ($files as $file) {
            $file = (string)$file;

            if ($file === '' || self::isOurs($file)) {
                continue;
            }

            $relative = self::relativePath($file);

            if (preg_match('#(^|/)extension/[^/]+/([^/]+)\.php$#', $relative, $matches)) {
                return array(
                    'origin' => 'plugin',
                    'origin_plugin' => self::text($matches[2])
                );
            }

            if (strpos($relative, 'catalog/view/theme/') === 0 || strpos($relative, 'view/theme/') === 0) {
                return array('origin' => 'theme');
            }

            return array('origin' => 'core');
        }

        return array('origin' => 'paypercut');
    }

    /**
     * Is this one of our own files? Ours never counts as the origin.
     */
    private static function isOurs($file)
    {
        return strpos($file, '/library/paypercut/') !== false
            || preg_match('#(^|/)paypercut[^/]*\.php$#i', $file) === 1;
    }

    /**
     * Absolute file paths from a throwable, its own location first.
     */
    private static function frameFiles($exception)
    {
        $files = array($exception->getFile());

        foreach ($exception->getTrace() as $frame) {
            if (isset($frame['file'])) {
                $files[] = (string)$frame['file'];
            }
        }

        return $files;
    }

    /**
     * Attach the ids that join this event to a payment.
     */
    public function about($correlation)
    {
        foreach (array('payment_intent_id', 'payment_id', 'order_ref') as $field) {
            $value = isset($correlation[$field]) ? trim((string)$correlation[$field]) : '';

            if ($value !== '') {
                $this->correlation[$field] = self::text($value);
            }
        }

        return $this;
    }

    /**
     * A message this extension authored itself, for a failure with no exception
     * worth quoting.
     */
    public function because($message)
    {
        $clean = self::text((string)$message);

        if ($clean !== '') {
            $this->error['message'] = $clean;
        }

        return $this;
    }

    /**
     * The class name without its namespace.
     *
     * Public because a call site that must not send an exception's message
     * still wants to name its type - a rejected credential is quoted back in
     * the message but never in the class.
     */
    public static function shortClassName($exception)
    {
        $parts = explode('\\', get_class($exception));
        $name = self::text((string)end($parts));

        return $name !== '' ? $name : 'Exception';
    }

    /**
     * File and line only, at most MAX_STACK_FRAMES of them.
     *
     * Never getTraceAsString(): that renders call arguments, which here are
     * checkout payloads and credentials.
     */
    private static function stack($exception)
    {
        $frames = array();

        foreach ($exception->getTrace() as $frame) {
            if (count($frames) >= self::MAX_STACK_FRAMES) {
                break;
            }

            if (!isset($frame['file']) || !isset($frame['line'])) {
                continue;
            }

            $frames[] = self::relativePath((string)$frame['file']) . ':' . (int)$frame['line'];
        }

        return $frames;
    }

    /**
     * Filesystem roots to strip, most-specific first.
     */
    private static function roots()
    {
        $roots = array();

        // The install root first: it yields catalog/... and admin/... which
        // says which side of OpenCart the frame came from.
        if (defined('DIR_SYSTEM')) {
            $roots[] = rtrim(dirname(rtrim(constant('DIR_SYSTEM'), '/')), '/') . '/';
        }

        foreach (array('DIR_CATALOG', 'DIR_APPLICATION', 'DIR_SYSTEM') as $name) {
            if (defined($name)) {
                $roots[] = (string)constant($name);
            }
        }

        return array_filter($roots);
    }

    /**
     * Paths relative to the OpenCart install: an absolute path on shared
     * hosting names the merchant's account or domain.
     */
    private static function relativePath($file)
    {
        $file = (string)$file;

        foreach (self::roots() as $prefix) {
            if ($prefix !== '' && strpos($file, $prefix) === 0) {
                return ltrim(substr($file, strlen($prefix)), '/');
            }
        }

        return '[external]';
    }

    /**
     * Note what is absent: the OpenCart user who started the session. The
     * durable record keeps it for the admin notice, but it is a store-user
     * identifier that the merchant-facing disclosure does not cover, so it does
     * not go on the wire.
     */
    public static function sessionStarted($session_id, $environment, $expires_at)
    {
        return new self(
            'session.started',
            array(
                'session_id' => self::identifier($session_id),
                'environment' => self::identifier($environment),
                'expires_at' => (int)$expires_at
            )
        );
    }

    public static function sessionStopped($session_id, $reason, $events_sent, $events_dropped)
    {
        return new self(
            'session.stopped',
            array(
                'session_id' => self::identifier($session_id),
                'reason' => self::identifier($reason),
                'events_sent' => (int)$events_sent,
                'events_dropped' => (int)$events_dropped
            )
        );
    }

    /**
     * Build the one-off environment snapshot.
     *
     * @param array $values Candidate values; only $snapshot_fields keys are read.
     */
    public static function environmentSnapshot($values)
    {
        return new self('environment.snapshot', self::castFields(self::$snapshot_fields, $values));
    }

    /**
     * Build the one-off extension-configuration snapshot.
     *
     * Separate from the environment snapshot only because the two together
     * exceed MAX_ATTRS; nothing else distinguishes them.
     */
    public static function environmentConfiguration($values)
    {
        return new self('environment.configuration', self::castFields(self::$configuration_fields, $values));
    }

    /**
     * Build the installed-extension inventory, chunked to fit the attribute cap.
     *
     * A conflict is usually named here: this is the list support compares
     * against a working store. Codes and versions only - no author, no path.
     *
     * @param array $plugins code => version, sorted by the caller.
     *
     * @return array of PaypercutEvent
     */
    public static function environmentPlugins($plugins)
    {
        $total = count($plugins);
        $chunks = array_chunk($plugins, self::MAX_ATTRS - 2, true);
        $events = array();

        foreach ($chunks as $index => $chunk) {
            $fields = array(
                'plugin_count' => $total,
                'chunk' => $index + 1
            );

            foreach ($chunk as $code => $version) {
                $key = self::text((string)$code);

                if ($key !== '') {
                    $fields[$key] = self::text((string)$version);
                }
            }

            $events[] = new self('environment.plugins', $fields);
        }

        return $events;
    }

    private static function castFields($schema, $values)
    {
        $fields = array();

        if (!is_array($values)) {
            return $fields;
        }

        foreach ($schema as $key => $cast) {
            if (!array_key_exists($key, $values)) {
                continue;
            }

            $value = $values[$key];

            if ($cast === 'bool') {
                $fields[$key] = (bool)$value;
                continue;
            }

            if (!is_scalar($value)) {
                continue;
            }

            $clean = $cast === 'identifier' ? self::identifier((string)$value) : self::text((string)$value);

            if ($clean !== '') {
                $fields[$key] = $clean;
            }
        }

        return $fields;
    }

    /**
     * Bound attributes a call site passed in, rather than trusting them.
     *
     * Booleans and ints are already bounded and pass through intact; strings
     * are clamped and control-stripped; anything else is not a scalar
     * diagnostic and is dropped.
     */
    private static function cleanAttrs($attrs)
    {
        $fields = array();

        if (!is_array($attrs)) {
            return $fields;
        }

        foreach ($attrs as $key => $value) {
            if (count($fields) >= self::MAX_ATTRS) {
                break;
            }

            $name = self::text((string)$key);

            if ($name === '' || !is_scalar($value)) {
                continue;
            }

            $fields[$name] = is_string($value) ? self::text($value) : $value;
        }

        return $fields;
    }

    public function name()
    {
        return $this->name;
    }

    public function fields()
    {
        return $this->fields;
    }

    /**
     * The wire shape of a single event inside a batch.
     *
     * The contract's field is `occurred_at`, an RFC3339 STRING. Sending a unix
     * int under that name fails the whole event, so name and type move together.
     *
     * @param int|null $now Injected clock; the suite cannot shadow time().
     */
    public function envelope($now = null)
    {
        $envelope = array(
            'event' => $this->name,
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', $now === null ? time() : (int)$now)
        );

        foreach ($this->correlation as $field => $value) {
            $envelope[$field] = $value;
        }

        if (!empty($this->error)) {
            $envelope['error'] = $this->error;
        }

        // PHP renders an empty array as [], which the edge reads as "not an
        // object" and records as a drop against an otherwise clean event.
        if (!empty($this->fields)) {
            $envelope['attrs'] = $this->fields;
        }

        return $envelope;
    }

    /**
     * Hard deny assertion over a whole envelope, exactly as it will be sent.
     *
     * The envelope is screened entire rather than a named subset of it: the
     * correlation ids `about()` writes are top-level siblings of `attrs` and
     * are fed from upstream API and webhook payloads, so screening only
     * `attrs` and `error` let a card number or the store's own API key travel
     * in `order_ref`, `payment_id` or `payment_intent_id`. Any field added to
     * envelope() in future is screened by construction.
     */
    public static function isEnvelopeDenied($envelope, $secrets = array())
    {
        return self::isDenied($envelope, $secrets);
    }

    /**
     * Hard deny assertion: true when this event must be dropped entirely.
     *
     * A safety net behind the named constructors, not the primary control. It
     * drops the whole event rather than the offending field, because a field
     * that trips it means the event was assembled wrongly and the rest of it
     * cannot be trusted either.
     */
    public static function isDenied($fields, $secrets = array(), $depth = 0)
    {
        if (!is_array($fields)) {
            return false;
        }

        foreach ($fields as $key => $value) {
            if (self::isDeniedKey($key)) {
                return true;
            }

            // The contract nests one level - `error`, and `error.stack` inside
            // it. Without recursion the assertion sees a non-string and gives
            // up, which is exactly where free text now lives.
            if (is_array($value)) {
                if ($depth < 2 && self::isDenied($value, $secrets, $depth + 1)) {
                    return true;
                }

                continue;
            }

            if (!is_string($value) || $value === '') {
                continue;
            }

            if (preg_match(self::$denied_value_pattern, $value)) {
                return true;
            }

            if (self::containsCardNumber($value)) {
                return true;
            }

            // Shape matching is a guess; comparing against the store's actual
            // credentials is not. This catches a secret whose format we never
            // anticipated, including one a future Paypercut release introduces.
            foreach ($secrets as $secret) {
                if (!is_string($secret) || $secret === '') {
                    continue;
                }

                if (strpos($value, $secret) !== false || self::endsMidSecret($value, $secret)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Is this a field name that must never appear, whatever its value?
     */
    private static function isDeniedKey($key)
    {
        return preg_match(self::$denied_key_pattern, (string)$key) === 1;
    }

    /**
     * Does this value end part-way through one of the store's credentials?
     *
     * text() clamps to MAX_TEXT_BYTES before the assertion ever sees a value,
     * so a credential that started near the limit survives only as a prefix at
     * the very end - which a whole-secret strpos() can never match. Only a
     * value sitting at the clamp boundary can have been cut that way, so
     * shorter values are left alone and ordinary prose is unaffected.
     */
    private static function endsMidSecret($value, $secret)
    {
        if (strlen($value) < self::MAX_TEXT_BYTES - 3) {
            return false;
        }

        $longest = min(strlen($value), strlen($secret) - 1);

        for ($length = self::MIN_SECRET_FRAGMENT; $length <= $longest; $length++) {
            if (substr($value, -$length) === substr($secret, 0, $length)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A Luhn-valid 13-19 digit run anywhere in the value.
     *
     * The edge screens for a PAN too, but only when the whole value is one:
     * "Card 4111111111111111 was declined" passes it. Card data must never
     * leave a merchant estate, so the client is the right place to enforce it.
     */
    public static function containsCardNumber($value)
    {
        if (!preg_match_all('/\d(?:[ -]?\d){12,18}/', (string)$value, $matches)) {
            return false;
        }

        foreach ($matches[0] as $candidate) {
            if (self::luhnValid(preg_replace('/\D/', '', $candidate))) {
                return true;
            }
        }

        return false;
    }

    private static function luhnValid($digits)
    {
        $length = strlen($digits);

        if ($length < 13 || $length > 19) {
            return false;
        }

        $sum = 0;
        $double = false;

        for ($i = $length - 1; $i >= 0; $i--) {
            $digit = (int)$digits[$i];

            if ($double) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = !$double;
        }

        return $sum % 10 === 0;
    }

    /**
     * Free-ish text: printable characters only, hard byte cap.
     *
     * UTF-8 is preserved rather than stripped - a Greek or Japanese theme name
     * is one of the more useful diagnostics there is, and reducing it to an
     * empty string would silently lose it. Only control characters go.
     */
    public static function text($value)
    {
        $value = (string)$value;
        $clean = (string)preg_replace('/[\x00-\x1F\x7F]/u', '', $value);

        if ($clean === '' && $value !== '') {
            // Invalid UTF-8 made the unicode-mode replace fail; fall back to ASCII.
            $clean = (string)preg_replace('/[^\x20-\x7E]/', '', $value);
        }

        // mb_strcut cuts on a byte budget while respecting codepoint
        // boundaries; mb_substr counts codepoints and would overshoot the
        // edge's byte bound.
        return function_exists('mb_strcut')
            ? mb_strcut($clean, 0, self::MAX_TEXT_BYTES)
            : substr($clean, 0, self::MAX_TEXT_BYTES);
    }

    /**
     * Identifier-shaped values only; anything else is dropped rather than mangled.
     */
    public static function identifier($value)
    {
        // \z with the D modifier, not $: PCRE lets $ match before a trailing
        // newline, which would pass an identifier carrying one.
        return preg_match('/^[A-Za-z0-9_.:-]{1,64}\z/D', (string)$value) ? (string)$value : '';
    }
}

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
     * Multi-byte characters that group a card number, folded to a plain space.
     *
     * The scan reads bytes, so a separator wider than one byte ends the digit run
     * and each group is screened alone: an en dash between the groups of a real
     * PAN was enough to ship it verbatim. Word processors, spreadsheets and
     * chat clients all substitute these for the ASCII forms on their own.
     */
    const PAN_UNICODE_SEPARATORS = array(
        "\xc2\xa0" => ' ', // U+00A0 no-break space
        "\xc2\xad" => ' ', // U+00AD soft hyphen
        "\xe2\x80\x87" => ' ', // U+2007 figure space
        "\xe2\x80\x88" => ' ', // U+2008 punctuation space
        "\xe2\x80\x89" => ' ', // U+2009 thin space
        "\xe2\x80\x8b" => ' ', // U+200B zero-width space
        "\xe2\x80\x90" => ' ', // U+2010 hyphen
        "\xe2\x80\x91" => ' ', // U+2011 non-breaking hyphen
        "\xe2\x80\x92" => ' ', // U+2012 figure dash
        "\xe2\x80\x93" => ' ', // U+2013 en dash
        "\xe2\x80\x94" => ' ', // U+2014 em dash
        "\xe2\x80\x95" => ' ', // U+2015 horizontal bar
        "\xe2\x80\xaf" => ' ', // U+202F narrow no-break space
        "\xe2\x81\xa0" => ' ', // U+2060 word joiner
        "\xe2\x88\x92" => ' ', // U+2212 minus sign
        "\xe3\x80\x80" => ' ', // U+3000 ideographic space
        "\xef\xbb\xbf" => ' ', // U+FEFF zero-width no-break space
        "\xef\xbc\x8d" => ' ', // U+FF0D fullwidth hyphen-minus
    );

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
     * Shortest slice of a credential looked for ANYWHERE in a value. Longer
     * than MIN_SECRET_FRAGMENT because that rule is confined to the clamp
     * boundary while this one runs over ordinary prose. See carriesSecret().
     */
    const MIN_SECRET_SLICE = 8;

    /**
     * How far past MAX_TEXT_BYTES text() screens before it clamps.
     *
     * Wide enough for the longest thing the screen recognises (a 19-digit PAN
     * with separators, or a credential) to be seen whole even when it starts
     * on the last byte inside the cap. See text().
     */
    const SCREEN_OVERSHOOT_BYTES = 64;

    /**
     * Longest correlation id kept, in bytes. See correlationId().
     */
    const MAX_CORRELATION_BYTES = 128;

    /**
     * Field names that must never appear in an event, whatever their value.
     *
     * `auth` and `nonce` are anchored to alphanumeric boundaries: as bare
     * substrings they also matched the stock extension codes
     * `payment.authorizenet_aim`/`_sim`, and a merchant's inventory is data,
     * not a field name this extension chose.
     *
     * A key name is credential-shaped only when it ENDS in one, matching the
     * other ports. Anchored mid-name it also denied this extension's own
     * `api_key_mode`, and a tripped assertion bins the whole event — so the
     * configuration snapshot never left an OpenCart 2 store at all.
     */
    private static $denied_key_pattern = '/secret|token|password|passphrase|credential|authorization|authenticat|(?<![a-z0-9])(?:auth|nonce)(?![a-z0-9])|api[_-]?keys?$|_keys?$/i';

    /**
     * Value shapes that must never appear in an event, whatever their field name.
     *
     * Not anchored to the start of the string, because a stack frame or an HTTP
     * error carries the credential mid-string every time. Not left unanchored
     * either: bare sk_/pk_ also matches `disk_usage` and `risk_free`, and a
     * tripped assertion bins the whole event. A full `sk_live_`-style prefix
     * is specific enough to need no boundary, which is how a credential glued
     * to a preceding word used to slip past; the key body has to be long
     * enough to be one, or `brisk_test_run` matches.
     */
    private static $denied_value_pattern = '/(?:^|[^A-Za-z0-9_])(?:ppc_|sk_|pk_|whsec_|eyJ[A-Za-z0-9_-]+\.)|(?:ppc|sk|pk)_(?:live|test|sandbox)_[A-Za-z0-9]{8,}|whsec_[A-Za-z0-9]{8,}/i';

    /**
     * Assigned issuer prefixes with the lengths each brand actually issues.
     *
     * Every candidate is gated on this BEFORE Luhn, the run taken whole
     * included: Luhn alone passes one run in ten and a sliding window
     * multiplies that, denying 24.9% of random 16-digit identifiers and 9.7%
     * of 13-digit millisecond timestamps against 8.0% and 0.0% once gated. The
     * trade is MII 0/1/7 and the unassigned parts of 8/9.
     */
    private static $card_brand_pattern = '/^(?:'
        // Visa 13/16/19; Mastercard 51-55 and 2221-2720; Mir 2200-2204
        . '4\d{12}|4\d{15}|4\d{18}'
        . '|5[1-5]\d{14}'
        . '|(?:222[1-9]|22[3-9]\d|2[3-6]\d{2}|27[01]\d|2720)\d{12}'
        . '|220[0-4]\d{12}'
        // Amex 15; Diners 14; JCB 3528-3589
        . '|3[47]\d{13}'
        . '|3(?:0[0-5]|[689]\d)\d{11}'
        . '|35(?:2[89]|[3-8]\d)\d{12,15}'
        // Discover 6011 / 622126-622925 / 644-649 / 65; UnionPay 62 and 81
        . '|6011\d{12,15}'
        . '|622(?:12[6-9]|1[3-9]\d|[2-8]\d{2}|9[01]\d|92[0-5])\d{10,13}'
        . '|64[4-9]\d{13,16}'
        . '|65\d{14,17}'
        . '|62\d{14,17}'
        . '|81\d{14,17}'
        // Maestro's published BIN list; RuPay 60/82/508
        . '|(?:5018|5020|5038|5893|6304|6759|676[1-3])\d{8,15}'
        . '|60\d{14}|82\d{14}|508\d{13}'
        // Troy, UzCard and Humo - live schemes in TR/UZ, outside MII 3-6
        . '|9792\d{12}|8600\d{12}|9860\d{12}'
        . ')\z/D';

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
        // origin/origin_plugin are merged in below and have to fit inside the
        // cap, or MAX_ATTRS is a claim rather than a bound.
        $event = new self($name, self::cleanAttrs($attrs, $exception instanceof Exception ? self::MAX_ATTRS - 2 : self::MAX_ATTRS));

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

        // api_code, api_param, trace_id and http_status are merged in below.
        $event = new self($name, self::cleanAttrs($attrs, self::MAX_ATTRS - 4));

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
            $clean = self::correlationId($value);

            if ($clean !== '') {
                $this->correlation[$field] = $clean;
            }
        }

        return $this;
    }

    /**
     * Bound a correlation id to an identifier charset rather than free text.
     *
     * These three fields are the only wire values fed straight from an upstream
     * payload, and on this platform the webhook that feeds two of them is
     * unauthenticated - text() would put 256 bytes of attacker-chosen printable
     * UTF-8 on the wire. Lossless here: every id is either a Paypercut
     * `pi_`/`cs_`/`pay_` handle or orderRef(), which returns a bare order id.
     * A value that does not fit drops the FIELD, never the event.
     */
    public static function correlationId($value)
    {
        $value = (string)$value;

        if (!preg_match('/^[A-Za-z0-9_.:-]{1,' . self::MAX_CORRELATION_BYTES . '}\z/D', $value)) {
            return '';
        }

        // A handle always carries an alphanumeric and never a '..' run; the
        // charset alone admitted '..' and '.', which land verbatim in
        // order_ref and read as path segments wherever the id is echoed.
        return preg_match('/[A-Za-z0-9]/', $value) && strpos($value, '..') === false ? $value : '';
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
                $release = self::text((string)$version);

                // Codes and versions are the only wire data this extension does
                // not author. One that would trip the deny assertion is dropped
                // on its own rather than binning the chunk around it;
                // plugin_count still reports the true total.
                if ($key === '' || self::isDeniedValue($key) || self::isDeniedValue($release)) {
                    continue;
                }

                $fields[$key] = $release;
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
    private static function cleanAttrs($attrs, $limit = null)
    {
        $fields = array();
        $limit = $limit === null ? self::MAX_ATTRS : max(0, (int)$limit);

        if (!is_array($attrs)) {
            return $fields;
        }

        foreach ($attrs as $key => $value) {
            if (count($fields) >= $limit) {
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
        // An extension code is merchant data in key position, not a field name
        // this extension chose, so the name-shape rule does not apply to it -
        // `module.nonce_helper` is the inventory a conflict gets named in.
        // Every VALUE rule still screens both halves.
        $inventory = isset($envelope['event'])
            && $envelope['event'] === 'environment.plugins'
            && isset($envelope['attrs'])
            && is_array($envelope['attrs']);

        if ($inventory) {
            $attrs = $envelope['attrs'];
            unset($envelope['attrs']);

            foreach ($attrs as $code => $release) {
                if (self::isDeniedValue((string)$code, $secrets) || self::isDeniedValue($release, $secrets)) {
                    return true;
                }
            }
        }

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
            // The key is screened by the VALUE rules as well as the name-shape
            // rule: a key is serialised onto the wire exactly as a value is, so
            // a PAN or a credential sitting in key position must not pass. Cast
            // first - PHP silently turns a digits-only key into an int, which
            // json_encode then renders straight back out as a string.
            if (self::isDeniedKey($key) || self::isDeniedValue((string)$key, $secrets)) {
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

            if (self::isDeniedValue($value, $secrets)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this single serialised string one that must never leave the store?
     *
     * Applied to keys and values alike - both are serialised into the same
     * JSON - and to a value BEFORE it is clamped, so the screen sees what the
     * caller actually handed us rather than a truncated fragment of it.
     */
    private static function isDeniedValue($value, $secrets = array())
    {
        // A float holds no PAN above 2^53 exactly, so one arriving as a float
        // fails Luhn while still carrying 15 correct digits, which Luhn
        // completes. The issuer prefix alone is the screen there.
        $imprecise = is_float($value);
        $value = self::wireText($value);

        if ($value === '') {
            return false;
        }

        if (preg_match(self::$denied_value_pattern, $value)) {
            return true;
        }

        if (self::scanDigits($value, !$imprecise)) {
            return true;
        }

        // Shape matching is a guess; comparing against the store's actual
        // credentials is not. This catches a secret whose format we never
        // anticipated, including one a future Paypercut release introduces.
        foreach ($secrets as $secret) {
            if (!is_string($secret) || $secret === '') {
                continue;
            }

            if (self::carriesSecret($value, $secret)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The exact string a scalar occupies once the envelope is serialised.
     *
     * Screening only pre-existing strings let a PAN through as a non-string
     * scalar: 4111111111111111 fits in a 64-bit int, and a float casts to
     * "4.1111111111111E+15" through `precision` while json_encode - the thing
     * that actually reaches the wire - puts all sixteen digits back.
     */
    private static function wireText($value)
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value)) {
            return (string)$value;
        }

        if (is_float($value)) {
            $json = json_encode($value);

            return is_string($json) ? $json : (string)$value;
        }

        // A bool and a null render as true/false/null and can carry nothing.
        return '';
    }

    /**
     * Does this value carry the store's credential, whole or in part?
     *
     * The slice scan is position-independent on purpose: endsMidSecret() only
     * recognises a credential the clamp cut, so a MIDDLE slice - what an
     * upstream error quoting part of the key produces - travelled untouched.
     */
    private static function carriesSecret($value, $secret)
    {
        if (strpos($value, $secret) !== false || self::endsMidSecret($value, $secret)) {
            return true;
        }

        $length = strlen($secret);

        if ($length <= self::MIN_SECRET_SLICE) {
            return false;
        }

        for ($start = 0; $start + self::MIN_SECRET_SLICE <= $length; $start++) {
            if (strpos($value, substr($secret, $start, self::MIN_SECRET_SLICE)) !== false) {
                return true;
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
     * An issuer-prefixed, Luhn-valid 13-19 digit run anywhere in the value.
     *
     * The edge screens for a PAN too, but only when the whole value is one:
     * "Card 4111111111111111 was declined" passes it. Card data must never
     * leave a merchant estate, so the client is the right place to enforce it.
     */
    public static function containsCardNumber($value)
    {
        return self::scanDigits(self::wireText($value), true);
    }

    /**
     * @param bool $require_luhn False screens on the issuer prefix alone.
     */
    private static function scanDigits($value, $require_luhn)
    {
        $value = strtr((string)$value, self::PAN_UNICODE_SEPARATORS);

        // Group separators, not just space and hyphen: '4111.1111.1111.1111',
        // '4111/1111/1111/1111', '4111_1111_1111_1111' and the comma-separated
        // forms an API error or a log line renders all read back as one PAN.
        if (!preg_match_all('/\d(?:[ \t.,\-_\/]{0,3}\d)+/', $value, $matches)) {
            return false;
        }

        foreach ($matches[0] as $candidate) {
            $digits = (string)preg_replace('/\D/', '', $candidate);
            $length = strlen($digits);

            // Every 13-19 digit window, the run taken whole included: a PAN
            // with other digits pressed against it is still a PAN, and
            // anchoring to the run let `77...774111111111111111` through.
            // Every window is gated on an assigned issuer prefix first - see
            // $card_brand_pattern for why Luhn alone is not a screen.
            for ($start = 0; $start + 13 <= $length; $start++) {
                for ($size = 13; $size <= 19 && $start + $size <= $length; $size++) {
                    $window = substr($digits, $start, $size);

                    if (!preg_match(self::$card_brand_pattern, $window)) {
                        continue;
                    }

                    if (!$require_luhn || self::luhnValid($window)) {
                        return true;
                    }
                }
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

        $clamped = self::cut($clean, self::MAX_TEXT_BYTES);

        if ($clamped === $clean) {
            return $clamped;
        }

        // Clamping runs before the deny assertion, so a cut through a PAN or a
        // credential leaves a fragment the assertion no longer recognises - 15
        // of 16 PAN digits is not redaction, Luhn completes it uniquely. Screen
        // what the caller actually passed, and when it trips hand back enough
        // of it for the assertion to trip too; a denied event is dropped whole
        // and never leaves the store.
        $screened = self::cut($clean, self::MAX_TEXT_BYTES + self::SCREEN_OVERSHOOT_BYTES);

        return self::isDeniedValue($screened) ? $screened : $clamped;
    }

    /**
     * Cut to a byte budget on a codepoint boundary.
     *
     * mb_substr counts codepoints and would overshoot the edge's byte bound.
     */
    private static function cut($value, $bytes)
    {
        return function_exists('mb_strcut')
            ? mb_strcut($value, 0, $bytes)
            : substr($value, 0, $bytes);
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

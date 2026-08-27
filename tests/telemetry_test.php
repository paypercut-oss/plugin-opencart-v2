<?php
/**
 * Telemetry unit tests.
 *
 * Plain PHP, no framework: the classes exercised here are deliberately free of
 * OpenCart dependencies at load time, so `php tests/telemetry_test.php` runs
 * anywhere PHP does.
 *
 * The privacy contract and the environment pairing are the two things that make
 * this feature safe to ship, so they carry the most assertions.
 */

require_once dirname(__DIR__) . '/upload/system/library/paypercut/telemetry/bootstrap.php';

$passed = 0;
$failed = array();

function ok($condition, $name)
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        return;
    }

    $failed[] = $name;
    echo "FAIL: " . $name . PHP_EOL;
}

function same($expected, $actual, $name)
{
    ok($expected === $actual, $name . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

// ---------------------------------------------------------------------------
// Environment: one value, both hosts.
// ---------------------------------------------------------------------------

same('https://api.paypercut.io/', PaypercutEnvironment::apiBaseUri('production'), 'production api base');
same('https://telemetry.paypercut.io/', PaypercutEnvironment::telemetryBaseUri('production'), 'production edge base');

same('https://api.stage.paypercut.net/', PaypercutEnvironment::apiBaseUri('stage'), 'stage api base');
same('https://telemetry.stage.paypercut.net/', PaypercutEnvironment::telemetryBaseUri('stage'), 'stage edge base');

same('https://api.dev.paypercut.net/', PaypercutEnvironment::apiBaseUri('dev'), 'dev api base');
same('https://telemetry.dev.paypercut.net/', PaypercutEnvironment::telemetryBaseUri('dev'), 'dev edge base');

// An unknown environment keeps checkout working but yields NO debug session:
// a token minted for one environment is rejected by every other one's edge.
same('https://api.paypercut.io/', PaypercutEnvironment::apiBaseUri(''), 'unset api base falls back to production');
same('https://api.paypercut.io/', PaypercutEnvironment::apiBaseUri('sandbox'), 'unknown api base falls back to production');
same('', PaypercutEnvironment::telemetryBaseUri(''), 'unset environment yields no edge');
same('', PaypercutEnvironment::telemetryBaseUri('sandbox'), 'unknown environment yields no edge');

foreach (array('production', 'stage', 'dev') as $environment) {
    $api = PaypercutEnvironment::apiBaseUri($environment);
    $edge = PaypercutEnvironment::telemetryBaseUri($environment);

    ok($api !== '' && $edge !== '', 'both hosts resolve for ' . $environment);
    ok(
        PaypercutEnvironment::allowedPaypercutBase($api) === $api
        && PaypercutEnvironment::allowedPaypercutBase($edge) === $edge,
        'both hosts pass the allow-list for ' . $environment
    );
}

// The destination allow-list guards a credential in transit.
foreach (array(
    'https://paypercut.io.evil.com/',
    'https://notpaypercut.io/',
    'https://paypercut.io.co/',
    'http://api.paypercut.io/',
    'ftp://api.paypercut.io/',
    'https://evil.com/?x=api.paypercut.io',
    ''
) as $rejected) {
    same('', PaypercutEnvironment::allowedPaypercutBase($rejected), 'rejects ' . var_export($rejected, true));
}

// One value, one PAIR of hosts: nothing may retarget the edge on its own, or a
// token minted on one environment meets another environment's edge and 401s.
foreach (array('production', 'stage', 'dev') as $environment) {
    same(
        str_replace('api', 'telemetry', PaypercutEnvironment::apiBaseUri($environment)),
        PaypercutEnvironment::telemetryBaseUri($environment),
        'both hosts name the same environment for ' . $environment
    );
}

define('PAYPERCUT_TELEMETRY_BASE_URI', 'https://telemetry.stage.paypercut.net/');

same(
    'https://telemetry.dev.paypercut.net/',
    PaypercutEnvironment::telemetryBaseUri('dev'),
    'no constant can retarget the edge away from the mint host'
);

// A store that predates the setting is on production - which is where its
// payments have always gone - but a value that IS set and unknown is not.
same('production', PaypercutEnvironment::stored(''), 'an unset environment is production');
same('production', PaypercutEnvironment::stored(null), 'a null environment is production');
same('dev', PaypercutEnvironment::stored('dev'), 'a known environment is itself');
same('', PaypercutEnvironment::stored('sandbox'), 'an unknown environment is not guessed at');

same('https://api.paypercut.io/', PaypercutEnvironment::allowedPaypercutBase('https://api.paypercut.io'), 'adds a trailing slash');
same(
    'https://telemetry.paypercut.io/x/',
    PaypercutEnvironment::allowedPaypercutBase('https://telemetry.paypercut.io/x?a=b'),
    'drops a query string that would swallow the endpoint path'
);
same(
    'https://telemetry.paypercut.io/',
    PaypercutEnvironment::allowedPaypercutBase('https://user:pass@telemetry.paypercut.io/#f'),
    'drops credentials and a fragment'
);
same('https://telemetry.dev.paypercut.net/', PaypercutEnvironment::allowedPaypercutBase('https://telemetry.dev.paypercut.net/'), 'accepts a paypercut.net subdomain');

// ---------------------------------------------------------------------------
// The deny assertion: a tripped rule drops the WHOLE event.
// ---------------------------------------------------------------------------

$secrets = array('sk_live_realstoresecret', 'whsec_realwebhooksecret');

// 1. Denied key names, at any nesting level.
foreach (array('api_client_secret', 'telemetry_token', 'api_key', 'nonce', 'authorization', 'webhook_secret', 'password', 'credential') as $key) {
    ok(PaypercutEvent::isDenied(array($key => 'anything')), 'denies key ' . $key);
}

ok(PaypercutEvent::isDenied(array('error' => array('stack' => array('secret' => 'x')))), 'denies a nested key two levels down');

// 2. Denied value shapes, whatever the field name.
foreach (array('ppc_abc123', 'sk_test_abc', 'pk_live_abc', 'whsec_abc', 'eyJhbGciOiJSUzI1NiJ9.payload') as $value) {
    ok(PaypercutEvent::isDenied(array('note' => $value)), 'denies value shape ' . $value);
}

// Mid-string, because a stack frame carries the credential mid-string every time.
ok(PaypercutEvent::isDenied(array('note' => 'rejected ppc_live_store_secret')), 'denies a credential mid-string');

// ...but not so loosely that ordinary prose loses the whole event.
foreach (array('disk_usage exceeded', 'backpack_pk_none missing', 'risk_free window elapsed') as $permitted) {
    ok(!PaypercutEvent::isDenied(array('note' => $permitted)), 'permits ' . $permitted);
}

// 3. Card numbers, Luhn-checked, anywhere in the value.
ok(PaypercutEvent::isDenied(array('note' => 'Card 4111111111111111 was declined')), 'denies an embedded PAN');
ok(PaypercutEvent::isDenied(array('note' => 'card 4111 1111 1111 1111 declined')), 'denies a spaced PAN');
ok(!PaypercutEvent::isDenied(array('note' => 'transaction 1234567890123456 not found')), 'permits a non-Luhn 16-digit id');
ok(!PaypercutEvent::isDenied(array('note' => 'expired at 1787250271000')), 'permits a millisecond timestamp');
ok(!PaypercutEvent::isDenied(array('note' => 'amount 4250 refused')), 'permits a small amount');

// A PAN pressed against other digits is still a PAN: the scan slides a window
// across the whole run rather than anchoring to its start.
$brands = array(
    'Visa' => '4111111111111111',
    'Visa 13' => '4222222222222',
    'Visa 19' => '4917610000000000003',
    'Mastercard' => '5555555555554444',
    'Mastercard 2-series' => '2221000000000009',
    'Amex' => '378282246310005',
    'Discover' => '6011111111111117',
    'JCB' => '3530111333300000',
    'Diners' => '30569309025904',
    'Maestro' => '5018000000000009'
);

foreach ($brands as $brand => $pan) {
    ok(PaypercutEvent::containsCardNumber($pan), 'finds a bare ' . $brand . ' PAN');
    ok(PaypercutEvent::containsCardNumber(str_repeat('7', 40) . $pan), 'finds a ' . $brand . ' PAN behind 40 leading digits');
    ok(PaypercutEvent::containsCardNumber($pan . str_repeat('7', 40)), 'finds a ' . $brand . ' PAN in front of 40 trailing digits');
    ok(PaypercutEvent::isDenied(array('note' => 'ref 99887766554433221100' . $pan . ' failed')), 'denies a ' . $brand . ' PAN inside a longer run');
}

// The sliding window must not turn every long number into a card: Luhn alone
// passes one run in ten, so a window also has to carry an issuer's prefix.
foreach (array('1234567890123456', '1787250271000', '1787250271000000000', '99887766554433221100', '2418', '10000000000000000') as $permitted) {
    ok(!PaypercutEvent::containsCardNumber($permitted), 'permits the digit run ' . $permitted);
}

// 3a. Screening only pre-existing strings let a PAN through as a non-string
// scalar: 4111111111111111 fits in a 64-bit int, and (string) renders a float
// through `precision` while json_encode puts all sixteen digits back.
foreach (array('4111111111111111', '5555555555554444', '378282246310005') as $pan) {
    foreach (array('int' => (int)$pan, 'float' => (float)$pan) as $type => $scalar) {
        ok(
            PaypercutEvent::isEnvelopeDenied(PaypercutEvent::of('checkout.started', array('detail' => $scalar))->envelope(0), $secrets),
            'denies a PAN passed as an ' . $type
        );
    }
}

ok(!PaypercutEvent::isDenied(array('amount' => 12999, 'total' => 129.99, 'ok' => true, 'ts' => 1787250271)), 'ordinary scalars still ship');

// 3c. Group separators: a PAN is a PAN however the renderer joined its groups.
foreach (array('.', '/', '_', ',', ', ', ' ', '-', ' - ') as $separator) {
    $spaced = implode($separator, str_split('4111111111111111', 4));
    ok(PaypercutEvent::isDenied(array('note' => 'card ' . $spaced . ' declined')), 'denies a PAN separated by ' . json_encode($separator));
}

// 3d. Issuer gating covers the schemes outside MII 3-6 that actually issue.
foreach (array('Troy' => '9792444444444445', 'UzCard' => '8600444444444449', 'Humo' => '9860444444444442', 'RuPay 81' => '8171444444444448') as $brand => $pan) {
    ok(PaypercutEvent::containsCardNumber(str_repeat('7', 40) . $pan), 'finds a padded ' . $brand . ' PAN');
}

// ...and a millisecond timestamp is not a card, which Luhn alone denied ~10%
// of the time.
foreach (array('1787250271000', '1787250271483', '1600000000123', '1899999999999') as $milliseconds) {
    ok(!PaypercutEvent::containsCardNumber($milliseconds), 'permits the millisecond timestamp ' . $milliseconds);
}

// 3b. Keys are serialised exactly as values are, so the value rules screen
// them too - the name-shape regex alone let a PAN or a credential travel in
// key position. Digits-only keys arrive as ints; the screen must still see them.
foreach (array('4111111111111111', 'Card 4111111111111111 was declined', 'sk_live_realstoresecret', 'eyJhbGciOiJSUzI1NiJ9.payload') as $poison) {
    ok(PaypercutEvent::isDenied(array($poison => 'x')), 'denies poison in KEY position: ' . substr($poison, 0, 24));
    ok(PaypercutEvent::isDenied(array('clean' => 'ok', $poison => 'x')), 'denies poison in KEY position beside a clean pair');
    ok(PaypercutEvent::isDenied(array('error' => array($poison => 'x'))), 'denies poison in a nested KEY position');
    ok(
        PaypercutEvent::isEnvelopeDenied(PaypercutEvent::of('checkout.started', array($poison => 'x'))->envelope(0), $secrets),
        'the whole envelope is denied for poison in KEY position'
    );
}

ok(PaypercutEvent::isDenied(array('sk_live_realstoresecret' => 'x'), $secrets), 'denies a literal stored secret in KEY position');

// 4. Literal comparison against the store's actual credentials.
ok(PaypercutEvent::isDenied(array('note' => 'used sk_live_realstoresecret here'), $secrets), 'denies a literal stored secret');
ok(!PaypercutEvent::isDenied(array('note' => 'nothing to see'), $secrets), 'permits a clean value');

// An empty secret would match every string.
ok(!PaypercutEvent::isDenied(array('note' => 'clean'), array('', null)), 'an empty secret does not match everything');

// The comparison is position-independent: an upstream error quoting the MIDDLE
// of the api key carries no prefix for a head-anchored rule to catch.
foreach (array(0, 4, 8, 12) as $offset) {
    ok(
        PaypercutEvent::isDenied(array('note' => 'upstream rejected xx' . substr('sk_live_realstoresecret', $offset, 10) . 'yy'), $secrets),
        'denies a slice of a stored secret at offset ' . $offset
    );
}

ok(PaypercutEvent::isDenied(array('note' => 'xxxsk_live_realstoresecret'), $secrets), 'denies a stored secret glued to a preceding word');

// 5. Recursion, exactly two levels deep - the contract nests `error.stack`.
ok(
    PaypercutEvent::isDenied(array('error' => array('stack' => array('rejected ppc_live_abc')))),
    'denies a credential inside error.stack'
);

// ---------------------------------------------------------------------------
// The assertion screens the WHOLE envelope, exactly as it will be sent.
//
// Driven by envelope() itself: a field added to the wire shape that escapes
// the screen fails here rather than shipping. The correlation ids are the case
// that matters - they are top-level siblings of `attrs`, and on this platform
// they are fed from upstream API and (unsigned) webhook payloads.
// ---------------------------------------------------------------------------

$store_secrets = array('ppc_live_realstoresecret', 'whsec_realwebhooksecret');

$maximal = PaypercutEvent::failure(
    'checkout.return.unverifiable',
    'lookup_failed',
    array('api_context' => 'verify'),
    new Exception('unusable')
)
    ->because('threw RuntimeException')
    ->about(array('payment_intent_id' => 'pi_1', 'payment_id' => 'pay_1', 'order_ref' => '2418'))
    ->envelope(1787250271);

ok(!PaypercutEvent::isEnvelopeDenied($maximal, $store_secrets), 'a clean envelope passes the screen');
ok(count($maximal) >= 7, 'the maximal envelope carries every wire field');

$poisons = array(
    'a Luhn-valid PAN' => '4111111111111111',
    'a store API key' => 'ppc_live_realstoresecret',
    'a key-shaped string' => 'sk_live_SUPERSECRET_ABC123',
    'a token' => 'eyJhbGciOiJSUzI1NiJ9.payload'
);

foreach ($maximal as $field => $value) {
    foreach ($poisons as $label => $poison) {
        $envelope = $maximal;

        $envelope[$field] = is_array($value)
            ? array_merge($value, array('planted' => $poison))
            : $poison;

        ok(
            PaypercutEvent::isEnvelopeDenied($envelope, $store_secrets),
            'denies ' . $label . ' in ' . $field
        );

        // The same poison in KEY position. An array field takes it as its own
        // key; a scalar field cannot hold one, so the envelope's key is used.
        $envelope = $maximal;

        if (is_array($value)) {
            // Assigned, not array_merge()d: merge REINDEXES the integer key a
            // digits-only poison becomes, which would quietly unplant it.
            $planted = $value;
            $planted[$poison] = 'planted';
            $envelope[$field] = $planted;
        } else {
            $envelope[$poison] = $value;
        }

        ok(
            PaypercutEvent::isEnvelopeDenied($envelope, $store_secrets),
            'denies ' . $label . ' in KEY position at ' . $field
        );
    }
}

// The exact envelope an unauthenticated webhook could steer into about().
$correlated = PaypercutEvent::of('webhook.received')
    ->about(array('payment_intent_id' => 'sk_live_SUPERSECRET_ABC123', 'order_ref' => '4111111111111111'))
    ->envelope(0);

ok(PaypercutEvent::isEnvelopeDenied($correlated), 'denies a credential and a PAN in the correlation ids');

// Clamping runs before the assertion, so a credential starting near the byte
// cap reaches the wire as a prefix that a whole-secret comparison cannot see.
$boundary_secret = 'ppc_live_' . str_repeat('z', 40);
$clamped = PaypercutEvent::of('checkout.session_create_failed', array(
    'note' => str_repeat('a', 250) . $boundary_secret
))->envelope(0);

same(256, strlen($clamped['attrs']['note']), 'the value was clamped part-way through the secret');
ok(
    PaypercutEvent::isEnvelopeDenied($clamped, array($boundary_secret)),
    'denies a value clamped part-way through a stored secret'
);
ok(
    !PaypercutEvent::isEnvelopeDenied(
        PaypercutEvent::of('x', array('note' => str_repeat('a', 300)))->envelope(0),
        array($boundary_secret)
    ),
    'a clamped value with no secret in it still passes'
);

same(
    256,
    strlen(PaypercutEvent::of('x', array('note' => str_repeat('a', 300)))->envelope(0)['attrs']['note']),
    'a clean over-long value is still clamped to the byte cap'
);

// A PAN that starts inside the clamp and ends past it. Fifteen of sixteen
// digits is not redaction - Luhn completes the sixteenth uniquely - so the
// screen has to see the value BEFORE it is cut, not after.
for ($offset = 230; $offset <= 262; $offset++) {
    $envelope = PaypercutEvent::of('checkout.session_create_failed', array(
        'note' => str_repeat('x', $offset) . '4111111111111111'
    ))->envelope(0);

    ok(
        PaypercutEvent::isEnvelopeDenied($envelope),
        'denies a PAN starting at byte ' . $offset . ', across the clamp boundary'
    );
}

// ---------------------------------------------------------------------------
// Correlation ids are bounded to an identifier charset, not to free text.
//
// They are the only wire values fed straight from an upstream payload, and the
// webhook feeding two of them is unauthenticated. Lossless on this platform:
// orderRef() returns the bare order id.
// ---------------------------------------------------------------------------

foreach (array('2418', '1', '104857', 'pi_3PabcDEF1234567890', 'cs_live_a1B2c3D4e5F6g7H8', 'ch_3PabcDEF.1') as $id) {
    $envelope = PaypercutEvent::of('webhook.order_updated')->about(array(
        'payment_intent_id' => $id,
        'payment_id' => $id,
        'order_ref' => $id
    ))->envelope(0);

    same($id, $envelope['order_ref'], 'a real correlation id survives intact: ' . $id);
    same($id, $envelope['payment_id'], 'a real payment_id survives intact: ' . $id);
    same($id, $envelope['payment_intent_id'], 'a real payment_intent_id survives intact: ' . $id);
}

foreach (array('<script>x</script>', 'https://evil.example/a?b=c', "0'; DROP--", 'two words', "\xE2\x80\xAEtxet", str_repeat('A', 200), '../../etc/passwd', '..', '.', '....') as $hostile) {
    $envelope = PaypercutEvent::of('webhook.received')->about(array(
        'payment_intent_id' => $hostile,
        'payment_id' => $hostile,
        'order_ref' => $hostile
    ))->envelope(0);

    ok(!isset($envelope['order_ref']), 'drops a free-text order_ref: ' . substr($hostile, 0, 20));
    ok(!isset($envelope['payment_id']), 'drops a free-text payment_id: ' . substr($hostile, 0, 20));
    ok(!isset($envelope['payment_intent_id']), 'drops a free-text payment_intent_id: ' . substr($hostile, 0, 20));

    // Dropping the field, never the event: a clean event still delivers.
    ok(!PaypercutEvent::isEnvelopeDenied($envelope), 'the event around a dropped correlation id still passes');
}

// ---------------------------------------------------------------------------
// Named constructors are the boundary: snapshots walk their OWN schema.
// ---------------------------------------------------------------------------

$snapshot = PaypercutEvent::environmentSnapshot(array(
    'plugin_version' => '1.0.5',
    'paypercut_api_key' => 'sk_live_realstoresecret',
    'paypercut_webhook_secret' => 'whsec_realwebhooksecret',
    'anything_else' => 'leaked?'
));

same(array('plugin_version' => '1.0.5'), $snapshot->fields(), 'environment.snapshot reads only its own schema');

$configuration = PaypercutEvent::environmentConfiguration(array(
    'checkout_mode' => 'hosted',
    'webhook_configured' => true,
    'paypercut_webhook_secret' => 'whsec_realwebhooksecret'
));

same(
    array('checkout_mode' => 'hosted', 'webhook_configured' => true),
    $configuration->fields(),
    'environment.configuration reads only its own schema'
);

// session.started deliberately omits the store user who started it.
$started = PaypercutEvent::sessionStarted('dbg_abc123', 'production', 1787250271)->fields();
same(array('session_id', 'environment', 'expires_at'), array_keys($started), 'session.started carries exactly three fields');

// The inventory's keys are stock extension CODES, not field names this
// extension chose - and `payment.authorizenet_aim` matches the denied-key
// pattern, which used to bin the one event a conflict gets named in.
$inventory = array(
    'payment.authorizenet_aim' => '1.0',
    'payment.authorizenet_sim' => '1.0',
    'payment.paypercut' => '1.0.5',
    'shipping.flat' => '1.0',
    'total.shipping' => '1.0'
);

$chunks = PaypercutEvent::environmentPlugins($inventory);
same(1, count($chunks), 'a small inventory is one chunk');

$chunk = $chunks[0]->envelope(0);
ok(!PaypercutEvent::isEnvelopeDenied($chunk), 'the inventory chunk survives the deny assertion');
same(count($inventory) + 2, count($chunk['attrs']), 'no extension is dropped from the chunk');

$rendered = json_encode($chunk['attrs']);

foreach (array_keys($inventory) as $code) {
    ok(strpos($rendered, $code) !== false, 'the inventory still names ' . $code);
}

// An extension code is merchant data in key position, not a field name this
// extension chose. A realistic OpenCart inventory carries codes that read like
// credential fields; every one keeps its OWN key and its version.
$slugs = array(
    'payment.authorizenet_aim' => '1.0',
    'payment.authorizenet_sim' => '1.0',
    'module.nonce_helper' => '2.1',
    'module.token_ring' => '1.4',
    'module.secret_santa' => '3.0',
    'module.oauth_bridge' => '1.1',
    'module.api_keychain' => '1.0',
    'module.password_helper' => '1.0',
    'payment.cod' => '1.0',
    'shipping.flat' => '1.0'
);

$slug_chunk = PaypercutEvent::environmentPlugins($slugs);
$slug_envelope = $slug_chunk[0]->envelope(0);

ok(!PaypercutEvent::isEnvelopeDenied($slug_envelope, $secrets), 'a credential-shaped inventory survives the deny assertion');

foreach ($slugs as $code => $version) {
    ok(
        isset($slug_envelope['attrs'][$code]) && $slug_envelope['attrs'][$code] === $version,
        'the inventory keeps ' . $code . ' under its own key'
    );
}

// ...but a code that is a PAN or a credential is still dropped on its own.
$poisoned = PaypercutEvent::environmentPlugins(array(
    'payment.cod' => '1.0',
    '4111111111111111' => '1.0',
    'sk_live_realstoresecret' => '1.0'
));
$poisoned_envelope = $poisoned[0]->envelope(0);

ok(!PaypercutEvent::isEnvelopeDenied($poisoned_envelope, $secrets), 'a poisoned code does not bin the chunk');
ok(isset($poisoned_envelope['attrs']['payment.cod']), 'the clean code beside it still ships');
same(
    false,
    strpos(json_encode($poisoned_envelope['attrs']), '4111111111111111') !== false,
    'a PAN in code position never reaches the wire'
);

$many = array();

for ($i = 0; $i < 70; $i++) {
    $many['payment.ext_' . $i] = '1.0';
}

$listed = 0;

foreach (PaypercutEvent::environmentPlugins($many) as $event) {
    $listed += count($event->fields()) - 2;
}

same(70, $listed, 'every extension appears exactly once across the chunks');

// MAX_ATTRS is a bound, not a claim: the fields merged in after cleanAttrs()
// have to fit inside it too.
$crowded = array();

for ($i = 0; $i < 40; $i++) {
    $crowded['field_' . $i] = $i;
}

ok(
    count(PaypercutEvent::failure('x', 'code', $crowded, new Exception('boom'))->fields()) <= PaypercutEvent::MAX_ATTRS,
    'failure() with an exception stays inside MAX_ATTRS'
);
ok(
    count(PaypercutEvent::apiFailure(
        'x',
        401,
        array('error' => array('code' => 'c', 'param' => 'p'), 'trace_id' => 't'),
        $crowded
    )->fields()) <= PaypercutEvent::MAX_ATTRS,
    'apiFailure() stays inside MAX_ATTRS'
);

// ---------------------------------------------------------------------------
// Our text yes, upstream text no.
// ---------------------------------------------------------------------------

$api = PaypercutEvent::apiFailure(
    'api.request_failed',
    401,
    array(
        'error' => array(
            'type' => 'invalid_request_error',
            'code' => 'token_invalid',
            'param' => 'authorization',
            'message' => "The provided access token 'sk_test_abc_probe' is invalid."
        ),
        'trace_id' => 'da74bcf0'
    ),
    array('api_context' => 'checkout_create')
);

$envelope = $api->envelope(1787250271);

ok(!isset($envelope['error']['message']), 'api_failure drops the upstream message');
same('http_401', $envelope['error']['code'], 'api_failure names the status');
same('invalid_request_error', $envelope['error']['type'], 'api_failure keeps the error type');
same('token_invalid', $envelope['attrs']['api_code'], 'api_failure keeps api_code');
same('da74bcf0', $envelope['attrs']['trace_id'], 'api_failure keeps trace_id');
same(401, $envelope['attrs']['http_status'], 'api_failure keeps http_status');

// A message this extension authored is the diagnosis and stays.
$authored = PaypercutEvent::failure('webhook.registration_failed', 'rejected')->because('threw RuntimeException');
same('threw RuntimeException', $authored->envelope(0)['error']['message'], 'because() keeps an authored message');

// An exception's own message never travels: OpenCart's database layer puts the
// failing statement and the connection account into it.
$thrown = PaypercutEvent::failure(
    'checkout.return.unverifiable',
    'lookup_failed',
    array(),
    new Exception("Error: Access denied for user 'store'@'db.internal'<br />SELECT * FROM oc_order")
)->envelope(0);

ok(!isset($thrown['error']['message']), 'failure() never sends the exception message');
same('Exception', $thrown['error']['type'], 'failure() keeps the exception type');
ok(isset($thrown['error']['stack']), 'failure() keeps a file:line stack');

// The fatal handler has no exception to name, so its message is scrubbed.
$fatal = PaypercutEvent::fatal(
    "Error: Access denied for user 'store'@'db.internal'<br />Error No: 1045<br />SELECT * FROM oc_customer\nStack trace:\n#0 {main}",
    '/var/www/store/system/library/db/mysqli.php',
    40,
    E_USER_ERROR
)->envelope(0);

ok(strpos($fatal['error']['message'], 'SELECT') === false, 'php.fatal drops the statement');
ok(strpos($fatal['error']['message'], 'db.internal') === false, 'php.fatal drops the database host');
ok(strpos($fatal['error']['message'], 'store') === false, 'php.fatal drops the database account');
ok(strpos($fatal['error']['message'], 'Access denied') !== false, 'php.fatal keeps the diagnosis');

// ---------------------------------------------------------------------------
// String bounding.
// ---------------------------------------------------------------------------

same(256, strlen(PaypercutEvent::text(str_repeat('a', 400))), 'text() clamps to 256 BYTES');
same('Thema Ellada', PaypercutEvent::text("The\x00ma Ell\x1Fada"), 'text() strips control characters');

$greek = PaypercutEvent::text('Θέμα Ελλάδα');
same('Θέμα Ελλάδα', $greek, 'text() preserves UTF-8');
ok(strlen(PaypercutEvent::text(str_repeat('日', 200))) <= 256, 'text() cuts CJK on a byte budget');

same('checkout.hosted', PaypercutEvent::identifier('checkout.hosted'), 'identifier() keeps an identifier');
same('', PaypercutEvent::identifier('jane@example.com'), 'identifier() drops an email');
same('', PaypercutEvent::identifier('12 Sunset Road'), 'identifier() drops an address');
same('', PaypercutEvent::identifier(str_repeat('a', 65)), 'identifier() drops an over-long value');

// ---------------------------------------------------------------------------
// Call-site attributes are bounded, not trusted.
// ---------------------------------------------------------------------------

$attrs = PaypercutEvent::of('checkout.blocks.fell_back', array(
    'duplicate' => false,
    'http_status' => 503,
    'nested' => array('not' => 'scalar')
))->fields();

same(false, $attrs['duplicate'], 'booleans pass through intact');
same(503, $attrs['http_status'], 'ints pass through intact');
ok(!isset($attrs['nested']), 'arrays are dropped');

$wide = array();
for ($i = 0; $i < 40; $i++) {
    $wide['field_' . $i] = $i;
}
same(PaypercutEvent::MAX_ATTRS, count(PaypercutEvent::of('x', $wide)->fields()), 'attributes are capped at MAX_ATTRS');

// ---------------------------------------------------------------------------
// Wire envelope.
// ---------------------------------------------------------------------------

$plain = PaypercutEvent::of('webhook.received')->envelope(1787250271);
same('2026-08-20T18:24:31Z', $plain['occurred_at'], 'occurred_at is an RFC3339 string in UTC');
ok(!isset($plain['attrs']), 'an empty attrs key is omitted');
ok(!isset($plain['error']), 'an empty error key is omitted');

$correlated = PaypercutEvent::of('payment.succeeded')
    ->about(array('payment_id' => 'pay_1', 'order_ref' => '178', 'payment_intent_id' => ''))
    ->envelope(0);

same('pay_1', $correlated['payment_id'], 'correlation ids sit outside attrs');
same('178', $correlated['order_ref'], 'order_ref sits outside attrs');
ok(!isset($correlated['payment_intent_id']), 'an empty correlation id is omitted');

// ---------------------------------------------------------------------------
// Queue caps and batch splitting.
// ---------------------------------------------------------------------------

$envelopes = array();
for ($i = 0; $i < 250; $i++) {
    $envelopes[] = array('event' => 'e' . $i, 'occurred_at' => '2026-08-19T12:24:31Z');
}

$capped = PaypercutEventQueue::cap($envelopes);
same(PaypercutTelemetrySession::MAX_QUEUE_EVENTS, count($capped['envelopes']), 'cap() enforces the event ceiling');
same(50, $capped['dropped'], 'cap() counts what it dropped');
same('e50', $capped['envelopes'][0]['event'], 'cap() drops the OLDEST first');

$split = PaypercutEventQueue::splitBatch($envelopes, 16384, 50);
same(50, count($split['batch']), 'splitBatch() honours the event cap');
same(200, count($split['remainder']), 'splitBatch() leaves the remainder');
same($envelopes, array_merge($split['batch'], $split['remainder']), 'splitBatch() neither drops nor reorders');

// A single oversized envelope must not wedge the queue forever.
$oversized = array(array('event' => 'huge', 'attrs' => array('blob' => str_repeat('x', 40000))));
same(1, count(PaypercutEventQueue::splitBatch($oversized, 16384, 50)['batch']), 'splitBatch() always takes at least one');

// ---------------------------------------------------------------------------
// The flusher's decision table.
// ---------------------------------------------------------------------------

$table = array(
    // status, retry_after, failures, outcome, end_session, retry_in, clears_batch
    array(202, 0, 0, 'accepted', false, 0, true),
    array(401, 0, 0, 'token_rejected', true, 0, true),
    array(413, 0, 0, 'split', false, 0, false),
    array(429, 0, 0, 'throttled', false, 60, false),
    array(429, 120, 0, 'throttled', false, 120, false),
    array(429, 99999, 0, 'throttled', false, 900, false),
    array(503, 0, 0, 'unready', false, 120, false),
    array(504, 0, 3, 'unready', false, 120, false),
    array(400, 0, 0, 'poison', false, 30, true),
    array(400, 0, 3, 'poison', true, 300, true),
    array(500, 0, 0, 'failed', false, 30, false),
    array(500, 0, 1, 'failed', false, 120, false),
    array(500, 0, 2, 'failed', false, 300, false),
    array(500, 0, 3, 'failed', true, 300, false),
    array(0, 0, 0, 'failed', false, 30, false),
    array(0, 0, 3, 'failed', true, 300, false)
);

foreach ($table as $row) {
    list($status, $retry_after, $failures, $outcome, $end_session, $retry_in, $clears_batch) = $row;

    $decision = PaypercutFlusher::decide($status, $retry_after, $failures);
    $label = 'decide(' . $status . ', ' . $retry_after . ', ' . $failures . ')';

    same($outcome, $decision['outcome'], $label . ' outcome');
    same($end_session, $decision['end_session'], $label . ' end_session');
    same($retry_in, $decision['retry_in'], $label . ' retry_in');
    same($clears_batch, $decision['clears_batch'], $label . ' clears_batch');
}

// A 413 never advances the give-up ladder, however often it happens.
for ($failures = 0; $failures < 10; $failures++) {
    ok(!PaypercutFlusher::decide(413, 0, $failures)['end_session'], 'a 413 never ends the session');
    ok(!PaypercutFlusher::decide(503, 0, $failures)['end_session'], 'a 503 never ends the session');
}

// ---------------------------------------------------------------------------

echo PHP_EOL . $passed . ' passed, ' . count($failed) . ' failed' . PHP_EOL;

exit(empty($failed) ? 0 : 1);

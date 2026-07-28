<?php

require_once __DIR__ . '/../whmcs/modules/gateways/tunapayment/tunapaymenthelper.php';

define('WHMCS', true);
require_once __DIR__ . '/../whmcs/modules/gateways/tunapix.php';

$failures = [];

function assertSameValue($expected, $actual, $message)
{
    global $failures;
    if ($expected !== $actual) {
        $failures[] = $message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')';
    }
}

function assertTrueValue($condition, $message)
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

$sanitized = tunapayment_sanitize_log_data([
    'card' => [
        'cardNumber' => '4111111111111111',
        'cVV' => '123',
        'token' => 'secret-token',
    ],
    'customer' => [
        'email' => 'customer@example.com',
        'productDescription' => 'Invoice 123',
    ],
]);
assertSameValue('***', $sanitized['card']['cardNumber'], 'Card number must be redacted');
assertSameValue('***', $sanitized['card']['cVV'], 'CVV must be redacted');
assertSameValue('***', $sanitized['card']['token'], 'Token must be redacted');
assertSameValue(
    '***',
    $sanitized['customer']['email'],
    'Customer email must be redacted'
);
assertSameValue(
    'Invoice 123',
    $sanitized['customer']['productDescription'],
    'Non-secret request data must remain available for debugging'
);

assertSameValue('success', getWhmcsPaymentStatus(2), 'Captured payments must succeed');
assertSameValue('pending', getWhmcsPaymentStatus('P'), 'Pending payments must stay pending');
assertSameValue('declined', getWhmcsPaymentStatus(4), 'Denied payments must be declined');
assertSameValue('error', getWhmcsPaymentStatus('A'), 'Processing errors must be errors');
assertSameValue(
    'error',
    getWhmcsPaymentStatus('C'),
    'Payment-method-only states must not be treated as overall payment states'
);
assertTrueValue(
    strpos(getStatusMessage(4), 'Denied:') === 0,
    'Tuna status messages must include their real status'
);
assertTrueValue(
    strpos(getCodeMessage(-110), 'CVV not valid.') === 0,
    'Tuna error codes must include their real message'
);
assertSameValue(
    'Current Tuna error',
    getTunaResponseMessage((object) [
        'code' => -999,
        'message' => (object) ['message' => 'Current Tuna error'],
    ]),
    'Live Tuna response messages must take precedence over the legacy code file'
);
assertSameValue(
    'Pending Jones',
    getFullName('Pending Jones', ''),
    'Production customer names must never be rewritten as sandbox commands'
);
assertSameValue(
    'Pending',
    getFullName('Pending Test', 'on'),
    'Sandbox customer names must retain Tuna test triggers'
);

$productionConfig = tunapayment_api_config(
    'payment',
    'Cancel',
    '',
    'merchant',
    'app-token'
);
assertSameValue(
    'https://engine.tunagateway.com/api/Payment/Cancel',
    $productionConfig['url'],
    'Production cancellation endpoint must be correct'
);

$sandboxConfig = tunapayment_api_config(
    'payment',
    'Cancel',
    'on',
    'merchant',
    'app-token'
);
assertSameValue(
    'https://sandbox.tuna-demo.uy/api/Payment/Cancel',
    $sandboxConfig['url'],
    'WHMCS checkbox value "on" must enable the sandbox'
);

$firstIdempotencyKey = tunapayment_idempotency_key('pix', 'init', '123', 20);
assertSameValue(
    $firstIdempotencyKey,
    tunapayment_idempotency_key('pix', 'init', '123', 20.00),
    'The same invoice operation must reuse its Tuna idempotency key'
);
assertTrueValue(
    $firstIdempotencyKey !== tunapayment_idempotency_key('pix', 'init', '123', 21),
    'Different payment amounts must use different Tuna idempotency keys'
);

$pixData = (object) [
    'methods' => [
        (object) [
            'pixInfo' => (object) [
                'qrImage' => 'javascript:alert(1)',
                'qrCopyPaste' => '<script>alert(1)</script>',
            ],
        ],
    ],
];
$pixHtml = tunapix_payment_details($pixData);
assertTrueValue(
    strpos($pixHtml, '<script>') === false,
    'Pix copy-and-paste content must be HTML escaped'
);
assertTrueValue(
    strpos($pixHtml, 'javascript:') === false,
    'Pix QR images must only use valid HTTPS URLs'
);

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "All tests passed." . PHP_EOL;

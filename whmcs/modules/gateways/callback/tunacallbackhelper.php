<?php

require_once __DIR__ . '/../tunapayment/tunapaymenthelper.php';

/**
 * Read either a JSON webhook body or a form-encoded callback.
 *
 * @return array
 */
function tunapayment_callback_payload()
{
    // Tuna's notification guide shows a JSON body containing paymentKey,
    // partnerUniqueId, statusId, amount and operationId. Form data remains
    // supported for backwards compatibility with earlier integrations.
    // https://dev.tuna.uy/api/webhooks-notifications/
    $contentType = isset($_SERVER['CONTENT_TYPE']) ? strtolower($_SERVER['CONTENT_TYPE']) : '';
    if (strpos($contentType, 'application/json') !== false) {
        $rawBody = file_get_contents('php://input');
        $payload = json_decode($rawBody, true);
        return is_array($payload) ? $payload : [];
    }

    return is_array($_POST) ? $_POST : [];
}

/**
 * End a callback request with an explicit HTTP response.
 *
 * @param int $statusCode
 * @param string $message
 * @return void
 */
function tunapayment_callback_response($statusCode, $message)
{
    http_response_code($statusCode);
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

/**
 * Verify and process a Tuna webhook.
 *
 * Tuna's webhook guide documents the notification body but no signature
 * header. Therefore authenticity is checked server-to-server through the
 * documented Payment/Status endpoint before WHMCS credits an invoice.
 * The Tuna paymentKey is also used as WHMCS transaction ID, preventing the
 * same payment from being applied more than once with a forged operationId.
 *
 * @see https://dev.tuna.uy/api/webhooks-notifications/
 * @see https://dev.tuna.uy/api/payment/#tag/default/operation/Status
 *
 * @param string $gatewayModuleName
 * @return void
 */
function tunapayment_handle_callback($gatewayModuleName)
{
    $gatewayParams = getGatewayVariables($gatewayModuleName);
    if (empty($gatewayParams['type'])) {
        tunapayment_callback_response(503, 'Module Not Activated');
    }

    $payload = tunapayment_callback_payload();
    $requiredFields = ['statusId', 'partnerUniqueId', 'paymentKey', 'amount'];
    foreach ($requiredFields as $field) {
        if (!isset($payload[$field]) || $payload[$field] === '') {
            tunapayment_callback_response(400, 'Invalid callback payload');
        }
    }

    $invoiceId = checkCbInvoiceID($payload['partnerUniqueId'], $gatewayParams['name']);
    $status = (string) $payload['statusId'];
    $paymentKey = (string) $payload['paymentKey'];

    // The official payment-status table defines "2" as Captured/funds secured.
    // Other notifications are logged but must not credit the WHMCS invoice.
    if ($status !== '2') {
        logTransaction(
            $gatewayParams['name'],
            tunapayment_sanitize_log_data($payload),
            getStatusMessage($status)
        );
        tunapayment_callback_response(200, 'Callback recorded');
    }

    $paymentAmount = filter_var($payload['amount'], FILTER_VALIDATE_FLOAT);
    if ($paymentAmount === false || $paymentAmount <= 0) {
        tunapayment_callback_response(400, 'Invalid payment amount');
    }

    checkCbTransID($paymentKey);

    $apiConfig = tunapayment_api_config(
        'payment',
        'Status',
        isset($gatewayParams['testMode']) ? $gatewayParams['testMode'] : '',
        $gatewayParams['tunaAccount'],
        $gatewayParams['tunaApptoken']
    );
    // Payment/Status is intentionally called without Idempotency-Key because
    // this is a changing resource: a Pix payment can move from P to 2.
    $verification = tunapayment_api_request(
        $apiConfig['url'],
        $apiConfig['account'],
        $apiConfig['appToken'],
        ['paymentKey' => $paymentKey],
        'Tuna Payment Callback',
        'verify_payment'
    );
    $verifiedPayment = $verification['data'];

    if (
        !$verification['success']
        || !isset($verifiedPayment->code, $verifiedPayment->status)
        || (int) $verifiedPayment->code !== 1
        || (string) $verifiedPayment->status !== '2'
    ) {
        logTransaction(
            $gatewayParams['name'],
            tunapayment_sanitize_log_data($payload),
            'Payment verification failed'
        );
        tunapayment_callback_response(400, 'Payment verification failed');
    }

    if (
        isset($verifiedPayment->partnerUniqueId)
        && (string) $verifiedPayment->partnerUniqueId !== (string) $invoiceId
    ) {
        tunapayment_callback_response(400, 'Invoice verification failed');
    }

    if (!function_exists('localAPI')) {
        tunapayment_callback_response(500, 'Invoice verification unavailable');
    }

    $invoice = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
    if (
        !isset($invoice['result'], $invoice['balance'])
        || $invoice['result'] !== 'success'
        || abs((float) $invoice['balance'] - (float) $paymentAmount) > 0.01
    ) {
        logTransaction(
            $gatewayParams['name'],
            tunapayment_sanitize_log_data($payload),
            'Payment amount does not match invoice balance'
        );
        tunapayment_callback_response(400, 'Payment amount verification failed');
    }

    logTransaction(
        $gatewayParams['name'],
        tunapayment_sanitize_log_data($payload),
        'Success'
    );

    addInvoicePayment(
        $invoiceId,
        $paymentKey,
        $paymentAmount,
        0,
        $gatewayModuleName
    );

    tunapayment_callback_response(200, 'OK');
}

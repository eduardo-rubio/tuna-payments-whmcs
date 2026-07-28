<?php

$global_id = 0;
$global_email = '';
$global_sessionId = 0;

$global_code_status = json_decode(file_get_contents(__DIR__ . "/messageCodeList.json"), true);
$global_payment_overall_status = json_decode(file_get_contents(__DIR__ . "/paymentStatus.json"), true);
$global_payment_status = json_decode(file_get_contents(__DIR__ . "/paymentMethodStatus.json"), true);
$global_code2_country = json_decode(file_get_contents(__DIR__ . "/iso2.json"), true);
$global_code2_currency = json_decode(file_get_contents(__DIR__ . "/currency.json"), true);

/**
 * Remove secrets and cardholder data before writing requests or responses to
 * the WHMCS gateway log.
 *
 * @param mixed $value
 * @return mixed
 */
function tunapayment_sanitize_log_data($value)
{
    if (is_object($value)) {
        $value = (array) $value;
    }

    if (!is_array($value)) {
        return $value;
    }

    $sensitiveKeys = [
        'cardnumber',
        'cardholdername',
        'cvv',
        'document',
        'email',
        'gatewayid',
        'name',
        'phone',
        'sessionid',
        'token',
        'tokensession',
        'x-tuna-apptoken',
    ];

    $sanitized = [];
    foreach ($value as $key => $item) {
        if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
            $sanitized[$key] = '***';
            continue;
        }

        $sanitized[$key] = tunapayment_sanitize_log_data($item);
    }

    return $sanitized;
}

/**
 * Write a safe gateway log entry.
 *
 * @param string $module
 * @param string $action
 * @param mixed $request
 * @param mixed $response
 * @param array $replaceVariables
 * @return void
 */
function tunapayment_log_call($module, $action, $request, $response, $replaceVariables = [])
{
    if (!function_exists('logModuleCall')) {
        return;
    }

    logModuleCall(
        $module,
        $action,
        tunapayment_sanitize_log_data($request),
        tunapayment_sanitize_log_data($response),
        null,
        array_values(array_filter($replaceVariables, 'is_scalar'))
    );
}

/**
 * Execute a JSON request against Tuna and normalize transport errors.
 *
 * Authentication follows Tuna's API guide: the account and app token are sent
 * only in the x-tuna-account and x-tuna-apptoken headers.
 * @see https://dev.tuna.uy/api/sandbox-environment/
 *
 * Tuna also supports an optional Idempotency-Key header. Callers should only
 * provide a key they can persist and reuse for the same logical attempt;
 * reusing a key for a later attempt would replay even the first 500 response.
 * @see https://dev.tuna.uy/api/idempotent-requests/
 *
 * @param string $url
 * @param string $tunaAccount
 * @param string $tunaApptoken
 * @param array $fields
 * @param string $module
 * @param string $action
 * @param string $httpMethod
 * @param string|null $idempotencyKey
 * @return array
 */
function tunapayment_api_request(
    $url,
    $tunaAccount,
    $tunaApptoken,
    array $fields,
    $module = 'Tuna Payment',
    $action = 'api_request',
    $httpMethod = 'POST',
    $idempotencyKey = null
) {
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'x-tuna-account: ' . $tunaAccount,
        'x-tuna-apptoken: ' . $tunaApptoken,
    ];
    if ($idempotencyKey !== null && $idempotencyKey !== '') {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }

    $jsonBody = json_encode($fields);
    if ($jsonBody === false) {
        return [
            'success' => false,
            'error' => 'Could not encode the Tuna request as JSON',
            'httpCode' => 0,
            'data' => null,
        ];
    }

    $ch = curl_init($url);
    $httpMethod = strtoupper($httpMethod);
    if ($httpMethod === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
    } else {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $httpMethod);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = is_string($body) ? json_decode($body) : null;
    $jsonError = json_last_error();

    $logResponse = $data ?: [
        'httpCode' => $httpCode,
        'error' => $curlError ?: ($jsonError !== JSON_ERROR_NONE ? json_last_error_msg() : null),
    ];
    tunapayment_log_call(
        $module,
        $action,
        $fields,
        $logResponse,
        [$tunaAccount, $tunaApptoken]
    );

    if ($body === false || $curlError !== '') {
        return [
            'success' => false,
            'error' => 'Could not connect to Tuna: ' . $curlError,
            'httpCode' => $httpCode,
            'data' => null,
        ];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return [
            'success' => false,
            'error' => 'Tuna returned HTTP status ' . $httpCode,
            'httpCode' => $httpCode,
            'data' => $data,
        ];
    }

    if ($data === null || $jsonError !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'error' => 'Tuna returned an invalid JSON response',
            'httpCode' => $httpCode,
            'data' => null,
        ];
    }

    // A 2xx response only means Tuna could process the HTTP request. Business
    // success is carried in the JSON "code" and "status" fields, so each
    // operation validates those fields after this transport-level result.
    // https://dev.tuna.uy/api/tuna-codes/#message-code-list
    return [
        'success' => true,
        'error' => null,
        'httpCode' => $httpCode,
        'data' => $data,
    ];
}

/**
 * Resolve Tuna API credentials and endpoint for the selected environment.
 *
 * @param string $service "payment" or "token"
 * @param string $action
 * @param string $testMode
 * @param string $tunaAccount
 * @param string $tunaApptoken
 * @return array
 */
function tunapayment_api_config($service, $action, $testMode, $tunaAccount, $tunaApptoken)
{
    $isTestMode = in_array(
        strtolower((string) $testMode),
        ['1', 'on', 'true', 'yes'],
        true
    );

    if ($isTestMode) {
        $host = $service === 'token' ? 'https://token.tuna-demo.uy' : 'https://sandbox.tuna-demo.uy';

        return [
            'url' => $host . '/api/' . ucfirst($service) . '/' . $action,
            'account' => 'demo',
            'appToken' => 'a3823a59-66bb-49e2-95eb-b47c447ec7a7',
        ];
    }

    $host = $service === 'token' ? 'https://token.tunagateway.com' : 'https://engine.tunagateway.com';

    return [
        'url' => $host . '/api/' . ucfirst($service) . '/' . $action,
        'account' => $tunaAccount,
        'appToken' => $tunaApptoken,
    ];
}

/**
 * Build a stable idempotency key for one WHMCS invoice operation.
 *
 * Payment/Init uses the invoice as the logical payment attempt. Reusing this
 * key lets Tuna replay the first response if WHMCS or the customer repeats the
 * same request, instead of creating a second charge.
 *
 * @see https://dev.tuna.uy/api/idempotent-requests/
 *
 * @param string $gateway
 * @param string $operation
 * @param string $invoiceId
 * @param float $amount
 * @return string
 */
function tunapayment_idempotency_key($gateway, $operation, $invoiceId, $amount)
{
    return hash(
        'sha256',
        implode('|', [
            'whmcs',
            $gateway,
            $operation,
            (string) $invoiceId,
            number_format((float) $amount, 2, '.', ''),
        ])
    );
}


/**
 *
 * @param string $tunaAccount
 * @param string $tunaApptoken
 * @param string $testMode
 * @param string $id
 * @param string $email
 *
 * @return array session response status
 */
function tunapayment_session($tunaAccount, $tunaApptoken, $testMode, $id, $email)
{
    global $global_sessionId, $global_id, $global_email;

    if ($global_id == $id && $global_email == $email && $global_sessionId != 0) {
        return [
            'success' => true,
            'session' => $global_sessionId,
        ];
    }

    // Tuna card flow, step 1: NewSession associates token operations with the
    // WHMCS customer. The returned sessionId is required by Payment/Init.
    // @see https://dev.tuna.uy/api/payment-integration/#step-1-start-a-new-session-for-your-customer
    $customer = array(
        'id' => strval($id),
        'email' => $email,
    );

    $postfields = array(
        'customer' => $customer,
    );

    $config = tunapayment_api_config('token', 'NewSession', $testMode, $tunaAccount, $tunaApptoken);
    $response = tunapayment_api_request(
        $config['url'],
        $config['account'],
        $config['appToken'],
        $postfields,
        'Tuna Payment',
        'tunapayment_session'
    );
    $session_data = $response['data'];

    if ($response['success'] && isset($session_data->code, $session_data->sessionId) && (int) $session_data->code === 1) {
        $global_id = $id;
        $global_email = $email;
        $global_sessionId = $session_data->sessionId;
        return [
            'success' => true,
            'session' => $global_sessionId,
        ];
    };
    return [
        'success' => false,
        'code' => isset($session_data->code) ? $session_data->code : null,
        'error' => $response['error'] ?: getTunaResponseMessage($session_data),
    ];
}

/**
 *
 * @param string $tunaAccount
 * @param string $tunaApptoken
 * @param string $testMode
 * @param string $sessionId
 * @param string $cardToken
 * @param string $cvv
 *
 * @return array token response status
 */

function tunapayment_bind_token(
    $tunaAccount,
    $tunaApptoken,
    $testMode,
    $sessionId,
    $cardToken,
    $cvv
) {

    // Tuna stored-card flow: bind the CVV to the reusable token in the new
    // customer session before a cardholder-initiated payment. Bind returns a
    // status, not a replacement token, so the original token remains in use.
    // @see https://dev.tuna.uy/api/payment-integration/#using-a-stored-credit-card
    $postfields = array(
        'token' => $cardToken,
        'cvv' => strval($cvv),
        'sessionId' => $sessionId,
    );

    $config = tunapayment_api_config('token', 'Bind', $testMode, $tunaAccount, $tunaApptoken);
    $response = tunapayment_api_request(
        $config['url'],
        $config['account'],
        $config['appToken'],
        $postfields,
        'Tuna Payment',
        'tunapayment_bind_token'
    );
    $bind_data = $response['data'];

    if ($response['success'] && isset($bind_data->code) && (int) $bind_data->code === 1) {
        return [
            'success' => true,
            'token' => $cardToken,
        ];
    };
    return [
        'success' => false,
        'token' => '',
        'code' => isset($bind_data->code) ? $bind_data->code : null,
        'error' => $response['error'] ?: getTunaResponseMessage($bind_data),
    ];
}
/**
 *
 * @param string $tunaAccount
 * @param string $tunaApptoken
 * @param string $testMode
 * @param string $sessionId
 * @param string $cardToken
 *
 * @return array token response status
 */

function tunapayment_delete_token(
    $tunaAccount,
    $tunaApptoken,
    $testMode,
    $sessionId,
    $cardToken
) {

    // Token/Delete is documented as an HTTP DELETE operation.
    // @see https://dev.tuna.uy/api/token/
    $postfields = array(
        'token' => $cardToken,
        'sessionId' => $sessionId,
    );

    $config = tunapayment_api_config('token', 'Delete', $testMode, $tunaAccount, $tunaApptoken);
    $response = tunapayment_api_request(
        $config['url'],
        $config['account'],
        $config['appToken'],
        $postfields,
        'Tuna Payment',
        'tunapayment_delete_token',
        'DELETE'
    );
    $delete_data = $response['data'];

    if ($response['success'] && isset($delete_data->code) && (int) $delete_data->code === 1) {
        return [
            'success' => true,
            'token' => isset($delete_data->token) ? $delete_data->token : '',
        ];
    };
    return [
        'success' => false,
        'token' => '',
        'code' => isset($delete_data->code) ? $delete_data->code : null,
        'error' => $response['error'] ?: getTunaResponseMessage($delete_data),
    ];
}

/**
 * tunapayment_generate_token
 *   @param string $tunaAccount,
 *   @param string $tunaApptoken,
 *   @param string $testMode,
 *   @param string $sessionId,
 *   @param string $cardHolderName,
 *   @param string $cardNumber,
 *   @param string $expirationMonth,
 *   @param string $expirationYear,
 *   @param string $cvv,
 *   @param string $singleUse
 *
 * @return array
 */
function tunapayment_generate_token(
    $tunaAccount,
    $tunaApptoken,
    $testMode,
    $sessionId,
    $cardHolderName,
    $cardNumber,
    $expirationMonth,
    $expirationYear,
    $cvv,
    $singleUse
) {

    // Tuna card flow, step 2: Generate exchanges raw card data for a Tuna
    // token. singleUse=false is used when WHMCS must retain it for recurring
    // charges; one-off captures use singleUse=true.
    // @see https://dev.tuna.uy/api/payment-integration/#using-a-new-credit-card
    $card = array(
        'cardNumber' => strval($cardNumber),
        'cardHolderName' => $cardHolderName,
        'expirationMonth' => intval($expirationMonth),
        'expirationYear' => intval($expirationYear),
        'singleUse' => $singleUse,
        'cvv' => strval($cvv),
    );
    $postfields = array(
        'card' => $card,
        'sessionId' => $sessionId,
    );

    $config = tunapayment_api_config('token', 'Generate', $testMode, $tunaAccount, $tunaApptoken);
    $response = tunapayment_api_request(
        $config['url'],
        $config['account'],
        $config['appToken'],
        $postfields,
        'Tuna Payment',
        'tunapayment_generate_token'
    );
    $token_data = $response['data'];

    if ($response['success'] && isset($token_data->code, $token_data->token) && (int) $token_data->code === 1) {
        return [
            'success' => true,
            'token' => $token_data->token,
        ];
    };
    return [
        'success' => false,
        'token' => '',
        'code' => isset($token_data->code) ? $token_data->code : null,
        'error' => $response['error'] ?: getTunaResponseMessage($token_data),
    ];
}


/**
 * getStatusMessage
 *
 * @param  string $statusNumber
 * @return string
 */
function getStatusMessage($statusNumber)
{

    global $global_payment_overall_status;

    $statusKey = (string) $statusNumber;
    if (!isset($global_payment_overall_status[$statusKey])) {
        return "INVALID STATUS #" . $statusNumber;
    }

    $status = $global_payment_overall_status[$statusKey];
    return $status['status'] . ': ' . $status['description'];
}

/**
 * Map Tuna payment states to the statuses accepted by WHMCS capture modules.
 *
 * @param string|int $statusNumber
 * @return string
 */
function getWhmcsPaymentStatus($statusNumber)
{
    $status = (string) $statusNumber;

    // Tuna recommends using the overall payment status for order decisions.
    // https://dev.tuna.uy/api/tuna-codes/#payment-status
    if ($status === '2') {
        return 'success';
    }

    if (in_array($status, ['0', 'P'], true)) {
        return 'pending';
    }

    if ($status === '4') {
        return 'declined';
    }

    return 'error';
}
/**
 * getCodeMessage
 *
 * @param  string $codeNumber
 * @return string
 */
function getCodeMessage($codeNumber)
{

    global $global_code_status;

    $codeKey = (string) $codeNumber;
    if (!isset($global_code_status[$codeKey])) {
        return "INVALID CODE #" . $codeNumber;
    }

    $code = $global_code_status[$codeKey];
    return trim(strip_tags($code['message'] . ' ' . $code['description']));
}

/**
 * Extract Tuna's current human-readable response message before falling back
 * to the bundled legacy code table. Tuna's live code list grows over time, so
 * the response object is the authoritative source for newly introduced codes.
 *
 * @see https://dev.tuna.uy/api/tuna-codes/#message-code-list
 *
 * @param mixed $data
 * @return string
 */
function getTunaResponseMessage($data)
{
    if (is_object($data) && isset($data->message)) {
        if (is_string($data->message) && $data->message !== '') {
            return strip_tags($data->message);
        }

        if (
            is_object($data->message)
            && isset($data->message->message)
            && is_string($data->message->message)
            && $data->message->message !== ''
        ) {
            return strip_tags($data->message->message);
        }
    }

    if (is_object($data) && isset($data->code)) {
        return getCodeMessage($data->code);
    }

    return 'Tuna request failed without an error message.';
}

/**
 * getFullName
 *
 * @param string $fullname
 * @param string $testMode
 * @return string
 */
function getFullName($fullname, $testMode = '')
{
    $isTestMode = in_array(
        strtolower((string) $testMode),
        ['1', 'on', 'true', 'yes'],
        true
    );
    if (!$isTestMode) {
        return $fullname;
    }

    if (str_starts_with($fullname, "Authorized")) {
        return "Authorized";
    }
    if (str_starts_with($fullname, "Captured")) {
        return "Captured";
    }
    if (str_starts_with($fullname, "Not Authorized")) {
        return "Not Authorized";
    }
    if (str_starts_with($fullname, "Error")) {
        return "Error";
    }
    if (str_starts_with($fullname, "Invalid")) {
        return "Invalid";
    }
    if (str_starts_with($fullname, "Pending")) {
        return "Pending";
    }
    if (str_starts_with($fullname, "Expired")) {
        return "Expired";
    }
    return $fullname;
}

/**
 * getCode2
 *
 * @param  string $code3
 * @return string
 */
function getCode2($code3)
{
    global $global_code2_country;

    return isset($global_code2_country[$code3]) ? $global_code2_country[$code3] : null;
}

/**
 * getCode2
 *
 * @param  string $code3
 * @return string
 */
function getCurrency2($code3)
{
    global $global_code2_currency;

    return isset($global_code2_currency[$code3]) ? $global_code2_currency[$code3] : null;
}

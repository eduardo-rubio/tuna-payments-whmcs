<?php

require_once __DIR__ . '/../../../includes/modulefunctions.php';

$global_id = 0;
$global_email = '';
$global_sessionId = 0;

$global_code_status = json_decode(file_get_contents(__DIR__ . "/messageCodeList.json"), true);
$global_payment_status = json_decode(file_get_contents(__DIR__ . "/paymentMethodStatus.json"), true);
$global_code2_country = json_decode(file_get_contents(__DIR__ . "/iso2.json"), true);
$global_code2_currency = json_decode(file_get_contents(__DIR__ . "/currency.json"), true);


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

    $tokenUrl = 'https://token.tunagateway.com/api/Token/NewSession';

    if ($testMode == 'yes') {
        $tokenUrl = 'https://token.tuna-demo.uy/api/Token/NewSession';
        $tunaAccount = 'demo';
        $tunaApptoken = 'a3823a59-66bb-49e2-95eb-b47c447ec7a7';
    }

    $postheader = array(
        'accept: application/json',
        'Content-Type: application/json',
        'x-tuna-account: ' . $tunaAccount,
        'x-tuna-apptoken: ' . $tunaApptoken,
    );

    $customer = array(
        'id' => strval($id),
        'email' => $email,
    );

    $postfields = array(
        'customer' => $customer,
    );

    $ch = curl_init($tokenUrl);

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $postheader);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postfields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $errno = 200;

    $session_response = curl_exec($ch);
    if (curl_error($ch)) {
        $errno = curl_errno($ch);
    }
    curl_close($ch);
    $session_data = json_decode($session_response);

    logModuleCall("Tuna Payment", "tunapayment_session", json_encode($postfields), $session_response, $session_data, $postheader);

    if ($session_data->code == 1) {
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
        'code' => $session_data->code

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

    $tokenUrl = 'https://token.tunagateway.com/api/Token/Bind';

    if ($testMode == 'yes') {
        $tokenUrl = 'https://token.tuna-demo.uy/api/Token/Bind';
        $tunaAccount = 'demo';
        $tunaApptoken = 'a3823a59-66bb-49e2-95eb-b47c447ec7a7';
    }

    $postheader = array(
        'accept: application/json',
        'Content-Type: application/json',
        'x-tuna-account: ' . $tunaAccount,
        'x-tuna-apptoken: ' . $tunaApptoken,
    );

    $postfields = array(
        'token' => $cardToken,
        'cVV' => strval($cvv),
        'sessionId' => $sessionId,
    );

    $ch = curl_init($tokenUrl);

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $postheader);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postfields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $errno = 200;

    $bind_response = curl_exec($ch);
    curl_close($ch);
    $bind_data = json_decode($bind_response);

    logModuleCall("Tuna Payment", "tunapayment_bind_token", json_encode($postfields), $bind_response, $bind_data, $postheader);

    if ($bind_data->code == 1) {
        return [
            'success' => true,
            'token' => $bind_data->token,
        ];
    };
    return [
        'success' => false,
        'token' => '',
        'code' => $bind_data->code
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

    $tokenUrl = 'https://token.tunagateway.com/api/Token/Delete';

    if ($testMode == 'yes') {
        $tokenUrl = 'https://token.tuna-demo.uy/api/Token/Delete';
        $tunaAccount = 'demo';
        $tunaApptoken = 'a3823a59-66bb-49e2-95eb-b47c447ec7a7';
    }

    $postheader = array(
        'accept: application/json',
        'Content-Type: application/json',
        'x-tuna-account: ' . $tunaAccount,
        'x-tuna-apptoken: ' . $tunaApptoken,
    );

    $postfields = array(
        'token' => $cardToken,
        'sessionId' => $sessionId,
    );

    $ch = curl_init($tokenUrl);

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $postheader);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postfields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $errno = 200;

    $delete_response = curl_exec($ch);
    curl_close($ch);
    $delete_data = json_decode($delete_response);

    logModuleCall("Tuna Payment", "tunapayment_delete_token", json_encode($postfields), $delete_response, $delete_data, $postheader);

    if ($delete_data->code == 1) {
        return [
            'success' => true,
            'token' => $delete_data->token,
        ];
    };
    return [
        'success' => false,
        'token' => '',
        'code' => $delete_data->code
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
 * @return void
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

    $tokenUrl = 'https://token.tunagateway.com/api/Token/Generate';

    if ($testMode == 'yes') {
        $tokenUrl = 'https://token.tuna-demo.uy/api/Token/Generate';
        $tunaAccount = 'demo';
        $tunaApptoken = 'a3823a59-66bb-49e2-95eb-b47c447ec7a7';
    }

    $postheader = array(
        'accept: application/json',
        'Content-Type: application/json',
        'x-tuna-account: ' . $tunaAccount,
        'x-tuna-apptoken: ' . $tunaApptoken,
    );

    $card = array(
        'cardNumber' => strval($cardNumber),
        'cardHolderName' => $cardHolderName,
        'expirationMonth' => intval($expirationMonth),
        'expirationYear' => intval($expirationYear),
        'singleUse' => $singleUse,
        'cVV' => strval($cvv),
    );
    $postfields = array(
        'card' => $card,
        'sessionId' => $sessionId,
    );

    $ch = curl_init($tokenUrl);

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $postheader);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postfields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $errno = 200;

    $token_response = curl_exec($ch);
    curl_close($ch);
    $token_data = json_decode($token_response);

    logModuleCall("Tuna Payment", "tunapayment_generate_token", json_encode($postfields), $token_response, $token_data, $postheader);

    if ($token_data->code == 1) {
        return [
            'success' => true,
            'token' => $token_data->token,
        ];
    };
    return [
        'success' => false,
        'token' => '',
        'code' => $token_data->code
    ];
}


/**
 * getStatusMessage
 *
 * @param  mixed $statusNumber
 * @return void
 */
function getStatusMessage($statusNumber)
{

    global $global_payment_status;

    $status = $global_payment_status[$statusNumber];
    if (is_null($status)) {
        return "INVALID STATUS #" . $statusNumber;
    }
    $msg = array_filter($global_payment_status[$statusNumber], function ($status) {
        return $status;
    });
}
/**
 * getCodeMessage
 *
 * @param  mixed $codeNumber
 * @return void
 */
function getCodeMessage($codeNumber)
{

    global $global_code_status;

    $code = $global_code_status[$codeNumber];

    if (is_null($code)) {
        return "INVALID CODE #" . $codeNumber;
    }
    $msg = array_filter($code, function ($message) {
        return $message;
    });
}

/**
 * getFullName
 *
 * @param  mixed $fullname
 * @return void
 */
function getFullName($fullname)
{
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
 * @param  mixed $code3
 * @return void
 */
function getCode2($code3)
{
    global $global_code2_country;

    return $global_code2_country[$code3];
}

/**
 * getCode2
 *
 * @param  mixed $code3
 * @return void
 */
function getCurrency2($code3)
{
    global $global_code2_currency;

    return $global_code2_currency[$code3];
}

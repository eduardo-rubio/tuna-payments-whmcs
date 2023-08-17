<?php

// Require libraries needed for gateway module functions.
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

// Detect module name from filename.
$gatewayModuleName = basename(__FILE__, '.php');

// Fetch gateway configuration parameters.
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active.
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

$global_id=0;
$global_email="";
$global_sessionId = 0;

function tunagateway_session($tunaAccount, $tunaApptoken, $testMode, $id, $email)
{
    global $global_sessionId, $global_id, $global_email;

    if ($global_id==$id && $global_email==$email && $global_sessionId!=0) {
        return $global_sessionId;
    }

    $tokenUrl = 'https://token.tunagateway.com/api/Token/NewSession';

    if ($testMode == 'yes') {
        $tokenUrl = 'https://token.tuna-demo.uy/api/Token/NewSession';
        $tunaAccount = 'demo';
        $tunaApptoken = 'a3823a59-66bb-49e2-95eb-b47c447ec7a7';
    }

    $postHeader = array(
        "accept" => "application/json",
        "x-tuna-account" => $tunaAccount,
        "x-tuna-apptoken" => $tunaApptoken,
        "Content-Type" => "application/json",
    );

    $customer = array(
        "id" => $id,
        "email" => $email,
    );

    $postParameter = array(
        "customer" => $customer,
    );

    $ch = curl_init($tokenUrl);

    curl_setopt($ch, CURLOPT_HTTPHEADER, $postHeader);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postParameter);

    $errno = 200;

    $data = curl_exec($ch);
    if (curl_error($ch)) {
        $errno = curl_errno($ch);
    }
    curl_close($ch);

    $global_id=$id;
    $global_email=$email;
    $global_sessionId=$data['sessionId'];

    return $global_sessionId;
}

function tunagateway_token($tunaAccount, $tunaApptoken, $testMode, $sessionId, $cardHolderName, $cardNumber,
    $expirationMonth, $expirationYear, $cvv, $singleUse) {
    $tokenUrl = 'https://token.tunagateway.com/api/Token/Generate';

    if ($testMode == 'yes') {
        $tokenUrl = 'https://token.tuna-demo.uy/api/Token/Generate';
        $tunaAccount = 'demo';
        $tunaApptoken = 'a3823a59-66bb-49e2-95eb-b47c447ec7a7';
    }

    $postHeader = array(
        "accept" => "application/json",
        "x-tuna-account" => $tunaAccount,
        "x-tuna-apptoken" => $tunaApptoken,
        "Content-Type" => "application/json",
    );

    $card = array(
        "cardHolderName" => $cardHolderName,
        "cardNumber" => $cardNumber,
        "expirationMonth" => $expirationMonth,
        "expirationYear" => $expirationYear,
        "singleUse" => $singleUse,
    );
    $postParameter = array(
        "sessionId" => $sessionId,
        "card" => $card,
    );

    $ch = curl_init($tokenUrl);

    curl_setopt($ch, CURLOPT_HTTPHEADER, $postHeader);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postParameter);

    $errno = 200;

    $data = curl_exec($ch);
    if (curl_error($ch)) {
        $errno = curl_errno($ch);
    }
    curl_close($ch);

    return $data['token'];
}

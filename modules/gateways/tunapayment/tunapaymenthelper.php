<?php

require_once __DIR__ . '/../../../includes/modulefunctions.php';

$global_id = 0;
$global_email = "";
$global_sessionId = 0;

function tunapayment_session($tunaAccount, $tunaApptoken, $testMode, $id, $email)
{
    global $global_sessionId, $global_id, $global_email;

    if ($global_id == $id && $global_email == $email && $global_sessionId != 0) {
        return $global_sessionId;
    }

    $tokenUrl = 'https://token.tunagateway.com/api/Token/NewSession';

    if ($testMode == 'yes') {
        $tokenUrl = 'https://token.tuna-demo.uy/api/Token/NewSession';
        $tunaAccount = 'demo';
        $tunaApptoken = 'a3823a59-66bb-49e2-95eb-b47c447ec7a7';
    }

    $postheader = array(
        "accept" => "application/json",
        "x-tuna-account" => $tunaAccount,
        "x-tuna-apptoken" => $tunaApptoken,
        "Content-Type" => "application/json",
    );

    $customer = array(
        "id" => $id,
        "email" => $email,
    );

    $postfields = array(
        "customer" => $customer,
    );

    $ch = curl_init($tokenUrl);

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, http_build_query($postheader));
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postfields));

    $errno = 200;

    $response = curl_exec($ch);
    if (curl_error($ch)) {
        $errno = curl_errno($ch);
    }
    curl_close($ch);

    logModuleCall("Tuna Payment", "tunapayment_session", $postheader + " " + $postfields, $response, "", "");

    $global_id = $id;
    $global_email = $email;
    $global_sessionId = $response['sessionId'];

    return $global_sessionId;
}

function tunapayment_token($tunaAccount, $tunaApptoken, $testMode, $sessionId, $cardHolderName, $cardNumber,
    $expirationMonth, $expirationYear, $cvv, $singleUse) {

        
    $tokenUrl = 'https://token.tunagateway.com/api/Token/Generate';

    if ($testMode == 'yes') {
        $tokenUrl = 'https://token.tuna-demo.uy/api/Token/Generate';
        $tunaAccount = 'demo';
        $tunaApptoken = 'a3823a59-66bb-49e2-95eb-b47c447ec7a7';
    }

    $postheader = array(
        "accept" => "application/json",
        "x-tuna-account" => $tunaAccount,
        "x-tuna-apptoken" => $tunaApptoken,
        "Content-Type" => "application/json",
    );

    $card = array(
        "cardNumber" => $cardNumber,
        "cardHolderName" => $cardHolderName,
        "expirationMonth" => $expirationMonth,
        "expirationYear" => $expirationYear,
        "singleUse" => $singleUse,
        "cVV" => $cvv,
    );
    $postfields = array(
        "card" => $card,
        "sessionId" => $sessionId,
    );

    $ch = curl_init($tokenUrl);

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, http_build_query($postheader));
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postfields));

    $errno = 200;

    $response = curl_exec($ch);

    logModuleCall("Tuna Payment", "tunapayment_token", $postheader + " " + $postfields, $response, "", "");

    if (curl_error($ch)) {
        $errno = curl_errno($ch);
        $response = [
            'success' => false,
        ];

    } else {
        $response = [
            'success' => true,
            'token' => $response['token'],
        ];
    }
    curl_close($ch);

    return $response;
}

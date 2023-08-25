<?php

require_once __DIR__ . '/../../../includes/modulefunctions.php';

$global_id = 0;
$global_email = "";
$global_sessionId = 0;

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
        "accept : application/json",
        "x-tuna-account : " . $tunaAccount,
        "x-tuna-apptoken : " . $tunaApptoken,
        "Content-Type : application/json",
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
    curl_setopt($ch, CURLOPT_HTTPHEADER, $postheader);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postfields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $errno = 200;

    $session_response = curl_exec($ch);
    if (curl_error($ch)) {
        $errno = curl_errno($ch);
    }
    curl_close($ch);

    logModuleCall("Tuna Payment", "tunapayment_session", json_encode($postfields), $session_response, "", "");

    $session_data = json_decode($session_response);

    $global_id = $id;
    $global_email = $email;
    $global_sessionId = $session_data->sessionId;

    return [
        'success' => true,
        'session' => $global_sessionId,
    ];
}

/**
 *
 * @param string $tunaAccount
 * @param string $tunaApptoken
 * @param string $testMode
 * @param string $sessionId
 * @param string $cardHolderName
 * @param string $cardNumber
 * @param string $expirationMonth
 * @param string $expirationYear
 * @param string $cvv
 * @param string $singleUse
 *
 * @return array token response status
 */

function tunapayment_token($tunaAccount, $tunaApptoken, $testMode, $sessionId, $cardHolderName, $cardNumber,
    $expirationMonth, $expirationYear, $cvv, $singleUse) {

    $tokenUrl = 'https://token.tunagateway.com/api/Token/Generate';

    if ($testMode == 'yes') {
        $tokenUrl = 'https://token.tuna-demo.uy/api/Token/Generate';
        $tunaAccount = 'demo';
        $tunaApptoken = 'a3823a59-66bb-49e2-95eb-b47c447ec7a7';
    }

    $postheader = array(
        "accept : application/json",
        "x-tuna-account : " . $tunaAccount,
        "x-tuna-apptoken : " . $tunaApptoken,
        "Content-Type : application/json",
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
    curl_setopt($ch, CURLOPT_HTTPHEADER, $postheader);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postfields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $errno = 200;

    $token_response = curl_exec($ch);

    logModuleCall("Tuna Payment", "tunapayment_token", json_encode($postfields), $token_response, "", "");

    $data = json_decode($token_response);

    if (curl_error($ch)) {
        $errno = curl_errno($ch);
        $response = [
            'success' => false,
            'token' => "",
        ];

    } else {
        $response = [
            'success' => true,
            'token' => $data->token,
        ];
    }
    curl_close($ch);

    return $response;
}

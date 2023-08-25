<?php
/**
 * WHMCS Tuna Payment Gateway Module
 */

require_once __DIR__ . '/tunapayment/tunapaymenthelper.php';
require_once __DIR__ . '/../../includes/modulefunctions.php';

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

$tunapayment_Description = "Tuna";
$tunapayment_Version = "1.0.0";

/**
 * Define module related meta data.
 *
 * Values returned here are used to determine module related capabilities and
 * settings.
 *
 * @see https://developers.whmcs.com/payment-gateways/meta-data-params/
 *
 * @return array
 */
function tunapayment_MetaData()
{
    global $tunapayment_Description;

    return array(
        'DisplayName' => 'Tuna Payment Gateway Module',
        'APIVersion' => '1.1', // Use API Version 1.1
        'failedEmail' => 'Credit Card Payment Failed',
        'successEmail' => 'Custom Credit Card Payment Template', // You can utilise custom templates here
        'pendingEmail' => 'Custom Credit Card Pending Template',
        'DisableLocalCreditCardInput' => false,
        'TokenisedStorage' => true,
    );
}

/**
 * Define gateway configuration options.
 *
 * The fields you define here determine the configuration options that are
 * presented to administrator users when activating and configuring your
 * payment gateway module for use.
 *
 *
 * @return array
 */
function tunapayment_config()
{
    global $tunapayment_Description, $tunapayment_Version;

    return array(
        // the friendly display name for a payment gateway should be
        // defined here for backwards compatibility
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Tuna Payment Gateway Module',
        ),
        // "x-tuna-account": "demo"
        'tunaAccount' => array(
            'FriendlyName' => 'Tuna Account',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Enter your Tuna Account here',
        ),
        // "x-tuna-apptoken": "a3823a59-66bb-49e2-95eb-b47c447ec7a7"
        'tunaApptoken' => array(
            'FriendlyName' => 'Tuna App Token',
            'Type' => 'text',
            'Size' => '36',
            'Default' => '',
            'Description' => 'Enter Tuna App Token here',
        ),
        // Test Environment
        'testMode' => array(
            'FriendlyName' => 'Test Environment',
            'Type' => 'yesno',
            'Description' => 'Tick to enable test environment',
        ),

    );
}

/**
 * Capture payment.
 *
 * Called when a payment is to be processed and captured.
 *
 * The card cvv number will only be present for the initial card holder present
 * transactions. Automated recurring capture attempts will not provide it.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @return array Transaction response status
 */
function tunapayment_capture($params)
{
    // Gateway Configuration Parameters
    $tunaAccount = $params['tunaAccount'];
    $tunaApptoken = $params['tunaApptoken'];
    $testMode = $params['testMode'];

    // Invoice Parameters
    $invoiceId = $params['invoiceid'];
    $description = $params["description"];
    $amount = $params['amount'];
    $currencyCode = $params['currency'];

    // Credit Card Parameters
    $remoteGatewayToken = $params['gatewayid'];

    $cardType = $params['cardtype'];
    $cardNumber = $params['cardnum'];
    $cardExpiry = $params['cardexp'];
    $cardStart = $params['cardstart'];
    $cardIssueNumber = $params['cardissuenum'];
    $cardCvv = $params['cccvv'];

    $expirationMonth = substr($cardExpiry, 0, 2);
    $expirationYear = "20"+substr($cardExpiry, 2, 2);

    // Client Parameters
    $firstname = $params['clientdetails']['firstname'];
    $lastname = $params['clientdetails']['lastname'];
    $email = $params['clientdetails']['email'];
    $address1 = $params['clientdetails']['address1'];
    $address2 = $params['clientdetails']['address2'];
    $city = $params['clientdetails']['city'];
    $state = $params['clientdetails']['state'];
    $postcode = $params['clientdetails']['postcode'];
    $country = $params['clientdetails']['country'];
    $phone = $params['clientdetails']['phonenumber'];
    $taxid = $params['clientdetails']['taxid'];
    $userid = $params['clientdetails']['id'];
    $fullname = $params['clientdetails']['fullname'];

    // System Parameters
    $companyName = $params['companyname'];
    $systemUrl = $params['systemurl'];
    $returnUrl = $params['returnurl'];
    $langPayNow = $params['langpaynow'];
    $moduleDisplayName = $params['name'];
    $moduleName = $params['paymentmethod'];
    $whmcsVersion = $params['whmcsVersion'];

    $session_response = tunapayment_session($tunaAccount, $tunaApptoken, $testMode, $userid, $email);
    if ($session_response['success']) {
        $remoteGatewayToken = $session_response['token'];
    } else {
        return [
            // 'success' if successful, otherwise 'error' for failure
            'status' => 'error',
            // Data to be recorded in the gateway log - can be a string or array
            'rawdata' => $session_response,
        ];
    }


    if (!$remoteGatewayToken) {
        // If there is no token yet, it indicates this capture is being
        // attempted using an existing locally stored card. Create a new
        // token and then attempt capture.

        $token_response = tunapayment_token($tunaAccount, $tunaApptoken, $testMode, $sessionId, $fullname, $cardNumber, $expirationMonth, $expirationYear, $cardCvv, true);
        if ($token_response['success']) {
            $remoteGatewayToken = $token_response['token'];
        } else {
            return [
                // 'success' if successful, otherwise 'error' for failure
                'status' => 'error',
                // Data to be recorded in the gateway log - can be a string or array
                'rawdata' => $token_response,
            ];
        }

    }
    ;

    $paymentUrl = 'https://engine.tunagateway.com/api/Payment/Init';

    if ($testMode == 'yes') {
        $paymentUrl = 'https://sandbox.tuna-demo.uy/api/Payment/Init';
        $tunaAccount = 'demo';
        $tunaApptoken = 'a3823a59-66bb-49e2-95eb-b47c447ec7a7';
    }

    $partnerUniqueId = $invoiceId;
    $customer = array(
        "id" => $userid,
        "email" => $email,
        "document" => $taxid,
        "documentType" => "TAXID",
        "name" => $fullname,
    );

    $paymentItems = array(
        "items" => array(
            "amount" => $amount,
            "detailUniqueId" => $invoiceId,
            "productDescription" => $description,
            "itemQuantity" => 1,
        ),
    );

    $deliveryAddress = array(
        "street" => $address1,
        "number" => $address2,
        "neighborhood" => $city,
        "city" => $city,
        "state" => $state,
        "postalCode" => $postcode,
        "phone" => $phone,
        "country" => $country,
    );

    $countryCode = $country;

    $paymentData = array(
        "paymentMethods" => array(
            "paymentMethodType" => 1,
            "amount" => $amount,
            "installments" => 1,
            "cardInfo" => array(
                "token" => $remoteGatewayToken,
                "tokenProvider" => "Tuna",
                "cardHolderName" => $fullname,
                "expirationMonth" => $expirationMonth,
                "expirationYear" => $expirationYear,
                "brandName" => $cardType,
                "tokenSingleUse" => 0,
                "saveCard" => false,
                "billingInfo" => array(
                    "document" => "744.479.870-23",
                    "documentType" => "CPF",
                ),
            ),
        ),
    );

    $card = array(
        "cardHolderName" => $fullname,
        "cardNumber" => $cardIssueNumber,
        "expirationMonth" => $expirationMonth,
        "expirationYear" => $expirationYear,
        "cvv" => $cardCvv,
        "singleUse" => true,
    );

    $postheader = array(
        "accept : application/json",
        "x-tuna-account : " . $tunaAccount,
        "x-tuna-apptoken : " . $tunaApptoken,
        "Content-Type : application/json",
    );

    $postfields = [
        'tokenSession' => $sessionId,
        'partnerUniqueId' => $partnerUniqueId,
        'customer' => $customer,
        'paymentItems' => $paymentItems,
        'paymentData' => $paymentData,
        'deliveryAddress' => $deliveryAddress,
        'countryCode' => $countryCode,
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $paymentUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $postheader);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postfields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    logModuleCall("Tuna Payment", "tunapayment_capture", $postfields, $response, "", "");

    $data = json_decode($response);

    // perform API call to capture payment and interpret result

    if ($data->status == 1) {
        $returnData = [
            // 'success' if successful, otherwise 'declined', 'error' for failure
            'status' => 'success',
            // Data to be recorded in the gateway log - can be a string or array
            'rawdata' => $data,
            // Unique Transaction ID for the capture transaction
            'transid' => $data->operationId,
            // Return only if the token has updated or changed
            'gatewayid' => $response['token'],
        ];
    } else {
        $returnData = [
            // 'success' if successful, otherwise 'declined', 'error' for failure
            'status' => 'declined',
            // When not successful, a specific decline reason can be logged in the Transaction History
            'declinereason' => 'Credit card declined. Please contact issuer.',
            // Data to be recorded in the gateway log - can be a string or array
            'rawdata' => $data,
        ];
    }

    return $returnData;
}

function tunapayment_storeremote($params)
{
    // Gateway Configuration Parameters
    $tunaAccount = $params['tunaAccount'];
    $tunaApptoken = $params['tunaApptoken'];
    $testMode = $params['testMode'];

    $action = $params['action'];
    $gatewayid = $params['gatewayid'];
    $cardtype = $params['cardtype'];
    $cardnum = $params['cardnum'];
    $cardexp = $params['cardexp'];
    $cardstart = $params['cardstart'];
    $cardissuenum = $params['cardissuenum'];
    $cardCvv = $params['cccvv'];

    $expirationMonth = substr($cardexp, 0, 2);
    $expirationYear = "20"+substr($cardexp, 2, 2);

    // Client Parameters
    $firstname = $params['clientdetails']['firstname'];
    $lastname = $params['clientdetails']['lastname'];
    $email = $params['clientdetails']['email'];
    $address1 = $params['clientdetails']['address1'];
    $address2 = $params['clientdetails']['address2'];
    $city = $params['clientdetails']['city'];
    $state = $params['clientdetails']['state'];
    $postcode = $params['clientdetails']['postcode'];
    $country = $params['clientdetails']['country'];
    $phone = $params['clientdetails']['phonenumber'];
    $taxid = $params['clientdetails']['taxid'];
    $userid = $params['clientdetails']['id'];
    $fullname = $params['clientdetails']['fullname'];

    try {
        $sessionId = tunapayment_session($tunaAccount, $tunaApptoken, $testMode, $userid, $email);
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'rawdata' => 'Invalid Session:'+$e
        ];
    };

    switch ($action) {
        case 'create':
            // Make API call to create a token here
            $gatewayid = tunapayment_token($tunaAccount, $tunaApptoken, $testMode, $sessionId, $fullname, $cardnum, $expirationMonth, $expirationYear, $cardCvv, true);

            return [
                'status' => 'success',
                'gatewayid' => $gatewayid,
            ];
        case 'update':
            // Make API call to update a token here
            $gatewayid = tunapayment_token($tunaAccount, $tunaApptoken, $testMode, $sessionId, $fullname, $cardnum, $expirationMonth, $expirationYear, $cardCvv, true);

            return [
                'status' => 'success',
                'gatewayid' => $gatewayid,
            ];
        case 'delete':
            // Make API call to delete a token here
            $postfields = [
                'remote_id' => $gatewayid,
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, 'https://www.example.com/api/delete');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postfields));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            curl_close($ch);
            logModuleCall("Tuna Payment", "tunapayment_storeremote", $postfields, $response, "", "");

            $data = json_decode($response);
            return [
                'status' => 'success',
            ];
    }
}

/**
 * Refund transaction.
 *
 * Called when a refund is requested for a previously successful transaction.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see https://developers.whmcs.com/payment-gateways/refunds/
 *
 * @return array Transaction response status
 */
function tunapayment_refund($params)
{
    // Gateway Configuration Parameters
    $tunaAccount = $params['tunaAccount'];
    $tunaApptoken = $params['tunaApptoken'];
    $testMode = $params['testMode'];

    // Transaction Parameters
    $invoiceId = $params['invoiceid'];
    $transactionIdToRefund = $params['transid'];
    $refundAmount = $params['amount'];
    $currencyCode = $params['currency'];

    // Client Parameters
    $firstname = $params['clientdetails']['firstname'];
    $lastname = $params['clientdetails']['lastname'];
    $email = $params['clientdetails']['email'];
    $address1 = $params['clientdetails']['address1'];
    $address2 = $params['clientdetails']['address2'];
    $city = $params['clientdetails']['city'];
    $state = $params['clientdetails']['state'];
    $postcode = $params['clientdetails']['postcode'];
    $country = $params['clientdetails']['country'];
    $phone = $params['clientdetails']['phonenumber'];

    // System Parameters
    $companyName = $params['companyname'];
    $systemUrl = $params['systemurl'];
    $langPayNow = $params['langpaynow'];
    $moduleDisplayName = $params['name'];
    $moduleName = $params['paymentmethod'];
    $whmcsVersion = $params['whmcsVersion'];

    $cancelUrl = 'https://engine.tunagateway.com/api/Payment/Cancel';

    if ($testMode == 'yes') {
        $paymentUrl = 'https://sandbox.tuna-demo.uy/api/Payment/Cancel';
        $tunaAccount = 'demo';
        $tunaApptoken = 'a3823a59-66bb-49e2-95eb-b47c447ec7a7';
    }

    $partnerUniqueId = $invoiceId;
    $postheader = array(
        "accept : application/json",
        "x-tuna-account : " . $tunaAccount,
        "x-tuna-apptoken : " . $tunaApptoken,
        "Content-Type : application/json",
    );

    $cardDetail = array(
        "amount" => $refundAmount,
        "methodId" => 0,
        "Splits" => array(
            "MerchantID" => "",
            "Amount" => $refundAmount,
        ),
    );

    $postfields = [
        'cardDetail' => $cardDetail,
        'paymentKey' => '',
        'partnerUniqueId' => $partnerUniqueId,
        'paymentDay' => '',
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $cancelUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $postheader);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postfields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    logModuleCall("Tuna Payment", "tunapayment_refund", $postfields, $response, "", "");

    $data = json_decode($response);

    // perform API call to initiate refund and interpret result

    return array(
        // 'success' if successful, otherwise 'declined', 'error' for failure
        'status' => 'success',
        // Data to be recorded in the gateway log - can be a string or array
        'rawdata' => $data,
        // Unique Transaction ID for the refund transaction
        'transid' => $transactionIdToRefund,
    );
}

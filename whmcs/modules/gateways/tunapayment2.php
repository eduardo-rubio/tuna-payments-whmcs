<?php
/**
 * WHMCS Tuna Payment Gateway Module
 */

require_once __DIR__ . '/tunapayment/tunapaymenthelper.php';
require_once __DIR__ . '/../../includes/modulefunctions.php';

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

$tunapayment2_Description = "Tuna Others";
$tunapayment2_Version = "1.0.0";

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
function tunapayment2_MetaData()
{
    global $tunapayment2_Description;

    return array(
        'DisplayName' => 'Tuna Payment Gateway Module',
        // Use API Version 1.1
        'APIVersion' => '1.1',
        // You can utilise custom templates here
        'failedEmail' => 'Credit Card Payment Failed',
        'successEmail' => 'Custom Credit Card Payment Template',
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
function tunapayment2_config()
{
    global $tunapayment2_Description, $tunapayment2_Version;

    return array(
        // the friendly display name for a payment gateway should be
        // defined here for backwards compatibility
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Tuna Payment Gateway 2 Module',
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

function tunapayment2_link($params)
{
    // Gateway Configuration Parameters
    $accountId = $params['accountID'];
    $secretKey = $params['secretKey'];
    $testMode = $params['testMode'];
    $dropdownField = $params['dropdownField'];
    $radioField = $params['radioField'];
    $textareaField = $params['textareaField'];

    // Invoice Parameters
    $invoiceId = $params['invoiceid'];
    $description = $params["description"];
    $amount = $params['amount'];
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
    $returnUrl = $params['returnurl'];
    $langPayNow = $params['langpaynow'];
    $moduleDisplayName = $params['name'];
    $moduleName = $params['paymentmethod'];
    $whmcsVersion = $params['whmcsVersion'];

    $url = 'https://www.demopaymentgateway.com/do.payment';

    $postfields = array();
    $postfields['username'] = $username;
    $postfields['invoice_id'] = $invoiceId;
    $postfields['description'] = $description;
    $postfields['amount'] = $amount;
    $postfields['currency'] = $currencyCode;
    $postfields['first_name'] = $firstname;
    $postfields['last_name'] = $lastname;
    $postfields['email'] = $email;
    $postfields['address1'] = $address1;
    $postfields['address2'] = $address2;
    $postfields['city'] = $city;
    $postfields['state'] = $state;
    $postfields['postcode'] = $postcode;
    $postfields['country'] = $country;
    $postfields['phone'] = $phone;
    $postfields['callback_url'] = $systemUrl . '/modules/gateways/callback/' . $moduleName . '.php';
    $postfields['return_url'] = $returnUrl;

    $htmlOutput = '<form method="post" action="' . $url . '">';
    foreach ($postfields as $k => $v) {
        $htmlOutput .= '<input type="hidden" name="' . $k . '" value="' . urlencode($v) . '" />';
    }
    $htmlOutput .= '<input type="submit" value="' . $langPayNow . '" />';
    $htmlOutput .= '</form>';

    return $htmlOutput;
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
function tunapayment2_refund($params)
{
    // Gateway Configuration Parameters
    $tunaAccount = $params['tunaAccount'];
    $tunaApptoken = $params['tunaApptoken'];
    $testMode = $params['testMode'];

    // Transaction Parameters
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
        'accept: application/json',
        'Content-Type: application/json',
        'x-tuna-account: ' . $tunaAccount,
        'x-tuna-apptoken: ' . $tunaApptoken,
    );

    $cardDetail = [
        array(
            'amount' => $refundAmount,
            'methodId' => 0,
            'Splits' => [
                array(
                    'MerchantID' => '',
                    'Amount' => $refundAmount,
                )
            ],
        )
    ];

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
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postfields, JSON_FORCE_OBJECT));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response);

    logModuleCall("Tuna Payment", "tunapayment_refund", json_encode($postfields, JSON_FORCE_OBJECT), $response, $data, $postheader);

    // perform API call to initiate refund and interpret result

    if ($data->code == 1) {
        if ($data->status == 1) {
            return array(
                // 'success' if successful, otherwise 'declined', 'error' for failure
                'status' => 'success',
                // Data to be recorded in the gateway log - can be a string or array
                'rawdata' => $data,
                // Unique Transaction ID for the refund transaction
                'transid' => $transactionIdToRefund,
            );

        }
    }
    ;
    return array(
        'status' => 'error',
        'rawdata' => $data,
    );    // perform API call to initiate refund and interpret result
}

/**
 * Cancel subscription.
 *
 * If the payment gateway creates subscriptions and stores the subscription
 * ID in tblhosting.subscriptionid, this function is called upon cancellation
 * or request by an admin user.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see https://developers.whmcs.com/payment-gateways/subscription-management/
 *
 * @return array Transaction response status
 */
function tunapayment2_cancelSubscription($params)
{
    // Gateway Configuration Parameters
    $accountId = $params['accountID'];
    $secretKey = $params['secretKey'];
    $testMode = $params['testMode'];
    $dropdownField = $params['dropdownField'];
    $radioField = $params['radioField'];
    $textareaField = $params['textareaField'];

    // Subscription Parameters
    $subscriptionIdToCancel = $params['subscriptionID'];

    // System Parameters
    $companyName = $params['companyname'];
    $systemUrl = $params['systemurl'];
    $langPayNow = $params['langpaynow'];
    $moduleDisplayName = $params['name'];
    $moduleName = $params['paymentmethod'];
    $whmcsVersion = $params['whmcsVersion'];

    // perform API call to cancel subscription and interpret result

    return array(
        // 'success' if successful, any other value for failure
        'status' => 'success',
        // Data to be recorded in the gateway log - can be a string or array
        'rawdata' => $responseData,
    );
}
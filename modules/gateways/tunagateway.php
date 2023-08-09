<?php
/**
 * WHMCS Tuna Payment Gateway Module
 */

require_once __DIR__ . '/tunagateway/tunagatewayhelper.php';

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

$tunagateway_Description = "Tuna";
$tunagateway_Version = "1.0.0";

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
function tunagateway_MetaData()
{
    global $tunagateway_Description;

    return array(
        'DisplayName' => 'Tuna Payment Gateway Module',
        'APIVersion' => '1.1', // Use API Version 1.1
        'failedEmail' => 'Credit Card Payment Failed',
        'successEmail' => 'Custom Credit Card Payment Template', // You can utilise custom templates here
        'pendingEmail' => 'Custom Credit Card Pending Template',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
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
function tunagateway_config()
{
    global $tunagateway_Description, $tunagateway_Version;

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
 * Payment link.
 *
 * Required by third party payment gateway modules only.
 *
 * Defines the HTML output displayed on an invoice. Typically consists of an
 * HTML form that will take the user to the payment gateway endpoint.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @see https://developers.whmcs.com/payment-gateways/third-party-gateway/
 *
 * @return string
 */
function tunagateway_link($params)
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

    // Client Parameters
    $userid = $params['clientdetails']['userid'];
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
    $taxId = $params['clientdetails']['taxId'];

    // System Parameters
    $companyName = $params['companyname'];
    $systemUrl = $params['systemurl'];
    $returnUrl = $params['returnurl'];
    $langPayNow = $params['langpaynow'];
    $moduleDisplayName = $params['name'];
    $moduleName = $params['paymentmethod'];
    $whmcsVersion = $params['whmcsVersion'];

    $paymentUrl = 'https://engine.tunagateway.com/api/PaymentInit';

    if ($testMode == 'yes') {
        $paymentUrl = 'https://sandbox.tuna-demo.uy/api/PaymentInit';
        $tunaAccount = 'demo';
        $tunaApptoken = 'a3823a59-66bb-49e2-95eb-b47c447ec7a7';
    }

    try {
        $sessionData = tunagateway_session($tunaAccount, $tunaApptoken, $testMode, $userid, $email);
    } catch (Exception $e) {
        return "<h4> Invalid Session </h4>";
    };

    $sessionId = $sessionData['sessionId'];

    $partnerUniqueId = $invoiceId;
    $customer= array(
      "id"=>$userid,
      "email"=>$email,
      "document"=>$taxId,
      "documentType"=>"TAXID",
      "name"=>$firstname + " " + $lastname
    );

    $paymentItems = array (
      "items"=> array (
          "amount"=>$amount,
          "detailUniqueId"=>$invoiceId,
          "productDescription"=>$description,
          "itemQuantity"=>1
      )
    );

    $deliveryAddress= array (
        "street"=>$address1,
        "number"=>$address2,
        "neighborhood"=>$city,
        "city"=>$city,
        "state"=>$state,
        "postalCode"=>$postcode,
        "phone"=>$phone,
        "country"=>$country
    );
    
    $countryCode=$country;

    /*
    "paymentData": {
      "paymentMethods": [
        {
          "paymentMethodType": "1",
          "amount": 20,
          "installments": 1,
          "cardInfo": {
            "token": "ct_NjJmM2QxOTUtYTM4OS00YmYyLTg4MDAtOTE3YzY1NzM0NmE30",
            "tokenProvider": "Tuna",
            "cardHolderName": "Captured",
            "expirationMonth": 12,
            "expirationYear": 2023,
            "brandName": "Visa",
            "tokenSingleUse": 0,
            "saveCard": false,
            "billingInfo": {
              "document": "744.479.870-23",
              "documentType": "CPF"
            }
          }
        }
      ],
    */

    $card = array(
        "cardHolderName" => "Captured",
        "cardNumber" => "4111111111111111",
        "expirationMonth" => 12,
        "expirationYear" => 2023,
        "cvv" => "222",
        "singleUse" => false,
    );

    $postfields = array();
    $postfields['username'] = $firstname+" "+$lastname;
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

    $htmlOutput = '<form method="post" action="' . $paymentUrl . '">';
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
function tunagateway_refund($params)
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

    // perform API call to initiate refund and interpret result

    return array(
        // 'success' if successful, otherwise 'declined', 'error' for failure
        'status' => 'success',
        // Data to be recorded in the gateway log - can be a string or array
        'rawdata' => $responseData,
        // Unique Transaction ID for the refund transaction
        'transid' => $refundTransactionId,
        // Optional fee amount for the fee value refunded
        'fees' => $feeAmount,
    );
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
function tunagateway_cancelSubscription($params)
{
    // Gateway Configuration Parameters
    $tunaAccount = $params['tunaAccount'];
    $tunaApptoken = $params['tunaApptoken'];
    $testMode = $params['testMode'];

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

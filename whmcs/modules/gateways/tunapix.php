<?php

/**
 * WHMCS Tuna Payment Gateway Module
 */

require_once __DIR__ . '/tunapayment/tunapaymenthelper.php';
require_once __DIR__ . '/../../includes/modulefunctions.php';

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

$tunapix_Description = "Tuna Others";
$tunapix_Version = "1.0.0";

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
function tunapix_MetaData()
{
    global $tunapix_Description;

    return array(
        'DisplayName' => 'Tuna Payment Gateway Module 2',
        // Use API Version 1.1
        'APIVersion' => '1.1',
        // You can utilise custom templates here
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
function tunapix_config()
{
    global $tunapix_Description, $tunapix_Version;

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
function tunapix_link($params)
{
    // Gateway Configuration Parameters
    $tunaAccount = $params['tunaAccount'];
    $tunaApptoken = $params['tunaApptoken'];
    $testMode = $params['testMode'];

    // Invoice Parameters
    $invoiceId = $params['invoiceid'];
    $description = $params["description"];
    $amount = $params['amount'];
    $currencyCode = getCurrency2($params['currency']);

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
    $fullname = getFullName($params['clientdetails']['fullname']);

    // System Parameters
    $companyName = $params['companyname'];
    $systemUrl = $params['systemurl'];
    $returnUrl = $params['returnurl'];
    $langPayNow = $params['langpaynow'];
    $moduleDisplayName = $params['name'];
    $moduleName = $params['paymentmethod'];
    $whmcsVersion = $params['whmcsVersion'];

    // Custom Fields
    $documenttype = $params['customfield']['documenttype'];
    $documentnumber = $params['customfield']['documentnumber'];

    $paymentUrl = 'https://engine.tunagateway.com/api/Payment/Init';

    if ($testMode == 'yes') {
        $paymentUrl = 'https://sandbox.tuna-demo.uy/api/Payment/Init';
        $tunaAccount = 'demo';
        $tunaApptoken = 'a3823a59-66bb-49e2-95eb-b47c447ec7a7';
    }


    // $countryCode = $country;
    if (is_null($currencyCode)) {
        $currencyCode = $country;
    }

    // {
    //     "partnerUniqueId": "#032",
    //     "customer": {
    //       "id": "7",
    //       "email": "maju.cheapetta@synapcom.com.br",
    //       "document": "744.479.870-23",
    //       "documentType": "CPF",
    //       "name": "Maju Cheapetta"
    //     },
    //     "paymentItems": {
    //       "items": [
    //         {
    //           "amount": 20,
    //           "detailUniqueId": "A01",
    //           "productDescription": "Test product",
    //           "itemQuantity": 1
    //         }
    //       ]
    //     },
    //     "paymentData": {
    //       "paymentMethods": [
    //         {
    //           "paymentMethodType": "D",
    //           "amount": 20,
    //           "pix": {
    //             "name": "Maju Cheapetta",
    //             "document": "744.479.870-23",
    //             "documentType": "CPF"
    //           }
    //         }
    //       ],
    //       "deliveryAddress": {
    //         "street": "Rua João Longo",
    //         "number": "1004",
    //         "neighborhood": "Jandira",
    //         "city": "São Paulo",
    //         "state": "SP",
    //         "postalCode": "06608-420",
    //         "phone": "(11) 6536-8864",
    //         "country": "BR"
    //       },
    //       "countryCode": "BR"
    //     }
    //   }

    $customer = array(
        'id' => strval($userid),
        'email' => $email,
        'document' => strval($documentnumber),
        'documentType' => $documenttype,
        'name' => $fullname,
    );

    $paymentItems = array(
        'items' => [
            array(
                'amount' => $amount,
                'detailUniqueId' => $invoiceId,
                'productDescription' => $description,
                'itemQuantity' => 1,
            )
        ]
    );


    $deliveryAddress = array(
        'street' => $address1,
        'number' => $address2,
        'neighborhood' => $city,
        'city' => $city,
        'state' => $state,
        'postalCode' => $postcode,
        'phone' => $phone,
        'country' => $country,
    );

    $paymentData = array(
        'paymentMethods' => [
            array(
                'paymentMethodType' => 'D',
                'amount' => $amount,
                'pix' => array(
                    'name' => $fullname,
                    'document' => $documentnumber,
                    'documentType' => $documenttype,
                ),
            ),
        ],
        'deliveryAddress' => $deliveryAddress,
        "countrycode" => $currencyCode,
    );

    $postheader = array(
        'accept: application/json',
        'Content-Type: application/json',
        'x-tuna-account: ' . $tunaAccount,
        'x-tuna-apptoken: ' . $tunaApptoken,
    );

    $postfields = [
        'partnerUniqueId' => $invoiceId,
        'customer' => $customer,
        'paymentItems' => $paymentItems,
        'paymentData' => $paymentData
    ];

    $url = $paymentUrl;

    $htmlOutput = '<form method="post" action="' . $url . '">';
    foreach ($postfields as $k => $v) {
        $htmlOutput .= '<input type="hidden" name="' . $k . '" value="' . urlencode($v) . '" />';
    }
    $htmlOutput .= '<input type="submit" value="' . $langPayNow . '" />';
    $htmlOutput .= '</form>';

    return $htmlOutput;
}

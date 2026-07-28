<?php
/**
 * WHMCS Tuna Payment Gateway Module
 */

require_once __DIR__ . '/tunapayment/tunapaymenthelper.php';

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

$tunapayment_Description = "Tuna CreditCard";
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
        'DisplayName' => 'Tuna Payment CreditCard',
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
            'Type' => 'password',
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
    $invoiceId = (string) $params['invoiceid'];
    $description = $params["description"];
    $amount = (float) $params['amount'];
    if ($amount <= 0) {
        return [
            'status' => 'error',
            'rawdata' => ['error' => 'Payment amount must be greater than zero'],
        ];
    }

    // Credit Card Parameters
    $remoteGatewayToken = $params['gatewayid'];
    $tokenSingleUse = empty($remoteGatewayToken);

    $cardType = $params['cardtype'];
    $cardNumber = $params['cardnum'];
    $cardExpiry = $params['cardexp'];
    $cardStart = $params['cardstart'];
    $cardIssueNumber = $params['cardissuenum'];
    $cardCvv = isset($params['cccvv']) ? (string) $params['cccvv'] : '';

    $expirationMonth = substr($cardExpiry, 0, 2);
    $expirationYear = "20" . substr($cardExpiry, 2, 2);

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
    $fullname = getFullName($params['clientdetails']['fullname'], $testMode);

    // Payment/Init expects an ISO-2 countryCode, not a currency code. WHMCS
    // already supplies the customer's ISO-2 country; the currency lookup is
    // retained only as a fallback for incomplete customer records.
    $countryCode = strtoupper((string) $country);
    if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
        $countryCode = getCurrency2($params['currency']);
    }
    if (!is_string($countryCode) || !preg_match('/^[A-Z]{2}$/', $countryCode)) {
        return [
            'status' => 'error',
            'rawdata' => ['error' => 'A valid ISO-2 customer country is required'],
        ];
    }

    // System Parameters
    $companyName = $params['companyname'];
    $systemUrl = $params['systemurl'];
    $returnUrl = $params['returnurl'];
    $langPayNow = $params['langpaynow'];
    $moduleDisplayName = $params['name'];
    $moduleName = $params['paymentmethod'];
    $whmcsVersion = $params['whmcsVersion'];

    // Custom Fields
    $documenttype = isset($params['customfield']['documenttype'])
        ? $params['customfield']['documenttype']
        : '';
    $documentnumber = isset($params['customfield']['documentnumber'])
        ? $params['customfield']['documentnumber']
        : '';
    if ($documenttype === '' || $documentnumber === '') {
        return [
            'status' => 'error',
            'rawdata' => ['error' => 'Customer document type and number are required'],
        ];
    }

    $session_response = tunapayment_session($tunaAccount, $tunaApptoken, $testMode, $userid, $email);
    if ($session_response['success']) {
        $sessionId = $session_response['session'];
    } else {
        return [
            // 'success' if successful, otherwise 'error' for failure
            'status' => 'error',
            // Data to be recorded in the gateway log - can be a string or array
            'rawdata' => $session_response,
        ];
    }
    ;

    if (!$remoteGatewayToken) {
        // Tuna requires tokenization before every credit-card Payment/Init.
        // This one-off token represents the card entered in WHMCS for this
        // capture and is not retained by Tuna.
        // https://dev.tuna.uy/api/payment-integration/#card-tokenization
        $token_response = tunapayment_generate_token($tunaAccount, $tunaApptoken, $testMode, $sessionId, $fullname, $cardNumber, $expirationMonth, $expirationYear, $cardCvv, true);
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
    } elseif ($cardCvv !== '') {
        // For a customer-initiated payment with an already stored token, Tuna
        // requires Token/Bind so the fresh CVV is validated in this session.
        // WHMCS intentionally omits CVV on automated recurring captures; those
        // are marked as merchant initiated in Payment/Init below.
        // https://dev.tuna.uy/api/payment-integration/#using-a-stored-credit-card
        $bind_response = tunapayment_bind_token(
            $tunaAccount,
            $tunaApptoken,
            $testMode,
            $sessionId,
            $remoteGatewayToken,
            $cardCvv
        );
        if (!$bind_response['success']) {
            return [
                'status' => 'error',
                'rawdata' => $bind_response,
            ];
        }
    }
    ;

    $isMerchantInitiated = !$tokenSingleUse && $cardCvv === '';

    $apiConfig = tunapayment_api_config('payment', 'Init', $testMode, $tunaAccount, $tunaApptoken);

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

    // Tuna Payment/Init step 3. Payment method "1" is CreditCard and the
    // overall status "2" means Captured. Payment status, rather than the more
    // granular method status, drives the WHMCS result below.
    // https://dev.tuna.uy/api/payment-integration/#step-3-request-for-the-payment
    // https://dev.tuna.uy/api/tuna-codes/#payment-status
    $paymentData = array(
        'paymentMethods' => [
            array(
                'paymentMethodType' => '1',
                'amount' => $amount,
                'installments' => 1,
                'cardInfo' => array(
                    'token' => $remoteGatewayToken,
                    'tokenProvider' => 'Tuna',
                    'cardHolderName' => $fullname,
                    'expirationMonth' => intval($expirationMonth),
                    'expirationYear' => intval($expirationYear),
                    'brandName' => $cardType,
                    'tokenSingleUse' => $tokenSingleUse ? 1 : 0,
                    'saveCard' => false,
                    'billingInfo' => array(
                        'document' => $documentnumber,
                        'documentType' => $documenttype,
                    ),
                ),
                "softDescriptor" => "Blymp",
            ),
        ],
        'deliveryAddress' => $deliveryAddress,
        "countryCode" => $countryCode,
        "amount" => $amount,
    );

    $postfields = [
        'tokenSession' => $sessionId,
        'partnerUniqueId' => $invoiceId,
        'customer' => $customer,
        'paymentItems' => $paymentItems,
        'paymentData' => $paymentData,
        // Tuna exposes this flag for transactions initiated by the merchant.
        // It is true only for WHMCS recurring captures where no CVV is supplied.
        'isMerchantInitiated' => $isMerchantInitiated,
    ];

    $apiResponse = tunapayment_api_request(
        $apiConfig['url'],
        $apiConfig['account'],
        $apiConfig['appToken'],
        $postfields,
        'Tuna Payment',
        'tunapayment_capture',
        'POST',
        tunapayment_idempotency_key('card', 'init', $invoiceId, $amount)
    );
    $data = $apiResponse['data'];

    if (!$apiResponse['success']) {
        return [
            'status' => 'error',
            'rawdata' => [
                'error' => $apiResponse['error'],
                'httpCode' => $apiResponse['httpCode'],
            ],
        ];
    }

    // perform API call to capture payment and interpret result
    // https://developers.whmcs.com/payment-gateways/tokenised-remote-storage/
    //  Parameter   Type	    Description
    //  status	    string	    One of either success, pending, declined
    //  declinereason	string	The reason for a decline
    //  transid	    string	    The Transaction ID returned by the payment gateway
    //  fee	        float	    The transaction fee returned by the payment gateway
    //  rawdata	    string or array	The raw data returned by the payment gateway for logging to the gateway log to aid in debugging
    //  gatewayid	string	    The token returned by the payment gateway

    if (isset($data->code) && (int) $data->code === 1) {
        $whmcsStatus = getWhmcsPaymentStatus(isset($data->status) ? $data->status : '');
        if (
            $whmcsStatus === 'success'
            && (!isset($data->paymentKey) || (string) $data->paymentKey === '')
        ) {
            $whmcsStatus = 'error';
        }
        if ($whmcsStatus === 'success') {
            $returnData = [
                // 'success' if successful, otherwise 'declined', 'error' for failure
                'status' => 'success',
                // Data to be recorded in the gateway log - can be a string or array
                'rawdata' => tunapayment_sanitize_log_data($data),
                // Unique Transaction ID for the capture transaction
                'transid' => isset($data->paymentKey) ? $data->paymentKey : '',
                // Return only if the token has updated or changed
                // 'gatewayid' => $data->token,
            ];
        } else {
            $returnData = [
                'status' => $whmcsStatus,
                // When not successful, a specific decline reason can be logged in the Transaction History
                'declinereason' => getStatusMessage(isset($data->status) ? $data->status : ''),
                // Data to be recorded in the gateway log - can be a string or array
                'rawdata' => tunapayment_sanitize_log_data($data),
            ];
        }
    } else {
        $returnData = [
            'status' => 'error',
            'rawdata' => tunapayment_sanitize_log_data($data),
            'code' => getTunaResponseMessage($data)
        ];
    }

    return $returnData;
}

/**
 * tunapayment_storeremote
 *
 * @param array $params Payment Gateway Module Parameters
 * 
 * @return array
 */
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
    $cardCvv = isset($params['cccvv']) ? (string) $params['cccvv'] : '';

    $expirationMonth = substr($cardexp, 0, 2);
    $expirationYear = "20" . substr($cardexp, 2, 2);

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
    $fullname = getFullName($params['clientdetails']['fullname'], $testMode);

    $session_response = tunapayment_session($tunaAccount, $tunaApptoken, $testMode, $userid, $email);
    if ($session_response['success']) {
        $sessionId = $session_response['session'];
    } else {
        return [
            // 'success' if successful, otherwise 'error' for failure
            'status' => 'error',
            // Data to be recorded in the gateway log - can be a string or array
            'rawdata' => $session_response,
        ];
    }

    switch ($action) {
        case 'create':
            // singleUse=false is required because WHMCS stores gatewayid and
            // reuses this Tuna token for future recurring captures.
            $token_response = tunapayment_generate_token($tunaAccount, $tunaApptoken, $testMode, $sessionId, $fullname, $cardnum, $expirationMonth, $expirationYear, $cardCvv, false);
            if ($token_response['success']) {
                return [
                    'status' => 'success',
                    'gatewayid' => $token_response['token'],
                ];
            } else {
                return [
                    // 'success' if successful, otherwise 'error' for failure
                    'status' => 'error',
                    // Data to be recorded in the gateway log - can be a string or array
                    'rawdata' => $token_response,
                ];
            }
        case 'update':
            // Token/Bind only validates a stored token with CVV; it does not
            // update PAN or expiry. Therefore a WHMCS card update creates a new
            // reusable token and removes the previous one afterwards.
            $token_response = tunapayment_generate_token(
                $tunaAccount,
                $tunaApptoken,
                $testMode,
                $sessionId,
                $fullname,
                $cardnum,
                $expirationMonth,
                $expirationYear,
                $cardCvv,
                false
            );
            if ($token_response['success']) {
                if ($gatewayid !== '') {
                    tunapayment_delete_token(
                        $tunaAccount,
                        $tunaApptoken,
                        $testMode,
                        $sessionId,
                        $gatewayid
                    );
                }
                return [
                    'status' => 'success',
                    'gatewayid' => $token_response['token'],
                ];
            } else {
                return [
                    // 'success' if successful, otherwise 'error' for failure
                    'status' => 'error',
                    // Data to be recorded in the gateway log - can be a string or array
                    'rawdata' => $token_response,
                ];
            }
        case 'delete':
            $delete_response = tunapayment_delete_token($tunaAccount, $tunaApptoken, $testMode, $sessionId, $gatewayid);
            if ($delete_response['success']) {
                return [
                    'status' => 'success',
                ];
            }

            return [
                'status' => 'error',
                'rawdata' => $delete_response,
            ];
    }
    return [
        'status' => 'error'
    ];
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
    $invoiceId = (string) $params['invoiceid'];
    $transactionIdToRefund = isset($params['transid']) ? (string) $params['transid'] : '';
    $refundAmount = (float) $params['amount'];
    if ($refundAmount <= 0) {
        return [
            'status' => 'error',
            'rawdata' => ['error' => 'Refund amount must be greater than zero'],
        ];
    }
    if ($transactionIdToRefund === '') {
        return [
            'status' => 'error',
            'rawdata' => ['error' => 'The original Tuna paymentKey is required'],
        ];
    }
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

    // Tuna calls refunds "cancellations". cardsDetail identifies the amount
    // and method to refund, while paymentKey identifies the original payment.
    // https://dev.tuna.uy/api/payment/#tag/default/operation/Cancel
    $apiConfig = tunapayment_api_config('payment', 'Cancel', $testMode, $tunaAccount, $tunaApptoken);

    // This gateway creates exactly one card method per payment, so methodId 0
    // is the method being refunded. Split-payment refunds are out of scope.
    $cardsDetail = [
        array(
            'amount' => $refundAmount,
            'methodId' => 0,
        )
    ];

    $postfields = [
        'cardsDetail' => $cardsDetail,
        'paymentKey' => $transactionIdToRefund,
        'partnerUniqueID' => (string) $invoiceId,
    ];

    $apiResponse = tunapayment_api_request(
        $apiConfig['url'],
        $apiConfig['account'],
        $apiConfig['appToken'],
        $postfields,
        'Tuna Payment',
        'tunapayment_refund'
    );
    $data = $apiResponse['data'];

    if (!$apiResponse['success']) {
        return [
            'status' => 'error',
            'rawdata' => [
                'error' => $apiResponse['error'],
                'httpCode' => $apiResponse['httpCode'],
            ],
        ];
    }

    // perform API call to initiate refund and interpret result

    if (isset($data->code) && (int) $data->code === 1) {
        // 3 = fully refunded; 9 = partially refunded.
        if (
            isset($data->status, $data->operationId)
            && in_array((string) $data->status, ['3', '9'], true)
            && (string) $data->operationId !== ''
        ) {
            return array(
                // 'success' if successful, otherwise 'declined', 'error' for failure
                'status' => 'success',
                // Data to be recorded in the gateway log - can be a string or array
                'rawdata' => tunapayment_sanitize_log_data($data),
                // Unique Transaction ID for the refund transaction
                // Cancel returns a new operationId for the refund operation.
                'transid' => $data->operationId,
            );

        }
    }
    ;
    return array(
        'status' => 'error',
        'rawdata' => tunapayment_sanitize_log_data($data),
    );
}

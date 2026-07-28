<?php

/**
 * WHMCS Tuna Pix Payment Gateway Module
 */

require_once __DIR__ . '/tunapayment/tunapaymenthelper.php';

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$tunapix_Description = 'Tuna Pix';
$tunapix_Version = '1.0.0';

function tunapix_MetaData()
{
    return [
        'DisplayName' => 'Tuna Payment Pix',
        'APIVersion' => '1.1',
    ];
}

function tunapix_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'Tuna Payment Gateway Module Pix',
        ],
        'tunaAccount' => [
            'FriendlyName' => 'Tuna Account',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Enter your Tuna Account here',
        ],
        'tunaApptoken' => [
            'FriendlyName' => 'Tuna App Token',
            'Type' => 'password',
            'Size' => '36',
            'Default' => '',
            'Description' => 'Enter Tuna App Token here',
        ],
        'testMode' => [
            'FriendlyName' => 'Test Environment',
            'Type' => 'yesno',
            'Description' => 'Tick to enable test environment',
        ],
    ];
}

/**
 * Escape a value for use in the invoice HTML.
 *
 * @param mixed $value
 * @return string
 */
function tunapix_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Render the server-side Pix creation form.
 *
 * Submitting to the current WHMCS invoice page keeps Tuna credentials on the
 * server and avoids relying on PHP globals across different HTTP requests.
 *
 * @param array $params
 * @return string
 */
function tunapix_payment_form(array $params)
{
    $action = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
    $csrf = function_exists('generate_token') ? generate_token('plain') : '';

    $html = '<form method="post" action="' . tunapix_escape($action) . '">';
    $html .= '<input type="hidden" name="tunapix_invoice_id" value="' . tunapix_escape($params['invoiceid']) . '">';
    if ($csrf !== '') {
        $html .= '<input type="hidden" name="token" value="' . tunapix_escape($csrf) . '">';
    }
    $html .= '<button type="submit">' . tunapix_escape($params['langpaynow']) . '</button>';
    $html .= '</form>';

    return $html;
}

/**
 * Render Pix QR data returned by Tuna.
 *
 * @param object $data
 * @return string
 */
function tunapix_payment_details($data)
{
    $method = isset($data->methods[0]) ? $data->methods[0] : null;
    $pixInfo = $method && isset($method->pixInfo) ? $method->pixInfo : null;

    if (!$pixInfo) {
        return '<p>El pago Pix fue creado, pero Tuna no devolvió los datos del código QR.</p>';
    }

    // The Payment/Init response schema currently spells these fields qRImage
    // and qRContent, while Tuna's example response uses qrImage/qrContent.
    // Accept both variants to remain compatible with the documented schema
    // and the real example payload.
    // https://dev.tuna.uy/api/payment/
    $qrImage = isset($pixInfo->qrImage) ? $pixInfo->qrImage : (isset($pixInfo->qRImage) ? $pixInfo->qRImage : '');
    $copyPaste = isset($pixInfo->qrCopyPaste) ? $pixInfo->qrCopyPaste : '';
    $qrContent = isset($pixInfo->qrContent) ? $pixInfo->qrContent : (isset($pixInfo->qRContent) ? $pixInfo->qRContent : '');
    if ($copyPaste === '') {
        $copyPaste = $qrContent;
    }

    $html = '<div class="tunapix-payment">';
    $html .= '<p>Escaneá el código QR o copiá el código Pix para completar el pago.</p>';

    if (
        $qrImage !== ''
        && filter_var($qrImage, FILTER_VALIDATE_URL)
        && strtolower((string) parse_url($qrImage, PHP_URL_SCHEME)) === 'https'
    ) {
        $html .= '<img src="' . tunapix_escape($qrImage) . '" alt="Código QR Pix" style="max-width:280px;height:auto">';
    }

    if ($copyPaste !== '') {
        $html .= '<label for="tunapix-copy-paste">Pix copia y pega</label>';
        $html .= '<textarea id="tunapix-copy-paste" readonly rows="4" style="width:100%">'
            . tunapix_escape($copyPaste)
            . '</textarea>';
    }

    $html .= '</div>';
    return $html;
}

/**
 * Create or display a Pix payment for a WHMCS invoice.
 *
 * @param array $params Payment Gateway Module Parameters
 * @return string
 */
function tunapix_link($params)
{
    $invoiceId = (string) $params['invoiceid'];
    $amount = (float) $params['amount'];
    if ($amount <= 0) {
        return '<p>No se puede crear el pago Pix: el importe debe ser mayor que cero.</p>';
    }
    $requestedInvoiceId = isset($_POST['tunapix_invoice_id'])
        ? (string) $_POST['tunapix_invoice_id']
        : '';
    $cacheKey = hash('sha256', $invoiceId . '|' . (string) $params['amount']);

    if (
        isset($_SESSION['tunapix_payments'][$cacheKey]['createdAt'])
        && $_SESSION['tunapix_payments'][$cacheKey]['createdAt'] >= time() - 1800
        && isset($_SESSION['tunapix_payments'][$cacheKey]['data'])
    ) {
        // Re-render the same QR instead of issuing another Payment/Init with
        // the invoice partnerUniqueId. This also avoids duplicate operations
        // when WHMCS renders the invoice more than once in the same session.
        $cachedData = json_decode(json_encode($_SESSION['tunapix_payments'][$cacheKey]['data']));
        return tunapix_payment_details($cachedData);
    }

    if ($requestedInvoiceId !== $invoiceId) {
        return tunapix_payment_form($params);
    }

    $client = $params['clientdetails'];
    // Tuna expects an ISO-2 countryCode. Prefer WHMCS's customer country and
    // infer it from the invoice currency only when the customer value is bad.
    $countryCode = strtoupper((string) $client['country']);
    if (!preg_match('/^[A-Z]{2}$/', $countryCode)) {
        $countryCode = getCurrency2($params['currency']);
    }
    if (!is_string($countryCode) || !preg_match('/^[A-Z]{2}$/', $countryCode)) {
        return '<p>No se puede crear el pago Pix: el país del cliente no es válido.</p>';
    }
    $fullname = getFullName($client['fullname'], $params['testMode']);
    $documentType = isset($params['customfield']['documenttype'])
        ? $params['customfield']['documenttype']
        : '';
    $documentNumber = isset($params['customfield']['documentnumber'])
        ? $params['customfield']['documentnumber']
        : '';

    if ($documentType === '' || $documentNumber === '') {
        return '<p>No se puede crear el pago Pix: faltan el tipo o el número de documento del cliente.</p>';
    }

    // Tuna documents PIX as a direct Payment/Init request: method "D" and no
    // tokenSession are required. The initial payment normally remains pending
    // until Tuna later sends a captured ("2") webhook.
    // https://dev.tuna.uy/api/payment-integration/#direct-request
    // https://dev.tuna.uy/api/tuna-codes/#payment-methods
    $postfields = [
        'partnerUniqueId' => $invoiceId,
        'customer' => [
            'id' => (string) $client['id'],
            'email' => $client['email'],
            'document' => (string) $documentNumber,
            'documentType' => $documentType,
            'name' => $fullname,
        ],
        'paymentItems' => [
            'items' => [[
                'amount' => $amount,
                'detailUniqueId' => $invoiceId,
                'productDescription' => $params['description'],
                'itemQuantity' => 1,
            ]],
        ],
        'paymentData' => [
            'paymentMethods' => [[
                'paymentMethodType' => 'D',
                'amount' => $amount,
                'pix' => [
                    'name' => $fullname,
                    'document' => (string) $documentNumber,
                    'documentType' => $documentType,
                ],
            ]],
            'deliveryAddress' => [
                'street' => $client['address1'],
                'number' => $client['address2'],
                'neighborhood' => $client['city'],
                'city' => $client['city'],
                'state' => $client['state'],
                'postalCode' => $client['postcode'],
                'phone' => $client['phonenumber'],
                'country' => $client['country'],
            ],
            'countryCode' => $countryCode,
        ],
    ];

    $apiConfig = tunapayment_api_config(
        'payment',
        'Init',
        $params['testMode'],
        $params['tunaAccount'],
        $params['tunaApptoken']
    );
    $response = tunapayment_api_request(
        $apiConfig['url'],
        $apiConfig['account'],
        $apiConfig['appToken'],
        $postfields,
        'Tuna Payment Pix',
        'tunapix_link',
        'POST',
        tunapayment_idempotency_key('pix', 'init', $invoiceId, $amount)
    );
    $data = $response['data'];

    if (!$response['success']) {
        return '<p>No se pudo contactar a Tuna para crear el pago Pix. Intentá nuevamente.</p>';
    }

    if (!isset($data->code) || (int) $data->code !== 1) {
        return '<p>No se pudo crear el pago Pix: '
            . tunapix_escape(getTunaResponseMessage($data))
            . '</p>';
    }

    $_SESSION['tunapix_payments'][$cacheKey] = [
        'createdAt' => time(),
        'data' => json_decode(json_encode($data), true),
    ];

    return tunapix_payment_details($data);
}

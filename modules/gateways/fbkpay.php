<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

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
function fbkpay_MetaData()
{
    return array(
        'DisplayName' => 'Fubuki Pay WHMCS Gateway Module',
        'APIVersion' => '1.1', // Use API Version 1.1
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
 * Supported field types include:
 * * text
 * * password
 * * yesno
 * * dropdown
 * * radio
 * * textarea
 *
 * Examples of each field type and their possible configuration parameters are
 * provided in the sample function below.
 *
 * @return array
 */
function fbkpay_config()
{
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Fubuki Pay',
        ),
        'access_key' => array(
            'FriendlyName' => 'Your Access Key',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Enter your access key here',
        ),
        'secret_key' => array(
            'FriendlyName' => 'Your Secret Key',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Enter your secret key here',
        ),
        'fees_payer' => array(
            'FriendlyName' => 'Fees Setting',
            'Type' => 'dropdown',
            'Size' => '25',
            'Default' => '1',
            'Options' => array(
                '1' => 'Merchant pay the fees',
                '2' => 'Customer pay the fees',
            ),
        ),
        'debug' => array(
            'FriendlyName' => 'Debug Mode',
            'Type' => 'dropdown',
            'Size' => '25',
            'Default' => '0',
            'Options' => array(
                '0' => 'OFF',
                '1' => 'ON',
            ),
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
function fbkpay_link($params)
{
    // Gateway Configuration Parameters
    $key = $params['access_key'];
    $secretKey = $params['secret_key'];
    $fees_payer = $params['fees_payer'];
    $debug = empty($params['debug']) ? false : true;

    $invoiceId = $params['invoiceid'];
    $description = $params["description"];
    $amount = $params['amount'];
    $currencyCode = $params['currency'];

    try {
        if (!in_array($currencyCode, ['USD', 'CNY'])) {
            throw new Exception('Unsupported Currency');
        }

        // System Parameters
        $companyName = $params['companyname'];
        $systemUrl = $params['systemurl'];
        $returnUrl = $params['returnurl'];
        $langPayNow = $params['langpaynow'];
        $moduleDisplayName = $params['name'];
        $moduleName = $params['paymentmethod'];
        $whmcsVersion = $params['whmcsVersion'];
        if (empty($_SESSION['invoice'][$invoiceId])) {
            $postData = array();
            $postData['base_currency'] = $currencyCode;
            $postData['amount'] = $amount;
            $postData['fees_payer'] = $fees_payer;
            $postData['merchant_tradeno'] = substr(strtoupper(md5($companyName)), 0, 12) . '_' . $invoiceId;
            $postData['title'] = $description;
            $postData['notify_url'] = rtrim($systemUrl, '/') . '/modules/gateways/callback/fbkpay.php';
            $uri = '/v1/invoice/create?ts=' . time() . '&nonce=' . md5(random_bytes(16));
            $sign = sign($uri, $secretKey);
            $postData['return_url'] = $returnUrl;;
            $headers[] = 'X-Access-Key:' . $key;
            $headers[] = 'X-Signature:' . $sign;
            $ch = curl_init('https://api.fubuki.us' . $uri);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $postData,
                CURLOPT_USERAGENT => getUserAgent(),
                CURLOPT_TIMEOUT => 15
            ));
            $rsp = curl_exec($ch);
            if (!empty(curl_error($ch))) {
                throw new Exception(curl_error($ch));
            }
            curl_close($ch);
            $rspjson = json_decode($rsp, true);
            if ($rspjson['code'] != 0) {
                throw new Exception($rspjson['msg']);
            }
            $_SESSION['invoice'][$invoiceId]['url'] = $rspjson['data']['payment_url'];
            $_SESSION['invoice'][$invoiceId]['amount'] = $amount;
        } else {
            if ($_SESSION['invoice'][$invoiceId]['amount'] != $amount) {
                unset($_SESSION['invoice'][$invoiceId]);
                header('location: ' . $_SERVER['HTTP_REFERER']);
            }
        }
    } catch (Throwable $e) {
        if ($debug) {
            return 'Error: ' . $e->getMessage();
        } else {
            return 'An error has occurred. Submit a ticket for help if you need.';
        }
    }

    return '<button type="button" onclick="window.location.href=\'' . $_SESSION['invoice'][$invoiceId]['url'] . '\';">Pay Now</button>';
}

/**
 * Make Signature
 * 
 * @param string $uri 
 * @param string $secret_key 
 * @return string
 * @throws Exception 
 * 
 * @see https://docs.fubuki.us/Signature
 */
function sign(string $data, string $secret_key)
{
    $signature = hash_hmac('SHA3-384', $data, $secret_key);
    return $signature;
}

function getUserAgent()
{
    return 'FubukiPay-WHMCS-Module/1.0.0';
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
function fbkpay_refund($params)
{
    return array(
        // 'success' if successful, otherwise 'declined', 'error' for failure
        'status' => 'error',
        // Data to be recorded in the gateway log - can be a string or array
        'rawdata' => 'unsupported',
        // Unique Transaction ID for the refund transaction
        'transid' => null
    );
}

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
    header('HTTP/1.1 501 Module Not Activated');
    die("Module Not Activated");
}

try {
    $success = 'Success';
    $tmp = explode('_', $_POST["merchant_tradeno"], 2);
    if (empty($tmp[1])) {
        throw new Exception('Invalid Trade Number', 404);
    }
    $invoiceId = $tmp[1];
    $transactionId = $_POST["serial"];
    $paymentAmount = $_POST["amount"];
    $paymentFee = 0;
    $transactionStatus = $success ? 'Success' : 'Failure';
    $headers = $_SERVER;
    $remote_access_key = $headers['HTTP_X_ACCESS_KEY'];
    $notify_key = $headers['HTTP_X_NOTIFY_KEY'];
    $remote_signature = $headers['HTTP_X_SIGNATURE'];

    $accessKey = $gatewayParams['access_key'];
    $secretKey = $gatewayParams['secret_key'];

    if ($remote_access_key != $accessKey) {
        throw new Exception('access key missmatch. i am using (' . $accessKey . ')', 400);
    }

    $url = rtrim($CONFIG['SystemURL'], '/') . '/modules/gateways/callback/fbkpay.php';
    $sign = sign($url, $secretKey);

    if (strcasecmp($remote_signature, $sign) !== 0) {
        throw new Exception('signature key missmatch. i am using (' . $url . ')', 401);
    }

    $invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);
    checkCbTransID($transactionId);
    logTransaction($gatewayParams['name'] . ' (' . $_POST['wallet'] . ')', $_POST, $transactionStatus);
    addInvoicePayment(
        $invoiceId,
        $transactionId,
        $paymentAmount,
        $paymentFee,
        $gatewayModuleName
    );
} catch (Throwable $e) {
    header('HTTP/1.1 ' . $e->getCode());
    header('Content-Type: text/plain');
    die($e->getMessage());
}

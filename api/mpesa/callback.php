<?php
// 1. Include core configuration and database helper
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/helpers.php';

// 2. Capture the JSON data from Safaricom
$callbackJSONData = file_get_contents('php://input');
$logFile = "mpesa_responses.log"; // For debugging
file_put_contents($logFile, $callbackJSONData . PHP_EOL, FILE_APPEND);

$data = json_decode($callbackJSONData, true);

if (!$data) {
    echo json_encode(["status" => "error", "message" => "Invalid data"]);
    exit;
}

$resultCode = $data['Body']['stkCallback']['ResultCode'];
$merchantRequestID = $data['Body']['stkCallback']['MerchantRequestID'];
$checkoutRequestID = $data['Body']['stkCallback']['CheckoutRequestID'];

try {
    if ($resultCode == 0) {
        // Payment was successful
        $callbackMetadata = $data['Body']['stkCallback']['CallbackMetadata']['Item'];
        
        $amount = 0;
        $mpesaReceipt = "";
        $phoneNumber = "";

        foreach ($callbackMetadata as $item) {
            if ($item['Name'] === 'Amount') $amount = $item['Value'];
            if ($item['Name'] === 'MpesaReceiptNumber') $mpesaReceipt = $item['Value'];
            if ($item['Name'] === 'PhoneNumber') $phoneNumber = $item['Value'];
        }

        // Update mpesa_transactions table
        DB::query(
            "UPDATE mpesa_transactions 
             SET mpesa_receipt = ?, status = 'Success', confirmed_at = NOW() 
             WHERE daraja_ref = ?", 
            [$mpesaReceipt, $checkoutRequestID]
        );

        // Update corresponding online_order status to 'Confirmed'
        // This assumes you stored the CheckoutRequestID in the description or a custom column
        DB::query(
            "UPDATE online_orders 
             SET status = 'Confirmed' 
             WHERE order_no = (SELECT description FROM mpesa_transactions WHERE daraja_ref = ? LIMIT 1)",
            [$checkoutRequestID]
        );

    } else {
        // Payment failed or was cancelled
        DB::query(
            "UPDATE mpesa_transactions SET status = 'Failed' WHERE daraja_ref = ?",
            [$checkoutRequestID]
        );
    }
} catch (Exception $e) {
    file_put_contents("error.log", $e->getMessage() . PHP_EOL, FILE_APPEND);
}

// Respond to Safaricom
echo json_encode(["ResultCode" => 0, "ResultDesc" => "Success"]);
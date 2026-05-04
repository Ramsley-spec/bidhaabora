<?php
// ================================================================
//  modules/MpesaModel.php — Daraja API Integration
// ================================================================
class MpesaModel {
    private static function getAccessToken(): string {
        $credentials = base64_encode(DARAJA_CONSUMER_KEY.':'.DARAJA_CONSUMER_SECRET);
        $ch = curl_init(DARAJA_AUTH_URL);
        curl_setopt_array($ch,[
            CURLOPT_HTTPHEADER  => ["Authorization: Basic $credentials"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => DARAJA_ENV==='production',
        ]);
        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);
        return $res['access_token'] ?? '';
    }

    public static function stkPush(string $phone, float $amount, string $poNumber, string $description=''): array {
        $token     = self::getAccessToken();
        $timestamp = date('YmdHis');
        $password  = base64_encode(DARAJA_SHORTCODE.DARAJA_PASSKEY.$timestamp);
        $phone     = '254'.ltrim($phone,'0+');
        $payload   = [
            'BusinessShortCode' => DARAJA_SHORTCODE,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => 'CustomerPayBillOnline',
            'Amount'            => (int)$amount,
            'PartyA'            => $phone,
            'PartyB'            => DARAJA_SHORTCODE,
            'PhoneNumber'       => $phone,
            'CallBackURL'       => DARAJA_CALLBACK_URL,
            'AccountReference'  => $poNumber,
            'TransactionDesc'   => $description ?: "Payment for $poNumber",
        ];
        $ch = curl_init(DARAJA_STK_URL);
        curl_setopt_array($ch,[
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token","Content-Type: application/json"],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => DARAJA_ENV==='production',
        ]);
        $res = json_decode(curl_exec($ch), true);
        curl_close($ch);
        if (!empty($res['CheckoutRequestID'])) {
            DB::query("INSERT INTO mpesa_transactions(phone_number,amount,description,daraja_ref,status) VALUES(?,?,?,?,'Pending')",
                [$phone,$amount,$description,$res['CheckoutRequestID']]);
        }
        return $res;
    }

    public static function handleCallback(array $data): void {
        $result = $data['Body']['stkCallback'] ?? [];
        $ref    = $result['CheckoutRequestID'] ?? null;
        $code   = $result['ResultCode'] ?? -1;
        if (!$ref) return;
        if ($code === 0) {
            $items   = $result['CallbackMetadata']['Item'] ?? [];
            $receipt = '';$amount=0;
            foreach ($items as $item){
                if ($item['Name']==='MpesaReceiptNumber') $receipt=$item['Value'];
                if ($item['Name']==='Amount') $amount=$item['Value'];
            }
            DB::query("UPDATE mpesa_transactions SET status='Success',mpesa_receipt=?,confirmed_at=NOW() WHERE daraja_ref=?",[$receipt,$ref]);
        } else {
            DB::query("UPDATE mpesa_transactions SET status='Failed' WHERE daraja_ref=?",[$ref]);
        }
    }
    public static function getAll(): array {
        return DB::fetchAll("SELECT * FROM mpesa_transactions ORDER BY created_at DESC");
    }
}

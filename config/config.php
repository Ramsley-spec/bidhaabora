<?php
// ================================================================
//  config/config.php — BidhaaBora Central Configuration
// ================================================================

define('APP_NAME',     'BidhaaBora');
define('APP_VERSION',  '2.0.0');
define('APP_URL',      'http://localhost/bidhaabora/web/');  // Change in production
define('CURRENCY',     'KSh');
define('VAT_RATE',     0.16);
define('TIMEZONE',     'Africa/Nairobi');
define('ITEMS_PER_PAGE', 25);

// Contact
define('SUPPORT_PHONE',  '0711 011 011');
define('SUPPORT_EMAIL',  'hello@bidhaabora.co.ke');
define('STORE_ADDRESS',  'Green Commercial Compound, Thika Road, Nairobi');

date_default_timezone_set(TIMEZONE);

if (!isset($_SESSION)) session_start();

// ── DATABASE ──────────────────────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_NAME',    'bidhaabora');
define('DB_USER',    'root');
define('DB_PASS',    '');           // Change in production
define('DB_CHARSET', 'utf8mb4');

// ── UPLOAD PATHS ──────────────────────────────────────────────────
define('ROOT_PATH',   dirname(__DIR__));
define('UPLOAD_PATH', ROOT_PATH . '/public/uploads/');
define('UPLOAD_URL',  APP_URL   . '/public/uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB

// ── DARAJA M-PESA API ─────────────────────────────────────────────
define('DARAJA_ENV',            'sandbox');  // 'sandbox' or 'production'
define('DARAJA_CONSUMER_KEY',   'YOUR_CONSUMER_KEY_HERE');
define('DARAJA_CONSUMER_SECRET','YOUR_CONSUMER_SECRET_HERE');
define('DARAJA_SHORTCODE',      '174379');   // Sandbox default
define('DARAJA_PASSKEY',        'YOUR_PASSKEY_HERE');
define('DARAJA_CALLBACK_URL',   APP_URL . '/api/mpesa/callback.php');
define('DARAJA_STK_URL',        DARAJA_ENV === 'sandbox'
    ? 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest'
    : 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest');
define('DARAJA_AUTH_URL',       DARAJA_ENV === 'sandbox'
    ? 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials'
    : 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials');

// ── ERROR HANDLING ────────────────────────────────────────────────
if (APP_ENV === 'development') {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

define('APP_ENV', 'development'); // 'production' in production
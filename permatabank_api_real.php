<?php
/**
 * ============================================================
 * Permata Bank SNAP API - Single File (PRODUCTION REAL)
 * ============================================================
 * Sumber   : permata-switching_v1.0_pro (reverse-engineering)
 * Endpoint : http://switching.mcoll.sis1.net
 *
 * TEMUAN PENTING DARI SOURCE CODE (func.h2h.mod.php):
 * ====================================================
 * 1. cekAuthorizationToken() untuk /b2b/token:
 *    - HANYA cek apakah x-client-key ada di tabel agen_apigateway_supplier.VAClientKey
 *    - TIDAK ada validasi x-signature pada endpoint token
 *    - 401XX00 = x-client-key tidak ditemukan di database
 *
 * 2. client-secret dari CDS diproses dengan:
 *    $cClientSecret = str_replace(' ', '+', $vaApiKey['client-secret']);
 *    → spasi dalam Base64 diganti '+' sebelum dipakai sebagai HMAC key (raw bytes)
 *
 * 3. checkSignature() untuk inquiry/payment:
 *    $secretkey dipakai langsung (raw) ke hash_hmac('sha512', ...)
 *    Jika client-secret Base64-encoded, HARUS base64_decode dulu
 *
 * !! PERINGATAN KEAMANAN - BACKDOOR DITEMUKAN !!
 * ===============================================
 * eval(base64_decode('...')) di b2b.controller.php & inquiry.controller.php
 * → mengirim IP+URL server ke Telegram bot penyerang
 * Bot: 8303943197:AAGCFO1EuotDeoyXnRpSNsMiFSCddm6UlQ4 | Chat: 590436982
 * HAPUS baris eval() sebelum deploy!
 * ============================================================
 */

// ==============================================================
// ======= KONFIGURASI PROFIL (dari agen.sql + agen_apigateway_supplier.sql)
// ==============================================================

// Pilih profil aktif: 'sekar_kaltim' | 'anjuk_ladang' | 'supplier0025' | 'custom'
$_ACTIVE_PROFILE = 'supplier0025';

$_PROFILES = [

    // --------------------------------------------------------
    // A-000115 | BPR Sekar Kaltim | Supplier 0023
    // Credential lengkap dari agen.ApiKeyMitra JSON
    // --------------------------------------------------------
    'sekar_kaltim' => [
        'base_url'      => 'http://switching.mcoll.sis1.net',
        'client_key'    => 'acb790af57ed465ba03b00f5f07dd65d',
        // Dari ApiKeyMitra JSON, str_replace(' ', '+', ...) sudah diterapkan di getClientSecret()
        'client_secret' => 'jaMC0vw0LJLXkrZGSjf8LCExETh0j7JI5bQ4bpPmbHU=',
        'partner_id'    => 'A-000115',
        'channel_id'    => '95221',
        'bin'           => '7406',
        'supplier_code' => '0023',
        'supplier_name' => 'SNAP VA Permata Sekar Kaltim',
        'api_key_va'    => '73b834a9571d7491400170b845cce1b7',
    ],

    // --------------------------------------------------------
    // A-000268 | Supplier 0021 | VAClientKey dari ID=3
    // --------------------------------------------------------
    'anjuk_ladang' => [
        'base_url'      => 'http://switching.mcoll.sis1.net',
        'client_key'    => 'b0a37cccc6d680f8f87abe76eeda2c4e',
        'client_secret' => 'GANTI_CLIENT_SECRET',
        'partner_id'    => 'A-000268',
        'channel_id'    => '95221',
        'bin'           => '',
        'supplier_code' => '0021',
        'supplier_name' => 'SNAP VA Permata Anjuk Ladang',
        'api_key_va'    => '',
    ],

    // --------------------------------------------------------
    // A-000300 | Supplier 0025 | VAClientKey diperbarui
    // --------------------------------------------------------
    'supplier0025' => [
        'base_url'      => 'http://switching.mcoll.sis1.net',
        'client_key'    => 'b779f2aeaa628a251684696c12a3d403',
        'client_secret' => 'GANTI_CLIENT_SECRET',
        'partner_id'    => 'A-000300',
        'channel_id'    => '95221',
        'bin'           => '',
        'supplier_code' => '0025',
        'supplier_name' => 'Permata SNAP BI — A-000300',
        'api_key_va'    => '',
    ],

    // --------------------------------------------------------
    // Custom — isi manual
    // --------------------------------------------------------
    'custom' => [
        'base_url'      => 'http://switching.mcoll.sis1.net',
        'client_key'    => 'GANTI_CLIENT_KEY',
        'client_secret' => 'GANTI_CLIENT_SECRET',
        'partner_id'    => 'A-XXXXXX',
        'channel_id'    => '95221',
        'bin'           => 'BIN_4_DIGIT',
        'supplier_code' => '0025',
        'supplier_name' => 'Custom Supplier',
        'api_key_va'    => '',
    ],
];

$_P = $_PROFILES[$_ACTIVE_PROFILE] ?? $_PROFILES['custom'];

define('SNAP_BASE_URL',      $_P['base_url']);
define('SNAP_CLIENT_KEY',    $_P['client_key']);
define('SNAP_CLIENT_SECRET', $_P['client_secret']);
define('SNAP_PARTNER_ID',    $_P['partner_id']);
define('SNAP_CHANNEL_ID',    $_P['channel_id']);
define('SNAP_BIN',           $_P['bin']);
define('SNAP_API_KEY_VA',    $_P['api_key_va']);
define('SNAP_SUPPLIER_CODE', $_P['supplier_code']);
define('SNAP_SUPPLIER_NAME', $_P['supplier_name']);

// Endpoint lengkap (dari routing MVC source code)
define('URL_TOKEN',   SNAP_BASE_URL . '/permata-switching_v1.0_pro/public/access-token/b2b');
define('URL_INQUIRY', SNAP_BASE_URL . '/permata-switching_v1.0_pro/public/transfer-va/inquiry');
define('URL_PAYMENT', SNAP_BASE_URL . '/permata-switching_v1.0_pro/public/transfer-va/payment');

// Path SNAP BI untuk string2sign signature (bukan URL penuh)
define('SNAP_PATH_INQUIRY', '/v1.0/transfer-va/inquiry');
define('SNAP_PATH_PAYMENT', '/v1.0/transfer-va/payment');

// ==============================================================
// ==================== TOKEN CACHE =============================
// ==============================================================
$_TOKEN_CACHE = ['access_token' => '', 'expires_at' => 0];

// ==============================================================
// ==================== HELPER FUNCTIONS ========================
// ==============================================================

/**
 * Timestamp ISO8601 — format persis seperti date("c") di PHP
 */
function snapTimestamp(): string
{
    return date('c');
}

/**
 * Generate UUID v4 untuk X-EXTERNAL-ID dan request ID
 */
function snapExternalId(): string
{
    return sprintf(
        '%08x-%04x-4%03x-%04x-%012x',
        time(),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff),
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffffffffffff)
    );
}

/**
 * Minify JSON — sama dengan minify() di func.mod.php:
 *   function minify($code) { return json_encode(json_decode($code)); }
 */
function minifyJson(string $json): string
{
    return json_encode(json_decode($json));
}

/**
 * Ambil client secret dalam format yang siap dipakai sebagai HMAC key.
 *
 * Dari source code func.h2h.mod.php:
 *   $cClientSecret = str_replace(' ', '+', $vaApiKey['client-secret']);
 *   → lalu dipakai langsung (raw Base64 string) ke hash_hmac('sha512', ..., $cClientSecret, true)
 *
 * Catatan: hash_hmac menerima secret sebagai raw string.
 * Jika secret adalah Base64-encoded bytes, server menggunakannya AS-IS (bukan di-decode).
 * Kita ikuti persis: str_replace(' ', '+') saja.
 */
function getClientSecret(): string
{
    return str_replace(' ', '+', SNAP_CLIENT_SECRET);
}

/**
 * Signature untuk /b2b/token
 *
 * Dari cekAuthorizationToken(): server TIDAK memvalidasi x-signature untuk token.
 * Header ini tetap dikirim (standar SNAP BI) tapi server hanya cek x-client-key di DB.
 *
 * Formula standar SNAP BI:
 *   message   = clientKey + "|" + timestamp
 *   signature = Base64( HMAC-SHA256( message, clientSecret ) )
 */
function genTokenSignature(string $clientKey, string $timestamp, string $clientSecret): string
{
    $message = $clientKey . '|' . $timestamp;
    return base64_encode(hash_hmac('sha256', $message, $clientSecret, true));
}

/**
 * Signature untuk /inquiry dan /payment
 *
 * Persis dari checkSignature() di func.h2h.mod.php:
 *   $stringmbulet = strtolower( hash('sha256', minify($request)) )
 *   $string2sign  = METHOD:path:accessToken:stringmbulet:timestamp
 *   return base64_encode(hash_hmac("sha512", $string2sign, $secretkey, true))
 *
 * PENTING: $secretkey dipakai raw (str_replace(' ','+', base64string))
 */
function genSnapSignature(
    string $method,
    string $snapPath,
    string $accessToken,
    string $requestBody,
    string $timestamp,
    string $clientSecret
): string {
    $bodyHash    = strtolower(hash('sha256', minifyJson($requestBody)));
    $string2sign = implode(':', [
        strtoupper($method),
        $snapPath,
        $accessToken,
        $bodyHash,
        $timestamp,
    ]);
    return base64_encode(hash_hmac('sha512', $string2sign, $clientSecret, true));
}

/**
 * HTTP POST via cURL
 */
function httpPost(string $url, array $headers, string $body): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER,     $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT,        30);
    curl_setopt($ch, CURLOPT_HEADER,         false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    return [
        'http_code' => $httpCode,
        'response'  => $response ?: '',
        'error'     => $curlError,
    ];
}

// ==============================================================
// ===================== FUNGSI UTAMA API =======================
// ==============================================================

/**
 * generateToken() — raw HTTP request ke /access-token/b2b
 *
 * TEMUAN SOURCE CODE (cekAuthorizationToken):
 *  - Server HANYA cek x-client-key ada di agen_apigateway_supplier.VAClientKey
 *  - x-signature TIDAK divalidasi (kode validasi ada tapi di-comment out)
 *  - 401XX00 = x-client-key tidak ada di database
 *
 * Gunakan getToken() untuk pemakaian normal (dengan cache otomatis).
 */
function generateToken(): array
{
    $timestamp = snapTimestamp();
    $clientKey = SNAP_CLIENT_KEY;
    $clientSec = getClientSecret();
    $url       = URL_TOKEN;

    // Signature dikirim meski server tidak validasi (standar SNAP BI)
    $signature = genTokenSignature($clientKey, $timestamp, $clientSec);

    $bodyArray = ['grantType' => 'client_credentials'];
    $jsonBody  = json_encode($bodyArray, JSON_UNESCAPED_UNICODE);

    $headers = [
        'Content-Type: application/json',
        'X-CLIENT-KEY: ' . $clientKey,
        'X-TIMESTAMP: '  . $timestamp,
        'X-SIGNATURE: '  . $signature,
    ];

    $debug = [
        'url'         => $url,
        'timestamp'   => $timestamp,
        'client_key'  => $clientKey,
        'sig_message' => $clientKey . '|' . $timestamp,
        'x_signature' => $signature,
        'body'        => $jsonBody,
        'note'        => 'Server cek x-client-key di agen_apigateway_supplier.VAClientKey, 401XX00 = key tidak ditemukan',
    ];

    $result = httpPost($url, $headers, $jsonBody);

    if ($result['error']) {
        return [
            'success'        => false,
            'error'          => 'cURL error: ' . $result['error'],
            'http_code'      => $result['http_code'],
            'raw'            => '',
            'debug'          => $debug,
            'access_token'   => '',
            'expires_in'     => 0,
        ];
    }

    $decoded = json_decode($result['response'], true);
    $rc      = $decoded['responseCode']    ?? '';
    $msg     = $decoded['responseMessage'] ?? '';

    if (!isset($decoded['accessToken'])) {
        return [
            'success'        => false,
            'error'          => "[$rc] $msg",
            'http_code'      => $result['http_code'],
            'raw'            => $result['response'],
            'debug'          => $debug,
            'access_token'   => '',
            'expires_in'     => 0,
        ];
    }

    return [
        'success'        => true,
        'access_token'   => $decoded['accessToken'],
        'token_type'     => $decoded['tokenType']  ?? 'Bearer',
        'expires_in'     => (int)($decoded['expiresIn'] ?? 900),
        'response_code'  => $rc,
        'http_code'      => $result['http_code'],
        'raw'            => $result['response'],
        'error'          => '',
        'debug'          => $debug,
    ];
}

/**
 * getToken() — ambil token dengan cache otomatis
 *
 * - Cache valid → pakai cache (tidak request ulang)
 * - Cache expired/kosong → generateToken() baru
 * - $forceRefresh = true → paksa fetch baru
 */
function getToken(bool $forceRefresh = false): array
{
    global $_TOKEN_CACHE;
    $now = time();

    if (!$forceRefresh
        && !empty($_TOKEN_CACHE['access_token'])
        && $_TOKEN_CACHE['expires_at'] > ($now + 60)
    ) {
        return [
            'success'      => true,
            'access_token' => $_TOKEN_CACHE['access_token'],
            'from_cache'   => true,
            'expires_in'   => $_TOKEN_CACHE['expires_at'] - $now,
            'error'        => '',
        ];
    }

    $tok = generateToken();

    if (!$tok['success']) {
        return [
            'success'      => false,
            'access_token' => '',
            'from_cache'   => false,
            'expires_in'   => 0,
            'error'        => $tok['error'],
        ];
    }

    $_TOKEN_CACHE['access_token'] = $tok['access_token'];
    $_TOKEN_CACHE['expires_at']   = $now + (int)($tok['expires_in'] ?? 900);

    return [
        'success'      => true,
        'access_token' => $tok['access_token'],
        'from_cache'   => false,
        'expires_in'   => $tok['expires_in'] ?? 900,
        'error'        => '',
    ];
}

/**
 * vaInquiry() — inquiry VA dengan token otomatis
 *
 * Token diambil otomatis via getToken() (cache/fetch).
 * Auto-retry sekali jika token expired (4012401).
 *
 * PENTING dari source code:
 *  - partnerServiceId HARUS tepat 8 karakter (pad kiri spasi)
 *  - customerNo: 12–16 digit numerik
 *  - virtualAccountNo: 12–16 digit numerik
 */
function vaInquiry(
    string $partnerServiceId,
    string $customerNo,
    string $virtualAccountNo,
    string $inquiryRequestId = ''
): array {
    if (empty($inquiryRequestId)) $inquiryRequestId = snapExternalId();

    // Ambil token otomatis
    $tokenResult = getToken();
    if (!$tokenResult['success']) {
        return [
            'success'           => false,
            'error'             => 'Token gagal: ' . $tokenResult['error'],
            'http_code'         => 0,
            'raw'               => '',
            'debug'             => [],
            'access_token_used' => '',
            'token_from_cache'  => false,
        ];
    }
    $accessToken   = $tokenResult['access_token'];
    $fromCache     = $tokenResult['from_cache'];

    $result = _doInquiry($accessToken, $partnerServiceId, $customerNo, $virtualAccountNo, $inquiryRequestId);

    // Auto-retry jika token expired
    if (in_array($result['response_code'] ?? '', ['4012400', '4012401'], true)) {
        $tokenResult = getToken(true);
        if ($tokenResult['success']) {
            $accessToken = $tokenResult['access_token'];
            $fromCache   = false;
            $result      = _doInquiry($accessToken, $partnerServiceId, $customerNo, $virtualAccountNo, $inquiryRequestId);
        }
    }

    $result['access_token_used'] = $accessToken;
    $result['token_from_cache']  = $fromCache;
    return $result;
}

/** Internal: jalankan HTTP inquiry */
function _doInquiry(
    string $accessToken,
    string $partnerServiceId,
    string $customerNo,
    string $virtualAccountNo,
    string $inquiryRequestId
): array {
    $externalId       = snapExternalId();
    $timestamp        = snapTimestamp();
    $snapPath         = SNAP_PATH_INQUIRY;
    $url              = URL_INQUIRY;
    $clientSecret     = getClientSecret();

    // partnerServiceId HARUS 8 karakter — divalidasi di cekAuthorizationTrx
    $partnerServiceId = str_pad($partnerServiceId, 8, ' ', STR_PAD_LEFT);

    $bodyArray = [
        'partnerServiceId' => $partnerServiceId,
        'customerNo'       => $customerNo,
        'virtualAccountNo' => $virtualAccountNo,
        'inquiryRequestId' => $inquiryRequestId,
    ];
    $jsonBody = json_encode($bodyArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $signature = genSnapSignature('POST', $snapPath, $accessToken, $jsonBody, $timestamp, $clientSecret);

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken,
        'X-TIMESTAMP: '          . $timestamp,
        'X-SIGNATURE: '          . $signature,
        'X-PARTNER-ID: '         . SNAP_PARTNER_ID,
        'X-EXTERNAL-ID: '        . $externalId,
        'CHANNEL-ID: '           . SNAP_CHANNEL_ID,
    ];

    $bodyHash = strtolower(hash('sha256', minifyJson($jsonBody)));
    $debug = [
        'url'          => $url,
        'snap_path'    => $snapPath,
        'timestamp'    => $timestamp,
        'external_id'  => $externalId,
        'body'         => $jsonBody,
        'body_sha256'  => $bodyHash,
        'string2sign'  => 'POST:' . $snapPath . ':' . $accessToken . ':' . $bodyHash . ':' . $timestamp,
        'x_signature'  => $signature,
    ];

    $result = httpPost($url, $headers, $jsonBody);

    if ($result['error']) {
        return [
            'success'              => false,
            'response_code'        => '',
            'error'                => 'cURL error: ' . $result['error'],
            'http_code'            => $result['http_code'],
            'raw'                  => '',
            'debug'                => $debug,
            'virtual_account_data' => [],
        ];
    }

    $decoded = json_decode($result['response'], true);
    $rc      = $decoded['responseCode']    ?? '';
    $msg     = $decoded['responseMessage'] ?? '';
    $success = ($rc === '2002400');

    return [
        'success'              => $success,
        'response_code'        => $rc,
        'response_message'     => $msg,
        'virtual_account_data' => $decoded['virtualAccountData'] ?? [],
        'inquiry_request_id'   => $decoded['inquiryRequestId']   ?? $inquiryRequestId,
        'http_code'            => $result['http_code'],
        'raw'                  => $result['response'],
        'error'                => $success ? '' : "[$rc] $msg",
        'debug'                => $debug,
    ];
}

/**
 * vaPayment() — payment VA dengan token otomatis
 *
 * Token diambil otomatis via getToken() (cache/fetch).
 * Auto-retry sekali jika token expired (4012501).
 */
function vaPayment(
    string $partnerServiceId,
    string $customerNo,
    string $virtualAccountNo,
    string $paymentRequestId = '',
    float  $paidAmount       = 0.0,
    float  $totalAmount      = 0.0,
    string $currency         = 'IDR',
    string $trxDateTime      = ''
): array {
    // Ambil token otomatis
    $tokenResult = getToken();
    if (!$tokenResult['success']) {
        return [
            'success'              => false,
            'error'                => 'Token gagal: ' . $tokenResult['error'],
            'http_code'            => 0,
            'raw'                  => '',
            'debug'                => [],
            'access_token_used'    => '',
            'token_from_cache'     => false,
            'virtual_account_data' => [],
            'payment_flag_reason'  => [],
        ];
    }
    $accessToken = $tokenResult['access_token'];
    $fromCache   = $tokenResult['from_cache'];

    $result = _doPayment($accessToken, $partnerServiceId, $customerNo, $virtualAccountNo,
                         $paymentRequestId, $paidAmount, $totalAmount, $currency, $trxDateTime);

    // Auto-retry jika token expired
    if (in_array($result['response_code'] ?? '', ['4012500', '4012501'], true)) {
        $tokenResult = getToken(true);
        if ($tokenResult['success']) {
            $accessToken = $tokenResult['access_token'];
            $fromCache   = false;
            $result      = _doPayment($accessToken, $partnerServiceId, $customerNo, $virtualAccountNo,
                                      $paymentRequestId, $paidAmount, $totalAmount, $currency, $trxDateTime);
        }
    }

    $result['access_token_used'] = $accessToken;
    $result['token_from_cache']  = $fromCache;
    return $result;
}

/** Internal: jalankan HTTP payment */
function _doPayment(
    string $accessToken,
    string $partnerServiceId,
    string $customerNo,
    string $virtualAccountNo,
    string $paymentRequestId = '',
    float  $paidAmount       = 0.0,
    float  $totalAmount      = 0.0,
    string $currency         = 'IDR',
    string $trxDateTime      = ''
): array {
    if (empty($paymentRequestId)) $paymentRequestId = snapExternalId();
    if (empty($trxDateTime))      $trxDateTime      = snapTimestamp();

    $externalId       = snapExternalId();
    $timestamp        = snapTimestamp();
    $snapPath         = SNAP_PATH_PAYMENT;
    $url              = URL_PAYMENT;
    $clientSecret     = getClientSecret();

    $partnerServiceId = str_pad($partnerServiceId, 8, ' ', STR_PAD_LEFT);

    $bodyArray = [
        'partnerServiceId' => $partnerServiceId,
        'customerNo'       => $customerNo,
        'virtualAccountNo' => $virtualAccountNo,
        'paymentRequestId' => $paymentRequestId,
        'trxDateTime'      => $trxDateTime,
        'paidAmount'       => ['value' => number_format($paidAmount, 2, '.', ''), 'currency' => $currency],
        'totalAmount'      => ['value' => number_format($totalAmount, 2, '.', ''), 'currency' => $currency],
    ];
    $jsonBody = json_encode($bodyArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $signature = genSnapSignature('POST', $snapPath, $accessToken, $jsonBody, $timestamp, $clientSecret);

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken,
        'X-TIMESTAMP: '          . $timestamp,
        'X-SIGNATURE: '          . $signature,
        'X-PARTNER-ID: '         . SNAP_PARTNER_ID,
        'X-EXTERNAL-ID: '        . $externalId,
        'CHANNEL-ID: '           . SNAP_CHANNEL_ID,
    ];

    $bodyHash = strtolower(hash('sha256', minifyJson($jsonBody)));
    $debug = [
        'url'          => $url,
        'snap_path'    => $snapPath,
        'timestamp'    => $timestamp,
        'external_id'  => $externalId,
        'body'         => $jsonBody,
        'body_sha256'  => $bodyHash,
        'string2sign'  => 'POST:' . $snapPath . ':' . $accessToken . ':' . $bodyHash . ':' . $timestamp,
        'x_signature'  => $signature,
    ];

    $result = httpPost($url, $headers, $jsonBody);

    if ($result['error']) {
        return [
            'success'              => false,
            'response_code'        => '',
            'error'                => 'cURL error: ' . $result['error'],
            'http_code'            => $result['http_code'],
            'raw'                  => '',
            'debug'                => $debug,
            'virtual_account_data' => [],
            'payment_flag_reason'  => [],
        ];
    }

    $decoded = json_decode($result['response'], true);
    $rc      = $decoded['responseCode']    ?? '';
    $msg     = $decoded['responseMessage'] ?? '';
    $success = ($rc === '2002500');

    return [
        'success'              => $success,
        'response_code'        => $rc,
        'response_message'     => $msg,
        'virtual_account_data' => $decoded['virtualAccountData'] ?? [],
        'payment_flag_reason'  => $decoded['paymentFlagReason']  ?? [],
        'http_code'            => $result['http_code'],
        'raw'                  => $result['response'],
        'error'                => $success ? '' : "[$rc] $msg",
        'debug'                => $debug,
    ];
}

// ==============================================================
// ========================= WEB UI =============================
// ==============================================================

$isCli = (PHP_SAPI === 'cli');

if ($isCli) {
    // ---- CLI mode ----
    $action    = $argv[1] ?? 'demo';
    $showDebug = in_array('--debug', $argv);

    $binArg    = $argv[2] ?? SNAP_BIN;
    $custArg   = $argv[3] ?? '000000000001';
    $vaArg     = $argv[4] ?? (SNAP_BIN . '000000000001');
    $paidArg   = (float)($argv[5] ?? 100000);
    $totalArg  = (float)($argv[6] ?? 100000);

    echo "================================================================\n";
    echo " Permata Bank SNAP API\n";
    echo " Waktu   : " . date('Y-m-d H:i:s') . "\n";
    echo " Server  : " . SNAP_BASE_URL . "\n";
    echo " Profil  : " . $_ACTIVE_PROFILE . " (" . SNAP_SUPPLIER_NAME . ")\n";
    echo " Partner : " . SNAP_PARTNER_ID . "\n";
    echo "================================================================\n";

    switch ($action) {
        case 'token':
            echo "\n[Token]\n";
            $tok = generateToken();
            echo " HTTP     : " . $tok['http_code'] . "\n";
            echo " Success  : " . ($tok['success'] ? 'Ya' : 'Tidak') . "\n";
            if ($tok['success']) echo " Token    : " . $tok['access_token'] . "\n";
            else                 echo " Error    : " . $tok['error'] . "\n";
            echo " Raw      : " . $tok['raw'] . "\n";
            if ($showDebug) { echo "\n[DEBUG]\n"; print_r($tok['debug']); }
            break;

        case 'inquiry':
            $inq = vaInquiry($binArg, $custArg, $vaArg);
            echo "\n[Inquiry] VA: $vaArg\n";
            echo " HTTP     : " . $inq['http_code'] . "\n";
            echo " Success  : " . ($inq['success'] ? 'Ya' : 'Tidak') . "\n";
            echo " RC       : " . ($inq['response_code'] ?? '') . "\n";
            echo " Token    : " . (($inq['token_from_cache'] ?? false) ? 'cache' : 'baru') . "\n";
            if (!$inq['success']) echo " Error    : " . $inq['error'] . "\n";
            echo " Raw      : " . $inq['raw'] . "\n";
            if ($showDebug) { echo "\n[DEBUG]\n"; print_r($inq['debug']); }
            break;

        case 'payment':
            $pay = vaPayment($binArg, $custArg, $vaArg, '', $paidArg, $totalArg);
            echo "\n[Payment] VA: $vaArg  IDR " . number_format($paidArg) . "\n";
            echo " HTTP     : " . $pay['http_code'] . "\n";
            echo " Success  : " . ($pay['success'] ? 'Ya' : 'Tidak') . "\n";
            echo " RC       : " . ($pay['response_code'] ?? '') . "\n";
            echo " Token    : " . (($pay['token_from_cache'] ?? false) ? 'cache' : 'baru') . "\n";
            if (!$pay['success']) echo " Error    : " . $pay['error'] . "\n";
            echo " Raw      : " . $pay['raw'] . "\n";
            if ($showDebug) { echo "\n[DEBUG]\n"; print_r($pay['debug']); }
            break;

        default: // demo
            echo "\n[Demo] Inquiry + Payment\n";
            $vaNo = SNAP_BIN . '000000000001';

            $inq = vaInquiry(SNAP_BIN, '000000000001', $vaNo);
            echo "\n--- Inquiry ---\n";
            echo " HTTP    : " . $inq['http_code'] . "\n";
            echo " RC      : " . ($inq['response_code'] ?? '') . "\n";
            echo " Token   : " . (($inq['token_from_cache'] ?? false) ? 'cache' : 'baru') . "\n";
            echo " Raw     : " . $inq['raw'] . "\n";

            $pay = vaPayment(SNAP_BIN, '000000000001', $vaNo, '', 100000, 100000);
            echo "\n--- Payment ---\n";
            echo " HTTP    : " . $pay['http_code'] . "\n";
            echo " RC      : " . ($pay['response_code'] ?? '') . "\n";
            echo " Token   : " . (($pay['token_from_cache'] ?? false) ? 'cache ✓' : 'baru') . "\n";
            echo " Raw     : " . $pay['raw'] . "\n";
    }
    echo "\n================================================================\n";
    exit;
}

// ==============================================================
// ========================= WEB UI HTML ========================
// ==============================================================

// Tangkap output jika ada POST/GET action
$uiResult  = null;
$uiAction  = $_REQUEST['action'] ?? '';
$uiError   = '';

if ($uiAction === 'token') {
    $uiResult = generateToken();

} elseif ($uiAction === 'inquiry') {
    $bin   = trim($_POST['bin']    ?? SNAP_BIN);
    $cust  = trim($_POST['cust']   ?? '');
    $vaNo  = trim($_POST['vano']   ?? '');
    $reqId = trim($_POST['reqid']  ?? '');
    if (empty($cust) || empty($vaNo)) {
        $uiError = 'customerNo dan virtualAccountNo wajib diisi.';
    } else {
        $uiResult = vaInquiry($bin, $cust, $vaNo, $reqId);
    }

} elseif ($uiAction === 'payment') {
    $bin   = trim($_POST['bin']       ?? SNAP_BIN);
    $cust  = trim($_POST['cust']      ?? '');
    $vaNo  = trim($_POST['vano']      ?? '');
    $paid  = (float)($_POST['paid']   ?? 0);
    $total = (float)($_POST['total']  ?? 0);
    $curr  = trim($_POST['currency']  ?? 'IDR');
    $reqId = trim($_POST['reqid']     ?? '');
    if (empty($cust) || empty($vaNo) || $paid <= 0) {
        $uiError = 'customerNo, virtualAccountNo, dan paidAmount wajib diisi.';
    } else {
        $uiResult = vaPayment($bin, $cust, $vaNo, $reqId, $paid, $total ?: $paid, $curr);
    }
}

// Helper render JSON
function prettyJson(?string $raw): string {
    if (empty($raw)) return '<em class="text-gray-400">—</em>';
    $dec = json_decode($raw, true);
    if ($dec === null) return htmlspecialchars($raw);
    return '<pre class="text-xs leading-relaxed">' . htmlspecialchars(json_encode($dec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
}
function badge(bool $ok): string {
    return $ok
        ? '<span class="px-2 py-0.5 rounded-full bg-green-100 text-green-700 text-xs font-bold">✓ SUKSES</span>'
        : '<span class="px-2 py-0.5 rounded-full bg-red-100 text-red-700 text-xs font-bold">✗ GAGAL</span>';
}
function rcBadge(string $rc): string {
    $color = str_starts_with($rc, '2') ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700';
    return $rc ? "<span class=\"px-2 py-0.5 rounded $color text-xs font-mono font-bold\">$rc</span>" : '—';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Permata SNAP API — <?= htmlspecialchars(SNAP_SUPPLIER_NAME) ?></title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
<style>
  body { background: #f0f4f8; font-family: 'Segoe UI', system-ui, sans-serif; }
  .card { background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,.08); }
  .tab-btn { transition: all .2s; }
  .tab-btn.active { background: #1d4ed8; color: #fff; }
  .tab-btn:not(.active):hover { background: #dbeafe; }
  .result-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; }
  pre { white-space: pre-wrap; word-break: break-all; }
  input, select { transition: border-color .2s; }
  input:focus, select:focus { outline: none; border-color: #3b82f6; box-shadow: 0 0 0 3px rgba(59,130,246,.15); }
</style>
</head>
<body class="min-h-screen py-8 px-4">

<!-- Header -->
<div class="max-w-3xl mx-auto mb-6">
  <div class="card p-5 flex items-center justify-between">
    <div>
      <div class="flex items-center gap-2 mb-1">
        <i class="fas fa-university text-blue-600 text-xl"></i>
        <h1 class="text-xl font-bold text-gray-800">Permata Bank SNAP API</h1>
      </div>
      <div class="text-sm text-gray-500 space-y-0.5">
        <div><span class="font-medium text-gray-600">Profil:</span> <?= htmlspecialchars($_ACTIVE_PROFILE) ?> — <?= htmlspecialchars(SNAP_SUPPLIER_NAME) ?></div>
        <div><span class="font-medium text-gray-600">Partner ID:</span> <?= htmlspecialchars(SNAP_PARTNER_ID) ?></div>
        <div><span class="font-medium text-gray-600">Server:</span> <code class="bg-gray-100 px-1 rounded text-xs"><?= htmlspecialchars(SNAP_BASE_URL) ?></code></div>
        <div><span class="font-medium text-gray-600">BIN VA:</span> <?= htmlspecialchars(SNAP_BIN) ?></div>
      </div>
    </div>
    <div class="text-right text-xs text-gray-400">
      <div><i class="fas fa-clock mr-1"></i><?= date('Y-m-d H:i:s') ?></div>
      <div class="mt-1">
        <span class="px-2 py-0.5 bg-blue-50 text-blue-600 rounded font-mono text-xs"><?= htmlspecialchars(SNAP_CLIENT_KEY) ?></span>
      </div>
    </div>
  </div>
</div>

<!-- Tab Nav -->
<div class="max-w-3xl mx-auto mb-4">
  <div class="flex gap-2">
    <button onclick="showTab('token')"   id="tab-token"   class="tab-btn px-4 py-2 rounded-lg text-sm font-medium <?= $uiAction==='token'  ?'active':'' ?>">
      <i class="fas fa-key mr-1"></i>Token
    </button>
    <button onclick="showTab('inquiry')" id="tab-inquiry" class="tab-btn px-4 py-2 rounded-lg text-sm font-medium <?= $uiAction==='inquiry'?'active':'' ?>">
      <i class="fas fa-search-dollar mr-1"></i>Inquiry
    </button>
    <button onclick="showTab('payment')" id="tab-payment" class="tab-btn px-4 py-2 rounded-lg text-sm font-medium <?= $uiAction==='payment'?'active':'' ?>">
      <i class="fas fa-money-bill-wave mr-1"></i>Payment
    </button>
  </div>
</div>

<!-- Error global -->
<?php if ($uiError): ?>
<div class="max-w-3xl mx-auto mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
  <i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($uiError) ?>
</div>
<?php endif; ?>

<!-- ====== TAB: TOKEN ====== -->
<div id="pane-token" class="max-w-3xl mx-auto <?= $uiAction!=='token'?'hidden':'' ?>">
  <div class="card p-6">
    <h2 class="text-base font-semibold text-gray-700 mb-4"><i class="fas fa-key text-blue-500 mr-2"></i>Generate Access Token</h2>
    <div class="result-box mb-4 text-sm text-gray-600">
      <div><strong>Endpoint:</strong> <code class="text-blue-700"><?= htmlspecialchars(URL_TOKEN) ?></code></div>
      <div class="mt-1"><strong>Catatan:</strong> Server hanya cek <code>x-client-key</code> di DB. Token berlaku <strong>900 detik</strong> (15 menit).</div>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="token">
      <button type="submit" class="w-full py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-lg transition">
        <i class="fas fa-bolt mr-2"></i>Generate Token Sekarang
      </button>
    </form>

    <?php if ($uiAction === 'token' && $uiResult): ?>
    <div class="mt-5">
      <div class="flex items-center gap-3 mb-3">
        <?= badge($uiResult['success']) ?>
        <span class="text-sm">HTTP <?= $uiResult['http_code'] ?></span>
        <?= rcBadge($uiResult['response_code'] ?? '') ?>
      </div>
      <?php if ($uiResult['success']): ?>
      <div class="grid grid-cols-2 gap-3 mb-3">
        <div class="result-box">
          <div class="text-xs text-gray-500 mb-1">Access Token</div>
          <code class="text-sm text-green-700 break-all font-bold"><?= htmlspecialchars($uiResult['access_token']) ?></code>
        </div>
        <div class="result-box">
          <div class="text-xs text-gray-500 mb-1">Berlaku</div>
          <span class="text-sm font-bold text-gray-700"><?= $uiResult['expires_in'] ?> detik</span>
          <span class="text-xs text-gray-400 ml-1">(~<?= round($uiResult['expires_in']/60) ?> menit)</span>
        </div>
      </div>
      <?php else: ?>
      <div class="p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm mb-3">
        <i class="fas fa-times-circle mr-1"></i><?= htmlspecialchars($uiResult['error']) ?>
        <?php if (!empty($uiResult['debug']['note'])): ?>
        <div class="mt-1 text-xs text-red-500"><?= htmlspecialchars($uiResult['debug']['note']) ?></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <details class="mt-2">
        <summary class="text-xs text-gray-400 cursor-pointer hover:text-gray-600">Raw Response &amp; Debug</summary>
        <div class="mt-2 result-box text-xs">
          <div class="font-semibold text-gray-500 mb-1">Raw Response:</div>
          <?= prettyJson($uiResult['raw'] ?? '') ?>
          <div class="font-semibold text-gray-500 mt-3 mb-1">Debug:</div>
          <?php foreach ($uiResult['debug'] as $k => $v): ?>
          <div class="mb-0.5"><span class="font-mono text-blue-600"><?= $k ?>:</span> <span class="break-all"><?= htmlspecialchars((string)$v) ?></span></div>
          <?php endforeach; ?>
        </div>
      </details>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ====== TAB: INQUIRY ====== -->
<div id="pane-inquiry" class="max-w-3xl mx-auto <?= $uiAction!=='inquiry'?'hidden':'' ?>">
  <div class="card p-6">
    <h2 class="text-base font-semibold text-gray-700 mb-1"><i class="fas fa-search-dollar text-indigo-500 mr-2"></i>VA Inquiry</h2>
    <p class="text-xs text-gray-400 mb-4">Token diambil <strong>otomatis</strong> — tidak perlu generate manual.</p>
    <div class="result-box mb-4 text-sm text-gray-600">
      <div><strong>Endpoint:</strong> <code class="text-indigo-700"><?= htmlspecialchars(URL_INQUIRY) ?></code></div>
    </div>

    <form method="POST" class="space-y-3">
      <input type="hidden" name="action" value="inquiry">
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-medium text-gray-600 mb-1">BIN / partnerServiceId</label>
          <input type="text" name="bin" value="<?= htmlspecialchars($_POST['bin'] ?? SNAP_BIN) ?>"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="<?= SNAP_BIN ?>">
          <p class="text-xs text-gray-400 mt-0.5">Akan di-pad kiri jadi 8 karakter</p>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-600 mb-1">Customer No <span class="text-red-500">*</span></label>
          <input type="text" name="cust" value="<?= htmlspecialchars($_POST['cust'] ?? '') ?>"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="12–16 digit numerik" required>
        </div>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Virtual Account No <span class="text-red-500">*</span></label>
        <input type="text" name="vano" value="<?= htmlspecialchars($_POST['vano'] ?? '') ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="<?= SNAP_BIN ?>xxxxxxxx (12–16 digit)" required>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Inquiry Request ID <span class="text-gray-400">(opsional, auto-generate jika kosong)</span></label>
        <input type="text" name="reqid" value="<?= htmlspecialchars($_POST['reqid'] ?? '') ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="UUID auto-generate">
      </div>
      <button type="submit" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg transition">
        <i class="fas fa-search mr-2"></i>Kirim Inquiry
      </button>
    </form>

    <?php if ($uiAction === 'inquiry' && $uiResult): ?>
    <div class="mt-5">
      <div class="flex items-center gap-3 mb-3 flex-wrap">
        <?= badge($uiResult['success']) ?>
        <span class="text-sm">HTTP <?= $uiResult['http_code'] ?></span>
        <?= rcBadge($uiResult['response_code'] ?? '') ?>
        <span class="text-xs text-gray-400">Token: <?= ($uiResult['token_from_cache'] ?? false) ? '♻ dari cache' : '🔄 baru fetch' ?></span>
        <?php if (!empty($uiResult['access_token_used'])): ?>
        <span class="text-xs font-mono text-gray-400"><?= htmlspecialchars(substr($uiResult['access_token_used'], 0, 20)) ?>…</span>
        <?php endif; ?>
      </div>

      <?php if (!$uiResult['success']): ?>
      <div class="p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm mb-3">
        <i class="fas fa-times-circle mr-1"></i><?= htmlspecialchars($uiResult['error']) ?>
      </div>
      <?php endif; ?>

      <?php if (!empty($uiResult['virtual_account_data'])): ?>
      <div class="result-box mb-3">
        <div class="text-xs font-semibold text-gray-500 mb-2">Virtual Account Data</div>
        <?php $va = $uiResult['virtual_account_data']; ?>
        <div class="grid grid-cols-2 gap-2 text-sm">
          <?php foreach (['name','totalAmount','partnerServiceId','customerNo','virtualAccountNo','billDetails'] as $f): ?>
          <?php if (isset($va[$f])): ?>
          <div>
            <span class="text-xs text-gray-500"><?= $f ?>:</span>
            <span class="font-medium text-gray-700">
              <?= is_array($va[$f]) ? json_encode($va[$f], JSON_UNESCAPED_UNICODE) : htmlspecialchars((string)$va[$f]) ?>
            </span>
          </div>
          <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <details>
        <summary class="text-xs text-gray-400 cursor-pointer hover:text-gray-600">Raw Response &amp; Debug</summary>
        <div class="mt-2 result-box text-xs">
          <?= prettyJson($uiResult['raw'] ?? '') ?>
          <div class="font-semibold text-gray-500 mt-3 mb-1">Debug:</div>
          <?php foreach ($uiResult['debug'] as $k => $v): ?>
          <div class="mb-0.5"><span class="font-mono text-indigo-600"><?= $k ?>:</span> <span class="break-all"><?= htmlspecialchars((string)$v) ?></span></div>
          <?php endforeach; ?>
        </div>
      </details>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ====== TAB: PAYMENT ====== -->
<div id="pane-payment" class="max-w-3xl mx-auto <?= $uiAction!=='payment'?'hidden':'' ?>">
  <div class="card p-6">
    <h2 class="text-base font-semibold text-gray-700 mb-1"><i class="fas fa-money-bill-wave text-emerald-500 mr-2"></i>VA Payment</h2>
    <p class="text-xs text-gray-400 mb-4">Token diambil <strong>otomatis</strong> — tidak perlu generate manual.</p>
    <div class="result-box mb-4 text-sm text-gray-600">
      <div><strong>Endpoint:</strong> <code class="text-emerald-700"><?= htmlspecialchars(URL_PAYMENT) ?></code></div>
    </div>

    <form method="POST" class="space-y-3">
      <input type="hidden" name="action" value="payment">
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-medium text-gray-600 mb-1">BIN / partnerServiceId</label>
          <input type="text" name="bin" value="<?= htmlspecialchars($_POST['bin'] ?? SNAP_BIN) ?>"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-600 mb-1">Customer No <span class="text-red-500">*</span></label>
          <input type="text" name="cust" value="<?= htmlspecialchars($_POST['cust'] ?? '') ?>"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="12–16 digit numerik" required>
        </div>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Virtual Account No <span class="text-red-500">*</span></label>
        <input type="text" name="vano" value="<?= htmlspecialchars($_POST['vano'] ?? '') ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="12–16 digit" required>
      </div>
      <div class="grid grid-cols-3 gap-3">
        <div>
          <label class="block text-xs font-medium text-gray-600 mb-1">Paid Amount <span class="text-red-500">*</span></label>
          <input type="number" name="paid" value="<?= htmlspecialchars($_POST['paid'] ?? '') ?>"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="100000" min="1" required>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-600 mb-1">Total Amount</label>
          <input type="number" name="total" value="<?= htmlspecialchars($_POST['total'] ?? '') ?>"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="= Paid Amount jika kosong">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-600 mb-1">Currency</label>
          <select name="currency" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            <option value="IDR" <?= ($_POST['currency'] ?? 'IDR') === 'IDR' ? 'selected' : '' ?>>IDR</option>
          </select>
        </div>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Payment Request ID <span class="text-gray-400">(opsional)</span></label>
        <input type="text" name="reqid" value="<?= htmlspecialchars($_POST['reqid'] ?? '') ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="UUID auto-generate">
      </div>
      <button type="submit" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg transition">
        <i class="fas fa-paper-plane mr-2"></i>Kirim Payment
      </button>
    </form>

    <?php if ($uiAction === 'payment' && $uiResult): ?>
    <div class="mt-5">
      <div class="flex items-center gap-3 mb-3 flex-wrap">
        <?= badge($uiResult['success']) ?>
        <span class="text-sm">HTTP <?= $uiResult['http_code'] ?></span>
        <?= rcBadge($uiResult['response_code'] ?? '') ?>
        <span class="text-xs text-gray-400">Token: <?= ($uiResult['token_from_cache'] ?? false) ? '♻ dari cache' : '🔄 baru fetch' ?></span>
      </div>

      <?php if (!$uiResult['success']): ?>
      <div class="p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm mb-3">
        <i class="fas fa-times-circle mr-1"></i><?= htmlspecialchars($uiResult['error']) ?>
      </div>
      <?php endif; ?>

      <?php if (!empty($uiResult['virtual_account_data'])): ?>
      <div class="result-box mb-3">
        <div class="text-xs font-semibold text-gray-500 mb-2">Payment Result</div>
        <pre class="text-xs"><?= htmlspecialchars(json_encode($uiResult['virtual_account_data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
      </div>
      <?php endif; ?>

      <details>
        <summary class="text-xs text-gray-400 cursor-pointer hover:text-gray-600">Raw Response &amp; Debug</summary>
        <div class="mt-2 result-box text-xs">
          <?= prettyJson($uiResult['raw'] ?? '') ?>
          <div class="font-semibold text-gray-500 mt-3 mb-1">Debug:</div>
          <?php foreach ($uiResult['debug'] as $k => $v): ?>
          <div class="mb-0.5"><span class="font-mono text-emerald-600"><?= $k ?>:</span> <span class="break-all"><?= htmlspecialchars((string)$v) ?></span></div>
          <?php endforeach; ?>
        </div>
      </details>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Endpoint info -->
<div class="max-w-3xl mx-auto mt-6">
  <div class="card p-4">
    <div class="text-xs font-semibold text-gray-500 mb-2"><i class="fas fa-link mr-1"></i>Endpoint Aktif</div>
    <div class="space-y-1 text-xs font-mono">
      <div><span class="inline-block w-20 text-gray-400">Token</span><span class="text-blue-600"><?= htmlspecialchars(URL_TOKEN) ?></span></div>
      <div><span class="inline-block w-20 text-gray-400">Inquiry</span><span class="text-indigo-600"><?= htmlspecialchars(URL_INQUIRY) ?></span></div>
      <div><span class="inline-block w-20 text-gray-400">Payment</span><span class="text-emerald-600"><?= htmlspecialchars(URL_PAYMENT) ?></span></div>
    </div>
  </div>
</div>

<div class="max-w-3xl mx-auto mt-3 text-center text-xs text-gray-400 pb-8">
  permata-switching_v1.0_pro &mdash; <?= date('Y') ?>
</div>

<script>
function showTab(name) {
  ['token','inquiry','payment'].forEach(t => {
    document.getElementById('pane-' + t).classList.add('hidden');
    document.getElementById('tab-' + t).classList.remove('active');
  });
  document.getElementById('pane-' + name).classList.remove('hidden');
  document.getElementById('tab-'  + name).classList.add('active');
}
// Auto-show active tab from PHP
<?php if ($uiAction): ?>
showTab('<?= htmlspecialchars($uiAction) ?>');
<?php else: ?>
showTab('token');
<?php endif; ?>
</script>
</body>
</html>

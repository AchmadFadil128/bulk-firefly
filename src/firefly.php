<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function firefly_base_url(): string
{
    $base = trim((string)(getenv('FIREFLY_BASE_URL') ?: ''));
    if ($base === '') {
        throw new RuntimeException('FIREFLY_BASE_URL belum dikonfigurasi.');
    }

    $parts = parse_url($base);
    if (!is_array($parts) || empty($parts['host']) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['https', 'http'], true)) {
        throw new RuntimeException('FIREFLY_BASE_URL tidak valid.');
    }
    $scheme = strtolower((string)$parts['scheme']);
    $host = strtolower((string)$parts['host']);
    $isLocal = in_array($host, ['localhost', '127.0.0.1', '::1', 'firefly-iii'], true);
    if ($scheme !== 'https' && !((getenv('APP_ENV') ?: 'production') === 'local' && $isLocal)) {
        throw new RuntimeException('FIREFLY_BASE_URL harus menggunakan HTTPS. HTTP hanya diizinkan untuk pengujian lokal ke host lokal.');
    }
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('FIREFLY_BASE_URL tidak boleh berisi kredensial, query, atau fragment.');
    }
    return rtrim($base, '/');
}

function firefly_token(): string
{
    $tokenFile = trim((string)(getenv('FIREFLY_API_TOKEN_FILE') ?: '/run/secrets/firefly_api_token'));
    if ($tokenFile !== '' && is_readable($tokenFile)) {
        $token = trim((string)file_get_contents($tokenFile));
        if ($token !== '') {
            return $token;
        }
    }
    $token = trim((string)(getenv('FIREFLY_API_TOKEN') ?: ''));
    if ($token !== '') {
        return $token;
    }
    throw new RuntimeException('Token API Firefly III tidak ditemukan. Periksa Docker secret firefly_api_token.');
}

/** @return array{ok:bool,status:int,data:mixed,error:string} */
function firefly_request(string $method, string $path, ?array $body = null): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Ekstensi cURL PHP tidak tersedia.'];
    }

    try {
        $base = firefly_base_url();
        $token = firefly_token();
        $accessClientIdFile = trim((string)(getenv('FIREFLY_CF_ACCESS_CLIENT_ID_FILE') ?: ''));
        $accessClientSecretFile = trim((string)(getenv('FIREFLY_CF_ACCESS_CLIENT_SECRET_FILE') ?: ''));
        $accessClientId = $accessClientIdFile !== '' && is_readable($accessClientIdFile) ? trim((string)file_get_contents($accessClientIdFile)) : '';
        $accessClientSecret = $accessClientSecretFile !== '' && is_readable($accessClientSecretFile) ? trim((string)file_get_contents($accessClientSecretFile)) : '';
        if (($accessClientId === '') !== ($accessClientSecret === '')) {
            throw new RuntimeException('Cloudflare Access Service Token tidak lengkap. Isi Client ID dan Client Secret, atau kosongkan keduanya.');
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => $e->getMessage()];
    }

    $url = $base . '/' . ltrim($path, '/');
    $headers = [
        'Accept: application/vnd.api+json, application/json',
        'Authorization: Bearer ' . $token,
    ];
    if ($accessClientId !== '' && $accessClientSecret !== '') {
        $headers[] = 'CF-Access-Client-Id: ' . $accessClientId;
        $headers[] = 'CF-Access-Client-Secret: ' . $accessClientSecret;
    }
    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HEADER => false,
    ];

    if ($body !== null) {
        $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Gagal menyiapkan JSON untuk Firefly III.'];
        }
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POSTFIELDS] = $json;
    }

    $ch = curl_init();
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Koneksi ke Firefly III gagal: ' . ($curlError ?: 'kesalahan jaringan tidak diketahui')];
    }

    $decoded = json_decode((string)$raw, true);
    $ok = $status >= 200 && $status < 300;
    $error = '';
    if (!$ok) {
        $error = extract_firefly_error(is_array($decoded) ? $decoded : [], $status);
    }

    return ['ok' => $ok, 'status' => $status, 'data' => $decoded, 'error' => $error];
}

function extract_firefly_error(array $data, int $status): string
{
    $message = trim((string)($data['message'] ?? ''));
    if ($message === '' && isset($data['errors']) && is_array($data['errors'])) {
        foreach ($data['errors'] as $fieldErrors) {
            if (is_array($fieldErrors)) {
                foreach ($fieldErrors as $fieldError) {
                    if (is_string($fieldError) && trim($fieldError) !== '') {
                        $message = trim($fieldError);
                        break 2;
                    }
                }
            } elseif (is_string($fieldErrors) && trim($fieldErrors) !== '') {
                $message = trim($fieldErrors);
                break;
            }
        }
    }
    if ($message === '') {
        $message = match ($status) {
            401 => 'Token API ditolak Firefly III. Periksa token dan izin aksesnya.',
            403 => 'Firefly III menolak akses ke endpoint ini.',
            404 => 'Endpoint atau data tidak ditemukan di Firefly III.',
            422 => 'Firefly III menolak data transaksi (validasi atau kemungkinan duplikat).',
            429 => 'Terlalu banyak request ke Firefly III. Coba lagi nanti.',
            default => 'Firefly III mengembalikan HTTP ' . $status . '.',
        };
    }
    return mb_substr($message, 0, 500);
}

function get_firefly_accounts(): array
{
    $result = firefly_request('GET', '/v1/autocomplete/accounts?limit=1000');
    if (!$result['ok'] || !is_array($result['data'])) {
        throw new RuntimeException($result['error'] ?: 'Respons daftar akun dari Firefly III tidak valid.');
    }
    $accounts = [];
    foreach ($result['data'] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $type = normalize_account_type((string)($item['type'] ?? ''));
        if (!in_array($type, ['asset', 'expense', 'revenue'], true)) {
            continue;
        }
        $accounts[] = [
            'id' => (string)($item['id'] ?? ''),
            'name' => (string)($item['name'] ?? ''),
            'type' => $type,
            'currency_code' => (string)($item['account_currency_code'] ?? $item['currency_code'] ?? ''),
        ];
    }
    return $accounts;
}

function get_firefly_categories(): array
{
    $result = firefly_request('GET', '/v1/autocomplete/categories?limit=1000');
    if (!$result['ok'] || !is_array($result['data'])) {
        throw new RuntimeException($result['error'] ?: 'Respons kategori dari Firefly III tidak valid.');
    }
    $categories = [];
    foreach ($result['data'] as $item) {
        if (is_array($item) && isset($item['id'], $item['name'])) {
            $categories[] = ['id' => (string)$item['id'], 'name' => (string)$item['name']];
        }
    }
    return $categories;
}

function normalize_account_type(string $type): string
{
    $value = strtolower(trim($type));
    return match ($value) {
        'asset', 'asset account' => 'asset',
        'expense', 'expense account' => 'expense',
        'revenue', 'revenue account', 'income' => 'revenue',
        default => $value,
    };
}

function detect_transaction_type(string $sourceType, string $destinationType): ?string
{
    if ($sourceType === 'asset' && $destinationType === 'expense') {
        return 'withdrawal';
    }
    if ($sourceType === 'revenue' && $destinationType === 'asset') {
        return 'deposit';
    }
    if ($sourceType === 'asset' && $destinationType === 'asset') {
        return 'transfer';
    }
    return null;
}

function normalize_amount(mixed $input): ?string
{
    if (!is_string($input) && !is_int($input) && !is_float($input)) {
        return null;
    }
    $value = trim((string)$input);
    $value = str_ireplace(['idr', 'rp', ' ', "\u{00A0}", "\u{202F}"], '', $value);
    $value = preg_replace('/[^0-9,.\-]/u', '', $value) ?? '';
    if ($value === '' || str_contains($value, '-')) {
        return null;
    }

    $lastComma = strrpos($value, ',');
    $lastDot = strrpos($value, '.');
    if ($lastComma !== false && $lastDot !== false) {
        $decimalSeparator = $lastComma > $lastDot ? ',' : '.';
        $thousandSeparator = $decimalSeparator === ',' ? '.' : ',';
        $value = str_replace($thousandSeparator, '', $value);
        $last = strrpos($value, $decimalSeparator);
        $integer = $last === false ? $value : substr($value, 0, $last);
        $fraction = $last === false ? '' : substr($value, $last + 1);
        $integer = str_replace($decimalSeparator, '', $integer);
        $value = $integer . ($fraction !== '' ? '.' . $fraction : '');
    } elseif ($lastComma !== false || $lastDot !== false) {
        $separator = $lastComma !== false ? ',' : '.';
        $parts = explode($separator, $value);
        if (count($parts) > 2) {
            $lastPart = array_pop($parts);
            if (strlen($lastPart) >= 1 && strlen($lastPart) <= 2) {
                $value = implode('', $parts) . '.' . $lastPart;
            } else {
                $value = implode('', $parts) . $lastPart;
            }
        } else {
            [$integer, $fraction] = array_pad($parts, 2, '');
            if (strlen($fraction) >= 1 && strlen($fraction) <= 2) {
                $value = $integer . '.' . $fraction;
            } else {
                $value = $integer . $fraction;
            }
        }
    }

    if (!preg_match('/^\d+(?:\.\d{1,16})?$/', $value)) {
        return null;
    }
    $pieces = explode('.', $value, 2);
    $integer = ltrim($pieces[0], '0');
    $integer = $integer === '' ? '0' : $integer;
    if (strlen($integer) > 15) {
        return null;
    }
    $fraction = $pieces[1] ?? '';
    $normalized = $integer . ($fraction !== '' ? '.' . rtrim($fraction, '0') : '');
    $normalized = rtrim($normalized, '.');
    if ($normalized === '' || (float)$normalized <= 0) {
        return null;
    }
    return $normalized;
}

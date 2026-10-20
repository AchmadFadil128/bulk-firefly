<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/src/firefly.php';
require_method('POST');
require_csrf_and_same_origin();
$payload = read_json_body();
$rows = $payload['rows'] ?? null;
if (!is_array($rows) || count($rows) === 0) {
    json_response(['ok' => false, 'message' => 'Tidak ada transaksi untuk disimpan.'], 400);
}
if (count($rows) > 100) {
    json_response(['ok' => false, 'message' => 'Maksimal 100 transaksi dalam satu proses.'], 413);
}

try {
    $accounts = get_firefly_accounts();
    $categories = get_firefly_categories();
} catch (Throwable $e) {
    json_response(['ok' => false, 'message' => 'Tidak dapat memuat referensi akun/kategori dari Firefly III: ' . $e->getMessage()], 502);
}

$accountMap = [];
foreach ($accounts as $account) {
    $accountMap[(string)$account['id']] = $account;
}
$categoryMap = [];
foreach ($categories as $category) {
    $categoryMap[(string)$category['id']] = $category;
}

$results = [];
$stopProcessingReason = null;
foreach ($rows as $index => $row) {
    $rowId = is_array($row) ? (string)($row['row_id'] ?? (string)($index + 1)) : (string)($index + 1);
    if (strlen($rowId) > 80 || $rowId === '') {
        $rowId = (string)($index + 1);
    }
    $fail = static function (string $message, ?string $type = null) use (&$results, $rowId): void {
        $results[] = ['row_id' => $rowId, 'ok' => false, 'type' => $type, 'message' => $message];
    };

    if ($stopProcessingReason !== null) {
        $fail($stopProcessingReason);
        continue;
    }
    if (!is_array($row)) {
        $fail('Format baris tidak valid.');
        continue;
    }

    $description = trim((string)($row['description'] ?? ''));
    $amount = normalize_amount($row['amount'] ?? '');
    $sourceId = trim((string)($row['source_id'] ?? ''));
    $destinationId = trim((string)($row['destination_id'] ?? ''));
    $categoryId = trim((string)($row['category_id'] ?? ''));
    $date = trim((string)($row['date'] ?? ''));

    if ($amount === null) {
        $fail('Nominal tidak valid. Gunakan angka positif; contoh 125000 atau 125000,50.');
        continue;
    }
    if ($description === '') {
        $fail('Nama transaksi wajib diisi.');
        continue;
    }
    if (mb_strlen($description) > 255) {
        $fail('Nama transaksi maksimal 255 karakter.');
        continue;
    }
    if ($sourceId === '' || !isset($accountMap[$sourceId])) {
        $fail('Source wajib dipilih dari akun Firefly III.');
        continue;
    }
    if ($destinationId === '' || !isset($accountMap[$destinationId])) {
        $fail('Destination wajib dipilih dari akun Firefly III.');
        continue;
    }
    if ($sourceId === $destinationId) {
        $fail('Source dan Destination tidak boleh akun yang sama.');
        continue;
    }
    $source = $accountMap[$sourceId];
    $destination = $accountMap[$destinationId];
    $sourceCurrency = strtoupper(trim((string)($source['currency_code'] ?? '')));
    $destinationCurrency = strtoupper(trim((string)($destination['currency_code'] ?? '')));
    if ($sourceCurrency !== '' && $destinationCurrency !== '' && $sourceCurrency !== $destinationCurrency) {
        $fail('Mata uang Source (' . $sourceCurrency . ') berbeda dari Destination (' . $destinationCurrency . '). Transaksi lintas mata uang belum didukung oleh form ini.');
        continue;
    }
    $type = detect_transaction_type($source['type'], $destination['type']);
    if ($type === null) {
        $fail('Kombinasi akun tidak valid. Withdrawal: aset → pengeluaran; Deposit: pendapatan → aset; Transfer: aset → aset.', null);
        continue;
    }
    if ($date === '') {
        $fail('Tanggal wajib diisi.');
        continue;
    }
    $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Jakarta'));
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$parsedDate || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $parsedDate->format('Y-m-d') !== $date) {
        $fail('Tanggal tidak valid. Gunakan format YYYY-MM-DD.');
        continue;
    }
    if ($categoryId !== '' && !isset($categoryMap[$categoryId])) {
        $fail('Kategori tidak ditemukan di Firefly III. Pilih kategori dari daftar.');
        continue;
    }

    $transaction = [
        'type' => $type,
        'date' => $parsedDate->format('Y-m-d') . 'T00:00:00+07:00',
        'amount' => $amount,
        'description' => $description,
        'source_id' => $sourceId,
        'destination_id' => $destinationId,
    ];
    $messageSuffix = '';
    if ($categoryId !== '' && $type !== 'transfer') {
        $transaction['category_id'] = $categoryId;
    } elseif ($categoryId !== '' && $type === 'transfer') {
        $messageSuffix = ' Kategori diabaikan untuk transfer.';
    }

    // Each row is sent separately so one failed transaction does not block later rows.
    // Duplicate-hash protection reduces accidental duplicate submissions.
    $response = firefly_request('POST', '/v1/transactions', [
        'error_if_duplicate_hash' => true,
        'apply_rules' => false,
        'fire_webhooks' => true,
        'transactions' => [$transaction],
    ]);

    if ($response['ok']) {
        $transactionId = null;
        if (is_array($response['data'])) {
            $transactionId = $response['data']['data']['id'] ?? $response['data']['data'][0]['id'] ?? null;
        }
        $results[] = [
            'row_id' => $rowId,
            'ok' => true,
            'type' => $type,
            'transaction_id' => is_scalar($transactionId) ? (string)$transactionId : null,
            'message' => 'Berhasil disimpan.' . $messageSuffix,
        ];
    } else {
        $message = $response['error'] ?: 'Transaksi gagal disimpan.';
        if ($response['status'] === 0) {
            $message .= ' Hasilnya mungkin belum pasti; periksa riwayat Firefly III sebelum mencoba ulang agar tidak membuat duplikat.';
        }
        $results[] = ['row_id' => $rowId, 'ok' => false, 'type' => $type, 'message' => $message];
        if ($response['status'] === 0 || in_array($response['status'], [401, 403, 404, 408, 429], true) || $response['status'] >= 500) {
            $stopProcessingReason = 'Tidak diproses karena API Firefly III mengalami kegagalan koneksi, autentikasi, atau server pada baris sebelumnya. Periksa token/koneksi dan riwayat Firefly III sebelum mengirim ulang.';
        }
    }
}

$successCount = count(array_filter($results, static fn(array $item): bool => $item['ok']));
$failedCount = count($results) - $successCount;
json_response([
    'ok' => true,
    'summary' => ['total' => count($results), 'success' => $successCount, 'failed' => $failedCount],
    'results' => $results,
]);

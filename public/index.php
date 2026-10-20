<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/src/bootstrap.php';
$csrf = htmlspecialchars(csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="csrf-token" content="<?= $csrf ?>">
  <meta name="color-scheme" content="light">
  <title>Bulk Transaction · Firefly III</title>
  <link rel="stylesheet" href="/assets/app.css">
  <script src="/assets/app.js" defer></script>
</head>
<body>
  <main class="app-shell">
    <header class="topbar">
      <div class="brand-mark" aria-hidden="true">F</div>
      <div class="brand-copy">
        <p class="eyebrow">FIREFLY III · PERSONAL FINANCE</p>
        <h1>Bulk Transaction</h1>
      </div>
      <div class="secure-pill"><span class="secure-dot"></span> Protected by Access</div>
    </header>

    <section class="intro-row">
      <div>
        <h2>Catat banyak transaksi sekaligus.</h2>
        <p>Tambahkan baris atau tempel langsung dari Excel / Google Sheets. Jenis transaksi akan terdeteksi dari akun sumber dan tujuan.</p>
      </div>
      <div class="intro-actions">
        <button class="button button-secondary" id="add-row" type="button"><span aria-hidden="true">＋</span> Tambah baris</button>
      </div>
    </section>

    <section class="notice notice-info" role="note">
      <span class="notice-icon" aria-hidden="true">i</span>
      <div><strong>Tips paste spreadsheet</strong><p>Urutan kolom: Nominal Transaksi, Nama Transaksi, Source, Destination, Kategori, Tanggal. Pilih sel pada tabel lalu tekan <kbd>Ctrl</kbd> + <kbd>V</kbd>. Nama akun dan kategori harus cocok dengan yang ada di Firefly III.</p></div>
    </section>

    <section class="table-card" aria-label="Tabel transaksi massal">
      <div class="table-toolbar">
        <div class="table-heading">
          <h3>Daftar transaksi</h3>
          <span class="count-pill" id="row-count">0 baris</span>
        </div>
        <div class="table-toolbar-right">
          <span class="api-state" id="api-state"><span class="state-dot"></span> Menghubungkan ke Firefly III…</span>
          <button class="button button-quiet" id="clear-empty" type="button">Hapus baris kosong</button>
        </div>
      </div>
      <div class="table-scroll">
        <table>
          <thead>
            <tr>
              <th class="col-amount">Nominal Transaksi</th>
              <th class="col-description">Nama Transaksi</th>
              <th class="col-account">Source</th>
              <th class="col-account">Destination</th>
              <th class="col-type">Jenis</th>
              <th class="col-category">Kategori</th>
              <th class="col-date">Tanggal</th>
              <th class="col-status">Status</th>
              <th class="col-action"><span class="sr-only">Aksi</span></th>
            </tr>
          </thead>
          <tbody id="transaction-rows"></tbody>
        </table>
      </div>
      <div class="table-footer">
        <button class="add-row-inline" id="add-row-inline" type="button"><span aria-hidden="true">＋</span> Tambah baris transaksi</button>
        <span class="table-footnote">Maksimal 100 transaksi per proses penyimpanan.</span>
      </div>
    </section>

    <section class="bottom-bar">
      <div class="summary" aria-live="polite">
        <div class="summary-icon" aria-hidden="true">↗</div>
        <div><strong id="summary-title">Siap untuk mulai</strong><p id="summary-text">Transaksi belum dikirim ke Firefly III.</p></div>
      </div>
      <button class="button button-primary" id="save-transactions" type="button" disabled>
        <span class="save-icon" aria-hidden="true">✓</span><span id="save-label">Simpan transaksi</span>
      </button>
    </section>

    <footer class="page-footer">
      <span>Bulk Transaction untuk Firefly III</span>
      <span>Token API tetap berada di server.</span>
    </footer>
  </main>
  <div class="toast-region" id="toast-region" aria-live="polite" aria-atomic="true"></div>
</body>
</html>

'use strict';

(() => {
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const tbody = document.getElementById('transaction-rows');
  const addButton = document.getElementById('add-row');
  const addInlineButton = document.getElementById('add-row-inline');
  const clearEmptyButton = document.getElementById('clear-empty');
  const saveButton = document.getElementById('save-transactions');
  const saveLabel = document.getElementById('save-label');
  const rowCount = document.getElementById('row-count');
  const apiState = document.getElementById('api-state');
  const summaryTitle = document.getElementById('summary-title');
  const summaryText = document.getElementById('summary-text');
  const toastRegion = document.getElementById('toast-region');

  const state = { accounts: [], categories: [], rows: [], busy: false, nextId: 1 };
  const editableFields = ['amount', 'description', 'source_id', 'destination_id', 'category_id', 'date'];

  function localToday() {
    const now = new Date();
    const y = now.getFullYear();
    const m = String(now.getMonth() + 1).padStart(2, '0');
    const d = String(now.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  }

  function newRow(values = {}) {
    return {
      id: `row-${state.nextId++}`,
      amount: values.amount ?? '',
      description: values.description ?? '',
      source_id: values.source_id ?? '',
      destination_id: values.destination_id ?? '',
      category_id: values.category_id ?? '',
      date: values.date ?? localToday(),
      status: 'Belum disimpan',
      statusKind: '',
      statusDetail: '',
      saved: false,
      busy: false,
      pasteError: '',
      pasteErrors: {}
    };
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  }

  function normalizeType(type) {
    const value = String(type || '').toLowerCase().trim();
    if (value === 'asset' || value === 'asset account') return 'asset';
    if (value === 'expense' || value === 'expense account') return 'expense';
    if (value === 'revenue' || value === 'revenue account' || value === 'income') return 'revenue';
    return value;
  }

  function accountById(id) {
    return state.accounts.find((account) => String(account.id) === String(id));
  }

  function detectType(row) {
    const source = accountById(row.source_id);
    const destination = accountById(row.destination_id);
    if (!source || !destination) return null;
    const s = normalizeType(source.type);
    const d = normalizeType(destination.type);
    if (s === 'asset' && d === 'expense') return 'withdrawal';
    if (s === 'revenue' && d === 'asset') return 'deposit';
    if (s === 'asset' && d === 'asset') return 'transfer';
    return null;
  }

  function typeLabel(type) {
    return ({ withdrawal: 'Withdrawal', deposit: 'Deposit', transfer: 'Transfer' })[type] || 'Periksa akun';
  }

  function formatAccount(account) {
    const typeLabel = ({ asset: 'Aset', expense: 'Pengeluaran', revenue: 'Pendapatan' })[normalizeType(account.type)] || account.type;
    const currency = account.currency_code ? ` · ${account.currency_code}` : '';
    return `${account.name} · ${typeLabel}${currency}`;
  }

  function optionList(items, selected, placeholder, labeler) {
    const blank = `<option value="">${escapeHtml(placeholder)}</option>`;
    return blank + items.map((item) => `<option value="${escapeHtml(item.id)}" ${String(item.id) === String(selected) ? 'selected' : ''}>${escapeHtml(labeler(item))}</option>`).join('');
  }

  function createCellInput(row, field, label, type = 'text', placeholder = '') {
    const input = document.createElement('input');
    input.className = `cell-input ${field === 'amount' ? 'amount' : ''} ${field === 'date' ? 'date' : ''}`;
    input.dataset.field = field;
    input.dataset.rowId = row.id;
    input.setAttribute('aria-label', label);
    input.type = type;
    input.value = row[field] ?? '';
    if (placeholder) input.placeholder = placeholder;
    if (field === 'amount') {
      input.inputMode = 'decimal';
      input.placeholder = '0';
      input.autocomplete = 'off';
    }
    if (field === 'date') input.max = '9999-12-31';
    if (field === 'description') input.maxLength = 255;
    input.disabled = row.saved || row.busy;
    return input;
  }

  function createSelect(row, field, label, items, placeholder, labeler) {
    const select = document.createElement('select');
    select.className = 'cell-select';
    select.dataset.field = field;
    select.dataset.rowId = row.id;
    select.setAttribute('aria-label', label);
    select.innerHTML = optionList(items, row[field], placeholder, labeler);
    select.disabled = row.saved || row.busy || (field === 'category_id' && detectType(row) === 'transfer');
    return select;
  }

  function makeTd(child, className = '') {
    const td = document.createElement('td');
    if (className) td.className = className;
    td.appendChild(child);
    return td;
  }

  function renderRows() {
    tbody.replaceChildren();
    state.rows.forEach((row) => {
      const tr = document.createElement('tr');
      tr.dataset.rowId = row.id;

      tr.appendChild(makeTd(createCellInput(row, 'amount', 'Nominal Transaksi', 'text', '0'), 'col-amount-cell'));
      tr.appendChild(makeTd(createCellInput(row, 'description', 'Nama Transaksi', 'text', 'Mis. Belanja bulanan')));

      const sourceOptions = state.accounts.filter((account) => ['asset', 'revenue'].includes(normalizeType(account.type)));
      const destinationOptions = state.accounts.filter((account) => ['asset', 'expense'].includes(normalizeType(account.type)));
      tr.appendChild(makeTd(createSelect(row, 'source_id', 'Source', sourceOptions, 'Pilih source', formatAccount)));
      tr.appendChild(makeTd(createSelect(row, 'destination_id', 'Destination', destinationOptions, 'Pilih destination', formatAccount)));

      const type = detectType(row);
      const badge = document.createElement('span');
      badge.className = `type-badge ${type || 'invalid'}`;
      badge.dataset.typeBadge = row.id;
      badge.textContent = typeLabel(type);
      badge.title = type ? `Jenis transaksi terdeteksi: ${typeLabel(type)}` : 'Pilih kombinasi akun yang valid.';
      tr.appendChild(makeTd(badge));

      tr.appendChild(makeTd(createSelect(row, 'category_id', 'Kategori', state.categories, 'Tanpa kategori', (category) => category.name)));
      tr.appendChild(makeTd(createCellInput(row, 'date', 'Tanggal', 'date')));

      const status = document.createElement('span');
      status.className = `status-text ${row.statusKind || ''}`;
      status.dataset.status = row.id;
      status.textContent = row.statusDetail || row.status;
      status.title = row.statusDetail || row.status;
      tr.appendChild(makeTd(status));

      const del = document.createElement('button');
      del.type = 'button';
      del.className = 'row-delete';
      del.dataset.deleteRow = row.id;
      del.setAttribute('aria-label', 'Hapus baris');
      del.title = row.saved ? 'Transaksi sudah disimpan' : 'Hapus baris';
      del.textContent = '×';
      del.disabled = row.saved || row.busy;
      tr.appendChild(makeTd(del));
      tbody.appendChild(tr);
      updateRowVisual(row);
    });
    updateCounts();
  }

  function updateCounts() {
    rowCount.textContent = `${state.rows.length} baris`;
    const pending = state.rows.filter((row) => !row.saved && hasAnyValue(row)).length;
    saveButton.disabled = state.busy || pending === 0 || !state.accounts.length;
    saveLabel.textContent = state.busy ? 'Menyimpan…' : `Simpan transaksi${pending ? ` (${pending})` : ''}`;
  }

  function hasAnyValue(row) {
    return Boolean(String(row.amount).trim() || String(row.description).trim() || row.source_id || row.destination_id || row.category_id);
  }

  function updateRowVisual(row) {
    const type = detectType(row);
    if (type === 'transfer' && row.category_id) {
      row.category_id = '';
    }
    const badge = tbody.querySelector(`[data-type-badge="${CSS.escape(row.id)}"]`);
    if (badge) {
      badge.className = `type-badge ${type || 'invalid'}`;
      badge.textContent = typeLabel(type);
      badge.title = type ? `Jenis transaksi terdeteksi: ${typeLabel(type)}` : 'Kombinasi akun belum valid.';
    }
    const category = tbody.querySelector(`select[data-row-id="${CSS.escape(row.id)}"][data-field="category_id"]`);
    if (category) {
      category.disabled = row.saved || row.busy || type === 'transfer';
      if (type === 'transfer') category.value = '';
    }
    const status = tbody.querySelector(`[data-status="${CSS.escape(row.id)}"]`);
    if (status) {
      status.className = `status-text ${row.statusKind || ''}`;
      status.textContent = row.statusDetail || row.status;
      status.title = row.statusDetail || row.status;
    }
    updateCounts();
  }

  function setRowStatus(row, text, kind = '', detail = '') {
    row.status = text;
    row.statusKind = kind;
    row.statusDetail = detail || text;
    updateRowVisual(row);
  }

  function addRow(values = {}) {
    if (state.rows.length >= 500) {
      showToast('Maksimal 500 baris di tabel. Simpan atau hapus sebagian baris terlebih dahulu.', 'error');
      return;
    }
    state.rows.push(newRow(values));
    renderRows();
    const last = tbody.querySelector(`tr[data-row-id="${CSS.escape(state.rows[state.rows.length - 1].id)}"] input`);
    last?.focus();
  }

  function showToast(message, kind = '') {
    const toast = document.createElement('div');
    toast.className = `toast ${kind}`;
    toast.textContent = message;
    toastRegion.appendChild(toast);
    window.setTimeout(() => toast.remove(), 6500);
  }

  function setApiState(kind, text) {
    apiState.className = `api-state ${kind || ''}`;
    apiState.lastChild && (apiState.lastChild.textContent = ` ${text}`);
    // Keep the dot as the first child; replace text without relying on HTML.
    apiState.replaceChildren(Object.assign(document.createElement('span'), { className: 'state-dot' }), document.createTextNode(` ${text}`));
    apiState.className = `api-state ${kind || ''}`;
  }

  async function getJson(url) {
    const response = await fetch(url, { method: 'GET', credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' });
    let data;
    try { data = await response.json(); } catch { data = {}; }
    if (!response.ok || !data.ok) throw new Error(data.message || `Request gagal (HTTP ${response.status}).`);
    return data;
  }

  async function loadOptions() {
    setApiState('', 'Menghubungkan ke Firefly III…');
    try {
      const [accountsData, categoriesData] = await Promise.all([getJson('/api/accounts.php'), getJson('/api/categories.php')]);
      state.accounts = Array.isArray(accountsData.accounts) ? accountsData.accounts : [];
      state.categories = Array.isArray(categoriesData.categories) ? categoriesData.categories : [];
      setApiState('is-ready', `${state.accounts.length} akun · ${state.categories.length} kategori`);
      if (!state.accounts.length) {
        setApiState('is-error', 'Akun tidak ditemukan');
        showToast('Tidak ada akun aset, pengeluaran, atau pendapatan yang tersedia. Periksa akun di Firefly III.', 'error');
      }
      renderRows();
      summaryTitle.textContent = 'Referensi Firefly III siap';
      summaryText.textContent = 'Pilih akun sumber dan tujuan. Jenis transaksi terdeteksi otomatis.';
    } catch (error) {
      setApiState('is-error', 'Gagal terhubung');
      summaryTitle.textContent = 'Tidak dapat memuat data Firefly III';
      summaryText.textContent = error.message;
      showToast(error.message, 'error');
      saveButton.disabled = true;
    }
  }


  function parsePastedDate(input) {
    const value = String(input ?? '').trim();
    if (!value) return '';
    if (/^\d{4}-\d{1,2}-\d{1,2}$/.test(value)) {
      const [y, m, d] = value.split('-');
      return `${y}-${m.padStart(2, '0')}-${d.padStart(2, '0')}`;
    }
    const match = value.match(/^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$/);
    if (match) return `${match[3]}-${match[2].padStart(2, '0')}-${match[1].padStart(2, '0')}`;
    return value;
  }

  function resolveByNameOrId(value, items) {
    const needle = String(value ?? '').trim().toLocaleLowerCase('id-ID');
    if (!needle) return { id: '', found: true };
    const byId = items.find((item) => String(item.id) === needle);
    if (byId) return { id: String(byId.id), found: true };
    const matches = items.filter((item) => String(item.name).trim().toLocaleLowerCase('id-ID') === needle);
    if (matches.length === 1) return { id: String(matches[0].id), found: true };
    if (matches.length > 1) return { id: '', found: false, message: `Nama "${value}" cocok dengan beberapa data; pilih akun/kategori secara manual.` };
    return { id: '', found: false, message: `Data "${value}" tidak ditemukan.` };
  }

  function applyPastedValue(row, field, value) {
    const raw = String(value ?? '').trim();
    if (field === 'source_id' || field === 'destination_id') {
      const resolved = resolveByNameOrId(raw, state.accounts);
      row[field] = resolved.id;
      if (!resolved.found) row.pasteErrors[field] = resolved.message;
      else delete row.pasteErrors[field];
    } else if (field === 'category_id') {
      const resolved = resolveByNameOrId(raw, state.categories);
      row[field] = resolved.id;
      if (!resolved.found) row.pasteErrors[field] = `Kategori: ${resolved.message}`;
      else delete row.pasteErrors[field];
    } else if (field === 'date') {
      row[field] = parsePastedDate(raw);
      delete row.pasteErrors[field];
    } else {
      row[field] = raw;
      delete row.pasteErrors[field];
    }
    row.pasteError = Object.values(row.pasteErrors).join(' ');
  }

  function handlePaste(event) {
    const target = event.target;
    if (!(target instanceof HTMLElement)) return;
    const field = target.dataset.field;
    const rowId = target.dataset.rowId;
    if (!field || !rowId || !editableFields.includes(field)) return;
    const clipboard = event.clipboardData?.getData('text/plain') ?? '';
    if (!clipboard.includes('\t') && !clipboard.includes('\n')) return;
    event.preventDefault();

    let lines = clipboard.replace(/\r/g, '').split('\n');
    while (lines.length && lines[lines.length - 1] === '') lines.pop();
    if (lines.length && /nominal|nama transaksi/i.test(lines[0])) lines.shift();
    if (!lines.length) return;

    const startRowIndex = state.rows.findIndex((row) => row.id === rowId);
    const startFieldIndex = editableFields.indexOf(field);
    if (startRowIndex < 0 || startFieldIndex < 0) return;
    const skipped = [];
    lines.forEach((line, lineIndex) => {
      const cells = line.split('\t');
      const rowIndex = startRowIndex + lineIndex;
      if (rowIndex >= 500) return;
      while (state.rows.length <= rowIndex) state.rows.push(newRow());
      const row = state.rows[rowIndex];
      if (row.saved) {
        skipped.push(lineIndex + 1);
        return;
      }
      row.pasteError = '';
      cells.forEach((cell, cellIndex) => {
        const fieldIndex = startFieldIndex + cellIndex;
        if (fieldIndex >= editableFields.length) return;
        applyPastedValue(row, editableFields[fieldIndex], cell);
      });
      row.saved = false;
      row.statusKind = row.pasteError ? 'error' : '';
      row.status = row.pasteError ? 'Periksa hasil paste' : 'Belum disimpan';
      row.statusDetail = row.pasteError || row.status;
    });
    renderRows();
    showToast(`${lines.length - skipped.length} baris ditempel. Periksa kolom Source/Destination dan jenis transaksi sebelum menyimpan.${skipped.length ? ` ${skipped.length} baris tersimpan dilewati.` : ''}`, skipped.length ? '' : 'success');
  }

  function clearEmptyRows() {
    const before = state.rows.length;
    state.rows = state.rows.filter((row) => row.saved || hasAnyValue(row));
    const removed = before - state.rows.length;
    if (removed) {
      renderRows();
      showToast(`${removed} baris kosong dihapus.`, 'success');
    } else {
      showToast('Tidak ada baris kosong untuk dihapus.');
    }
  }

  async function saveTransactions() {
    if (state.busy) return;
    const allPending = state.rows.filter((row) => !row.saved && hasAnyValue(row));
    if (!allPending.length) {
      showToast('Tidak ada transaksi baru untuk disimpan.');
      return;
    }
    const pasteInvalidRows = allPending.filter((row) => row.pasteError);
    pasteInvalidRows.forEach((row) => {
      row.status = 'Periksa hasil paste';
      row.statusKind = 'error';
      row.statusDetail = row.pasteError;
    });
    const pending = allPending.filter((row) => !row.pasteError);
    if (!pending.length) {
      renderRows();
      showToast('Ada data hasil paste yang belum cocok dengan akun/kategori Firefly III. Perbaiki baris yang ditandai sebelum menyimpan.', 'error');
      return;
    }
    const invalidTypeRows = pending.filter((row) => !detectType(row));
    if (invalidTypeRows.length) {
      const continueAnyway = window.confirm(`${invalidTypeRows.length} baris memiliki kombinasi Source/Destination yang belum valid. Baris tersebut akan ditandai gagal; transaksi lain tetap diproses. Lanjutkan?`);
      if (!continueAnyway) return;
    }
    const pasteSkipMessage = pasteInvalidRows.length ? ` ${pasteInvalidRows.length} baris dengan hasil paste yang belum cocok akan dilewati.` : '';
    const ok = window.confirm(`Kirim ${pending.length} transaksi ke Firefly III? Transaksi dikirim dalam kelompok maksimal 25 baris. Transaksi yang berhasil langsung tercatat dan tidak otomatis dibatalkan jika baris lain gagal.${pasteSkipMessage}`);
    if (!ok) return;

    state.busy = true;
    pending.forEach((row) => {
      row.busy = true;
      row.status = 'Menunggu proses…';
      row.statusKind = 'working';
      row.statusDetail = row.status;
    });
    renderRows();
    updateCounts();
    let totalSuccess = 0;
    let totalFailed = 0;
    const chunks = [];
    const batchSize = 25;
    for (let i = 0; i < pending.length; i += batchSize) chunks.push(pending.slice(i, i + batchSize));

    try {
      for (let chunkIndex = 0; chunkIndex < chunks.length; chunkIndex++) {
        const chunk = chunks[chunkIndex];
        chunk.forEach((row) => {
          row.status = 'Memproses…';
          row.statusKind = 'working';
          row.statusDetail = row.status;
        });
        summaryTitle.textContent = `Menyimpan transaksi · kelompok ${chunkIndex + 1}/${chunks.length}`;
        summaryText.textContent = `Memproses baris ${chunkIndex * batchSize + 1}–${chunkIndex * batchSize + chunk.length} dari ${pending.length}.`;
        renderRows();

        try {
          const response = await fetch('/api/save-transactions.php', {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-Token': csrfToken },
            body: JSON.stringify({ rows: chunk.map((row) => ({
              row_id: row.id,
              amount: row.amount,
              description: row.description,
              source_id: row.source_id,
              destination_id: row.destination_id,
              category_id: row.category_id,
              date: row.date
            })) })
          });
          let data;
          try { data = await response.json(); } catch { data = {}; }
          if (!response.ok || !data.ok || !Array.isArray(data.results)) {
            throw new Error(data.message || `Penyimpanan gagal (HTTP ${response.status}).`);
          }

          const resultMap = new Map(data.results.map((result) => [String(result.row_id), result]));
          chunk.forEach((row) => {
            row.busy = false;
            const result = resultMap.get(row.id);
            if (!result) {
              row.status = 'Hasil tidak diketahui';
              row.statusKind = 'error';
              row.statusDetail = 'Tidak ada hasil untuk baris ini. Periksa riwayat Firefly III sebelum mencoba ulang.';
              totalFailed++;
              return;
            }
            if (result.ok) {
              row.saved = true;
              row.statusKind = 'success';
              row.status = 'Berhasil';
              row.statusDetail = result.transaction_id ? `Berhasil disimpan · ID ${result.transaction_id}. ${result.message || ''}` : (result.message || 'Berhasil disimpan.');
              totalSuccess++;
            } else {
              row.statusKind = 'error';
              row.status = 'Gagal';
              row.statusDetail = result.message || 'Transaksi gagal disimpan.';
              totalFailed++;
            }
          });
        } catch (error) {
          chunk.forEach((row) => {
            row.busy = false;
            row.status = 'Hasil tidak pasti';
            row.statusKind = 'error';
            row.statusDetail = `${error.message} Jika request sempat diterima server, periksa riwayat Firefly III sebelum mencoba ulang.`;
            totalFailed++;
          });
        }
        renderRows();
      }

      summaryTitle.textContent = `${totalSuccess} berhasil · ${totalFailed} gagal`;
      summaryText.textContent = totalFailed ? 'Baris gagal bisa diperbaiki lalu dicoba kembali. Baris berhasil dikunci untuk mencegah pengiriman ulang.' : 'Semua transaksi berhasil dicatat di Firefly III.';
      showToast(`${totalSuccess} transaksi berhasil, ${totalFailed} gagal. Periksa kolom Status untuk detail.`, totalFailed ? '' : 'success');
    } finally {
      pending.forEach((row) => { row.busy = false; });
      state.busy = false;
      renderRows();
      updateCounts();
    }
  }

  function handleManualFieldChange(target) {
    const row = state.rows.find((item) => item.id === target.dataset.rowId);
    const field = target.dataset.field;
    if (!row || !field || !editableFields.includes(field)) return;
    row[field] = target.value;
    if (row.pasteErrors) delete row.pasteErrors[field];
    row.pasteError = Object.values(row.pasteErrors || {}).join(' ');
    row.status = row.pasteError ? 'Periksa hasil paste' : 'Belum disimpan';
    row.statusKind = row.pasteError ? 'error' : '';
    row.statusDetail = row.pasteError || '';
    updateRowVisual(row);
  }

  tbody.addEventListener('input', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement)) return;
    handleManualFieldChange(target);
  });

  tbody.addEventListener('change', (event) => {
    const target = event.target;
    if (!(target instanceof HTMLInputElement || target instanceof HTMLSelectElement)) return;
    handleManualFieldChange(target);
  });

  tbody.addEventListener('click', (event) => {
    const button = event.target.closest('[data-delete-row]');
    if (!button || button.disabled) return;
    const rowId = button.dataset.deleteRow;
    state.rows = state.rows.filter((row) => row.id !== rowId);
    if (!state.rows.length) state.rows.push(newRow());
    renderRows();
  });

  tbody.addEventListener('paste', handlePaste);
  addButton.addEventListener('click', () => addRow());
  addInlineButton.addEventListener('click', () => addRow());
  clearEmptyButton.addEventListener('click', clearEmptyRows);
  saveButton.addEventListener('click', saveTransactions);

  // Start with three editable rows, matching the quick-entry workflow.
  state.rows = [newRow(), newRow(), newRow()];
  renderRows();
  loadOptions();
})();

(() => {
    'use strict';

    const API = '../api/portal.php';
    const money = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });
    const dateFmt = new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
    const titles = { dashboard: 'Dashboard', penghuni: 'Data penghuni', 'detail-penghuni': 'Informasi penghuni', password: 'Reset sandi penghuni', kamar: 'Manajemen kamar', 'tambah-kamar': 'Tambah kamar', fasilitas: 'Fasilitas kamar', verifikasi: 'Verifikasi akun', laporan: 'Pelaporan masalah', pembayaran: 'Verifikasi pembayaran', keuangan: 'Keuangan' };
    const state = { csrf: '', user: null, residents: [], rooms: [], pending: [], reports: [], facilities: [], payments: [], transactions: [], summary: {}, selectedResidentId: 0, editingRoomId: 0 };
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const dateText = (value) => {
        if (!value) return '—';
        const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
        return Number.isNaN(date.getTime()) ? String(value) : dateFmt.format(date);
    };
    const initials = (name) => String(name || '').trim().split(/\s+/).slice(0, 2).map((part) => part[0] || '').join('').toUpperCase() || 'AK';
    const byId = (list, id) => list.find((item) => Number(item.id) === Number(id));
    let toastTimer;
    function toast(message) {
        const el = document.querySelector('#admin-toast');
        el.textContent = message;
        el.classList.add('is-visible');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(() => el.classList.remove('is-visible'), 3500);
    }
    async function request(resource, { method = 'GET', id = 0, body } = {}) {
        const url = new URL(API, window.location.href);
        url.searchParams.set('resource', resource);
        if (id) url.searchParams.set('id', String(id));
        const headers = { Accept: 'application/json' };
        if (body !== undefined) headers['Content-Type'] = 'application/json';
        if (method !== 'GET' && state.csrf) headers['X-CSRF-Token'] = state.csrf;
        const response = await fetch(url, { method, headers, credentials: 'same-origin', body: body === undefined ? undefined : JSON.stringify(body) });
        const data = await response.json().catch(() => ({}));
        if (response.status === 401) {
            window.location.href = '../index.html#login-admin';
            throw new Error(data.message || 'Silakan masuk kembali.');
        }
        if (!response.ok || data.status === 'error') throw new Error(data.message || 'Permintaan gagal.');
        return data;
    }
    function showError(err) { toast(err instanceof Error ? err.message : 'Terjadi kesalahan.'); }

    function normalizeResident(row) {
        const room = byId(state.rooms, row.kamar_id);
        return { ...row, name: row.nama, phone: row.nomor_telepon, roomId: row.kamar_id, roomNumber: row.nomor_kamar || room?.nomor_kamar || '', roomType: row.tipe_kamar || room?.tipe_kamar || '', start: row.tanggal_masuk, due: row.tanggal_habis, status: row.status === 'Menunggu ACC' ? 'Menunggu' : row.status };
    }
    function renderDashboard() {
        const d = state.summary;
        document.querySelector('#stat-residents').textContent = d.residents ?? 0;
        document.querySelector('#stat-occupied').textContent = d.occupied ?? 0;
        document.querySelector('#stat-rooms').textContent = d.rooms ?? 0;
        document.querySelector('#stat-vacant').textContent = d.vacant ?? 0;
        document.querySelector('#stat-pending').textContent = d.pending ?? 0;
        document.querySelector('#stat-income').textContent = money.format(Number(d.income_month) || 0);
        document.querySelector('#admin-period').textContent = new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' }).format(new Date());
        document.querySelector('#pending-nav-count').textContent = String(d.pending ?? 0);
        document.querySelector('#reports-nav-count').textContent = String(d.open_reports ?? 0);
        const waitingPayments = state.payments.filter((item) => item.status === 'Menunggu Verifikasi').length;
        document.querySelector('#payments-nav-count').textContent = String(waitingPayments);
        document.querySelector('#dashboard-pending').innerHTML = state.pending.length ? state.pending.slice(0, 3).map((p) => `<div class="admin-mini-row"><span class="mini-avatar">${escapeHtml(initials(p.nama))}</span><div class="mini-copy"><strong>${escapeHtml(p.nama)}</strong><small>Permohonan · ${escapeHtml(dateText(p.created_at))}</small></div><a class="mini-action" href="#verifikasi">Tinjau</a></div>`).join('') : '<div class="empty-message">Tidak ada pendaftaran yang menunggu.</div>';
        const open = state.reports.filter((r) => r.status !== 'Selesai');
        document.querySelector('#dashboard-reports').innerHTML = open.length ? open.slice(0, 3).map((r) => `<div class="admin-mini-row"><span class="mini-avatar">⚑</span><div class="mini-copy"><strong>${escapeHtml(r.judul)}</strong><small>${escapeHtml(r.nama_penghuni)} · Kamar ${escapeHtml(r.nomor_kamar || '—')}</small></div><a class="mini-action" href="#laporan">Buka</a></div>`).join('') : '<div class="empty-message">Tidak ada laporan aktif.</div>';
    }
    function renderResidents() {
        const q = document.querySelector('#resident-search').value.trim().toLowerCase();
        const status = document.querySelector('#resident-status-filter').value;
        const list = state.residents.map(normalizeResident).filter((r) => `${r.name} ${r.email} ${r.roomNumber} ${r.phone}`.toLowerCase().includes(q) && (status === 'all' || r.status === status));
        document.querySelector('#resident-result-count').textContent = `${list.length} data`;
        document.querySelector('#resident-table').innerHTML = list.length ? list.map((r) => `<tr><td><div class="person-cell"><span class="mini-avatar">${escapeHtml(initials(r.name))}</span><div><strong>${escapeHtml(r.name)}</strong><small>${escapeHtml(r.email)}</small></div></div></td><td>${r.roomNumber ? `Kamar ${escapeHtml(r.roomNumber)}` : 'Belum ditetapkan'}<div class="cell-secondary">${escapeHtml(r.roomType)}</div></td><td>${escapeHtml(r.phone || '—')}</td><td>${escapeHtml(dateText(r.start))}<div class="cell-secondary">Tempo ${escapeHtml(dateText(r.due))}</div></td><td><span class="admin-status ${r.status === 'Aktif' ? 'admin-status-green' : 'admin-status-amber'}">${escapeHtml(r.status)}</span></td><td><button class="table-action" data-resident-detail="${Number(r.id)}">Detail</button> <button class="table-action table-action-danger" data-resident-delete="${Number(r.id)}">Hapus</button></td></tr>`).join('') : '<tr><td class="table-empty" colspan="6">Data penghuni tidak ditemukan.</td></tr>';
    }
    function renderResidentDetail(id = state.selectedResidentId) {
        const r = byId(state.residents, id) || state.residents[0];
        if (!r) return;
        state.selectedResidentId = Number(r.id);
        const room = byId(state.rooms, r.kamar_id);
        document.querySelector('#detail-avatar').textContent = initials(r.nama);
        document.querySelector('#detail-name').textContent = r.nama;
        document.querySelector('#detail-email').textContent = r.email || '—';
        document.querySelector('#detail-status').textContent = r.status;
        const values = [['Nama lengkap', r.nama], ['Alamat email', r.email], ['Nomor telepon', r.nomor_telepon], ['Alamat asal', r.alamat], ['Kontak darurat', r.kontak_darurat], ['Kamar', room ? `${room.nomor_kamar} · ${room.tipe_kamar}` : '—'], ['Harga sewa', room ? money.format(Number(room.harga_per_bulan)) : '—'], ['Mulai sewa', dateText(r.tanggal_masuk)], ['Jatuh tempo', dateText(r.tanggal_habis)]];
        document.querySelector('#resident-detail-list').innerHTML = values.map(([label, value]) => `<div><span>${escapeHtml(label)}</span><strong>${escapeHtml(value || '—')}</strong></div>`).join('');
        fillPasswordResidents();
        document.querySelector('#password-resident').value = String(r.id);
    }
    function roomStatusClass(status) { return status === 'Terisi' ? 'admin-status-green' : status === 'Perbaikan' ? 'admin-status-red' : 'admin-status-gray'; }
    function renderRooms() {
        const q = document.querySelector('#room-search').value.trim().toLowerCase();
        const status = document.querySelector('#room-status-filter').value;
        const count = { Terisi: 0, Tersedia: 0, Perbaian: 0, Perbaikan: 0 };
        state.rooms.forEach((room) => { count[room.status_kamar] = (count[room.status_kamar] || 0) + 1; });
        document.querySelector('#room-count-total').textContent = state.rooms.length;
        document.querySelector('#room-count-occupied').textContent = count.Terisi || 0;
        document.querySelector('#room-count-vacant').textContent = count.Tersedia || 0;
        document.querySelector('#room-count-repair').textContent = count.Perbaikan || 0;
        const list = state.rooms.filter((r) => `${r.nomor_kamar} ${r.tipe_kamar} ${r.fasilitas || ''}`.toLowerCase().includes(q) && (status === 'all' || status === r.status_kamar));
        document.querySelector('#room-grid').innerHTML = list.length ? list.map((r) => {
            const resident = state.residents.find((item) => Number(item.kamar_id) === Number(r.id));
            const chips = String(r.fasilitas || '').split(',').map((x) => x.trim()).filter(Boolean);
            const statusOptions = r.status_kamar === 'Terisi' ? ['Terisi', 'Perbaikan'] : ['Tersedia', 'Perbaikan'];
            return `<article class="admin-room-card"><div class="room-card-top"><div><span class="room-card-icon">▦</span><div><strong>Kamar ${escapeHtml(r.nomor_kamar)}</strong><small>${escapeHtml(r.tipe_kamar)}</small></div></div><span class="admin-status ${roomStatusClass(r.status_kamar)}">${escapeHtml(r.status_kamar)}</span></div><div class="room-card-price"><span>Harga sewa per bulan</span><strong>${escapeHtml(money.format(Number(r.harga_per_bulan)))}</strong></div><div class="room-card-occupant">${r.status_kamar === 'Terisi' ? `Penghuni: ${escapeHtml(resident?.nama_lengkap || resident?.nama || 'Penghuni aktif')}` : 'Belum ada penghuni'}</div><div class="room-facility-pills">${chips.map((x) => `<span>${escapeHtml(x)}</span>`).join('') || '<span>Belum diatur</span>'}</div><div class="room-card-footer"><label class="cell-secondary" for="room-status-${Number(r.id)}">Status</label><select id="room-status-${Number(r.id)}" data-room-status="${Number(r.id)}" aria-label="Status kamar ${escapeHtml(r.nomor_kamar)}">${statusOptions.map((s) => `<option ${r.status_kamar === s ? 'selected' : ''}>${s}</option>`).join('')}</select></div><div class="room-card-actions"><button type="button" class="table-action" data-room-edit="${Number(r.id)}">Edit</button><button type="button" class="table-action table-action-danger" data-room-delete="${Number(r.id)}">Hapus</button></div></article>`;
        }).join('') : '<div class="empty-message">Tidak ada kamar yang cocok.</div>';
    }
    function checksHtml(checked = []) {
        return state.facilities.map((facility) => `<label class="admin-check"><input type="checkbox" name="facilities" value="${escapeHtml(facility.nama)}" ${checked.includes(facility.nama) ? 'checked' : ''}><span>${escapeHtml(facility.nama)}</span></label>`).join('');
    }
    function fillPasswordResidents() {
        const select = document.querySelector('#password-resident');
        const active = state.residents.filter((r) => r.status === 'Aktif');
        const prev = select.value;
        select.innerHTML = active.map((r) => `<option value="${Number(r.id)}">${escapeHtml(r.nama)} · ${escapeHtml(r.nomor_kamar || 'Tanpa kamar')}</option>`).join('') || '<option value="">Tidak ada penghuni aktif</option>';
        if (active.some((r) => Number(r.id) === Number(prev))) select.value = prev;
        else if (active.some((r) => Number(r.id) === Number(state.selectedResidentId))) select.value = String(state.selectedResidentId);
    }
    function fillRoomSelect() {
        const options = state.rooms.map((r) => `<option value="${Number(r.id)}">Kamar ${escapeHtml(r.nomor_kamar)} · ${escapeHtml(r.status_kamar)}${r.status_kamar === 'Terisi' ? ' · tidak tersedia untuk alokasi' : ''}</option>`).join('');
        const select = document.querySelector('#facility-room-select');
        const previous = select.value;
        select.innerHTML = options;
        if (state.rooms.some((r) => String(r.id) === previous)) select.value = previous;
        const room = byId(state.rooms, select.value) || state.rooms[0];
        const chosen = String(room?.fasilitas || '').split(',').map((x) => x.trim()).filter(Boolean);
        document.querySelector('#facility-checkboxes').innerHTML = checksHtml(chosen);
        document.querySelector('#room-facility-checks').innerHTML = checksHtml();
    }
    function renderFacilities() {
        document.querySelector('#facility-master-list').innerHTML = state.facilities.map((f) => `<span class="facility-master-item">${escapeHtml(f.nama)}<button type="button" class="facility-delete" data-facility-delete="${Number(f.id)}" aria-label="Hapus fasilitas ${escapeHtml(f.nama)}" title="Hapus fasilitas">×</button></span>`).join('') || '<span class="cell-secondary">Belum ada fasilitas.</span>';
        document.querySelector('#facility-room-table').innerHTML = state.rooms.map((r) => `<tr><td><strong>Kamar ${escapeHtml(r.nomor_kamar)}</strong></td><td>${escapeHtml(r.tipe_kamar)}</td><td>${String(r.fasilitas || '').split(',').filter(Boolean).map((x) => `<span class="facility-table-chip">${escapeHtml(x.trim())}</span>`).join(' ') || '—'}</td><td>${String(r.fasilitas || '').split(',').filter(Boolean).length} fasilitas</td></tr>`).join('');
        fillRoomSelect();
    }
    function renderRegistrations() {
        document.querySelector('#verification-count').textContent = state.pending.length;
        document.querySelector('#pending-nav-count').textContent = state.pending.length;
        document.querySelector('#verification-list').innerHTML = state.pending.length ? state.pending.map((p) => `<article class="verification-card" data-verification="${Number(p.id)}"><div><div class="verification-person"><span class="mini-avatar">${escapeHtml(initials(p.nama))}</span><div><h2>${escapeHtml(p.nama)}</h2><p>Daftar ${escapeHtml(dateText(p.created_at))} · ${escapeHtml(p.email)}</p></div></div><div class="verification-fields"><div><span>NOMOR TELEPON</span><strong>${escapeHtml(p.nomor_telepon || '—')}</strong></div><div><span>EMAIL</span><strong>${escapeHtml(p.email)}</strong></div><div><span>KAMAR DIAJUKAN</span><strong>${escapeHtml(p.nomor_kamar ? `Kamar ${p.nomor_kamar} · ${p.tipe_kamar}` : 'Pilih kamar')}</strong></div><div><span>ALAMAT</span><strong>${escapeHtml(p.alamat || '—')}</strong></div><div><span>KONTAK DARURAT</span><strong>${escapeHtml(p.kontak_darurat || '—')}</strong></div><div><span>STATUS</span><strong>Menunggu persetujuan</strong></div></div></div><div class="verification-actions"><select aria-label="Pilih kamar untuk ${escapeHtml(p.nama)}"><option value="">Pilih kamar tersedia</option>${state.rooms.filter((r) => r.status_kamar === 'Tersedia').map((r) => `<option value="${Number(r.id)}" ${Number(r.id) === Number(p.kamar_id) ? 'selected' : ''}>Kamar ${escapeHtml(r.nomor_kamar)} · ${escapeHtml(r.tipe_kamar)}</option>`).join('')}</select><div><button class="admin-btn-reject" data-verification-action="reject" type="button">Tolak</button><button class="admin-btn-approve" data-verification-action="approve" type="button">Setujui</button></div></div></article>`).join('') : '<div class="empty-message">Semua permohonan pendaftaran sudah ditinjau.</div>';
    }
    function renderReports() {
        const q = document.querySelector('#report-search').value.trim().toLowerCase();
        const status = document.querySelector('#report-status-filter').value;
        const open = state.reports.filter((r) => r.status !== 'Selesai');
        document.querySelector('#open-report-count').textContent = open.length;
        document.querySelector('#reports-nav-count').textContent = open.length;
        const list = state.reports.filter((r) => `${r.judul} ${r.kategori} ${r.nama_penghuni} ${r.nomor_kamar}`.toLowerCase().includes(q) && (status === 'all' || r.status === status));
        document.querySelector('#report-list').innerHTML = list.length ? list.map((r) => `<article class="report-card" data-report-id="${Number(r.id)}"><div class="report-card-head"><div><h2>${escapeHtml(r.judul)}</h2><p class="report-card-sub">${escapeHtml(r.nama_penghuni)} · Kamar ${escapeHtml(r.nomor_kamar || '—')}</p></div><span class="admin-status ${r.status === 'Selesai' ? 'admin-status-green' : r.status === 'Sedang Dikerjakan' ? 'admin-status-amber' : 'admin-status-gray'}">${escapeHtml(r.status)}</span></div><p class="report-card-description">${escapeHtml(r.deskripsi)}</p><div class="report-card-meta"><span>${escapeHtml(r.kategori)}</span><span>Urgensi: ${escapeHtml(r.urgensi)}</span><span>${escapeHtml(dateText(r.created_at))}</span></div>${r.tanggapan_admin ? `<div class="admin-report-reply"><strong>Tanggapan:</strong> ${escapeHtml(r.tanggapan_admin)}</div>` : ''}<div class="report-card-actions"><select aria-label="Status laporan ${escapeHtml(r.judul)}"><option ${r.status === 'Menunggu' ? 'selected' : ''}>Menunggu</option><option ${r.status === 'Sedang Dikerjakan' ? 'selected' : ''}>Sedang Dikerjakan</option><option ${r.status === 'Selesai' ? 'selected' : ''}>Selesai</option></select><button data-report-save="${Number(r.id)}" type="button">Simpan status</button></div></article>`).join('') : '<div class="empty-message">Tidak ada laporan yang cocok.</div>';
    }
    function renderPayments() {
        const waiting = state.payments.filter((p) => p.status === 'Menunggu Verifikasi');
        document.querySelector('#pending-payment-count').textContent = waiting.length;
        document.querySelector('#payments-nav-count').textContent = waiting.length;
        document.querySelector('#admin-payment-table').innerHTML = state.payments.length ? [...state.payments].map((p) => `<tr><td><div class="person-cell"><span class="mini-avatar">${escapeHtml(initials(p.nama))}</span><div><strong>${escapeHtml(p.nama)}</strong><small>${escapeHtml(p.email)}</small></div></div></td><td><strong>${escapeHtml(p.invoice_no)}</strong><div class="cell-secondary">${escapeHtml(p.periode)} · Kamar ${escapeHtml(p.nomor_kamar || '—')}</div></td><td>${escapeHtml(dateText(p.tanggal_bayar))}</td><td>${escapeHtml(p.metode)}<div class="cell-secondary">${escapeHtml(p.catatan || '—')}</div>${p.bukti_transfer ? `<a class="table-action proof-link" href="../api/portal.php?resource=payment-proof&id=${Number(p.id)}" target="_blank" rel="noopener">Lihat bukti</a>` : '<span class="cell-secondary">Tanpa bukti foto</span>'}</td><td>${escapeHtml(money.format(Number(p.nominal)))}</td><td><span class="admin-status ${p.status === 'Lunas' ? 'admin-status-green' : 'admin-status-amber'}">${escapeHtml(p.status)}</span></td><td>${p.status === 'Menunggu Verifikasi' ? `<button type="button" class="table-action" data-payment-approve="${Number(p.id)}">Tandai lunas</button>` : '—'}</td></tr>`).join('') : '<tr><td colspan="7" class="table-empty">Belum ada pembayaran masuk.</td></tr>';
    }
    function renderFinance() {
        const s = state.finance.summary || {};
        const totalIncome = Number(s.total_masuk) || 0, totalExpense = Number(s.total_keluar) || 0;
        document.querySelector('#finance-balance').textContent = money.format(totalIncome - totalExpense);
        document.querySelector('#finance-income').textContent = money.format(Number(s.bulan_masuk) || 0);
        document.querySelector('#finance-expense').textContent = money.format(Number(s.bulan_keluar) || 0);
        document.querySelector('#transaction-count').textContent = `${state.transactions.length} transaksi`;
        document.querySelector('#transaction-table').innerHTML = state.transactions.length ? state.transactions.map((t) => `<tr><td>${escapeHtml(dateText(t.tanggal_transaksi))}</td><td><strong>${escapeHtml(t.keterangan || t.kategori)}</strong></td><td>${escapeHtml(t.kategori)}</td><td><span class="admin-status ${t.jenis_transaksi === 'Masuk' ? 'admin-status-green' : 'admin-status-red'}">${escapeHtml(t.jenis_transaksi)}</span></td><td class="${t.jenis_transaksi === 'Masuk' ? 'money-in' : 'money-out'}">${t.jenis_transaksi === 'Masuk' ? '+' : '−'} ${escapeHtml(money.format(Number(t.jumlah_uang)))}</td></tr>`).join('') : '<tr><td colspan="5" class="table-empty">Belum ada transaksi.</td></tr>';
    }
    function renderAll() { renderDashboard(); renderResidents(); renderResidentDetail(); renderRooms(); renderFacilities(); renderRegistrations(); renderReports(); renderPayments(); renderFinance(); fillPasswordResidents(); }
    async function refreshAll() {
        const [dashboard, residents, rooms, registrations, reports, finance, payments, facilities] = await Promise.all([
            request('admin-dashboard'), request('residents'), request('rooms'), request('registrations'), request('reports'), request('finance'), request('admin-payments'), request('facilities')
        ]);
        state.summary = dashboard.data;
        state.residents = residents.data;
        state.rooms = rooms.data;
        state.pending = registrations.data;
        state.reports = reports.data;
        state.finance = finance;
        state.transactions = finance.data;
        state.payments = payments.data;
        state.facilities = facilities.data;
        renderAll();
    }
    function navigate() {
        const route = location.hash.slice(1);
        const view = titles[route] ? route : 'dashboard';
        document.querySelectorAll('[data-admin-view]').forEach((el) => { el.hidden = el.dataset.adminView !== view; });
        document.querySelectorAll('[data-admin-link]').forEach((link) => {
            const active = link.dataset.adminLink === view || (view === 'detail-penghuni' && link.dataset.adminLink === 'penghuni');
            link.classList.toggle('is-active', active);
            if (active) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
        });
        document.querySelector('#admin-page-title').textContent = titles[view];
        if (view === 'detail-penghuni') renderResidentDetail();
    }

    document.querySelector('#admin-today').textContent = new Intl.DateTimeFormat('id-ID', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }).format(new Date());
    document.querySelector('#resident-search').addEventListener('input', renderResidents);
    document.querySelector('#resident-status-filter').addEventListener('change', renderResidents);
    document.querySelector('#room-search').addEventListener('input', renderRooms);
    document.querySelector('#room-status-filter').addEventListener('change', renderRooms);
    document.querySelector('#report-search').addEventListener('input', renderReports);
    document.querySelector('#report-status-filter').addEventListener('change', renderReports);
    document.querySelector('#focus-transaction').addEventListener('click', () => document.querySelector('#transaction-form [name="type"]').focus());
    document.querySelector('#transaction-form [name="date"]').value = new Date().toISOString().slice(0, 10);
    document.querySelector('#room-form [name="status"] option[value="Terisi"]').disabled = true;

    document.querySelector('#resident-table').addEventListener('click', (event) => {
        const button = event.target.closest('[data-resident-detail]');
        if (button) {
            state.selectedResidentId = Number(button.dataset.residentDetail);
            renderResidentDetail(); location.hash = 'detail-penghuni';
            return;
        }
        const deleteButton = event.target.closest('[data-resident-delete]');
        if (!deleteButton) return;
        const resident = byId(state.residents, deleteButton.dataset.residentDelete);
        if (!resident || !window.confirm(`Nonaktifkan akun ${resident.nama} dan lepaskan kamar jika sudah kosong? Riwayat transaksi tetap disimpan.`)) return;
        request('residents', { method: 'DELETE', id: resident.id }).then(async (result) => {
            if (state.selectedResidentId === Number(resident.id)) state.selectedResidentId = 0;
            await refreshAll(); toast(result.message || 'Akun penghuni dinonaktifkan.');
        }).catch(showError);
    });
    document.querySelector('#room-grid').addEventListener('change', async (event) => {
        const select = event.target.closest('[data-room-status]'); if (!select) return;
        const room = byId(state.rooms, select.dataset.roomStatus); if (!room) return;
        try { await request('rooms', { method: 'PATCH', id: room.id, body: { status_kamar: select.value } }); await refreshAll(); toast('Status kamar diperbarui.'); } catch (e) { showError(e); }
    });
    document.querySelector('#room-grid').addEventListener('click', async (event) => {
        const editButton = event.target.closest('[data-room-edit]');
        if (editButton) {
            const room = byId(state.rooms, editButton.dataset.roomEdit);
            if (!room) return;
            state.editingRoomId = Number(room.id);
            document.querySelector('#room-form [name="status"] option[value="Terisi"]').disabled = false;
            const form = document.querySelector('#room-form');
            form.elements.namedItem('number').value = room.nomor_kamar || '';
            form.elements.namedItem('type').value = room.tipe_kamar || '';
            form.elements.namedItem('price').value = room.harga_per_bulan || '';
            form.elements.namedItem('status').value = room.status_kamar || 'Tersedia';
            document.querySelectorAll('#room-facility-checks input[name="facilities"]').forEach((input) => {
                input.checked = String(room.fasilitas || '').split(',').map((item) => item.trim()).includes(input.value);
            });
            document.querySelector('#room-form-title').textContent = `Edit kamar ${room.nomor_kamar}`;
            document.querySelector('#room-form-submit').textContent = 'Simpan perubahan';
            document.querySelector('#room-form-cancel').hidden = false;
            location.hash = 'tambah-kamar';
            return;
        }
        const deleteButton = event.target.closest('[data-room-delete]');
        if (!deleteButton) return;
        const room = byId(state.rooms, deleteButton.dataset.roomDelete);
        if (!room || !window.confirm(`Hapus Kamar ${room.nomor_kamar}? Kamar yang memiliki penghuni, riwayat pembayaran, atau laporan tidak dapat dihapus.`)) return;
        try {
            const result = await request('rooms', { method: 'DELETE', id: room.id });
            await refreshAll(); toast(result.message || 'Kamar dihapus.');
        } catch (error) { showError(error); }
    });
    document.querySelector('#room-form').addEventListener('submit', async (event) => {
        event.preventDefault(); const form = event.currentTarget; if (!form.reportValidity()) return;
        const data = new FormData(form);
        const payload = { nomor_kamar: String(data.get('number')).trim(), tipe_kamar: String(data.get('type')).trim(), harga_per_bulan: Number(data.get('price')), status_kamar: data.get('status'), fasilitas: data.getAll('facilities').join(', ') };
        try {
            if (state.editingRoomId) {
                await request('rooms', { method: 'PATCH', id: state.editingRoomId, body: payload });
            } else {
                await request('rooms', { method: 'POST', body: payload });
            }
            const wasEditing = Boolean(state.editingRoomId);
            state.editingRoomId = 0;
            await refreshAll(); form.reset();
            document.querySelector('#room-form-title').textContent = 'Informasi kamar';
            document.querySelector('#room-form-submit').textContent = 'Simpan kamar';
            document.querySelector('#room-form-cancel').hidden = true;
            document.querySelector('#room-form [name="status"] option[value="Terisi"]').disabled = true;
            toast(wasEditing ? 'Data kamar berhasil diperbarui.' : 'Kamar berhasil ditambahkan ke database.'); location.hash = 'kamar';
        } catch (e) { showError(e); }
    });
    document.querySelector('#room-form-cancel').addEventListener('click', () => {
        state.editingRoomId = 0;
        document.querySelector('#room-form').reset();
        document.querySelector('#room-form-title').textContent = 'Informasi kamar';
        document.querySelector('#room-form-submit').textContent = 'Simpan kamar';
        document.querySelector('#room-form-cancel').hidden = true;
        document.querySelector('#room-form [name="status"] option[value="Terisi"]').disabled = true;
    });
    document.querySelector('#facility-room-select').addEventListener('change', () => {
        const room = byId(state.rooms, document.querySelector('#facility-room-select').value);
        const checked = String(room?.fasilitas || '').split(',').map((x) => x.trim()).filter(Boolean);
        document.querySelector('#facility-checkboxes').innerHTML = checksHtml(checked);
    });
    document.querySelector('#facility-form').addEventListener('submit', async (event) => {
        event.preventDefault(); const roomId = Number(document.querySelector('#facility-room-select').value);
        const facilities = [...document.querySelectorAll('#facility-checkboxes input:checked')].map((input) => input.value);
        try { await request('rooms', { method: 'PATCH', id: roomId, body: { fasilitas: facilities.join(', ') } }); await refreshAll(); toast('Fasilitas kamar disimpan ke database.'); } catch (e) { showError(e); }
    });
    document.querySelector('#facility-add-form').addEventListener('submit', async (event) => {
        event.preventDefault(); const form = event.currentTarget; const name = String(new FormData(form).get('facility') || '').trim();
        try { await request('facilities', { method: 'POST', body: { nama: name } }); await refreshAll(); form.reset(); toast('Fasilitas ditambahkan ke database.'); } catch (e) { showError(e); }
    });
    document.querySelector('#facility-master-list').addEventListener('click', async (event) => {
        const button = event.target.closest('[data-facility-delete]');
        if (!button) return;
        const facility = byId(state.facilities, button.dataset.facilityDelete);
        if (!facility || !window.confirm(`Hapus fasilitas "${facility.nama}" dari master dan semua kamar?`)) return;
        try {
            const result = await request('facilities', { method: 'DELETE', id: facility.id });
            await refreshAll(); toast(result.message || 'Fasilitas dihapus.');
        } catch (error) { showError(error); }
    });
    document.querySelector('#verification-list').addEventListener('click', async (event) => {
        const button = event.target.closest('[data-verification-action]'); if (!button) return;
        const card = button.closest('[data-verification]');
        const payload = { action: button.dataset.verificationAction };
        if (payload.action === 'approve') payload.kamar_id = Number(card.querySelector('select').value);
        if (payload.action === 'approve' && !payload.kamar_id) return toast('Pilih kamar yang tersedia terlebih dahulu.');
        try { await request('registrations', { method: 'POST', id: card.dataset.verification, body: payload }); await refreshAll(); toast('Status pendaftaran diperbarui.'); } catch (e) { showError(e); }
    });
    document.querySelector('#report-list').addEventListener('click', async (event) => {
        const button = event.target.closest('[data-report-save]'); if (!button) return;
        const card = button.closest('[data-report-id]');
        const status = card.querySelector('select').value;
        const replyInput = window.prompt('Tanggapan untuk penghuni:', status === 'Selesai' ? 'Laporan telah selesai ditangani.' : 'Laporan sedang ditindaklanjuti.');
        if (replyInput === null) return;
        try { await request('reports', { method: 'PATCH', id: button.dataset.reportSave, body: { status, tanggapan_admin: replyInput.trim() } }); await refreshAll(); toast('Laporan diperbarui.'); } catch (e) { showError(e); }
    });
    document.querySelector('#admin-payment-table').addEventListener('click', async (event) => {
        const button = event.target.closest('[data-payment-approve]'); if (!button) return;
        if (!window.confirm('Konfirmasi bahwa dana sudah diterima dan tandai pembayaran ini lunas?')) return;
        try { await request('admin-payments', { method: 'PATCH', id: button.dataset.paymentApprove, body: { status: 'Lunas' } }); await refreshAll(); toast('Pembayaran diverifikasi dan dicatat pada keuangan.'); } catch (e) { showError(e); }
    });
    document.querySelector('#password-reset-form').addEventListener('submit', async (event) => {
        event.preventDefault(); const form = event.currentTarget; if (!form.reportValidity()) return;
        const data = new FormData(form); const userId = Number(data.get('residentId'));
        if (String(data.get('newPassword')) !== String(data.get('confirmPassword'))) return toast('Konfirmasi kata sandi tidak cocok.');
        if (!window.confirm('Reset kata sandi akun penghuni ini?')) return;
        try { await request('admin-reset-password', { method: 'PUT', body: { user_id: userId, new_password: data.get('newPassword') } }); form.reset(); fillPasswordResidents(); toast('Kata sandi penghuni berhasil direset.'); } catch (e) { showError(e); }
    });
    document.querySelector('#transaction-form').addEventListener('submit', async (event) => {
        event.preventDefault(); const form = event.currentTarget; if (!form.reportValidity()) return;
        const data = new FormData(form);
        try { await request('finance', { method: 'POST', body: { jenis_transaksi: data.get('type'), kategori: data.get('category'), jumlah_uang: Number(data.get('amount')), tanggal_transaksi: data.get('date'), keterangan: String(data.get('description') || '').trim() || data.get('category') } }); await refreshAll(); form.reset(); document.querySelector('#transaction-form [name="date"]').value = new Date().toISOString().slice(0, 10); toast('Transaksi tersimpan di database.'); } catch (e) { showError(e); }
    });
    window.addEventListener('hashchange', navigate);
    navigate();

    (async () => {
        try {
            const bootstrap = await request('bootstrap');
            state.csrf = bootstrap.csrf;
            state.user = bootstrap.user;
            if (!state.user || !['admin', 'petugas'].includes(state.user.role)) { window.location.href = '../index.html#login-admin'; return; }
            await refreshAll(); navigate();
        } catch (error) { showError(error); }
    })();
})();

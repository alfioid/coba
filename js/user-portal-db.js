(() => {
    'use strict';

    const API = '../api/portal.php';
    const state = { csrf: '', user: null, profile: null, payments: [], reports: [], bill: null };
    const views = ['dashboard', 'profil', 'edit-profil', 'pembayaran', 'laporan'];
    const viewTitles = { dashboard: 'Dashboard', profil: 'Profil saya', 'edit-profil': 'Edit informasi', pembayaran: 'Pembayaran', laporan: 'Laporan masalah' };
    const money = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 });
    const dateLong = (value) => {
        if (!value) return '—';
        const date = new Date(`${String(value).slice(0, 10)}T00:00:00`);
        return Number.isNaN(date.getTime()) ? String(value) : new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric' }).format(date);
    };
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    const initials = (name) => String(name || '').trim().split(/\s+/).slice(0, 2).map((part) => part[0] || '').join('').toUpperCase() || 'AK';
    let toastTimer;
    function toast(message) {
        const el = document.querySelector('#toast');
        el.textContent = message;
        el.classList.add('is-visible');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(() => el.classList.remove('is-visible'), 3500);
    }
    async function request(resource, { method = 'GET', body } = {}) {
        const url = new URL(API, window.location.href);
        url.searchParams.set('resource', resource);
        const headers = { Accept: 'application/json' };
        const isFormData = body instanceof FormData;
        if (body !== undefined && !isFormData) headers['Content-Type'] = 'application/json';
        if (method !== 'GET' && state.csrf) headers['X-CSRF-Token'] = state.csrf;
        const response = await fetch(url, { method, headers, credentials: 'same-origin', body: body === undefined ? undefined : (isFormData ? body : JSON.stringify(body)) });
        const data = await response.json().catch(() => ({}));
        if (response.status === 401) {
            window.location.href = '../index.html#login-user';
            throw new Error(data.message || 'Silakan masuk kembali.');
        }
        if (!response.ok || data.status === 'error') throw new Error(data.message || 'Permintaan gagal.');
        return data;
    }
    function showError(error) { toast(error instanceof Error ? error.message : 'Terjadi kesalahan.'); }
    function updateProofRequirement() {
        const method = document.querySelector('#payment-form [name="method"]').value;
        const required = method !== 'Tunai';
        document.querySelector('#payment-form [name="proof"]').required = required;
        document.querySelector('#proof-required-mark').hidden = !required;
    }

    function updateIdentity() {
        const p = state.profile;
        if (!p) return;
        document.querySelectorAll('[data-user-name]').forEach((el) => { el.textContent = p.nama || ''; });
        document.querySelectorAll('[data-user-email]').forEach((el) => { el.textContent = p.email || ''; });
        document.querySelectorAll('[data-user-phone]').forEach((el) => { el.textContent = p.nomor_telepon || '—'; });
        document.querySelectorAll('[data-user-address]').forEach((el) => { el.textContent = p.alamat || '—'; });
        document.querySelectorAll('[data-user-emergency]').forEach((el) => { el.textContent = p.kontak_darurat || '—'; });
        document.querySelectorAll('[data-user-initials]').forEach((el) => { el.textContent = initials(p.nama); });
        const room = p.kamar || {};
        document.querySelectorAll('[data-room]').forEach((el) => { el.textContent = room.nomor_kamar || '—'; });
        document.querySelectorAll('[data-room-type]').forEach((el) => { el.textContent = room.tipe_kamar || '—'; });
        document.querySelectorAll('[data-rent]').forEach((el) => { el.textContent = money.format(Number(room.harga_per_bulan) || 0); });
        const facilities = document.querySelector('#view-dashboard .detail-list > div:last-child strong');
        if (facilities) facilities.textContent = room.fasilitas || 'Belum ada informasi fasilitas';
        const form = document.querySelector('#profile-form');
        if (form && !form.dataset.initialized) {
            form.elements.namedItem('name').value = p.nama || '';
            form.elements.namedItem('email').value = p.email || '';
            form.elements.namedItem('phone').value = p.nomor_telepon || '';
            form.elements.namedItem('address').value = p.alamat || '';
            form.elements.namedItem('emergency').value = p.kontak_darurat || '';
            form.dataset.initialized = 'true';
        }
    }
    function renderDates() {
        const now = new Date();
        document.querySelector('#today-date').textContent = new Intl.DateTimeFormat('id-ID', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' }).format(now);
        document.querySelector('#summary-period').textContent = new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' }).format(now);
        document.querySelector('#invoice-period').textContent = state.bill?.periode || new Intl.DateTimeFormat('id-ID', { month: 'long', year: 'numeric' }).format(now);
        const invoice = document.querySelector('#invoice-number');
        if (invoice && state.bill?.invoice_no) invoice.textContent = state.bill.invoice_no;
        const paymentDate = document.querySelector('#payment-form [name="date"]');
        if (paymentDate && !paymentDate.value) paymentDate.value = now.toISOString().slice(0, 10);
        const due = document.querySelector('#due-date');
        if (due) due.textContent = state.profile?.penghuni?.tanggal_habis ? dateLong(state.profile.penghuni.tanggal_habis) : 'Hubungi pengelola';
    }
    function renderDashboard() {
        const active = state.reports.filter((r) => r.status !== 'Selesai').length;
        document.querySelector('#active-reports').textContent = String(active);
        const latestPayment = state.payments[0];
        const paymentStatus = document.querySelector('#payment-status');
        const invoiceStatus = document.querySelector('#invoice-status');
        const paid = latestPayment?.status === 'Lunas' && latestPayment?.periode === state.bill?.periode;
        if (paymentStatus) paymentStatus.textContent = paid ? 'Lunas' : latestPayment ? `Menunggu verifikasi (${latestPayment.status})` : 'Belum ada pembayaran';
        if (invoiceStatus) {
            invoiceStatus.textContent = paid ? '✓ Lunas' : latestPayment?.status === 'Menunggu Verifikasi' ? '● Menunggu verifikasi' : '● Menunggu pembayaran';
            invoiceStatus.className = paid ? 'pill pill-success' : 'pill pill-warning';
        }
        const latest = state.reports[0];
        const box = document.querySelector('#dashboard-latest-report');
        if (latest) {
            box.className = 'latest-report';
            box.innerHTML = `<span class="pill ${latest.status === 'Selesai' ? 'pill-success' : 'pill-warning'}">${escapeHtml(latest.status)}</span><strong>${escapeHtml(latest.judul)}</strong><p>${escapeHtml(latest.kategori)} · ${escapeHtml(dateLong(latest.created_at))}</p><a class="text-link" href="#laporan">Lihat laporan <span aria-hidden="true">→</span></a>`;
        } else {
            box.className = 'empty-state compact-empty';
            box.innerHTML = '<span class="empty-icon" aria-hidden="true">✓</span><strong>Belum ada laporan</strong><p>Semua aman. Jika ada kendala, kirim laporan baru.</p><a class="text-link" href="#laporan">Buat laporan <span aria-hidden="true">→</span></a>';
        }
    }
    function renderPayments() {
        const body = document.querySelector('#payment-history');
        document.querySelector('#payment-count').textContent = `${state.payments.length} transaksi`;
        body.innerHTML = state.payments.length ? state.payments.map((p) => `<tr><td>${escapeHtml(dateLong(p.tanggal_bayar))}</td><td>${escapeHtml(p.periode)}</td><td>${escapeHtml(p.metode)}</td><td>${escapeHtml(p.catatan || '—')}</td><td>${escapeHtml(money.format(Number(p.nominal)))}</td><td><span class="status-tag ${p.status === 'Lunas' ? 'status-tag-success' : ''}">${escapeHtml(p.status)}</span></td></tr>`).join('') : '<tr><td class="table-empty" colspan="6">Belum ada riwayat pembayaran.</td></tr>';
        const bill = state.bill;
        if (bill) {
            document.querySelectorAll('[data-rent]').forEach((el) => { el.textContent = money.format(Number(bill.nominal) || 0); });
            document.querySelector('#invoice-number').textContent = bill.invoice_no;
            document.querySelector('#invoice-period').textContent = bill.periode;
        }
    }
    function renderReports() {
        const container = document.querySelector('#report-history');
        document.querySelector('#report-count').textContent = String(state.reports.length);
        document.querySelector('#report-history-count').textContent = `${state.reports.length} laporan`;
        container.innerHTML = state.reports.length ? state.reports.map((r) => `<article class="report-item"><div><h3>${escapeHtml(r.judul)}</h3><p>${escapeHtml(r.deskripsi)}</p><div class="report-meta"><span>${escapeHtml(r.kategori)}</span><span>Urgensi: ${escapeHtml(r.urgensi)}</span><span>${escapeHtml(dateLong(r.created_at))}</span></div>${r.tanggapan_admin ? `<p class="user-report-reply"><strong>Tanggapan pengelola:</strong> ${escapeHtml(r.tanggapan_admin)}</p>` : ''}</div><span class="pill ${r.status === 'Selesai' ? 'pill-success' : 'pill-warning'}">${escapeHtml(r.status)}</span></article>`).join('') : '<div class="empty-state"><span class="empty-icon" aria-hidden="true">✦</span><strong>Belum ada laporan</strong><p>Laporan yang Anda kirim akan tampil di sini.</p></div>';
    }
    function navigate() {
        const requested = location.hash.slice(1);
        const current = views.includes(requested) ? requested : 'dashboard';
        document.querySelectorAll('[data-view]').forEach((view) => { view.hidden = view.dataset.view !== current; });
        document.querySelectorAll('[data-view-link]').forEach((link) => {
            const active = link.dataset.viewLink === current;
            link.classList.toggle('is-active', active);
            if (active) link.setAttribute('aria-current', 'page'); else link.removeAttribute('aria-current');
        });
        document.querySelector('#breadcrumb-title').textContent = viewTitles[current];
    }
    async function refreshUserData() {
        const [me, payments] = await Promise.all([request('me'), request('payments')]);
        state.profile = me.data;
        state.payments = payments.data || [];
        state.reports = me.reports || [];
        state.bill = payments.bill || null;
        updateIdentity(); renderDates(); renderPayments(); renderReports(); renderDashboard();
    }

    document.querySelector('#profile-form').addEventListener('submit', async (event) => {
        event.preventDefault(); const form = event.currentTarget; if (!form.reportValidity()) return;
        const data = new FormData(form);
        try {
            const result = await request('me', { method: 'PATCH', body: { nama: String(data.get('name')).trim(), email: String(data.get('email')).trim(), nomor_telepon: String(data.get('phone')).trim(), alamat: String(data.get('address')).trim(), kontak_darurat: String(data.get('emergency')).trim() } });
            state.profile = result.data; updateIdentity(); toast('Profil berhasil disimpan ke database.'); location.hash = 'profil';
        } catch (error) { showError(error); }
    });
    document.querySelector('#payment-form [name="method"]').addEventListener('change', updateProofRequirement);
    updateProofRequirement();
    document.querySelector('#payment-form').addEventListener('submit', async (event) => {
        event.preventDefault(); const form = event.currentTarget; if (!form.reportValidity()) return;
        const data = new FormData(form);
        try {
            const payment = new FormData();
            payment.set('tanggal_bayar', String(data.get('date') || ''));
            payment.set('metode', String(data.get('method') || 'QRIS'));
            payment.set('catatan', String(data.get('reference') || '').trim());
            const proof = data.get('proof');
            if (proof instanceof File && proof.size > 0) payment.set('bukti_transfer', proof);
            const result = await request('payments', { method: 'POST', body: payment });
            await refreshUserData(); form.reset(); renderDates(); toast(result.message || 'Konfirmasi pembayaran dicatat.');
            updateProofRequirement();
        } catch (error) { showError(error); }
    });
    document.querySelector('#report-form').addEventListener('submit', async (event) => {
        event.preventDefault(); const form = event.currentTarget; if (!form.reportValidity()) return;
        const data = new FormData(form);
        try {
            const result = await request('reports', { method: 'POST', body: { kategori: data.get('category'), urgensi: data.get('urgency'), judul: String(data.get('title')).trim(), deskripsi: String(data.get('description')).trim() } });
            await refreshUserData(); form.reset(); toast(result.message || 'Laporan terkirim.');
        } catch (error) { showError(error); }
    });
    window.addEventListener('hashchange', navigate);
    navigate();

    (async () => {
        try {
            const bootstrap = await request('bootstrap');
            state.csrf = bootstrap.csrf;
            state.user = bootstrap.user;
            if (!state.user || state.user.role !== 'penghuni') { window.location.href = '../index.html#login-user'; return; }
            await refreshUserData(); navigate();
        } catch (error) { showError(error); }
    })();
})();

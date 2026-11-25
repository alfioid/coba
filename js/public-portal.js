(() => {
    'use strict';

    const roomGrid = document.querySelector('#public-room-grid');
    const roomSelect = document.querySelector('#register-room');
    const API_URL = './api/public-kamar.php';
    const availableRooms = [];
    let publicSummary = { total_kamar: 0, kamar_tersedia: 0 };

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[character]);
    const formatCurrency = (value) => {
        const amount = Number(value);
        return Number.isFinite(amount) ? `Rp ${new Intl.NumberFormat('id-ID').format(amount)}` : 'Hubungi pengelola';
    };
    function setPublicView() {
        const hash = window.location.hash.replace(/^#/, '');
        const current = hash === 'register' ? 'register' : hash === 'login-admin' || hash === 'login-user' ? 'login' : 'home';
        document.querySelectorAll('[data-public-view]').forEach((view) => {
            view.hidden = view.dataset.publicView !== current;
        });
        document.querySelectorAll('.public-auth-tabs a').forEach((tab) => {
            const active = tab.id === (hash === 'login-admin' ? 'login-admin-tab' : 'login-user-tab');
            tab.classList.toggle('is-active', active);
            if (active) tab.setAttribute('aria-current', 'page');
            else tab.removeAttribute('aria-current');
        });

        const admin = hash === 'login-admin';
        document.querySelector('#login-role').value = admin ? 'admin' : 'penghuni';
        document.querySelector('#login-heading').textContent = admin ? 'Masuk admin' : 'Masuk penghuni';
        document.querySelector('#login-copy').textContent = admin ? 'Gunakan akun pengelola Alfian Kost.' : 'Gunakan email dan kata sandi akun penghuni Anda.';
        document.querySelector('#login-button-label').textContent = admin ? 'Masuk sebagai admin' : 'Masuk sebagai penghuni';
        document.querySelector('#auth-story-label').textContent = admin ? 'PORTAL PENGELOLA' : 'PORTAL PENGHUNI';
        document.querySelector('#auth-story-title').innerHTML = admin ? 'Ruang kelola yang<br>lebih tertata.' : 'Urus kebutuhan kost,<br>lebih mudah.';
        document.querySelector('#auth-story-copy').textContent = admin
            ? 'Kelola data kamar, penghuni, verifikasi akun, laporan, dan keuangan kost melalui satu panel.'
            : 'Akses informasi hunian, pantau pembayaran sewa, dan sampaikan kendala dari satu tempat.';
        document.querySelector('#auth-story-feature').textContent = admin ? 'Kelola operasional kost' : 'Hunian dalam genggaman';
        document.querySelector('#auth-story-feature-copy').textContent = admin
            ? 'Masuk dengan akun admin atau petugas untuk melanjutkan ke panel pengelola.'
            : 'Masuk dengan akun terdaftar untuk melanjutkan ke portal Alfian Kost.';
        document.querySelector('#login-bottom-note').innerHTML = admin
            ? 'Bukan admin? <a href="#login-user">Masuk sebagai penghuni</a>'
            : 'Belum punya akun? <a href="#register">Daftar akun penghuni</a>';
        document.querySelector('#public-login-form [name="email"]').placeholder = admin ? 'admin@email.com' : 'nama@email.com';
        const params = new URLSearchParams(window.location.search);
        const authError = params.get('login_error');
        const registerError = params.get('register_error');
        const authErrorBox = document.querySelector('#auth-form-error');
        const registerErrorBox = document.querySelector('#register-form-error');
        if (authErrorBox) {
            authErrorBox.hidden = !authError || current !== 'login';
            authErrorBox.textContent = authError || '';
        }
        if (registerErrorBox) {
            registerErrorBox.hidden = !registerError || current !== 'register';
            registerErrorBox.textContent = registerError || '';
        }
        if (authError || registerError) window.history.replaceState(null, '', `${window.location.pathname}${window.location.hash}`);
        document.title = current === 'register' ? 'Daftar Penghuni | Alfian Kost' : current === 'login' ? `${admin ? 'Login Admin' : 'Login Penghuni'} | Alfian Kost` : 'Alfian Kost - Promosi Publik';
        if (current === 'register') loadRooms();
    }

    function renderRoomCard(room) {
        const number = escapeHtml(room.nomor_kamar || room.id || '—');
        const facilities = escapeHtml(room.fasilitas || 'Fasilitas dapat dikonfirmasi ke pengelola');
        const price = escapeHtml(formatCurrency(room.harga_per_bulan));
        const type = escapeHtml(room.tipe_kamar || 'Kamar kost');
        const size = escapeHtml(room.ukuran_kamar || 'Ukuran konfirmasi ke pengelola');
        const imagePath = String(room.foto_url || '').trim();
        const image = /^(https?:\/\/|\/|\.\/|\.\.\/)[^\s]*$/i.test(imagePath)
            ? `<img src="${escapeHtml(imagePath)}" alt="Foto kamar ${number}" loading="lazy">`
            : '<div class="placeholder-content"><span class="placeholder-icon" aria-hidden="true">▧</span><span>Foto belum ditambahkan</span></div>';
        return `<article class="room-card"><div class="room-card-image">${image}</div><div class="room-card-header"><h3>Kamar ${number}</h3><span class="badge-available">Tersedia</span></div><p class="room-type">${type} · ${size}</p><p class="room-price">${price} <span>/ bulan</span></p><p class="room-facilities">${facilities}</p></article>`;
    }

    function renderRooms() {
        if (!roomGrid) return;
        roomGrid.innerHTML = availableRooms.length
            ? availableRooms.map(renderRoomCard).join('')
            : '<p class="rooms-empty-state">Belum ada kamar tersedia untuk ditampilkan.</p>';
        const total = Number(publicSummary.total_kamar) || availableRooms.length;
        const available = Number(publicSummary.kamar_tersedia) || availableRooms.length;
        const countLabel = document.querySelector('#rooms-available-count');
        if (countLabel) countLabel.textContent = `${available} dari ${total} kamar tersedia · Hubungi pengelola untuk konfirmasi`;
        const hero = document.querySelector('#hero-available-count');
        if (hero) hero.textContent = `${available} kamar tersedia`;
        document.querySelector('#public-room-total').textContent = `${total} kamar`;
        const prices = availableRooms.map((room) => Number(room.harga_per_bulan)).filter(Number.isFinite);
        document.querySelector('#public-room-price').textContent = prices.length ? `Mulai ${formatCurrency(Math.min(...prices))}` : 'Hubungi pengelola';
        const sizes = [...new Set(availableRooms.map((room) => room.ukuran_kamar).filter(Boolean))];
        document.querySelector('#public-room-size').textContent = sizes.length === 1 ? sizes[0] : sizes.length ? 'Bervariasi' : '—';
    }

    async function loadRooms() {
        if (roomSelect?.dataset.loaded === 'true' || roomSelect?.dataset.loading === 'true') return;
        if (roomSelect) {
            roomSelect.dataset.loading = 'true';
            roomSelect.replaceChildren(new Option('Memuat kamar tersedia...', ''));
        }
        try {
            const response = await fetch(API_URL, { headers: { Accept: 'application/json' } });
            const result = await response.json();
            if (!response.ok || result.status !== 'sukses' || !Array.isArray(result.data)) throw new Error('Daftar kamar belum dapat dimuat.');
            const available = result.data.filter((room) => String(room.status_kamar || '').toLowerCase() === 'tersedia');
            publicSummary = result.summary || publicSummary;
            availableRooms.splice(0, availableRooms.length, ...available);
            if (roomSelect) {
                roomSelect.replaceChildren(new Option(available.length ? 'Pilih kamar yang tersedia' : 'Belum ada kamar tersedia', ''));
                available.forEach((room) => {
                    const option = new Option(`Kamar ${room.nomor_kamar} · ${room.tipe_kamar} · ${formatCurrency(room.harga_per_bulan)} / bulan`, String(room.id));
                    option.dataset.number = room.nomor_kamar || '';
                    option.dataset.type = room.tipe_kamar || '';
                    option.dataset.price = String(room.harga_per_bulan || 0);
                    option.dataset.facilities = room.fasilitas || '';
                    roomSelect.add(option);
                });
                roomSelect.disabled = available.length === 0;
                roomSelect.dataset.loaded = 'true';
            }
            renderRooms();
        } catch (error) {
            console.error('Katalog kamar gagal dimuat:', error);
            if (roomSelect) {
                roomSelect.replaceChildren(new Option('Kamar belum bisa dimuat. Coba muat ulang halaman.', ''));
                roomSelect.disabled = true;
            }
            const previewType = document.querySelector('#register-preview-type');
            if (previewType) previewType.textContent = 'Pastikan Laragon dan koneksi database aktif.';
            if (roomGrid) roomGrid.innerHTML = '<p class="rooms-empty-state" role="alert">Katalog belum dapat dimuat. Pastikan Laragon aktif dan koneksi database tersedia.</p>';
            const hero = document.querySelector('#hero-available-count');
            if (hero) hero.textContent = 'Data kamar belum tersedia';
            const countLabel = document.querySelector('#rooms-available-count');
            if (countLabel) countLabel.textContent = 'Data ketersediaan belum dapat dimuat.';
        } finally {
            if (roomSelect) delete roomSelect.dataset.loading;
        }
    }

    function updateRoomPreview() {
        const option = roomSelect?.selectedOptions[0];
        const title = document.querySelector('#register-preview-title');
        const type = document.querySelector('#register-preview-type');
        const price = document.querySelector('#register-preview-price');
        const facilities = document.querySelector('#register-preview-facilities');
        if (!option?.value) {
            title.textContent = 'Pilih kamar';
            type.textContent = 'Detail kamar pilihan Anda akan muncul di sini.';
            price.textContent = '—';
            facilities.textContent = 'Pilih kamar untuk melihat fasilitas';
            return;
        }
        title.textContent = `Kamar ${option.dataset.number}`;
        type.textContent = option.dataset.type || 'Kamar kost';
        price.textContent = `${formatCurrency(option.dataset.price)} / bulan`;
        const items = (option.dataset.facilities || '').split(',').map((item) => item.trim()).filter(Boolean);
        facilities.replaceChildren(...(items.length ? items : ['Fasilitas standar']).map((item) => {
            const pill = document.createElement('span');
            pill.textContent = item;
            return pill;
        }));
    }

    document.querySelectorAll('[data-password-target]').forEach((button) => {
        button.addEventListener('click', () => {
            const form = button.closest('form');
            const inputs = button.dataset.passwordTarget === 'register'
                ? [form.elements.namedItem('password'), form.elements.namedItem('confirm_password')]
                : [form.elements.namedItem('password')];
            const show = inputs[0].type === 'password';
            inputs.forEach((input) => { input.type = show ? 'text' : 'password'; });
            button.textContent = show ? 'Sembunyikan' : 'Lihat';
            button.setAttribute('aria-label', show ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
        });
    });

    document.querySelector('#public-register-form').addEventListener('submit', (event) => {
        const form = event.currentTarget;
        const password = form.elements.namedItem('password');
        const confirm = form.elements.namedItem('confirm_password');
        confirm.setCustomValidity(password.value === confirm.value ? '' : 'Kata sandi dan konfirmasi belum cocok.');
        if (!form.reportValidity()) event.preventDefault();
    });
    document.querySelector('#public-register-form [name="confirm_password"]').addEventListener('input', (event) => event.currentTarget.setCustomValidity(''));
    document.querySelector('#public-register-form [name="tgl_masuk"]').value = new Date().toISOString().slice(0, 10);
    roomSelect?.addEventListener('change', updateRoomPreview);
    window.addEventListener('hashchange', setPublicView);
    setPublicView();
    loadRooms();
})();

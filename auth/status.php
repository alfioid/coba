<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_helper.php';

$pdo = db();
$email = trim((string)($_GET['email'] ?? ($_SESSION['email'] ?? '')));

// API Polling endpoint: auth/status.php?action=check&email=...
if (($_GET['action'] ?? '') === 'check') {
    header('Content-Type: application/json; charset=utf-8');
    if ($email === '') {
        echo json_encode(['status' => 'not_found']);
        exit;
    }
    $stmt = $pdo->prepare('SELECT status, nama, email FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $u = $stmt->fetch();
    if (!$u) {
        echo json_encode(['status' => 'not_found']);
        exit;
    }
    echo json_encode(['status' => $u['status'], 'nama' => $u['nama']]);
    exit;
}

// Ambil detail pendaftaran dari MySQL
$user = null;
if ($email !== '') {
    $stmt = $pdo->prepare('SELECT u.*, k.nomor_kamar, k.tipe_kamar, k.harga_per_bulan 
                           FROM users u 
                           LEFT JOIN kamar k ON u.kamar_id = k.id 
                           WHERE u.email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
}

if (!$user) {
    // Ambil calon penghuni terakhir yang menunggu ACC
    $stmt = $pdo->query('SELECT u.*, k.nomor_kamar, k.tipe_kamar, k.harga_per_bulan 
                         FROM users u 
                         LEFT JOIN kamar k ON u.kamar_id = k.id 
                         WHERE u.role = "penghuni" 
                         ORDER BY u.id DESC LIMIT 1');
    $user = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Status Pendaftaran | Alfian Kost</title>
    <link rel="stylesheet" href="../css/styles.css">
</head>
<body class="auth-page">

    <header class="auth-header-nav">
        <a href="../index.php" class="auth-brand" aria-label="Alfian Kost Beranda">
            <span class="auth-brand-icon" aria-hidden="true">🏠</span>
            <span>Alfian Kost</span>
        </a>
        <a href="login.php" class="auth-header-link">
            <span>Masuk ke Akun</span> ➔
        </a>
    </header>

    <main class="status-container">
        <article class="status-card" id="status-card">
            
            <?php if (!$user): ?>
                <div class="status-icon-circle pending">
                    <span>❓</span>
                </div>
                <h1>Data Tidak Ditemukan</h1>
                <p class="status-lead">Belum ada riwayat pendaftaran yang cocok. Silakan lakukan pendaftaran akun baru.</p>
                <a href="register.php" class="auth-submit-btn" style="text-decoration: none;">Daftar Akun Baru</a>
            <?php else: 
                $isAktif = ($user['status'] === 'Aktif');
                $isDitolak = ($user['status'] === 'Ditolak');
            ?>
                <div class="status-icon-circle <?= $isAktif ? 'approved' : 'pending' ?>" id="status-icon">
                    <span aria-hidden="true" id="icon-symbol"><?= $isAktif ? '✓' : ($isDitolak ? '✕' : '⏳') ?></span>
                </div>

                <h1 id="status-heading">
                    <?= $isAktif ? 'Pendaftaran Disetujui (ACC)!' : ($isDitolak ? 'Pendaftaran Ditolak' : 'Pendaftaran Berhasil Dikirim') ?>
                </h1>
                <p class="status-lead" id="status-lead">
                    <?= $isAktif ? 'Akun Anda telah di-ACC oleh Admin pengelola kost. Anda dapat langsung login dan masuk ke portal penghuni.' : ($isDitolak ? 'Mohon maaf, permohonan pendaftaran Anda tidak disetujui oleh admin pengelola kost.' : 'Permohonan akun penghuni Anda telah tersimpan di database dan sedang menunggu persetujuan (ACC) dari Admin Alfian Kost.') ?>
                </p>

                <div class="status-details-box">
                    <div class="status-row">
                        <span>Nama Calon Penghuni</span>
                        <strong><?= htmlspecialchars($user['nama']) ?></strong>
                    </div>
                    <div class="status-row">
                        <span>Alamat Email</span>
                        <strong id="user-email-target"><?= htmlspecialchars($user['email']) ?></strong>
                    </div>
                    <div class="status-row">
                        <span>Nomor WhatsApp / HP</span>
                        <strong><?= htmlspecialchars($user['nomor_telepon'] ?? '-') ?></strong>
                    </div>
                    <div class="status-row">
                        <span>Kamar yang Diajukan</span>
                        <strong>Kamar <?= htmlspecialchars($user['nomor_kamar'] ?? (string)$user['kamar_id']) ?> <?= !empty($user['tipe_kamar']) ? '· ' . htmlspecialchars($user['tipe_kamar']) : '' ?></strong>
                    </div>
                    <div class="status-row">
                        <span>Tanggal Pengajuan</span>
                        <strong><?= htmlspecialchars($user['created_at'] ?? date('Y-m-d')) ?></strong>
                    </div>
                    <div class="status-row">
                        <span>Status Pendaftaran</span>
                        <span class="badge-status-pill <?= $isAktif ? 'approved' : 'pending' ?>" id="display-status-badge">
                            <?= $isAktif ? '✓ Disetujui / Aktif' : ($isDitolak ? '✕ Ditolak Admin' : '⏳ Menunggu ACC Admin') ?>
                        </span>
                    </div>
                </div>

                <div class="auth-feature-box" style="margin-bottom: 1.5rem; text-align: left;">
                    <span class="auth-feature-icon" aria-hidden="true">ℹ️</span>
                    <div class="auth-feature-text">
                        <strong>Verifikasi Database Otomatis</strong>
                        <p>Halaman ini terhubung langsung ke database MySQL. Begitu admin mengklik tombol "ACC" pada panel admin, status di sini otomatis berubah tanpa perlu refresh manual.</p>
                    </div>
                </div>

                <div class="status-actions">
                    <a href="../#login-user" class="auth-submit-btn" id="btn-login-now" style="<?= $isAktif ? 'display:flex; background-color:#16a34a;' : 'display:none;' ?> text-decoration: none;">
                        <span aria-hidden="true">🚀</span> Masuk ke Akun Sekarang
                    </a>

                    <button type="button" class="auth-submit-btn" id="btn-refresh-status" style="<?= $isAktif ? 'display:none;' : 'display:flex;' ?> background-color: var(--color-primary);">
                        <span aria-hidden="true">🔄</span> Periksa Status Terkini
                    </button>

                    <a href="../index.html" class="auth-link" style="margin-top: 0.5rem; display: inline-block;">
                        ← Kembali ke Halaman utama
                    </a>
                </div>
            <?php endif; ?>

        </article>
    </main>

    <script>
        const emailEl = document.getElementById("user-email-target");
        const btnRefresh = document.getElementById("btn-refresh-status");
        const btnLoginNow = document.getElementById("btn-login-now");
        const statusBadge = document.getElementById("display-status-badge");
        const statusHeading = document.getElementById("status-heading");
        const statusLead = document.getElementById("status-lead");
        const statusIcon = document.getElementById("status-icon");
        const iconSymbol = document.getElementById("icon-symbol");

        async function checkStatus() {
            if (!emailEl) return;
            const email = emailEl.textContent.trim();
            try {
                const res = await fetch(`status.php?action=check&email=${encodeURIComponent(email)}`);
                const data = await res.json();
                if (data.status === 'Aktif') {
                    statusIcon.className = 'status-icon-circle approved';
                    iconSymbol.textContent = '✓';
                    statusHeading.textContent = 'Pendaftaran Disetujui (ACC)!';
                    statusLead.textContent = 'Akun Anda telah di-ACC oleh Admin pengelola kost. Anda dapat langsung login dan masuk ke portal penghuni.';
                    statusBadge.className = 'badge-status-pill approved';
                    statusBadge.textContent = '✓ Disetujui / Aktif';
                    if (btnLoginNow) btnLoginNow.style.display = 'flex';
                    if (btnRefresh) btnRefresh.style.display = 'none';
                } else if (data.status === 'Ditolak') {
                    statusIcon.className = 'status-icon-circle pending';
                    iconSymbol.textContent = '✕';
                    statusHeading.textContent = 'Pendaftaran Ditolak';
                    statusBadge.className = 'badge-status-pill';
                    statusBadge.style.background = '#fee2e2';
                    statusBadge.style.color = '#dc2626';
                    statusBadge.textContent = '✕ Ditolak Admin';
                }
            } catch (e) {
                console.warn('Gagal cek status:', e);
            }
        }

        btnRefresh?.addEventListener("click", () => {
            btnRefresh.innerHTML = "<span>Memeriksa database...</span>";
            checkStatus().finally(() => {
                setTimeout(() => {
                    btnRefresh.innerHTML = "<span aria-hidden='true'>🔄</span> Periksa Status Terkini";
                }, 500);
            });
        });

        // Polling database otomatis setiap 4 detik
        setInterval(checkStatus, 4000);
    </script>
</body>
</html>

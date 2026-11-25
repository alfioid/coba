<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_helper.php';

$pdo = db();
$error = '';
$success = '';

// Ambil daftar kamar dari database MySQL
$kamarStmt = $pdo->query("SELECT id, nomor_kamar, tipe_kamar, fasilitas, harga_per_bulan, status_kamar FROM kamar WHERE status_kamar = 'Tersedia' ORDER BY nomor_kamar ASC");
$kamarList = $kamarStmt->fetchAll();

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama = trim((string)($_POST['nama'] ?? ''));
    $telepon = trim((string)($_POST['telepon'] ?? ''));
    $email = trim(strtolower((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    $kamarId = (int)($_POST['kamar_id'] ?? 0);
    $alamat = trim((string)($_POST['alamat'] ?? ''));
    $catatan = trim((string)($_POST['catatan'] ?? ''));
    $tglMasuk = trim((string)($_POST['tgl_masuk'] ?? date('Y-m-d')));

    if ($nama === '' || $telepon === '' || $email === '' || $password === '' || $kamarId === 0) {
        $error = 'Semua field bertanda bintang (*) wajib diisi.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format alamat email tidak valid.';
    } elseif (strlen($password) < 6) {
        $error = 'Kata sandi minimal 6 karakter.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Kata sandi dan konfirmasi kata sandi tidak cocok.';
    } elseif (!DateTimeImmutable::createFromFormat('!Y-m-d', $tglMasuk) || DateTimeImmutable::createFromFormat('!Y-m-d', $tglMasuk)->format('Y-m-d') !== $tglMasuk) {
        $error = 'Tanggal mulai menempati tidak valid.';
    } else {
        try {
            $pdo->beginTransaction();
            $roomStmt = $pdo->prepare("SELECT id FROM kamar WHERE id = ? AND status_kamar = 'Tersedia' FOR UPDATE");
            $roomStmt->execute([$kamarId]);
            if (!$roomStmt->fetch()) {
                $pdo->rollBack();
                $error = 'Kamar yang dipilih sudah tidak tersedia. Silakan pilih kamar lain.';
            } else {
            // Cek apakah email sudah terdaftar
            $cek = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $cek->execute([$email]);
            if ($cek->fetch()) {
                $pdo->rollBack();
                $error = 'Email ini sudah terdaftar. Silakan login atau gunakan email lain.';
            } else {
                // Simpan ke database users dengan status Menunggu ACC
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins = $pdo->prepare('INSERT INTO users (nama, email, nomor_telepon, password_hash, role, kamar_id, alamat, kontak_darurat, status, tanggal_masuk) VALUES (?, ?, ?, ?, "penghuni", ?, ?, ?, "Menunggu ACC", ?)');
                $ins->execute([$nama, $email, $telepon, $hash, $kamarId, $alamat, $catatan, $tglMasuk]);
                $newUserId = (int)$pdo->lastInsertId();
                $pdo->commit();

                header('Location: status.php?email=' . urlencode($email) . '&registered=1');
                exit;
            }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Pendaftaran penghuni gagal: ' . $e->getMessage());
            $error = 'Pendaftaran belum dapat diproses. Silakan coba kembali atau hubungi pengelola.';
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error !== '') {
    header('Location: ../index.html?register_error=' . rawurlencode($error) . '#register');
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pendaftaran Akun Penghuni | Alfian Kost</title>
    <link rel="stylesheet" href="../css/styles.css">
</head>
<body class="auth-page">

    <header class="auth-header-nav">
        <a href="../index.php" class="auth-brand" aria-label="Alfian Kost Beranda">
            <span class="auth-brand-icon" aria-hidden="true">🏠</span>
            <span>Alfian Kost</span>
        </a>
        <a href="login.php" class="auth-header-link">
            <span>Sudah punya akun? Masuk</span> ➔
        </a>
    </header>

    <main class="register-container">
        <header class="register-header">
            <h1>Pendaftaran Akun Penghuni</h1>
            <p>Masukkan data diri Anda untuk pengajuan akun sewa kamar kost. Akun Anda akan disimpan di database dan diverifikasi oleh Admin (ACC) sebelum dapat digunakan sepenuhnya.</p>
        </header>

        <div class="register-grid">
            
            <!-- Formulir Pendaftaran -->
            <form id="form-daftar-penghuni" class="register-form-cards" method="POST" action="register.php" novalidate>
                
                <!-- 1. Data Diri & Kontak -->
                <section class="register-card">
                    <h2 class="register-card-title">
                        <span aria-hidden="true">👤</span> Data Diri & Kontak
                    </h2>
                    
                    <div class="auth-form">
                        <div class="auth-field">
                            <label for="reg-nama">Nama Lengkap <span style="color:#ef4444;">*</span></label>
                            <input type="text" id="reg-nama" name="nama" class="auth-input" placeholder="Contoh: Budi Santoso" required value="<?= htmlspecialchars($_POST['nama'] ?? '') ?>">
                        </div>

                        <div class="register-fields-2col">
                            <div class="auth-field">
                                <label for="reg-telepon">Nomor yang Bisa Dihubungi (HP/WA) <span style="color:#ef4444;">*</span></label>
                                <input type="tel" id="reg-telepon" name="telepon" class="auth-input" placeholder="08xxxxxxxxxx" required value="<?= htmlspecialchars($_POST['telepon'] ?? '') ?>">
                            </div>
                            <div class="auth-field">
                                <label for="reg-email">Alamat Email (untuk Login) <span style="color:#ef4444;">*</span></label>
                                <input type="email" id="reg-email" name="email" class="auth-input" placeholder="nama@email.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="auth-field">
                            <label for="reg-alamat">Alamat Asal / Asal Kota (Opsional)</label>
                            <input type="text" id="reg-alamat" name="alamat" class="auth-input" placeholder="Contoh: Jl. Merdeka No. 10, Bandung" value="<?= htmlspecialchars($_POST['alamat'] ?? '') ?>">
                        </div>
                    </div>
                </section>

                <!-- 2. Keamanan Akun -->
                <section class="register-card">
                    <h2 class="register-card-title">
                        <span aria-hidden="true">🔒</span> Keamanan Akun
                    </h2>
                    
                    <div class="register-fields-2col">
                        <div class="auth-field">
                            <label for="reg-password">Kata Sandi untuk Login <span style="color:#ef4444;">*</span></label>
                            <div class="auth-input-wrapper">
                                <input type="password" id="reg-password" name="password" class="auth-input" placeholder="Minimal 6 karakter" required minlength="6">
                                <button type="button" class="auth-toggle-pwd" id="toggle-reg-pwd" title="Lihat Sandi">👁️</button>
                            </div>
                        </div>

                        <div class="auth-field">
                            <label for="reg-confirm-password">Ulangi Kata Sandi <span style="color:#ef4444;">*</span></label>
                            <div class="auth-input-wrapper">
                                <input type="password" id="reg-confirm-password" name="confirm_password" class="auth-input" placeholder="Ketik ulang kata sandi" required minlength="6">
                            </div>
                        </div>
                    </div>
                </section>

                <!-- 3. Kamar yang Ditempati -->
                <section class="register-card">
                    <h2 class="register-card-title">
                        <span aria-hidden="true">🛏️</span> Kamar yang Ditempati
                    </h2>

                    <div class="auth-form">
                        <div class="register-fields-2col">
                            <div class="auth-field">
                                <label for="reg-kamar">Pilih Nomor Kamar <span style="color:#ef4444;">*</span></label>
                                <select id="reg-kamar" name="kamar_id" class="auth-input" required>
                                    <option value="" disabled <?= empty($_POST['kamar_id']) ? 'selected' : '' ?>>Pilih kamar...</option>
                                    <?php foreach ($kamarList as $k): ?>
                                        <option value="<?= $k['id'] ?>" 
                                            data-nomor="<?= htmlspecialchars($k['nomor_kamar']) ?>"
                                            data-tipe="<?= htmlspecialchars($k['tipe_kamar']) ?>"
                                            data-harga="<?= htmlspecialchars((string)$k['harga_per_bulan']) ?>"
                                            data-fasilitas="<?= htmlspecialchars($k['fasilitas']) ?>"
                                            data-status="<?= htmlspecialchars($k['status_kamar']) ?>"
                                            <?= (isset($_POST['kamar_id']) && (int)$_POST['kamar_id'] === (int)$k['id']) ? 'selected' : '' ?>>
                                            Kamar <?= htmlspecialchars($k['nomor_kamar']) ?> · <?= htmlspecialchars($k['tipe_kamar']) ?> (Rp <?= number_format((float)$k['harga_per_bulan'], 0, ',', '.') ?>/bln) [<?= htmlspecialchars($k['status_kamar']) ?>]
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="auth-field">
                                <label for="reg-tgl-masuk">Tanggal Mulai Menempati <span style="color:#ef4444;">*</span></label>
                                <input type="date" id="reg-tgl-masuk" name="tgl_masuk" class="auth-input" required value="<?= htmlspecialchars($_POST['tgl_masuk'] ?? date('Y-m-d')) ?>">
                            </div>
                        </div>

                        <div class="auth-field">
                            <label for="reg-catatan">Catatan / Kontak Darurat (Opsional)</label>
                            <textarea id="reg-catatan" name="catatan" class="auth-input" rows="2" placeholder="Contoh: Kontak orang tua 0812xxxxxx"><?= htmlspecialchars($_POST['catatan'] ?? '') ?></textarea>
                        </div>
                    </div>
                </section>

                <?php if ($error !== ''): ?>
                    <div class="alert-box alert-box--danger" role="alert">
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <div style="display: flex; gap: 1rem; align-items: center;">
                    <button type="submit" class="auth-submit-btn" style="flex: 1;" id="btn-submit-register">
                        <span aria-hidden="true">✓</span> Kirim Pendaftaran Akun
                    </button>
                    <a href="login.php" class="btn-secondary" style="padding: 0.85rem 1.5rem; border-radius: 10px; text-decoration: none; font-weight: 600; color: var(--color-text-muted); border: 1px solid var(--color-border); text-align: center;">Batal</a>
                </div>

            </form>

            <!-- Kolom Kanan: Preview Kamar & Alur Pendaftaran -->
            <aside class="register-preview-sticky">
                
                <!-- Card Preview Kamar -->
                <article class="room-preview-card" id="room-preview-box">
                    <span class="room-preview-badge" id="preview-floor-badge">Status Kamar</span>
                    <h3 class="room-preview-title" id="preview-room-title">Pilih Kamar</h3>
                    <p class="room-preview-price" id="preview-room-price">Rp 0 <span style="font-size: 0.85rem; font-weight: normal; color: #e5e7eb;">/ bulan</span></p>
                    
                    <p style="font-size: 0.85rem; opacity: 0.9; margin-bottom: 0.5rem;">Fasilitas Kamar:</p>
                    <div class="room-preview-facilities" id="preview-facilities-list">
                        <span class="room-preview-pill">Pilih nomor kamar di samping</span>
                    </div>
                </article>

                <!-- Alur Pendaftaran & ACC -->
                <section class="registration-steps-card">
                    <h3 style="font-size: 1rem; font-weight: 700; color: var(--color-primary); margin-bottom: 1rem;">Alur Verifikasi Akun:</h3>
                    
                    <div class="step-item">
                        <span class="step-number">1</span>
                        <div class="step-info">
                            <strong>Kirim Data Diri</strong>
                            <p>Data diri dan kamar yang dipilih akan tersimpan di database MySQL.</p>
                        </div>
                    </div>

                    <div class="step-item">
                        <span class="step-number">2</span>
                        <div class="step-info">
                            <strong>Menunggu ACC Admin</strong>
                            <p>Admin kost memeriksa permohonan melalui Panel ACC di Data Penghuni.</p>
                        </div>
                    </div>

                    <div class="step-item">
                        <span class="step-number">3</span>
                        <div class="step-info">
                            <strong>Akun Aktif & Login</strong>
                            <p>Setelah di-ACC, Anda dapat langsung login untuk bayar via QRIS dan lapor kendala.</p>
                        </div>
                    </div>
                </section>

            </aside>

        </div>
    </main>

    <script>
        const roomSelect = document.getElementById("reg-kamar");
        const previewFloor = document.getElementById("preview-floor-badge");
        const previewTitle = document.getElementById("preview-room-title");
        const previewPrice = document.getElementById("preview-room-price");
        const previewFacilities = document.getElementById("preview-facilities-list");
        const togglePwd = document.getElementById("toggle-reg-pwd");
        const pwdInput = document.getElementById("reg-password");
        const confirmPwdInput = document.getElementById("reg-confirm-password");

        function updatePreview() {
            const opt = roomSelect.options[roomSelect.selectedIndex];
            if (!opt || !opt.value) return;

            const nomor = opt.getAttribute("data-nomor") || "";
            const tipe = opt.getAttribute("data-tipe") || "";
            const harga = Number(opt.getAttribute("data-harga") || 0);
            const fasilitas = opt.getAttribute("data-fasilitas") || "";
            const status = opt.getAttribute("data-status") || "Tersedia";

            previewFloor.textContent = `${tipe} · ${status}`;
            previewTitle.textContent = `Kamar ${nomor}`;
            previewPrice.innerHTML = `Rp ${harga.toLocaleString("id-ID")} <span style="font-size: 0.85rem; font-weight: normal; color: #e5e7eb;">/ bulan</span>`;

            previewFacilities.innerHTML = "";
            const facArray = fasilitas.split(",").map(f => f.trim()).filter(Boolean);
            if (facArray.length === 0) facArray.push("Fasilitas standar");
            facArray.forEach(f => {
                const span = document.createElement("span");
                span.className = "room-preview-pill";
                span.textContent = f;
                previewFacilities.appendChild(span);
            });
        }

        roomSelect.addEventListener("change", updatePreview);
        if (roomSelect.selectedIndex > 0) {
            updatePreview();
        } else if (roomSelect.options.length > 1) {
            roomSelect.selectedIndex = 1;
            updatePreview();
        }

        togglePwd.addEventListener("click", () => {
            if (pwdInput.type === "password") {
                pwdInput.type = "text";
                confirmPwdInput.type = "text";
                togglePwd.textContent = "🙈";
            } else {
                pwdInput.type = "password";
                confirmPwdInput.type = "password";
                togglePwd.textContent = "👁️";
            }
        });
    </script>
</body>
</html>

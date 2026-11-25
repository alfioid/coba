<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

try {
    $pdo = db();
    echo "Terhubung ke database pemweb_obe...\n";

    // 1. Update tabel users
    echo "1. Memperbarui tabel users...\n";
    $pdo->exec("
        ALTER TABLE users 
            MODIFY COLUMN role ENUM('admin','petugas','viewer','penghuni') NOT NULL DEFAULT 'penghuni'
    ");

    $cols = array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(), 'Field');
    if (!in_array('nomor_telepon', $cols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN nomor_telepon VARCHAR(25) NULL DEFAULT NULL AFTER email");
    }
    if (!in_array('kamar_id', $cols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN kamar_id BIGINT(20) UNSIGNED NULL DEFAULT NULL AFTER nomor_telepon");
    }
    if (!in_array('alamat', $cols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN alamat TEXT NULL DEFAULT NULL AFTER kamar_id");
    }
    if (!in_array('kontak_darurat', $cols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN kontak_darurat VARCHAR(255) NULL DEFAULT NULL AFTER alamat");
    }
    if (!in_array('status', $cols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN status ENUM('Menunggu ACC','Aktif','Ditolak','Keluar') NOT NULL DEFAULT 'Menunggu ACC' AFTER kontak_darurat");
    }

    // 2. Buat tabel pelaporan_masalah jika belum ada
    echo "2. Memeriksa tabel pelaporan_masalah...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pelaporan_masalah (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            nama_penghuni VARCHAR(100) NOT NULL,
            kamar_id BIGINT(20) UNSIGNED NULL,
            kategori VARCHAR(100) NOT NULL,
            urgensi ENUM('Normal','Mendesak') NOT NULL DEFAULT 'Normal',
            judul VARCHAR(255) NOT NULL,
            deskripsi TEXT NOT NULL,
            foto_url VARCHAR(500) NULL,
            status ENUM('Menunggu','Sedang Dikerjakan','Selesai') NOT NULL DEFAULT 'Menunggu',
            tanggapan_admin TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_pelaporan (user_id),
            INDEX idx_kamar_pelaporan (kamar_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 3. Buat tabel pembayaran_qris jika belum ada
    echo "3. Memeriksa tabel pembayaran_qris...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pembayaran_qris (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            kamar_id BIGINT(20) UNSIGNED NULL,
            invoice_no VARCHAR(50) NOT NULL,
            periode VARCHAR(50) NOT NULL,
            nominal DECIMAL(10,2) NOT NULL,
            metode VARCHAR(50) NOT NULL DEFAULT 'QRIS',
            tanggal_bayar DATE NOT NULL,
            status ENUM('Lunas','Menunggu Verifikasi') NOT NULL DEFAULT 'Lunas',
            catatan TEXT NULL,
            bukti_transfer VARCHAR(500) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_bayar (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 4. Periksa kolom penghuni
    $colsPenghuni = array_column($pdo->query("SHOW COLUMNS FROM penghuni")->fetchAll(), 'Field');
    if (!in_array('user_id', $colsPenghuni, true)) {
        $pdo->exec("ALTER TABLE penghuni ADD COLUMN user_id INT(11) NULL DEFAULT NULL AFTER id");
    }
    if (!in_array('email', $colsPenghuni, true)) {
        $pdo->exec("ALTER TABLE penghuni ADD COLUMN email VARCHAR(100) NULL DEFAULT NULL AFTER nama_lengkap");
    }
    if (!in_array('tanggal_bayar', $colsPenghuni, true)) {
        $pdo->exec("ALTER TABLE penghuni ADD COLUMN tanggal_bayar DATE NULL DEFAULT NULL AFTER tanggal_masuk");
    }
    if (!in_array('tanggal_habis', $colsPenghuni, true)) {
        $pdo->exec("ALTER TABLE penghuni ADD COLUMN tanggal_habis DATE NULL DEFAULT NULL AFTER tanggal_bayar");
    }

    // 5. Seed / Pastikan Kamar Tersedia Lengkap (101 - 106, 201 - 206)
    echo "4. Menyinkronkan data kamar...\n";
    $defaultRooms = [
        ['101', 'Standar AC', 'Kasur, Lemari, Meja, Kursi, Kamar mandi dalam, AC', 1500000.00, 'Terisi'],
        ['102', 'Standar AC', 'Kasur, Lemari, Kamar mandi dalam, AC', 1500000.00, 'Tersedia'],
        ['103', 'Standar', 'Kasur, Lemari, Meja, Kursi, AC', 1500000.00, 'Tersedia'],
        ['104', 'VIP', 'Kasur, Lemari, Meja, Kursi, Kamar mandi dalam, AC, TV', 1750000.00, 'Tersedia'],
        ['105', 'Standar', 'Kasur, Lemari, Meja, Kamar mandi dalam', 1400000.00, 'Tersedia'],
        ['106', 'Standar AC', 'Kasur, Lemari, AC, Meja', 1500000.00, 'Tersedia'],
        ['201', 'Standar AC', 'Kasur, Lemari, Meja, Kursi, Kamar mandi dalam', 1500000.00, 'Tersedia'],
        ['202', 'VIP', 'Kasur, Lemari, Meja, Kamar mandi dalam, AC, Kulkas', 1800000.00, 'Tersedia'],
        ['203', 'Standar AC', 'Kasur, Lemari, Kursi, AC', 1500000.00, 'Tersedia'],
        ['204', 'VIP', 'Kasur, Lemari, Meja, Kursi, Kamar mandi dalam, AC', 1750000.00, 'Tersedia'],
        ['205', 'Standar', 'Kasur, Lemari, Meja, Kamar mandi dalam', 1400000.00, 'Tersedia'],
        ['206', 'Standar AC', 'Kasur, Lemari, Kursi, AC', 1500000.00, 'Tersedia'],
    ];

    $checkStmt = $pdo->prepare("SELECT id FROM kamar WHERE nomor_kamar = ?");
    $insertKamar = $pdo->prepare("INSERT INTO kamar (nomor_kamar, tipe_kamar, fasilitas, harga_per_bulan, status_kamar) VALUES (?, ?, ?, ?, ?)");

    foreach ($defaultRooms as $r) {
        $checkStmt->execute([$r[0]]);
        if (!$checkStmt->fetch()) {
            $insertKamar->execute($r);
        }
    }

    // Ambil ID kamar 101 & 102
    $checkStmt->execute(['101']);
    $kamar101 = $checkStmt->fetch();
    $kamar101Id = $kamar101 ? (int)$kamar101['id'] : 3;

    $checkStmt->execute(['102']);
    $kamar102 = $checkStmt->fetch();
    $kamar102Id = $kamar102 ? (int)$kamar102['id'] : null;

    // 6. Seed Akun Default
    echo "5. Menyinkronkan pengguna admin & user...\n";
    $pwdAdminHash = password_hash('admin123', PASSWORD_DEFAULT);
    $pwdBudiHash = password_hash('password123', PASSWORD_DEFAULT);

    // Pastikan admin ada
    $userStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $userStmt->execute(['admin@kost.com']);
    $adminRow = $userStmt->fetch();
    if ($adminRow) {
        $pdo->prepare("UPDATE users SET password_hash = ?, role = 'admin', status = 'Aktif' WHERE email = 'admin@kost.com'")
            ->execute([$pwdAdminHash]);
    } else {
        $pdo->prepare("INSERT INTO users (nama, email, password_hash, role, nomor_telepon, status) VALUES (?, ?, ?, 'admin', '0812-0000-9999', 'Aktif')")
            ->execute(['Alfian Admin', 'admin@kost.com', $pwdAdminHash]);
    }

    // Pastikan Budi Santoso (Penghuni Aktif) ada
    $userStmt->execute(['budi@kost.com']);
    $budiRow = $userStmt->fetch();
    if ($budiRow) {
        $pdo->prepare("UPDATE users SET password_hash = ?, role = 'penghuni', kamar_id = ?, status = 'Aktif' WHERE email = 'budi@kost.com'")
            ->execute([$pwdBudiHash, $kamar101Id]);
        $budiId = (int)$budiRow['id'];
    } else {
        $pdo->prepare("INSERT INTO users (nama, email, password_hash, role, nomor_telepon, kamar_id, alamat, kontak_darurat, status) VALUES (?, ?, ?, 'penghuni', '0812-3456-7890', ?, 'Jl. Merdeka No. 45, Bandung', 'Siti Rahma (Ibu) - 0813-2233-4455', 'Aktif')")
            ->execute(['Budi Santoso', 'budi@kost.com', $pwdBudiHash, $kamar101Id]);
        $budiId = (int)$pdo->lastInsertId();
    }

    // Pastikan Dewi Sartika (Menunggu ACC) ada
    $userStmt->execute(['dewi@kost.com']);
    $dewiRow = $userStmt->fetch();
    if ($dewiRow) {
        $pdo->prepare("UPDATE users SET password_hash = ?, role = 'penghuni', kamar_id = ?, status = 'Menunggu ACC' WHERE email = 'dewi@kost.com'")
            ->execute([$pwdBudiHash, $kamar102Id]);
    } else {
        $pdo->prepare("INSERT INTO users (nama, email, password_hash, role, nomor_telepon, kamar_id, alamat, kontak_darurat, status) VALUES (?, ?, ?, 'penghuni', '0857-1122-3344', ?, 'Jl. Cihampelas No. 80, Bandung', 'Bambang (Kakak) - 0857-9988-7766', 'Menunggu ACC')")
            ->execute(['Dewi Sartika', 'dewi@kost.com', $pwdBudiHash, $kamar102Id]);
    }

    // Sinkronkan data penghuni Budi Santoso di tabel penghuni
    $cekPenghuni = $pdo->prepare("SELECT id FROM penghuni WHERE email = ? OR user_id = ?");
    $cekPenghuni->execute(['budi@kost.com', $budiId]);
    $penghuniBudiRow = $cekPenghuni->fetch();
    if ($penghuniBudiRow) {
        $penghuniBudiId = (int)$penghuniBudiRow['id'];
    } else {
        $pdo->prepare("INSERT INTO penghuni (user_id, nama_lengkap, email, nomor_telepon, kamar_id, tanggal_masuk, tanggal_bayar, tanggal_habis, status_hunian) VALUES (?, ?, ?, ?, ?, '2026-11-01', '2026-11-01', '2026-12-01', 'Aktif')")
            ->execute([$budiId, 'Budi Santoso', 'budi@kost.com', '0812-3456-7890', $kamar101Id]);
        $penghuniBudiId = (int)$pdo->lastInsertId();
    }

    // Seed transaksi keuangan untuk Budi jika masih kosong
    $cekUang = $pdo->query("SELECT COUNT(*) FROM keuangan_kost")->fetchColumn();
    if ((int)$cekUang === 0) {
        $pdo->prepare("INSERT INTO keuangan_kost (penghuni_id, jenis_transaksi, kategori, jumlah_uang, tanggal_transaksi, keterangan) VALUES (?, 'Masuk', 'Sewa kamar', 1500000.00, '2026-11-01', 'Pembayaran sewa Kamar 101 - Budi Santoso')")
            ->execute([$penghuniBudiId]);
        $pdo->exec("INSERT INTO keuangan_kost (penghuni_id, jenis_transaksi, kategori, jumlah_uang, tanggal_transaksi, keterangan) VALUES (NULL, 'Keluar', 'Utilitas', 350000.00, '2026-11-02', 'Biaya listrik & air bulan November 2026')");
    }

    // Seed pelaporan masalah jika kosong
    $cekLapor = $pdo->query("SELECT COUNT(*) FROM pelaporan_masalah")->fetchColumn();
    if ((int)$cekLapor === 0) {
        $pdo->prepare("INSERT INTO pelaporan_masalah (user_id, nama_penghuni, kamar_id, kategori, urgensi, judul, deskripsi, status, tanggapan_admin) VALUES (?, 'Budi Santoso', ?, 'Kerusakan Air / Pipa', 'Normal', 'Keran wastafel bocor dan menetes', 'Keran wastafel kamar mandi tidak bisa ditutup rapat.', 'Sedang Dikerjakan', 'Teknisi dijadwalkan datang memperbaiki hari Rabu pukul 10:00 WIB.')")
            ->execute([$budiId, $kamar101Id]);
    }

    // Seed pembayaran QRIS jika kosong
    $cekBayar = $pdo->query("SELECT COUNT(*) FROM pembayaran_qris")->fetchColumn();
    if ((int)$cekBayar === 0) {
        $pdo->prepare("INSERT INTO pembayaran_qris (user_id, kamar_id, invoice_no, periode, nominal, metode, tanggal_bayar, status, catatan) VALUES (?, ?, 'INV-202611-101', 'November 2026', 1500000.00, 'QRIS', '2026-11-01', 'Lunas', 'Pembayaran sewa bulan November 2026 via QRIS BCA')")
            ->execute([$budiId, $kamar101Id]);
    }

    echo "=== DATABASE SETUP BERHASIL TUNTAS! ===\n";

} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

final class PortalApiError extends RuntimeException
{
    public int $statusCode;
    public function __construct(int $statusCode, string $message)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }
}

function portalReply(array $body, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function portalInput(): array
{
    $raw = file_get_contents('php://input');
    $input = json_decode($raw ?: '[]', true);
    if (!is_array($input)) {
        throw new PortalApiError(400, 'Format JSON tidak valid.');
    }
    return $input;
}

function portalRequireRole(array $roles): array
{
    if (empty($_SESSION['user_id'])) {
        throw new PortalApiError(401, 'Sesi login berakhir. Silakan masuk kembali.');
    }
    $stmt = db()->prepare('SELECT id, nama, email, role, status, nomor_telepon, kamar_id, alamat, kontak_darurat FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user || ($user['role'] === 'penghuni' && $user['status'] !== 'Aktif')) {
        $_SESSION = [];
        session_destroy();
        throw new PortalApiError(401, 'Akun tidak aktif atau sesi sudah tidak berlaku.');
    }
    if (!in_array($user['role'], $roles, true)) {
        throw new PortalApiError(403, 'Anda tidak memiliki izin untuk tindakan ini.');
    }
    return $user;
}

function portalRequireCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['portal_csrf'] ?? '';
    if (!is_string($token) || $expected === '' || !hash_equals($expected, $token)) {
        throw new PortalApiError(403, 'Token keamanan tidak valid. Muat ulang halaman.');
    }
}

function portalDate(string $value, string $label): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new PortalApiError(422, "Tanggal {$label} tidak valid.");
    }
    return $value;
}

function portalRoom(PDO $pdo, int $roomId): ?array
{
    $stmt = $pdo->prepare('SELECT id, nomor_kamar, tipe_kamar, fasilitas, harga_per_bulan, status_kamar, jenis_penghuni, ukuran_kamar, foto_url, tampilkan_publik FROM kamar WHERE id = ?');
    $stmt->execute([$roomId]);
    return $stmt->fetch() ?: null;
}

function portalUserData(PDO $pdo, array $user): array
{
    $room = !empty($user['kamar_id']) ? portalRoom($pdo, (int) $user['kamar_id']) : null;
    $residentStmt = $pdo->prepare('SELECT id, tanggal_masuk, tanggal_bayar, tanggal_habis, status_hunian FROM penghuni WHERE user_id = ? OR email = ? ORDER BY id DESC LIMIT 1');
    $residentStmt->execute([(int) $user['id'], $user['email']]);
    $resident = $residentStmt->fetch() ?: null;
    return [
        'id' => (int) $user['id'],
        'nama' => $user['nama'],
        'email' => $user['email'],
        'role' => $user['role'],
        'status' => $user['status'],
        'nomor_telepon' => $user['nomor_telepon'] ?? '',
        'alamat' => $user['alamat'] ?? '',
        'kontak_darurat' => $user['kontak_darurat'] ?? '',
        'kamar' => $room,
        'penghuni' => $resident,
    ];
}

try {
    $pdo = db();
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $resource = (string) ($_GET['resource'] ?? '');
    $id = max(0, (int) ($_GET['id'] ?? 0));

    if ($method === 'GET' && $resource === 'bootstrap') {
        $user = portalRequireRole(['admin', 'petugas', 'penghuni']);
        if (empty($_SESSION['portal_csrf'])) {
            $_SESSION['portal_csrf'] = bin2hex(random_bytes(32));
        }
        portalReply(['status' => 'sukses', 'csrf' => $_SESSION['portal_csrf'], 'user' => portalUserData($pdo, $user)]);
    }

    if ($resource === 'payment-proof') {
        $user = portalRequireRole(['admin', 'petugas', 'penghuni']);
        if ($method !== 'GET' || $id <= 0) throw new PortalApiError(404, 'Bukti pembayaran tidak ditemukan.');
        $stmt = $pdo->prepare('SELECT user_id, bukti_transfer FROM pembayaran_qris WHERE id = ?');
        $stmt->execute([$id]);
        $payment = $stmt->fetch();
        if (!$payment || empty($payment['bukti_transfer'])) throw new PortalApiError(404, 'Bukti pembayaran tidak ditemukan.');
        if ($user['role'] === 'penghuni' && (int) $payment['user_id'] !== (int) $user['id']) {
            throw new PortalApiError(403, 'Anda tidak memiliki izin untuk melihat bukti pembayaran ini.');
        }
        $filename = (string) $payment['bukti_transfer'];
        if (!preg_match('/\A[a-f0-9]{32}\.(?:jpg|png|webp)\z/', $filename)) throw new PortalApiError(404, 'Bukti pembayaran tidak ditemukan.');
        $proofPath = __DIR__ . '/../storage/payment-proofs/' . $filename;
        if (!is_file($proofPath)) throw new PortalApiError(404, 'File bukti pembayaran tidak ditemukan.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($proofPath);
        $mimeTypes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!isset($mimeTypes[$extension]) || $mime !== $mimeTypes[$extension]) throw new PortalApiError(404, 'Format bukti pembayaran tidak valid.');
        header('Content-Type: ' . $mimeTypes[$extension]);
        header('Content-Disposition: inline; filename="bukti-pembayaran.' . $extension . '"');
        header('Cache-Control: private, no-store, max-age=0');
        header('Content-Length: ' . (string) filesize($proofPath));
        readfile($proofPath);
        exit;
    }

    if ($resource === 'me') {
        $user = portalRequireRole(['penghuni']);
        if ($method === 'GET') {
            $profile = portalUserData($pdo, $user);
            $paymentStmt = $pdo->prepare('SELECT id, invoice_no, periode, nominal, metode, tanggal_bayar, status, catatan, bukti_transfer, created_at FROM pembayaran_qris WHERE user_id = ? ORDER BY id DESC LIMIT 100');
            $paymentStmt->execute([(int) $user['id']]);
            $reportStmt = $pdo->prepare('SELECT id, kategori, urgensi, judul, deskripsi, status, tanggapan_admin, created_at FROM pelaporan_masalah WHERE user_id = ? ORDER BY id DESC LIMIT 100');
            $reportStmt->execute([(int) $user['id']]);
            portalReply(['status' => 'sukses', 'data' => $profile, 'payments' => $paymentStmt->fetchAll(), 'reports' => $reportStmt->fetchAll()]);
        }
        if ($method === 'PATCH') {
            portalRequireCsrf();
            $input = portalInput();
            $name = trim((string) ($input['nama'] ?? ''));
            $email = strtolower(trim((string) ($input['email'] ?? '')));
            $phone = trim((string) ($input['nomor_telepon'] ?? ''));
            $address = trim((string) ($input['alamat'] ?? ''));
            $emergency = trim((string) ($input['kontak_darurat'] ?? ''));
            if (mb_strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '') {
                throw new PortalApiError(422, 'Nama, email yang valid, dan nomor telepon wajib diisi.');
            }
            $duplicate = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $duplicate->execute([$email, (int) $user['id']]);
            if ($duplicate->fetch()) {
                throw new PortalApiError(409, 'Email sudah digunakan akun lain.');
            }
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE users SET nama = ?, email = ?, nomor_telepon = ?, alamat = ?, kontak_darurat = ? WHERE id = ?')
                ->execute([$name, $email, $phone, $address, $emergency, (int) $user['id']]);
            $pdo->prepare('UPDATE penghuni SET nama_lengkap = ?, email = ?, nomor_telepon = ? WHERE user_id = ? OR email = ?')
                ->execute([$name, $email, $phone, (int) $user['id'], $user['email']]);
            $pdo->commit();
            $_SESSION['nama'] = $name;
            $_SESSION['email'] = $email;
            $user['nama'] = $name; $user['email'] = $email; $user['nomor_telepon'] = $phone; $user['alamat'] = $address; $user['kontak_darurat'] = $emergency;
            portalReply(['status' => 'sukses', 'message' => 'Profil berhasil diperbarui.', 'data' => portalUserData($pdo, $user)]);
        }
        throw new PortalApiError(405, 'Metode tidak diizinkan.');
    }

    if ($resource === 'password') {
        $user = portalRequireRole(['penghuni', 'admin', 'petugas']);
        if ($method !== 'PUT') throw new PortalApiError(405, 'Metode tidak diizinkan.');
        portalRequireCsrf();
        $input = portalInput();
        $current = (string) ($input['current_password'] ?? '');
        $new = (string) ($input['new_password'] ?? '');
        if (strlen($new) < 8) throw new PortalApiError(422, 'Kata sandi baru minimal 8 karakter.');
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([(int) $user['id']]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($current, (string) $row['password_hash'])) throw new PortalApiError(422, 'Kata sandi saat ini tidak sesuai.');
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), (int) $user['id']]);
        portalReply(['status' => 'sukses', 'message' => 'Kata sandi berhasil diubah.']);
    }

    if ($resource === 'admin-reset-password') {
        portalRequireRole(['admin', 'petugas']);
        if ($method !== 'PUT') throw new PortalApiError(405, 'Metode tidak diizinkan.');
        portalRequireCsrf();
        $input = portalInput();
        $targetId = (int) ($input['user_id'] ?? 0);
        $new = (string) ($input['new_password'] ?? '');
        if ($targetId <= 0 || strlen($new) < 8) throw new PortalApiError(422, 'Pilih penghuni dan gunakan kata sandi minimal 8 karakter.');
        $target = $pdo->prepare("SELECT id FROM users WHERE id = ? AND role = 'penghuni' AND status = 'Aktif'");
        $target->execute([$targetId]);
        if (!$target->fetch()) throw new PortalApiError(404, 'Akun penghuni aktif tidak ditemukan.');
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $targetId]);
        portalReply(['status' => 'sukses', 'message' => 'Kata sandi penghuni berhasil direset.']);
    }

    if ($resource === 'payments') {
        if ($method === 'GET') {
            $user = portalRequireRole(['penghuni']);
            $profile = portalUserData($pdo, $user);
            $room = $profile['kamar'];
            $period = new DateTimeImmutable('first day of this month');
            $invoice = sprintf('INV-%s-%s', $period->format('Ym'), $room['nomor_kamar'] ?? $user['kamar_id']);
            $stmt = $pdo->prepare('SELECT id, invoice_no, periode, nominal, metode, tanggal_bayar, status, catatan, bukti_transfer, created_at FROM pembayaran_qris WHERE user_id = ? ORDER BY id DESC LIMIT 100');
            $stmt->execute([(int) $user['id']]);
            portalReply(['status' => 'sukses', 'data' => $stmt->fetchAll(), 'bill' => ['invoice_no' => $invoice, 'periode' => $period->format('F Y'), 'nominal' => (float) ($room['harga_per_bulan'] ?? 0), 'room' => $room]]);
        }
        if ($method === 'POST') {
            $user = portalRequireRole(['penghuni']);
            portalRequireCsrf();
            $dateInput = (string) ($_POST['tanggal_bayar'] ?? '');
            $methodName = (string) ($_POST['metode'] ?? 'QRIS');
            $reference = trim((string) ($_POST['catatan'] ?? ''));
            if ($dateInput === '' && stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false) {
                $input = portalInput();
                $dateInput = (string) ($input['tanggal_bayar'] ?? '');
                $methodName = (string) ($input['metode'] ?? 'QRIS');
                $reference = trim((string) ($input['catatan'] ?? ''));
            }
            $date = portalDate($dateInput, 'pembayaran');
            if (!in_array($methodName, ['QRIS', 'Transfer bank', 'Tunai'], true)) throw new PortalApiError(422, 'Metode pembayaran tidak valid.');
            $profile = portalUserData($pdo, $user);
            $room = $profile['kamar'];
            if (!$room) throw new PortalApiError(409, 'Akun belum memiliki kamar aktif. Hubungi pengelola.');
            $period = new DateTimeImmutable('first day of this month');
            $periodLabel = $period->format('F Y');
            $duplicate = $pdo->prepare('SELECT id FROM pembayaran_qris WHERE user_id = ? AND periode = ? AND status IN (\'Lunas\', \'Menunggu Verifikasi\') LIMIT 1');
            $duplicate->execute([(int) $user['id'], $periodLabel]);
            if ($duplicate->fetch()) throw new PortalApiError(409, 'Konfirmasi pembayaran untuk periode ini sudah tercatat.');
            $proofFilename = null;
            $upload = $_FILES['bukti_transfer'] ?? null;
            if ($upload && is_array($upload) && (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if ((int) $upload['error'] !== UPLOAD_ERR_OK) throw new PortalApiError(422, 'Bukti transfer gagal diunggah. Silakan coba kembali.');
                if ((int) $upload['size'] <= 0 || (int) $upload['size'] > 5 * 1024 * 1024) throw new PortalApiError(422, 'Ukuran bukti transfer maksimal 5 MB.');
                $temporaryPath = (string) ($upload['tmp_name'] ?? '');
                if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) throw new PortalApiError(422, 'Bukti transfer tidak valid.');
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
                $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!isset($extensions[$mime])) throw new PortalApiError(422, 'Bukti transfer harus berupa foto JPG, PNG, atau WEBP.');
                $proofDirectory = __DIR__ . '/../storage/payment-proofs';
                if (!is_dir($proofDirectory) && !mkdir($proofDirectory, 0750, true) && !is_dir($proofDirectory)) {
                    throw new RuntimeException('Folder penyimpanan bukti pembayaran tidak dapat dibuat.');
                }
                $proofFilename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
                if (!move_uploaded_file($temporaryPath, $proofDirectory . '/' . $proofFilename)) {
                    throw new RuntimeException('Bukti pembayaran tidak dapat disimpan.');
                }
            }
            if ($methodName !== 'Tunai' && $proofFilename === null) throw new PortalApiError(422, 'Unggah foto bukti transfer untuk metode non-tunai.');
            $invoice = sprintf('INV-%s-%s', $period->format('Ym'), $room['nomor_kamar']);
            $stmt = $pdo->prepare('INSERT INTO pembayaran_qris (user_id, kamar_id, invoice_no, periode, nominal, metode, tanggal_bayar, status, catatan, bukti_transfer) VALUES (?, ?, ?, ?, ?, ?, ?, \'Menunggu Verifikasi\', ?, ?)');
            try {
                $stmt->execute([(int) $user['id'], (int) $room['id'], $invoice, $periodLabel, (float) $room['harga_per_bulan'], $methodName, $date, $reference, $proofFilename]);
            } catch (Throwable $error) {
                if ($proofFilename !== null && is_file($proofDirectory . '/' . $proofFilename)) unlink($proofDirectory . '/' . $proofFilename);
                throw $error;
            }
            portalReply(['status' => 'sukses', 'message' => 'Konfirmasi tersimpan dan menunggu verifikasi pengelola.']);
        }
        throw new PortalApiError(405, 'Metode tidak diizinkan.');
    }

    if ($resource === 'reports') {
        if ($method === 'GET') {
            $user = portalRequireRole(['admin', 'petugas', 'penghuni']);
            $isAdmin = in_array($user['role'], ['admin', 'petugas'], true);
            $query = 'SELECT p.id, p.user_id, p.nama_penghuni, p.kamar_id, p.kategori, p.urgensi, p.judul, p.deskripsi, p.status, p.tanggapan_admin, p.created_at, k.nomor_kamar FROM pelaporan_masalah p LEFT JOIN kamar k ON k.id = p.kamar_id';
            if (!$isAdmin) {
                $query .= ' WHERE p.user_id = ?';
                $stmt = $pdo->prepare($query . ' ORDER BY p.id DESC LIMIT 200');
                $stmt->execute([(int) $user['id']]);
            } else {
                $stmt = $pdo->query($query . ' ORDER BY p.id DESC LIMIT 500');
            }
            portalReply(['status' => 'sukses', 'data' => $stmt->fetchAll()]);
        }
        if ($method === 'POST') {
            $user = portalRequireRole(['penghuni']);
            portalRequireCsrf();
            $input = portalInput();
            $category = trim((string) ($input['kategori'] ?? ''));
            $urgency = (string) ($input['urgensi'] ?? 'Normal');
            $title = trim((string) ($input['judul'] ?? ''));
            $description = trim((string) ($input['deskripsi'] ?? ''));
            $allowedCategories = ['Air / pipa', 'Kelistrikan / lampu', 'AC / fasilitas kamar', 'Wi-Fi / fasilitas bersama', 'Kebersihan', 'Lainnya'];
            if (!in_array($category, $allowedCategories, true) || !in_array($urgency, ['Normal', 'Mendesak'], true) || $title === '' || mb_strlen($title) > 160 || $description === '' || mb_strlen($description) > 5000) {
                throw new PortalApiError(422, 'Lengkapi kategori, urgensi, judul, dan deskripsi laporan dengan benar.');
            }
            $stmt = $pdo->prepare('INSERT INTO pelaporan_masalah (user_id, nama_penghuni, kamar_id, kategori, urgensi, judul, deskripsi, status, tanggapan_admin) VALUES (?, ?, ?, ?, ?, ?, ?, \'Menunggu\', NULL)');
            $stmt->execute([(int) $user['id'], $user['nama'], $user['kamar_id'] ?: null, $category, $urgency, $title, $description]);
            portalReply(['status' => 'sukses', 'message' => 'Laporan berhasil dikirim ke pengelola.']);
        }
        if ($method === 'PATCH') {
            portalRequireRole(['admin', 'petugas']); portalRequireCsrf();
            if ($id <= 0) throw new PortalApiError(422, 'ID laporan tidak valid.');
            $input = portalInput();
            $status = (string) ($input['status'] ?? '');
            $reply = trim((string) ($input['tanggapan_admin'] ?? ''));
            if (!in_array($status, ['Menunggu', 'Sedang Dikerjakan', 'Selesai'], true)) throw new PortalApiError(422, 'Status laporan tidak valid.');
            $stmt = $pdo->prepare('UPDATE pelaporan_masalah SET status = ?, tanggapan_admin = ? WHERE id = ?');
            $stmt->execute([$status, $reply, $id]);
            if ($stmt->rowCount() === 0) {
                $check = $pdo->prepare('SELECT id FROM pelaporan_masalah WHERE id = ?'); $check->execute([$id]);
                if (!$check->fetch()) throw new PortalApiError(404, 'Laporan tidak ditemukan.');
            }
            portalReply(['status' => 'sukses', 'message' => 'Status laporan diperbarui.']);
        }
        throw new PortalApiError(405, 'Metode tidak diizinkan.');
    }

    if ($resource === 'admin-dashboard') {
        portalRequireRole(['admin', 'petugas']);
        $rooms = (int) $pdo->query('SELECT COUNT(*) FROM kamar')->fetchColumn();
        $occupied = (int) $pdo->query("SELECT COUNT(*) FROM kamar WHERE status_kamar = 'Terisi'")->fetchColumn();
        $active = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'penghuni' AND status = 'Aktif'")->fetchColumn();
        $pending = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'penghuni' AND status = 'Menunggu ACC'")->fetchColumn();
        $openReports = (int) $pdo->query("SELECT COUNT(*) FROM pelaporan_masalah WHERE status <> 'Selesai'")->fetchColumn();
        $financeStmt = $pdo->query("SELECT COALESCE(SUM(CASE WHEN jenis_transaksi = 'Masuk' THEN jumlah_uang ELSE 0 END), 0) AS income, COALESCE(SUM(CASE WHEN jenis_transaksi = 'Keluar' THEN jumlah_uang ELSE 0 END), 0) AS expense FROM keuangan_kost WHERE YEAR(tanggal_transaksi) = YEAR(CURRENT_DATE()) AND MONTH(tanggal_transaksi) = MONTH(CURRENT_DATE())");
        $finance = $financeStmt->fetch();
        portalReply(['status' => 'sukses', 'data' => ['residents' => $active, 'rooms' => $rooms, 'occupied' => $occupied, 'vacant' => max(0, $rooms - $occupied), 'pending' => $pending, 'open_reports' => $openReports, 'income_month' => (float) $finance['income'], 'expense_month' => (float) $finance['expense']]]);
    }

    if ($resource === 'residents') {
        portalRequireRole(['admin', 'petugas']);
        if ($method === 'DELETE') {
            portalRequireCsrf();
            if ($id <= 0) throw new PortalApiError(422, 'ID penghuni tidak valid.');
            $pdo->beginTransaction();
            $target = $pdo->prepare("SELECT id, kamar_id FROM users WHERE id = ? AND role = 'penghuni' AND status <> 'Keluar' FOR UPDATE");
            $target->execute([$id]);
            $resident = $target->fetch();
            if (!$resident) {
                $pdo->rollBack();
                throw new PortalApiError(404, 'Penghuni tidak ditemukan atau sudah keluar.');
            }
            $pdo->prepare("UPDATE users SET status = 'Keluar', kamar_id = NULL WHERE id = ?")->execute([$id]);
            if (!empty($resident['kamar_id'])) {
                $otherOccupant = $pdo->prepare("SELECT id FROM users WHERE kamar_id = ? AND role = 'penghuni' AND status = 'Aktif' LIMIT 1");
                $otherOccupant->execute([(int) $resident['kamar_id']]);
                if (!$otherOccupant->fetch()) {
                    $pdo->prepare("UPDATE kamar SET status_kamar = 'Tersedia' WHERE id = ? AND status_kamar = 'Terisi'")->execute([(int) $resident['kamar_id']]);
                }
            }
            $pdo->commit();
            portalReply(['status' => 'sukses', 'message' => 'Akun penghuni dinonaktifkan dan kamar telah diperbarui.']);
        }
        if ($method !== 'GET') throw new PortalApiError(405, 'Metode tidak diizinkan.');
        $query = trim((string) ($_GET['q'] ?? ''));
        $status = (string) ($_GET['status'] ?? 'all');
        $sql = 'SELECT u.id, u.nama, u.email, u.nomor_telepon, u.alamat, u.kontak_darurat, u.kamar_id, u.status, u.created_at, p.tanggal_masuk, p.tanggal_habis, p.status_hunian, k.nomor_kamar, k.tipe_kamar FROM users u LEFT JOIN kamar k ON k.id = u.kamar_id LEFT JOIN penghuni p ON p.user_id = u.id WHERE u.role = \'penghuni\' AND u.status <> \'Keluar\'';
        $params = [];
        if ($status === 'Aktif') $sql .= ' AND u.status = \'Aktif\'';
        if ($status === 'Menunggu') $sql .= ' AND u.status = \'Menunggu ACC\'';
        if ($query !== '') { $sql .= ' AND (u.nama LIKE ? OR u.email LIKE ? OR u.nomor_telepon LIKE ? OR k.nomor_kamar LIKE ?)'; $like = '%' . $query . '%'; array_push($params, $like, $like, $like, $like); }
        $sql .= ' ORDER BY u.id DESC LIMIT 500';
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        portalReply(['status' => 'sukses', 'data' => $stmt->fetchAll()]);
    }

    if ($resource === 'registrations') {
        portalRequireRole(['admin', 'petugas']);
        if ($method === 'GET') {
            $stmt = $pdo->query("SELECT u.id, u.nama, u.email, u.nomor_telepon, u.alamat, u.kontak_darurat, u.kamar_id, u.created_at, k.nomor_kamar, k.tipe_kamar FROM users u LEFT JOIN kamar k ON k.id = u.kamar_id WHERE u.role = 'penghuni' AND u.status = 'Menunggu ACC' ORDER BY u.id DESC");
            $rooms = $pdo->query("SELECT id, nomor_kamar, tipe_kamar, harga_per_bulan FROM kamar WHERE status_kamar = 'Tersedia' ORDER BY nomor_kamar ASC")->fetchAll();
            portalReply(['status' => 'sukses', 'data' => $stmt->fetchAll(), 'rooms' => $rooms]);
        }
        if ($method === 'POST') {
            portalRequireCsrf();
            if ($id <= 0) throw new PortalApiError(422, 'ID pendaftaran tidak valid.');
            $input = portalInput();
            $action = (string) ($input['action'] ?? '');
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'penghuni' AND status = 'Menunggu ACC' FOR UPDATE");
            $stmt->execute([$id]);
            $candidate = $stmt->fetch();
            if (!$candidate) { $pdo->rollBack(); throw new PortalApiError(409, 'Pendaftaran sudah diproses atau tidak ditemukan.'); }
            if ($action === 'reject') {
                $pdo->prepare("UPDATE users SET status = 'Ditolak' WHERE id = ?")->execute([$id]);
                $pdo->commit();
                portalReply(['status' => 'sukses', 'message' => 'Pendaftaran ditolak.']);
            }
            if ($action !== 'approve') { $pdo->rollBack(); throw new PortalApiError(422, 'Aksi pendaftaran tidak valid.'); }
            $roomId = (int) ($input['kamar_id'] ?? $candidate['kamar_id'] ?? 0);
            $roomStmt = $pdo->prepare("SELECT id, nomor_kamar FROM kamar WHERE id = ? AND status_kamar = 'Tersedia' FOR UPDATE");
            $roomStmt->execute([$roomId]);
            $room = $roomStmt->fetch();
            if (!$room) { $pdo->rollBack(); throw new PortalApiError(409, 'Kamar yang dipilih sudah tidak tersedia.'); }
            $today = date('Y-m-d');
            $start = (string) ($candidate['tanggal_masuk'] ?? $today);
            $end = date('Y-m-d', strtotime($start . ' +1 month'));
            $pdo->prepare("UPDATE users SET status = 'Aktif', kamar_id = ? WHERE id = ?")->execute([$roomId, $id]);
            $residentStmt = $pdo->prepare('SELECT id FROM penghuni WHERE user_id = ? OR email = ? LIMIT 1');
            $residentStmt->execute([$id, $candidate['email']]);
            $resident = $residentStmt->fetch();
            if ($resident) {
                $pdo->prepare("UPDATE penghuni SET user_id = ?, nama_lengkap = ?, email = ?, nomor_telepon = ?, kamar_id = ?, tanggal_masuk = ?, tanggal_bayar = ?, tanggal_habis = ?, status_hunian = 'Aktif' WHERE id = ?")
                    ->execute([$id, $candidate['nama'], $candidate['email'], $candidate['nomor_telepon'], $roomId, $start, $start, $end, $resident['id']]);
            } else {
                $pdo->prepare("INSERT INTO penghuni (user_id, nama_lengkap, email, nomor_telepon, kamar_id, tanggal_masuk, tanggal_bayar, tanggal_habis, status_hunian) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Aktif')")
                    ->execute([$id, $candidate['nama'], $candidate['email'], $candidate['nomor_telepon'], $roomId, $start, $start, $end]);
            }
            $pdo->prepare("UPDATE kamar SET status_kamar = 'Terisi' WHERE id = ?")->execute([$roomId]);
            $pdo->commit();
            portalReply(['status' => 'sukses', 'message' => 'Pendaftaran disetujui dan kamar dialokasikan.']);
        }
        throw new PortalApiError(405, 'Metode tidak diizinkan.');
    }

    if ($resource === 'rooms') {
        portalRequireRole(['admin', 'petugas']);
        if ($method === 'GET') {
            $stmt = $pdo->query('SELECT id, nomor_kamar, tipe_kamar, fasilitas, harga_per_bulan, status_kamar, jenis_penghuni, ukuran_kamar, foto_url, tampilkan_publik FROM kamar ORDER BY nomor_kamar ASC');
            portalReply(['status' => 'sukses', 'data' => $stmt->fetchAll()]);
        }
        if ($method === 'POST') {
            portalRequireCsrf();
            $input = portalInput();
            $number = trim((string) ($input['nomor_kamar'] ?? ''));
            $type = trim((string) ($input['tipe_kamar'] ?? ''));
            $price = filter_var($input['harga_per_bulan'] ?? null, FILTER_VALIDATE_FLOAT);
            $status = (string) ($input['status_kamar'] ?? 'Tersedia');
            $facilities = trim((string) ($input['fasilitas'] ?? ''));
            if ($number === '' || mb_strlen($number) > 12 || $type === '' || mb_strlen($type) > 80 || mb_strlen($facilities) > 2000 || $price === false || $price <= 0 || !in_array($status, ['Tersedia', 'Perbaikan'], true)) throw new PortalApiError(422, 'Nomor, tipe, fasilitas, harga, dan status kamar wajib valid.');
            $dup = $pdo->prepare('SELECT id FROM kamar WHERE nomor_kamar = ?'); $dup->execute([$number]);
            if ($dup->fetch()) throw new PortalApiError(409, 'Nomor kamar sudah terdaftar.');
            $stmt = $pdo->prepare('INSERT INTO kamar (nomor_kamar, tipe_kamar, fasilitas, harga_per_bulan, status_kamar) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$number, $type, $facilities, $price, $status]);
            portalReply(['status' => 'sukses', 'message' => 'Kamar ditambahkan.', 'id' => (int) $pdo->lastInsertId()], 201);
        }
        if ($method === 'PATCH') {
            portalRequireCsrf();
            if ($id <= 0) throw new PortalApiError(422, 'ID kamar tidak valid.');
            $input = portalInput();
            $allowed = ['nomor_kamar', 'tipe_kamar', 'fasilitas', 'harga_per_bulan', 'status_kamar'];
            if (isset($input['nomor_kamar'])) {
                $number = trim((string) $input['nomor_kamar']);
                if ($number === '' || mb_strlen($number) > 12) throw new PortalApiError(422, 'Nomor kamar wajib diisi (maksimal 12 karakter).');
                $duplicate = $pdo->prepare('SELECT id FROM kamar WHERE nomor_kamar = ? AND id <> ? LIMIT 1');
                $duplicate->execute([$number, $id]);
                if ($duplicate->fetch()) throw new PortalApiError(409, 'Nomor kamar sudah digunakan.');
                $input['nomor_kamar'] = $number;
            }
            if (isset($input['tipe_kamar'])) {
                $input['tipe_kamar'] = trim((string) $input['tipe_kamar']);
                if ($input['tipe_kamar'] === '' || mb_strlen($input['tipe_kamar']) > 80) throw new PortalApiError(422, 'Tipe kamar wajib diisi (maksimal 80 karakter).');
            }
            if (isset($input['fasilitas']) && mb_strlen((string) $input['fasilitas']) > 2000) throw new PortalApiError(422, 'Daftar fasilitas terlalu panjang.');
            if (isset($input['status_kamar']) && !in_array($input['status_kamar'], ['Tersedia', 'Terisi', 'Perbaikan'], true)) throw new PortalApiError(422, 'Status kamar tidak valid.');
            if (isset($input['harga_per_bulan'])) {
                $price = filter_var($input['harga_per_bulan'], FILTER_VALIDATE_FLOAT);
                if ($price === false || $price <= 0) throw new PortalApiError(422, 'Harga kamar harus lebih besar dari nol.');
                $input['harga_per_bulan'] = $price;
            }
            $updates = []; $values = [];
            foreach ($allowed as $key) {
                if (array_key_exists($key, $input)) {
                    $updates[] = "{$key} = ?";
                    $values[] = $input[$key];
                }
            }
            if (!$updates) throw new PortalApiError(422, 'Tidak ada perubahan kamar.');
            if (($input['status_kamar'] ?? null) === 'Tersedia') {
                $occupant = $pdo->prepare("SELECT id FROM users WHERE kamar_id = ? AND role = 'penghuni' AND status = 'Aktif' LIMIT 1");
                $occupant->execute([$id]);
                if ($occupant->fetch()) throw new PortalApiError(409, 'Kamar masih terikat ke akun penghuni aktif. Selesaikan proses pindah/checkout terlebih dahulu.');
            }
            $values[] = $id;
            $stmt = $pdo->prepare('UPDATE kamar SET ' . implode(', ', $updates) . ' WHERE id = ?'); $stmt->execute($values);
            if (!$stmt->rowCount()) { $check = $pdo->prepare('SELECT id FROM kamar WHERE id = ?'); $check->execute([$id]); if (!$check->fetch()) throw new PortalApiError(404, 'Kamar tidak ditemukan.'); }
            portalReply(['status' => 'sukses', 'message' => 'Data kamar diperbarui.']);
        }
        if ($method === 'DELETE') {
            portalRequireCsrf();
            if ($id <= 0) throw new PortalApiError(422, 'ID kamar tidak valid.');
            $pdo->beginTransaction();
            $room = $pdo->prepare('SELECT id, nomor_kamar FROM kamar WHERE id = ? FOR UPDATE');
            $room->execute([$id]);
            if (!$room->fetch()) {
                $pdo->rollBack();
                throw new PortalApiError(404, 'Kamar tidak ditemukan.');
            }
            foreach ([
                ['SELECT id FROM users WHERE kamar_id = ? LIMIT 1', 'Kamar masih terhubung dengan akun penghuni.'],
                ['SELECT id FROM penghuni WHERE kamar_id = ? LIMIT 1', 'Kamar masih memiliki riwayat data penghuni.'],
                ['SELECT id FROM pembayaran_qris WHERE kamar_id = ? LIMIT 1', 'Kamar memiliki riwayat pembayaran dan tidak dapat dihapus.'],
                ['SELECT id FROM pelaporan_masalah WHERE kamar_id = ? LIMIT 1', 'Kamar memiliki riwayat laporan dan tidak dapat dihapus.'],
            ] as [$query, $message]) {
                $linked = $pdo->prepare($query);
                $linked->execute([$id]);
                if ($linked->fetch()) throw new PortalApiError(409, $message);
            }
            $pdo->prepare('DELETE FROM kamar WHERE id = ?')->execute([$id]);
            $pdo->commit();
            portalReply(['status' => 'sukses', 'message' => 'Kamar berhasil dihapus.']);
        }
        throw new PortalApiError(405, 'Metode tidak diizinkan.');
    }

    if ($resource === 'facilities') {
        portalRequireRole(['admin', 'petugas']);
        if ($method === 'GET') {
            $stmt = $pdo->query('SELECT id, nama FROM fasilitas_master ORDER BY nama ASC');
            portalReply(['status' => 'sukses', 'data' => $stmt->fetchAll()]);
        }
        if ($method === 'POST') {
            portalRequireCsrf();
            $input = portalInput();
            $name = trim((string) ($input['nama'] ?? ''));
            if ($name === '' || mb_strlen($name) > 80) throw new PortalApiError(422, 'Nama fasilitas wajib diisi (maksimal 80 karakter).');
            try {
                $stmt = $pdo->prepare('INSERT INTO fasilitas_master (nama) VALUES (?)');
                $stmt->execute([$name]);
            } catch (PDOException $error) {
                if ($error->getCode() === '23000') throw new PortalApiError(409, 'Fasilitas tersebut sudah terdaftar.');
                throw $error;
            }
            portalReply(['status' => 'sukses', 'message' => 'Fasilitas ditambahkan.', 'id' => (int) $pdo->lastInsertId()], 201);
        }
        if ($method === 'DELETE') {
            portalRequireCsrf();
            if ($id <= 0) throw new PortalApiError(422, 'ID fasilitas tidak valid.');
            $pdo->beginTransaction();
            $facilityStmt = $pdo->prepare('SELECT nama FROM fasilitas_master WHERE id = ? FOR UPDATE');
            $facilityStmt->execute([$id]);
            $facility = $facilityStmt->fetch();
            if (!$facility) {
                $pdo->rollBack();
                throw new PortalApiError(404, 'Fasilitas tidak ditemukan.');
            }
            $rooms = $pdo->query('SELECT id, fasilitas FROM kamar')->fetchAll();
            $updateRoom = $pdo->prepare('UPDATE kamar SET fasilitas = ? WHERE id = ?');
            foreach ($rooms as $room) {
                $originalItems = array_map('trim', explode(',', (string) $room['fasilitas']));
                $items = array_values(array_filter($originalItems, static fn(string $item): bool => $item !== '' && $item !== $facility['nama']));
                if (count($items) !== count($originalItems)) $updateRoom->execute([implode(', ', $items), (int) $room['id']]);
            }
            $pdo->prepare('DELETE FROM fasilitas_master WHERE id = ?')->execute([$id]);
            $pdo->commit();
            portalReply(['status' => 'sukses', 'message' => 'Fasilitas dihapus dari daftar dan seluruh kamar.']);
        }
        throw new PortalApiError(405, 'Metode tidak diizinkan.');
    }

    if ($resource === 'finance') {
        portalRequireRole(['admin', 'petugas']);
        if ($method === 'GET') {
            $summary = $pdo->query("SELECT COALESCE(SUM(CASE WHEN jenis_transaksi = 'Masuk' THEN jumlah_uang ELSE 0 END),0) AS total_masuk, COALESCE(SUM(CASE WHEN jenis_transaksi = 'Keluar' THEN jumlah_uang ELSE 0 END),0) AS total_keluar, COALESCE(SUM(CASE WHEN jenis_transaksi = 'Masuk' AND YEAR(tanggal_transaksi)=YEAR(CURRENT_DATE()) AND MONTH(tanggal_transaksi)=MONTH(CURRENT_DATE()) THEN jumlah_uang ELSE 0 END),0) AS bulan_masuk, COALESCE(SUM(CASE WHEN jenis_transaksi = 'Keluar' AND YEAR(tanggal_transaksi)=YEAR(CURRENT_DATE()) AND MONTH(tanggal_transaksi)=MONTH(CURRENT_DATE()) THEN jumlah_uang ELSE 0 END),0) AS bulan_keluar FROM keuangan_kost")->fetch();
            $rows = $pdo->query('SELECT id, jenis_transaksi, kategori, jumlah_uang, tanggal_transaksi, keterangan FROM keuangan_kost ORDER BY tanggal_transaksi DESC, id DESC LIMIT 200')->fetchAll();
            portalReply(['status' => 'sukses', 'summary' => $summary, 'data' => $rows]);
        }
        if ($method === 'POST') {
            portalRequireCsrf();
            $input = portalInput();
            $type = (string) ($input['jenis_transaksi'] ?? '');
            $category = trim((string) ($input['kategori'] ?? ''));
            $amount = filter_var($input['jumlah_uang'] ?? null, FILTER_VALIDATE_FLOAT);
            $date = portalDate((string) ($input['tanggal_transaksi'] ?? ''), 'transaksi');
            $description = trim((string) ($input['keterangan'] ?? ''));
            if (!in_array($type, ['Masuk', 'Keluar'], true) || $category === '' || $amount === false || $amount <= 0 || $description === '') throw new PortalApiError(422, 'Jenis, kategori, nominal, tanggal, dan keterangan transaksi wajib valid.');
            $stmt = $pdo->prepare('INSERT INTO keuangan_kost (jenis_transaksi, kategori, jumlah_uang, tanggal_transaksi, keterangan) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$type, $category, $amount, $date, $description]);
            portalReply(['status' => 'sukses', 'message' => 'Transaksi dicatat.', 'id' => (int) $pdo->lastInsertId()], 201);
        }
        throw new PortalApiError(405, 'Metode tidak diizinkan.');
    }

    if ($resource === 'admin-payments') {
        portalRequireRole(['admin', 'petugas']);
        if ($method === 'GET') {
            $stmt = $pdo->query('SELECT q.id, q.user_id, q.invoice_no, q.periode, q.nominal, q.metode, q.tanggal_bayar, q.status, q.catatan, q.bukti_transfer, q.created_at, u.nama, u.email, k.nomor_kamar FROM pembayaran_qris q JOIN users u ON u.id = q.user_id LEFT JOIN kamar k ON k.id = q.kamar_id ORDER BY q.id DESC LIMIT 300');
            portalReply(['status' => 'sukses', 'data' => $stmt->fetchAll()]);
        }
        if ($method === 'PATCH') {
            portalRequireCsrf();
            if ($id <= 0) throw new PortalApiError(422, 'ID pembayaran tidak valid.');
            $input = portalInput();
            $status = (string) ($input['status'] ?? '');
            if (!in_array($status, ['Lunas', 'Menunggu Verifikasi'], true)) throw new PortalApiError(422, 'Status pembayaran tidak valid.');
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT * FROM pembayaran_qris WHERE id = ? FOR UPDATE'); $stmt->execute([$id]); $payment = $stmt->fetch();
            if (!$payment) { $pdo->rollBack(); throw new PortalApiError(404, 'Pembayaran tidak ditemukan.'); }
            $pdo->prepare('UPDATE pembayaran_qris SET status = ? WHERE id = ?')->execute([$status, $id]);
            if ($status === 'Lunas' && $payment['status'] !== 'Lunas') {
                $residentStmt = $pdo->prepare('SELECT id FROM penghuni WHERE user_id = ? ORDER BY id DESC LIMIT 1'); $residentStmt->execute([(int) $payment['user_id']]); $resident = $residentStmt->fetch();
                $description = 'Pembayaran sewa ' . $payment['invoice_no'];
                $ledger = $pdo->prepare('SELECT id FROM keuangan_kost WHERE keterangan = ? LIMIT 1');
                $ledger->execute([$description]);
                if (!$ledger->fetch()) {
                    $pdo->prepare("INSERT INTO keuangan_kost (penghuni_id, jenis_transaksi, kategori, jumlah_uang, tanggal_transaksi, keterangan) VALUES (?, 'Masuk', 'Sewa kamar', ?, ?, ?)")
                        ->execute([$resident['id'] ?? null, $payment['nominal'], $payment['tanggal_bayar'], $description]);
                }
            }
            $pdo->commit();
            portalReply(['status' => 'sukses', 'message' => 'Status pembayaran diperbarui.']);
        }
        throw new PortalApiError(405, 'Metode tidak diizinkan.');
    }

    throw new PortalApiError(404, 'Endpoint tidak ditemukan.');
} catch (PortalApiError $error) {
    portalReply(['status' => 'error', 'message' => $error->getMessage()], $error->statusCode);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Portal API: ' . $error->getMessage());
    portalReply(['status' => 'error', 'message' => 'Terjadi kesalahan server. Periksa konfigurasi dan struktur database.'], 500);
}

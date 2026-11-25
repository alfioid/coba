<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_helper.php';

$error = '';
$success = '';

// Proses POST Form Login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Bisa dari fetch/JSON atau form x-www-form-urlencoded
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $email = trim((string)($input['email'] ?? ''));
        $password = (string)($input['password'] ?? '');
        $role = trim((string)($input['role'] ?? 'penghuni'));
        $isJson = true;
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $role = trim((string)($_POST['role'] ?? 'penghuni'));
        $isJson = false;
    }

    if (!in_array($role, ['admin', 'penghuni'], true)) {
        $error = 'Jenis akun tidak valid.';
    } elseif ($email === '' || $password === '') {
        $error = 'Email dan kata sandi wajib diisi.';
    } else {
        try {
            $stmt = db()->prepare('SELECT id, nama, email, password_hash, role, status, nomor_telepon, kamar_id FROM users WHERE email = ?');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                $error = 'Email atau kata sandi tidak sesuai.';
            } elseif (($role === 'admin' && !in_array($user['role'], ['admin', 'petugas'], true)) || ($role === 'penghuni' && $user['role'] !== 'penghuni')) {
                $error = $role === 'admin'
                    ? 'Akun ini bukan akun pengelola. Silakan gunakan tab Masuk Penghuni.'
                    : 'Akun ini bukan akun penghuni. Silakan gunakan tab Masuk Admin.';
            } else {
                // Verifikasi status pendaftaran calon penghuni
                if ($user['role'] === 'penghuni' && $user['status'] === 'Menunggu ACC') {
                    if ($isJson) {
                        http_response_code(200);
                        echo json_encode([
                            'status' => 'pending',
                            'message' => 'Akun Anda sedang menunggu persetujuan (ACC) dari admin.',
                            'redirect' => 'status.php?email=' . urlencode($email)
                        ]);
                        exit;
                    }
                    header('Location: status.php?email=' . urlencode($email));
                    exit;
                }

                if ($user['status'] === 'Keluar') {
                    $error = 'Akun ini sudah dinonaktifkan. Hubungi pengelola jika Anda perlu mengakses kembali.';
                } elseif ($user['status'] === 'Ditolak') {
                    $error = 'Mohon maaf, pendaftaran akun Anda ditolak oleh admin. Hubungi pengelola.';
                } else {
                    // Login Sukses
                    loginUserSession($user);

                    $targetRedirect = ($user['role'] === 'admin' || $user['role'] === 'petugas') ? '../admin/portal.html' : '../user/portal.html';

                    if ($isJson) {
                        http_response_code(200);
                        echo json_encode([
                            'status' => 'sukses',
                            'message' => 'Login berhasil!',
                            'role' => $user['role'],
                            'redirect' => $targetRedirect
                        ]);
                        exit;
                    }

                    header('Location: ' . $targetRedirect);
                    exit;
                }
            }
        } catch (Throwable $e) {
            $error = 'Terjadi gangguan koneksi ke database.';
        }
    }

    if ($isJson && $error !== '') {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'error' => $error]);
        exit;
    }
    if (!$isJson && $error !== '') {
        $loginHash = $role === 'admin' ? '#login-admin' : '#login-user';
        header('Location: ../index.html?login_error=' . rawurlencode($error) . $loginHash);
        exit;
    }
}
?>
<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

require_once __DIR__ . '/../config/database.php';

function isApiRequest(): bool {
    $headers = getallheaders();
    $accept = $headers['Accept'] ?? $headers['accept'] ?? '';
    return str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/') || str_contains($accept, 'application/json');
}

function requireLogin(?string $redirectUrl = null): void {
    if (empty($_SESSION['user_id'])) {
        if (isApiRequest()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'error' => 'Silakan login terlebih dahulu']);
            exit;
        }

        $base = dirname($_SERVER['SCRIPT_NAME'] ?? '');
        // Hitung path relatif ke auth/login.php
        $target = $redirectUrl ?? (str_contains($_SERVER['REQUEST_URI'], '/admin/') || str_contains($_SERVER['REQUEST_URI'], '/user/') ? '../auth/login.php' : 'auth/login.php');
        header('Location: ' . $target);
        exit;
    }
}

function requireRole(array $allowedRoles): void {
    requireLogin();

    $userRole = $_SESSION['role'] ?? '';
    if (!in_array($userRole, $allowedRoles, true)) {
        if (isApiRequest()) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status' => 'error', 'error' => 'Akses ditolak: Anda tidak memiliki hak akses']);
            exit;
        }

        // Jika bukan role yang tepat, arahkan sesuai rolenya
        if ($userRole === 'admin' || $userRole === 'petugas') {
            header('Location: ../admin/index.php');
        } else {
            header('Location: ../user/dashboard.php');
        }
        exit;
    }
}



function getAuthUser(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    try {
        $stmt = db()->prepare('SELECT u.*, k.nomor_kamar, k.tipe_kamar, k.harga_per_bulan, k.fasilitas 
                               FROM users u 
                               LEFT JOIN kamar k ON u.kamar_id = k.id 
                               WHERE u.id = ?');
        $stmt->execute([(int)$_SESSION['user_id']]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function loginUserSession(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['nama'] = (string)$user['nama'];
    $_SESSION['email'] = (string)$user['email'];
    $_SESSION['role'] = (string)$user['role'];
    $_SESSION['kamar_id'] = !empty($user['kamar_id']) ? (int)$user['kamar_id'] : null;
    $_SESSION['status'] = (string)($user['status'] ?? 'Aktif');
}
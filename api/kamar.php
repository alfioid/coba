<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_helper.php'; // Memuat helper otorisasi session & role

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

// 1. METHOD GET: Mengambil data (Collection atau By ID) - Bersifat publik / terbuka
if ($method === 'GET') {
    try {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

        if ($id) {
            // GET by ID
            $stmt = db()->prepare('SELECT * FROM kamar WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $data = $stmt->fetch();

            if (!$data) {
                http_response_code(404);
                echo json_encode(['error' => 'Data kamar dengan ID tersebut tidak ditemukan']);
                exit;
            }

            http_response_code(200);
            echo json_encode(['status' => 'sukses', 'data' => $data], JSON_UNESCAPED_UNICODE);
            exit;
        } else {
            // GET Collection dengan Filter & Pagination
            $tipe = trim((string) ($_GET['tipe'] ?? ''));
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $limit = 5; 
            $offset = ($page - 1) * $limit;

            $sql = "SELECT * FROM kamar";
            $params = [];

            if ($tipe !== '') {
                $sql .= " WHERE tipe_kamar = :tipe";
                $params['tipe'] = $tipe;
            }

            $sql .= " ORDER BY id DESC LIMIT $limit OFFSET $offset";
            
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $data = $stmt->fetchAll();

            http_response_code(200);
            echo json_encode([
                'status' => 'sukses',
                'halaman' => $page,
                'jumlah_data' => count($data),
                'data' => $data
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Terjadi kesalahan pada sistem server']);
        exit;
    }
}

// 2. METHOD POST: Menambahkan data baru (Hanya Admin dan Petugas)
if ($method === 'POST') {
    // Proteksi Otorisasi: Pastikan user sudah login & rolenya diizinkan
    requireRole(['admin', 'petugas']);

    try {
        $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);

        $nomorKamar = trim((string) ($input['nomor_kamar'] ?? ''));
        $tipeKamar  = trim((string) ($input['tipe_kamar'] ?? ''));
        $fasilitas  = trim((string) ($input['fasilitas'] ?? ''));
        $harga      = filter_var($input['harga_per_bulan'] ?? null, FILTER_VALIDATE_FLOAT);

        if ($nomorKamar === '' || $tipeKamar === '' || $fasilitas === '' || $harga === false || $harga < 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Data tidak valid: Nomor kamar, tipe, fasilitas, dan harga wajib diisi dengan benar']);
            exit;
        }

        $sql = "INSERT INTO kamar (nomor_kamar, tipe_kamar, fasilitas, harga_per_bulan) VALUES (:nomor, :tipe, :fasilitas, :harga)";
        $stmt = db()->prepare($sql);
        $stmt->execute([
            'nomor'     => $nomorKamar,
            'tipe'      => $tipeKamar,
            'fasilitas' => $fasilitas,
            'harga'     => $harga
        ]);

        $newId = (int) db()->lastInsertId();

        http_response_code(201);
        echo json_encode([
            'status' => 'sukses',
            'message' => 'Data kamar berhasil ditambahkan',
            'id' => $newId
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Terjadi kesalahan pada sistem server']);
        exit;
    }
}

// 3. METHOD PATCH/PUT: Memperbarui data kamar (Hanya Admin dan Petugas)
if ($method === 'PATCH' || $method === 'PUT') {
    // Proteksi Otorisasi
    requireRole(['admin', 'petugas']);

    try {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'ID kamar tidak valid atau tidak disertakan di URL']);
            exit;
        }

        $checkStmt = db()->prepare('SELECT id FROM kamar WHERE id = :id');
        $checkStmt->execute(['id' => $id]);
        if (!$checkStmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Data kamar yang ingin diubah tidak ditemukan']);
            exit;
        }

        $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);

        $nomorKamar = trim((string) ($input['nomor_kamar'] ?? ''));
        $tipeKamar  = trim((string) ($input['tipe_kamar'] ?? ''));
        $harga      = filter_var($input['harga_per_bulan'] ?? null, FILTER_VALIDATE_FLOAT);

        if ($nomorKamar === '' || $tipeKamar === '' || $harga === false || $harga < 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Data pembaruan tidak valid']);
            exit;
        }

        $sql = "UPDATE kamar SET nomor_kamar = :nomor, tipe_kamar = :tipe, harga_per_bulan = :harga WHERE id = :id";
        $stmt = db()->prepare($sql);
        $stmt->execute([
            'nomor' => $nomorKamar,
            'tipe'  => $tipeKamar,
            'harga' => $harga,
            'id'    => $id
        ]);

        http_response_code(200);
        echo json_encode([
            'status' => 'sukses',
            'message' => "Data kamar dengan ID {$id} berhasil diperbarui"
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Terjadi kesalahan pada sistem server']);
        exit;
    }
}

// 4. METHOD DELETE: Menghapus data kamar (Hanya Admin dan Petugas)
if ($method === 'DELETE') {
    // Proteksi Otorisasi
    requireRole(['admin', 'petugas']);

    try {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'ID kamar tidak valid']);
            exit;
        }

        $checkStmt = db()->prepare('SELECT id FROM kamar WHERE id = :id');
        $checkStmt->execute(['id' => $id]);
        if (!$checkStmt->fetch()) {
            http_response_code(404);
            echo json_encode(['error' => 'Data yang akan dihapus tidak ditemukan']);
            exit;
        }

        $stmt = db()->prepare('DELETE FROM kamar WHERE id = :id');
        $stmt->execute(['id' => $id]);

        http_response_code(200);
        echo json_encode([
            'status' => 'sukses',
            'message' => "Data kamar dengan ID {$id} berhasil dihapus"
        ], JSON_UNESCAPED_UNICODE);
        exit;

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Terjadi kesalahan pada sistem server']);
        exit;
    }
}
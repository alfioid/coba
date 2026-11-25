<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['status' => 'error', 'message' => 'Metode tidak diizinkan.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db();
    $columnRows = $pdo->query('SHOW COLUMNS FROM kamar')->fetchAll();
    $columns = array_column($columnRows, 'Field');
    $requiredColumns = ['id', 'nomor_kamar', 'tipe_kamar', 'fasilitas', 'harga_per_bulan', 'status_kamar'];
    $missingColumns = array_diff($requiredColumns, $columns);

    if ($missingColumns !== []) {
        throw new RuntimeException('Struktur tabel kamar belum sesuai dengan API katalog publik.');
    }

    $optionalColumns = [
        'jenis_penghuni' => 'jenis_penghuni',
        'ukuran_kamar' => 'ukuran_kamar',
        'foto_url' => 'foto_url',
        'tampilkan_publik' => 'tampilkan_publik',
    ];
    $selectColumns = [
        'id',
        'nomor_kamar',
        'tipe_kamar',
        'fasilitas',
        'harga_per_bulan',
        'status_kamar',
    ];

    foreach ($optionalColumns as $column) {
        if (in_array($column, $columns, true)) {
            $selectColumns[] = $column;
        }
    }

    $publicCondition = in_array('tampilkan_publik', $columns, true) ? 'tampilkan_publik = 1' : '1 = 1';
    $countStatement = $pdo->query(
        "SELECT COUNT(*) AS total_kamar,
                SUM(CASE WHEN LOWER(TRIM(status_kamar)) = 'tersedia' THEN 1 ELSE 0 END) AS kamar_tersedia
         FROM kamar
         WHERE {$publicCondition}"
    );
    $summary = $countStatement->fetch() ?: ['total_kamar' => 0, 'kamar_tersedia' => 0];

    $availableStatement = $pdo->query(
        'SELECT ' . implode(', ', $selectColumns) . "
         FROM kamar
         WHERE LOWER(TRIM(status_kamar)) = 'tersedia' AND {$publicCondition}
         ORDER BY nomor_kamar ASC"
    );

    echo json_encode([
        'status' => 'sukses',
        'summary' => [
            'total_kamar' => (int) ($summary['total_kamar'] ?? 0),
            'kamar_tersedia' => (int) ($summary['kamar_tersedia'] ?? 0),
        ],
        'data' => $availableStatement->fetchAll(),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $error) {
    error_log('Katalog kamar publik gagal dimuat: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Data kamar belum dapat dimuat. Periksa koneksi database dan struktur tabel kamar.',
    ], JSON_UNESCAPED_UNICODE);
}

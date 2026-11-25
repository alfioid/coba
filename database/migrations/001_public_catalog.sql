-- Jalankan satu kali pada database pemweb_obe setelah tabel kamar tersedia.
-- Kolom tambahan bersifat opsional bagi API sampai katalog membutuhkan detailnya.
ALTER TABLE kamar
    ADD COLUMN jenis_penghuni ENUM('Putra', 'Putri') NULL DEFAULT NULL AFTER tipe_kamar,
    ADD COLUMN ukuran_kamar VARCHAR(30) NULL DEFAULT NULL AFTER harga_per_bulan,
    ADD COLUMN foto_url VARCHAR(500) NULL DEFAULT NULL AFTER ukuran_kamar,
    ADD COLUMN tampilkan_publik TINYINT(1) NOT NULL DEFAULT 1 AFTER foto_url;

CREATE INDEX idx_kamar_public_status ON kamar (tampilkan_publik, status_kamar);

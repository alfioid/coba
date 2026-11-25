-- Run after 001_public_catalog.sql. Safe to run repeatedly on MySQL/MariaDB.
-- Adds columns used by shared database-backed admin and penghuni portals.

DELIMITER //
CREATE PROCEDURE ensure_portal_column(
    IN table_name_value VARCHAR(64),
    IN column_name_value VARCHAR(64),
    IN definition_value TEXT
)
BEGIN
    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = table_name_value
    ) AND NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = table_name_value
          AND COLUMN_NAME = column_name_value
    ) THEN
        SET @portal_ddl = CONCAT('ALTER TABLE `', table_name_value, '` ADD COLUMN `', column_name_value, '` ', definition_value);
        PREPARE portal_stmt FROM @portal_ddl;
        EXECUTE portal_stmt;
        DEALLOCATE PREPARE portal_stmt;
    END IF;
END//
DELIMITER ;

CALL ensure_portal_column('users', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
CALL ensure_portal_column('users', 'tanggal_masuk', 'DATE NULL DEFAULT NULL');
CALL ensure_portal_column('kamar', 'jenis_penghuni', 'ENUM(''Putra'',''Putri'') NULL DEFAULT NULL');
CALL ensure_portal_column('kamar', 'ukuran_kamar', 'VARCHAR(30) NULL DEFAULT NULL');
CALL ensure_portal_column('kamar', 'foto_url', 'VARCHAR(500) NULL DEFAULT NULL');
CALL ensure_portal_column('kamar', 'tampilkan_publik', 'TINYINT(1) NOT NULL DEFAULT 1');
CALL ensure_portal_column('pembayaran_qris', 'bukti_transfer', 'VARCHAR(500) NULL DEFAULT NULL');

DROP PROCEDURE ensure_portal_column;

ALTER TABLE users
    MODIFY COLUMN status ENUM('Menunggu ACC','Aktif','Ditolak','Keluar') NOT NULL DEFAULT 'Menunggu ACC';

CREATE TABLE IF NOT EXISTS fasilitas_master (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(80) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO fasilitas_master (nama) VALUES
    ('AC'), ('Wi-Fi'), ('Kasur'), ('Lemari'), ('Meja'), ('Kamar mandi dalam'), ('Water heater'), ('Kipas angin');

-- Keep pending confirmations distinct from payments verified by the operator.
ALTER TABLE pembayaran_qris
    MODIFY COLUMN status ENUM('Lunas','Menunggu Verifikasi') NOT NULL DEFAULT 'Menunggu Verifikasi';

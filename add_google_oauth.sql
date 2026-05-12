-- Tambah kolom google_id untuk OAuth Google
ALTER TABLE users ADD COLUMN google_id VARCHAR(255) UNIQUE DEFAULT NULL AFTER email;
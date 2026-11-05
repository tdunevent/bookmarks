-- Bookmarks tables for MySQL 8 / MariaDB.
-- From this folder:
--   mysql -u root -p < sql/schema.sql

CREATE DATABASE IF NOT EXISTS bookmarks
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE bookmarks;

CREATE TABLE IF NOT EXISTS folders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  position INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookmarks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  folder_id INT UNSIGNED NULL,
  title VARCHAR(300) NOT NULL,
  url VARCHAR(2048) NOT NULL,
  image_path VARCHAR(255) NULL,
  image_kind ENUM('photo', 'icon', 'none') NOT NULL DEFAULT 'none',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_bookmarks_folder FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE SET NULL,
  INDEX idx_bookmarks_folder (folder_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

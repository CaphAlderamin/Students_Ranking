-- Миграция 001: таблица schools (источник истины: database/schema.dbml)
CREATE TABLE schools (
    id INT AUTO_INCREMENT,
    code VARCHAR(16) NOT NULL,
    name VARCHAR(255) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_schools_code (code)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
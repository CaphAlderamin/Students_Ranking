-- Миграция 005: таблица disciplines (источник истины: database/schema.dbml)
CREATE TABLE disciplines (
    id INT AUTO_INCREMENT,
    module_id INT NOT NULL,
    title varchar(255) NOT NULL,
    semester TINYINT NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_disciplines_module FOREIGN KEY (module_id) REFERENCES modules (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- Миграция 008: таблица application_items (источник истины: database/schema.dbml)
CREATE TABLE application_items (
    id INT AUTO_INCREMENT,
    application_id INT NOT NULL,
    module_id INT NOT NULL,
    priority TINYINT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_app_items_application_priority (application_id, priority),
    KEY idx_items_module (module_id),
    CONSTRAINT fk_app_items_application FOREIGN KEY (application_id) REFERENCES applications (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_app_items_module FOREIGN KEY (module_id) REFERENCES modules (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
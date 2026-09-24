-- Миграция 006: таблица module_eligible_schools (источник истины: database/schema.dbml)
CREATE TABLE module_eligible_schools (
    module_id INT NULL,
    school_id INT NULL,
    UNIQUE KEY uk_module_school (module_id, school_id),
    CONSTRAINT fk_mes_module FOREIGN KEY (module_id) REFERENCES modules (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_mes_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
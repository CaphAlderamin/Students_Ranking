-- Миграция 009: таблица assignments (источник истины: database/schema.dbml)
CREATE TABLE assignments (
    id INT AUTO_INCREMENT,
    student_id INT NOT NULL,
    module_id INT NOT NULL,
    algorithm varchar(16) NOT NULL,
    source varchar(16) NOT NULL,
    rank_position INT NULL,
    assigned_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_assignments_student (student_id),
    KEY idx_assign_module (module_id),
    CONSTRAINT fk_assignments_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_assignments_module FOREIGN KEY (module_id) REFERENCES modules (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
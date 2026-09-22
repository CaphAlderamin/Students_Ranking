-- Миграция 002: таблица student_groups (источник истины: database/schema.dbml)
CREATE TABLE student_groups (
    id INT AUTO_INCREMENT,
    school_id INT NOT NULL,
    name VARCHAR(32) NOT NULL,
    is_technical TINYINT(1) NOT NULL DEFAULT 1,
    admission_year INT NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_student_groups_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
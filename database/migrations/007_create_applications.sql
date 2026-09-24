-- Миграция 007: таблица applications (источник истины: database/schema.dbml)
CREATE TABLE applications (
    id INT AUTO_INCREMENT,
    student_id INT NOT NULL,
    submitted_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_applications_student (student_id),
    CONSTRAINT fk_applications_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
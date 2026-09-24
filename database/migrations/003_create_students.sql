-- Миграция 003: таблица students (источник истины: database/schema.dbml)
CREATE TABLE students (
    id INT AUTO_INCREMENT,
    group_id INT NOT NULL,
    full_name varchar(255) NOT NULL,
    is_target_quota TINYINT(1) NOT NULL DEFAULT 0,
    is_paid TINYINT(1) NOT NULL DEFAULT 0,
    is_disabled TINYINT(1) NOT NULL DEFAULT 0,
    entrance_exams_sum SMALLINT NOT NULL,
    gpa_sem12 DECIMAL(4,3) NOT NULL,
    gpa_basic DECIMAL(4,3) NOT NULL,
    entrance_test_score SMALLINT NOT NULL,
    PRIMARY KEY (id),
    KEY idx_students_group (group_id),
    CONSTRAINT fk_students_group FOREIGN KEY (group_id) REFERENCES student_groups (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
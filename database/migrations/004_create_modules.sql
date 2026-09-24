-- Миграция 004: таблица modules (источник истины: database/schema.dbml)
CREATE TABLE modules (
    id INT AUTO_INCREMENT,
    school_id INT NOT NULL,
    title varchar(255) NOT NULL,
    module_type varchar(16) NOT NULL,
    min_students INT NOT NULL,
    max_students INT NOT NULL,
    academic_year varchar(9) NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT fk_modules_school FOREIGN KEY (school_id) REFERENCES schools (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
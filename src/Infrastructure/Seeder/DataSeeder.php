<?php

declare(strict_types=1);

namespace App\Infrastructure\Seeder;

use App\Infrastructure\Db\PdoFactory;

/**
 * Оркестратор сидера (B2-02): очистка таблиц и детерминированное наполнение.
 *
 * Протокол очистки (план B2-02 §2.3): SET FOREIGN_KEY_CHECKS=0 → TRUNCATE
 * всех сидовых таблиц (сброс AUTO_INCREMENT → стабильные ID) →
 * SET FOREIGN_KEY_CHECKS=1. Журнал schema_migrations не трогаем, поэтому
 * повторный прогон сидера идемпотентен и даёт те же счётчики/ID/данные.
 *
 * Слои: запись напрямую через PDO — интерфейсы B2-01 контрактно только
 * на чтение, write-методов в них нет (план B2-02 §2.8).
 */
final readonly class DataSeeder
{
    public function __construct(
        private \PDO $pdo,
        private SeedCatalog $catalog,
        private StudentGenerator $generator,
    ) {
    }

    /**
     * Очищает сидовые таблицы и наполняет их каталогом.
     */
    public function seed(): void
    {
        $this->truncateSeedTables();

        $schoolIds = $this->insertSchools();
        $groupIds = $this->insertGroups($schoolIds);
        $modules = $this->modules();
        $moduleIds = $this->insertModules($schoolIds, $modules);
        $this->insertDisciplines($modules, $moduleIds);
        $this->insertFreeModuleEligibleSchools($moduleIds, $schoolIds);
        $this->insertStudents($groupIds);
    }

    /**
     * Создаёт сидер из конфигурации config/db.php (для scripts/seed.php).
     *
     * @param string $configPath путь к файлу конфигурации БД
     */
    public static function fromConfigFile(string $configPath): self
    {
        $factory = PdoFactory::fromConfigFile($configPath);

        return new self($factory->create(), new SeedCatalog(), new StudentGenerator());
    }

    /**
     * TRUNCATE всех сидовых таблиц с отключёнными проверками внешних ключей.
     * Порядок перечисления не важен: FK_CHECKS=0 разрешает усечение родительских.
     */
    private function truncateSeedTables(): void
    {
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['assignments', 'application_items', 'applications', 'module_eligible_schools', 'disciplines', 'modules', 'students', 'student_groups', 'schools'] as $table) {
            $this->pdo->exec('TRUNCATE TABLE `' . $table . '`');
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * Вставляет школы и возвращает карту «код школы → id».
     *
     * @return array<non-empty-string, int>
     */
    private function insertSchools(): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO schools (code, name) VALUES (?, ?)');
        $ids = [];

        foreach ($this->catalog->schools() as $school) {
            $stmt->execute([$school['code'], $school['name']]);
            $ids[$school['code']] = (int) $this->pdo->lastInsertId();
        }

        return $ids;
    }

    /**
     * Вставляет учебные группы (по одной на школу) и возвращает карту
     * «код школы → id группы».
     *
     * @param array<non-empty-string, int> $schoolIds карта «код → id школы»
     *
     * @return array<non-empty-string, int>
     */
    private function insertGroups(array $schoolIds): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO student_groups (school_id, name, is_technical, admission_year) VALUES (?, ?, ?, ?)',
        );
        $ids = [];

        foreach ($this->catalog->schools() as $school) {
            $stmt->execute([
                $schoolIds[$school['code']],
                $school['code'] . '-1',
                $school['isTechnical'] ? 1 : 0,
                SeedCatalog::ADMISSION_YEAR,
            ]);
            $ids[$school['code']] = (int) $this->pdo->lastInsertId();
        }

        return $ids;
    }

    /**
     * Вставляет модули (технические, гуманитарные, свободные).
     *
     * @param array<non-empty-string, int> $schoolIds карта «код → id школы»
     * @param list<array{schoolCode: non-empty-string, title: non-empty-string,
     *                   moduleType: string, minStudents: int, maxStudents: int}> $modules
     *
     * @return list<int> идентификаторы модулей в порядке $modules
     */
    private function insertModules(array $schoolIds, array $modules): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO modules (school_id, title, module_type, min_students, max_students, academic_year)
             VALUES (?, ?, ?, ?, ?, ?)',
        );
        $ids = [];

        foreach ($modules as $module) {
            $stmt->execute([
                $schoolIds[$module['schoolCode']],
                $module['title'],
                $module['moduleType'],
                $module['minStudents'],
                $module['maxStudents'],
                SeedCatalog::ACADEMIC_YEAR,
            ]);
            $ids[] = (int) $this->pdo->lastInsertId();
        }

        return $ids;
    }

    /**
     * Все модули каталога одним списком в порядке: технические, гуманитарные,
     * свободные. Свободным модулям тип 'free'.
     *
     * @return list<array{schoolCode: non-empty-string, title: non-empty-string,
     *                    moduleType: string, minStudents: int, maxStudents: int}>
     */
    private function modules(): array
    {
        $modules = [];
        foreach ($this->catalog->technicalModules() as $module) {
            $modules[] = $module + ['moduleType' => 'technical'];
        }
        foreach ($this->catalog->humanitarianModules() as $module) {
            $modules[] = $module + ['moduleType' => 'humanitarian'];
        }
        foreach ($this->catalog->freeModules() as $module) {
            $modules[] = $module + ['moduleType' => 'free'];
        }

        return $modules;
    }

    /**
     * Вставляет по 3 дисциплины на модуль с семестрами {3, 4, 5} (R-02/ADR-005).
     *
     * @param list<array{schoolCode: non-empty-string, title: non-empty-string,
     *                   moduleType: string, minStudents: int, maxStudents: int}> $modules
     * @param list<int> $moduleIds идентификаторы модулей в том же порядке
     */
    private function insertDisciplines(array $modules, array $moduleIds): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO disciplines (module_id, title, semester) VALUES (?, ?, ?)');

        foreach ($moduleIds as $index => $moduleId) {
            foreach ([3, 4, 5] as $semester) {
                $stmt->execute([
                    $moduleId,
                    'Дисциплина модуля «' . $modules[$index]['title'] . '», семестр ' . $semester,
                    $semester,
                ]);
            }
        }
    }

    /**
     * Закрепляет свободные модули за подмножествами школ (R-19, инвариант (б)).
     *
     * Свободные модули — последние FREE_MODULE_COUNT записей в $moduleIds.
     *
     * @param list<int>                    $moduleIds идентификаторы модулей в порядке каталога
     * @param array<non-empty-string, int> $schoolIds карта «код → id школы»
     */
    private function insertFreeModuleEligibleSchools(array $moduleIds, array $schoolIds): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO module_eligible_schools (module_id, school_id) VALUES (?, ?)',
        );

        $freeCount = SeedCatalog::FREE_MODULE_COUNT;
        $freeOffset = count($moduleIds) - $freeCount;
        $subsets = $this->catalog->freeModuleEligibleSchools();

        foreach ($subsets as $index => $schoolCodes) {
            $moduleId = $moduleIds[$freeOffset + $index];
            foreach ($schoolCodes as $schoolCode) {
                $stmt->execute([$moduleId, $schoolIds[$schoolCode]]);
            }
        }
    }

    /**
     * Генерирует и вставляет студентов по шаблону «код школы → число студентов».
     *
     * @param array<non-empty-string, int> $groupIds карта «код школы → id группы»
     */
    private function insertStudents(array $groupIds): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO students (
                group_id, full_name, is_target_quota, is_paid, is_disabled,
                entrance_exams_sum, gpa_sem12, gpa_basic, entrance_test_score
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        );

        foreach ($this->catalog->studentsBySchool() as $schoolCode => $count) {
            $groupId = $groupIds[$schoolCode];
            foreach ($this->generator->rows($groupId, $count) as $row) {
                $insert->execute([
                    $groupId,
                    $row['fullName'],
                    $row['isTargetQuota'] ? 1 : 0,
                    $row['isPaid'] ? 1 : 0,
                    $row['isDisabled'] ? 1 : 0,
                    $row['entranceExamsSum'],
                    $row['gpaSem12'],
                    $row['gpaBasic'],
                    $row['entranceTestScore'],
                ]);
            }
        }
    }
}
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Db;

use App\Domain\Enum\ModuleType;
use App\Infrastructure\Db\PdoFactory;
use App\Infrastructure\Seeder\DataSeeder;
use App\Infrastructure\Seeder\SeedCatalog;
use App\Infrastructure\Seeder\StudentGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Интеграционный тест сидера (B2-02, критерии приёмки).
 *
 * Бутстрап по паттерну B2-01: guard config/db.php → подпроцесс
 * scripts/migrate.php → DataSeeder::seed() в setUpBeforeClass. Прогон сидера
 * выполняется ДВАЖДЫ: первый — наполнение, второй — проверка детерминизма
 * (одинаковые счётчики строк, ID и контрольные суммы выборки).
 *
 * Стиль запросов — из B2-01 (RepositoryRoundTripTest): prepare() + execute(),
 * inline-аннотации @var; query() не используется (phpstan-phpunit не подключён,
 * поэтому сужение типов только через flow-анализ if-проверок).
 *
 * Проверяемые правила: R-01 (один год приёма), R-02/R-04 (3 дисциплины {3,4,5},
 * min≤max, диапазоны каталога), R-10 (инвариант (в): Σmax свободных ≥ 0.25×N),
 * R-11 (инвариант (а): Σmax типа ≥ N типа), R-13, R-19 (ровно 3 свободных +
 * module_eligible_schools), Q-4 (метрики в границах).
 */
final class SeederRoundTripTest extends TestCase
{
    private static PdoFactory $factory;
    private static \PDO $pdo;

    public static function setUpBeforeClass(): void
    {
        $configPath = dirname(__DIR__, 3) . '/config/db.php';
        self::assertFileExists($configPath, 'config/db.php отсутствует — скопируйте config/db.php.example');

        $command = PHP_BINARY . ' ' . escapeshellarg(dirname(__DIR__, 3) . '/scripts/migrate.php');
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);
        if ($exitCode !== 0) {
            self::fail('Не удалось применить миграции (шаг неуспешен): ' . implode("\n", $output));
        }

        self::$factory = PdoFactory::fromConfigFile($configPath);
        self::$pdo = self::$factory->create();

        // Первый прогон — наполнение.
        (new DataSeeder(self::$pdo, new SeedCatalog(), new StudentGenerator()))->seed();
    }

    public static function tearDownAfterClass(): void
    {
        // Изоляция: оставленный наполнением набор ломает B2-01, ожидающий пустую
        // БД (см. RepositoryRoundTripTest). Очищаем те же таблицы тем же протоколом.
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['assignments', 'application_items', 'applications', 'module_eligible_schools', 'disciplines', 'modules', 'students', 'student_groups', 'schools'] as $table) {
            self::$pdo->exec('TRUNCATE TABLE `' . $table . '`');
        }
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testSeedProducesSeedSnapshotDeterministically(): void
    {
        $snapshotFirst = $this->snapshot();

        // Второй прогон — тот же каталог и тот же генератор.
        (new DataSeeder(self::$pdo, new SeedCatalog(), new StudentGenerator()))->seed();
        $snapshotSecond = $this->snapshot();

        self::assertSame($snapshotFirst, $snapshotSecond, 'Два прогона сидера должны дать идентичные данные');
    }

    public function testSeedCountsMatchCatalog(): void
    {
        $catalog = new SeedCatalog();

        self::assertSame(count($catalog->schools()), $this->countRows('schools'), 'число школ');
        self::assertSame(count($catalog->schools()), $this->countRows('student_groups'), 'число групп по школам');

        $expectedModules = SeedCatalog::TECHNICAL_MODULE_COUNT
            + SeedCatalog::HUMANITARIAN_MODULE_COUNT
            + SeedCatalog::FREE_MODULE_COUNT;
        self::assertSame($expectedModules, $this->countRows('modules'), 'число модулей');

        $students = $this->countRows('students');
        self::assertSame(SeedCatalog::STUDENT_COUNT, $students, 'число студентов');
        self::assertGreaterThanOrEqual(300, $students);
        self::assertLessThanOrEqual(500, $students);
    }

    public function testSingleAdmissionYear(): void
    {
        $stmt = self::$pdo->prepare('SELECT DISTINCT admission_year FROM student_groups');
        $stmt->execute();
        /** @var list<array{admission_year: string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        self::assertCount(1, $rows, 'должен быть ровно один год приёма (R-01)');
        self::assertSame(SeedCatalog::ADMISSION_YEAR, (int) $rows[0]['admission_year']);
    }

    public function testStudentsMetricsWithinQBounds(): void
    {
        $stmt = self::$pdo->prepare(
            'SELECT MIN(entrance_exams_sum) AS min_exams, MAX(entrance_exams_sum) AS max_exams,
                    MIN(gpa_sem12) AS min_gpa, MAX(gpa_sem12) AS max_gpa,
                    MIN(entrance_test_score) AS min_test, MAX(entrance_test_score) AS max_test
             FROM students',
        );
        $stmt->execute();
        /** @var array{min_exams: string, max_exams: string, min_gpa: string, max_gpa: string, min_test: string, max_test: string}|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertNotSame(false, $row);
        \assert(is_array($row));

        self::assertGreaterThanOrEqual(0, (int) $row['min_exams']);
        self::assertLessThanOrEqual(300, (int) $row['max_exams']);
        self::assertGreaterThanOrEqual(2.0, (float) $row['min_gpa']);
        self::assertLessThanOrEqual(5.0, (float) $row['max_gpa']);
        self::assertGreaterThanOrEqual(0, (int) $row['min_test']);
        self::assertLessThanOrEqual(100, (int) $row['max_test']);
    }

    public function testModulesIntrospectInvariants(): void
    {
        $catalog = new SeedCatalog();

        $stmt = self::$pdo->prepare(
            'SELECT module_type, min_students, max_students, academic_year
             FROM modules ORDER BY id',
        );
        $stmt->execute();
        /** @var list<array{module_type: string, min_students: string, max_students: string, academic_year: string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        self::assertNotSame('', $rows[0]['academic_year'] ?? '');
        self::assertSame(
            SeedCatalog::ACADEMIC_YEAR,
            $rows[0]['academic_year'],
            'учебный год модулей фиксирован',
        );

        $sumMaxByType = ['technical' => 0, 'humanitarian' => 0, 'free' => 0];
        foreach ($rows as $row) {
            $min = (int) $row['min_students'];
            $max = (int) $row['max_students'];

            self::assertGreaterThanOrEqual(SeedCatalog::MIN_STUDENTS_MIN, $min);
            self::assertLessThanOrEqual(SeedCatalog::MIN_STUDENTS_MAX, $min);
            self::assertGreaterThanOrEqual(SeedCatalog::MAX_STUDENTS_MIN, $max);
            self::assertLessThanOrEqual(SeedCatalog::MAX_STUDENTS_MAX, $max);
            self::assertGreaterThanOrEqual($min, $max, 'min_students не должен превышать max_students (R-04)');

            $sumMaxByType[$row['module_type']] += $max;
        }

        self::assertCount(
            $catalog::TECHNICAL_MODULE_COUNT,
            array_filter($rows, static fn (array $m): bool => $m['module_type'] === ModuleType::Technical->value),
            'ровно 8 технических модулей',
        );
        self::assertCount(
            $catalog::HUMANITARIAN_MODULE_COUNT,
            array_filter($rows, static fn (array $m): bool => $m['module_type'] === ModuleType::Humanitarian->value),
            'ровно 5 гуманитарных модулей',
        );
        self::assertCount(
            $catalog::FREE_MODULE_COUNT,
            array_filter($rows, static fn (array $m): bool => $m['module_type'] === ModuleType::Free->value),
            'ровно 3 свободных модуля (R-19)',
        );

        // Инвариант (а): Σmax типа ≥ N соответствующего типа (R-11).
        $studentsByGroupType = $this->studentsByGroupType();
        self::assertGreaterThanOrEqual(
            $studentsByGroupType['technical'],
            $sumMaxByType[ModuleType::Technical->value],
            'инвариант (а): Σmax технических ≥ N технических',
        );
        self::assertGreaterThanOrEqual(
            $studentsByGroupType['humanitarian'],
            $sumMaxByType[ModuleType::Humanitarian->value],
            'инвариант (а): Σmax гуманитарных ≥ N гуманитарных',
        );

        // Инвариант (в): Σmax свободных ≥ 0.25 × N (R-10).
        $n = $this->countRows('students');
        self::assertGreaterThanOrEqual(
            (int) ceil(0.25 * $n),
            $sumMaxByType[ModuleType::Free->value],
            'инвариант (в): Σmax свободных ≥ 0.25×N',
        );
    }

    public function testFreeModulesCoverAllSchools(): void
    {
        // Инвариант (б): объединение module_eligible_schools свободных модулей = все 7 школ.
        $stmt = self::$pdo->prepare(
            'SELECT DISTINCT s.code
             FROM module_eligible_schools me
             JOIN modules m ON m.id = me.module_id
             JOIN schools s ON s.id = me.school_id
             WHERE m.module_type = ?
             ORDER BY s.code',
        );
        $stmt->execute([ModuleType::Free->value]);
        /** @var list<array{code: string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $covered = array_column($rows, 'code');
        sort($covered);

        $expected = array_column((new SeedCatalog())->schools(), 'code');
        sort($expected);

        self::assertSame($expected, $covered, 'свободные модули покрывают все школы каталога (R-19)');
    }

    public function testDisciplinesThreePerModuleOnSemesters345(): void
    {
        $stmt = self::$pdo->prepare(
            'SELECT m.id AS module_id, COUNT(d.id) AS disciplines,
                    MIN(d.semester) AS min_sem, MAX(d.semester) AS max_sem
             FROM modules m
             LEFT JOIN disciplines d ON d.module_id = m.id
             GROUP BY m.id
             ORDER BY m.id',
        );
        $stmt->execute();
        /** @var list<array{module_id: string, disciplines: string, min_sem: string, max_sem: string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $expectedModules = SeedCatalog::TECHNICAL_MODULE_COUNT
            + SeedCatalog::HUMANITARIAN_MODULE_COUNT
            + SeedCatalog::FREE_MODULE_COUNT;
        self::assertCount($expectedModules, $rows, 'у каждого модуля в выборке запись');

        foreach ($rows as $row) {
            self::assertSame(3, (int) $row['disciplines'], 'ровно 3 дисциплины на модуль (R-02)');
            self::assertSame(3, (int) $row['min_sem'], 'первая дисциплина — 3-й семестр (R-02)');
            self::assertSame(5, (int) $row['max_sem'], 'последняя дисциплина — 5-й семестр (R-02)');
        }
    }

    /**
     * Снимок данных сидера: счётчики всех сидовых таблиц + контрольная сумма
     * выборки студентов (id + ключевые метрики) и модулей.
     *
     * @return array{counts: array<string, int>, checksum: string}
     */
    private function snapshot(): array
    {
        $counts = [];
        foreach (['schools', 'student_groups', 'students', 'modules', 'disciplines', 'module_eligible_schools'] as $table) {
            $counts[$table] = $this->countRows($table);
        }

        $studentsStmt = self::$pdo->prepare(
            'SELECT id, group_id, full_name, entrance_exams_sum, gpa_sem12, gpa_basic, entrance_test_score
             FROM students ORDER BY id',
        );
        $studentsStmt->execute();
        $students = $studentsStmt->fetchAll(\PDO::FETCH_ASSOC);

        $modulesStmt = self::$pdo->prepare(
            'SELECT id, school_id, title, module_type, min_students, max_students, academic_year
             FROM modules ORDER BY id',
        );
        $modulesStmt->execute();
        $modules = $modulesStmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'counts' => $counts,
            'checksum' => hash('sha256', serialize([$students, $modules])),
        ];
    }

    /**
     * Число студентов по типам групп (тех/гум).
     *
     * @return array{technical: int, humanitarian: int}
     */
    private function studentsByGroupType(): array
    {
        $stmt = self::$pdo->prepare(
            'SELECT g.is_technical AS is_technical, COUNT(s.id) AS cnt
             FROM students s
             JOIN student_groups g ON g.id = s.group_id
             GROUP BY g.is_technical',
        );
        $stmt->execute();
        /** @var list<array{is_technical: string, cnt: string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $result = ['technical' => 0, 'humanitarian' => 0];
        foreach ($rows as $row) {
            $key = $row['is_technical'] === '1' ? 'technical' : 'humanitarian';
            $result[$key] = (int) $row['cnt'];
        }

        return $result;
    }

    private function countRows(string $table): int
    {
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM `' . $table . '`');
        $stmt->execute();
        /** @var int|string|false $value */
        $value = $stmt->fetchColumn();

        return (int) $value;
    }
}
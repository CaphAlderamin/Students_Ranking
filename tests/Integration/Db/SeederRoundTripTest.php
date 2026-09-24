<?php

declare(strict_types=1);

namespace App\Tests\Integration\Db;

use App\Domain\Enum\ModuleType;
use App\Infrastructure\Db\PdoFactory;
use App\Infrastructure\Seeder\CohortCatalog;
use App\Infrastructure\Seeder\EdgeSeeder;
use App\Infrastructure\Seeder\SeedCatalog;
use App\Infrastructure\Seeder\StudentGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Интеграционный тест сидера (B2-03, критерии приёмки, план v4).
 *
 * Бутстрап по паттерну B2-02: guard config/db.php → подпроцесс
 * scripts/migrate.php → EdgeSeeder::seed() в setUpBeforeClass (композиция поверх
 * DataSeeder: базовый каталог + краевые когорты + недоборный модуль). Прогон
 * сидера выполняется ДВАЖДЫ: первый — наполнение, второй — проверка
 * детерминизма (одинаковые счётчики строк, ID, контрольные суммы выборки
 * и флаги когорт).
 *
 * Расщеплённые проверки (правка ревью v2/v4):
 *  - (а) DB-флаг-счёты: целевики 30, инвалиды 10, платники 50, пересечение 5;
 *  - (б) выборки каталога (CohortCatalog): размеры и непересекаемость
 *    (целевики 30 / «молчуны» 40 / инвалиды-основные 5 / платники-основные 50),
 *    студенты «молчуны-40» с is_target_quota=0, «целевики-8» — с
 *    is_target_quota=1; различных 125, база 275.
 *
 * Стиль запросов — из B2-01 (RepositoryRoundTripTest): prepare() + execute(),
 * inline-аннотации @var; query() не используется.
 *
 * Проверяемые правила: R-01, R-02 (3 дисциплины {3,4,5}), R-04 (min≤max,
 * диапазоны), R-10/R-11/R-19 (инварианты вместимости), R-14/R-15/R-16 (когорты),
 * R-19 (ровно 3 свободных + module_eligible_schools), ADR-002/004/006, Q-4.
 */
final class SeederRoundTripTest extends TestCase
{
    private static PdoFactory $factory;
    private static \PDO $pdo;
    private static CohortCatalog $cohorts;

    /** Отчёт первого прогона EdgeSeeder (сегменты когорт + модули). */
    /** @var array{segmentIds: array<string, list<int>>, moduleIds: list<int>} */
    private static array $report;

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
        self::$cohorts = new CohortCatalog();

        // Первый прогон — наполнение каталогом и когортами.
        self::$report = (new EdgeSeeder(self::$pdo, new SeedCatalog(), self::$cohorts, new StudentGenerator()))->seed();
    }

    public static function tearDownAfterClass(): void
    {
        // Изоляция: данные сидера не должны попадать в другие интеграционные
        // тесты. Очищаем те же таблицы тем же протоколом (пункт 0 дублирует
        // очистку в RepositoryRoundTripTest — здесь она оставлена по прецеденту B2-02).
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['assignments', 'application_items', 'applications', 'module_eligible_schools', 'disciplines', 'modules', 'students', 'student_groups', 'schools'] as $table) {
            self::$pdo->exec('TRUNCATE TABLE `' . $table . '`');
        }
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testSeedProducesSeedSnapshotDeterministically(): void
    {
        $snapshotFirst = $this->snapshot();

        // Второй прогон — тот же каталог и тот же генератор (EdgeSeeder).
        $reportSecond = (new EdgeSeeder(self::$pdo, new SeedCatalog(), self::$cohorts, new StudentGenerator()))->seed();
        $snapshotSecond = $this->snapshot();

        self::assertSame($snapshotFirst, $snapshotSecond, 'Два прогона сидера должны дать идентичные данные');

        $firstSegments = self::$report['segmentIds'];
        $secondSegments = $reportSecond['segmentIds'];
        self::assertSame(
            $firstSegments,
            $secondSegments,
            'Распределение студентов по когортам должно быть детерминированным',
        );
    }

    public function testSeedCountsMatchCatalog(): void
    {
        $catalog = new SeedCatalog();

        self::assertSame(count($catalog->schools()), $this->countRows('schools'), 'число школ');
        self::assertSame(count($catalog->schools()), $this->countRows('student_groups'), 'число групп по школам');

        $expectedModules = self::$cohorts->totalModuleCount();
        self::assertSame($expectedModules, $this->countRows('modules'), 'число модулей (16 базовых + 1 недоборный)');

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

        // 8 базовых технических + 1 недоборный.
        self::assertCount(
            $catalog::TECHNICAL_MODULE_COUNT + 1,
            array_filter($rows, static fn (array $m): bool => $m['module_type'] === ModuleType::Technical->value),
            'ровно 9 технических модулей (8 + недоборный)',
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

        $expectedModules = self::$cohorts->totalModuleCount();
        self::assertCount($expectedModules, $rows, 'у каждого модуля в выборке запись');

        foreach ($rows as $row) {
            self::assertSame(3, (int) $row['disciplines'], 'ровно 3 дисциплины на модуль (R-02)');
            self::assertSame(3, (int) $row['min_sem'], 'первая дисциплина — 3-й семестр (R-02)');
            self::assertSame(5, (int) $row['max_sem'], 'последняя дисциплина — 5-й семестр (R-02)');
        }
    }

    public function testUnderEnrollmentModuleInCatalog(): void
    {
        $module = self::$cohorts->underEnrollmentModule();
        $demand = self::$cohorts->underEnrollmentDemand();

        self::assertSame('МДС: редкое направление', $module['title']);
        self::assertSame('ИЯТШ', $module['schoolCode']);
        self::assertSame('technical', $module['moduleType']);
        self::assertSame(CohortCatalog::UNDERENROLLMENT_MIN, $module['minStudents']);
        self::assertSame(CohortCatalog::UNDERENROLLMENT_MAX, $module['maxStudents']);
        self::assertSame(6, $demand['count']);
        self::assertSame(4, $demand['priorityOne']);
        self::assertSame(2, $demand['priorityTwo']);
        self::assertLessThan(CohortCatalog::UNDERENROLLMENT_MIN, CohortCatalog::UNDERENROLLMENT_DEMAND_COUNT, 'D < min (заведомый недобор)');

        $stmt = self::$pdo->prepare(
            'SELECT m.id, m.title, m.module_type, m.min_students, m.max_students, s.code AS school_code
             FROM modules m
             JOIN schools s ON s.id = m.school_id
             WHERE m.id = ?',
        );
        $stmt->execute([self::$report['moduleIds'][0]]);
        /** @var array{id: string, title: string, module_type: string, min_students: string, max_students: string, school_code: string}|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertNotSame(false, $row);
        \assert(is_array($row));

        self::assertSame($module['title'], $row['title']);
        self::assertSame('technical', $row['module_type']);
        self::assertSame(CohortCatalog::UNDERENROLLMENT_MIN, (int) $row['min_students']);
        self::assertSame(CohortCatalog::UNDERENROLLMENT_MAX, (int) $row['max_students']);
        self::assertSame('ИЯТШ', $row['school_code']);
    }

    public function testCohortFlagCounts(): void
    {
        // (а) DB-флаг-счёты: целевики 30, инвалиды 10, платники 50, пересечение 5.
        self::assertSame(
            CohortCatalog::TARGET_QUOTA_COUNT,
            $this->countFlag('is_target_quota', 1),
            'счёт целевиков (DB-флаг)',
        );
        self::assertSame(
            CohortCatalog::DISABLED_BASE_COUNT + CohortCatalog::PAID_DISABLED_INTERSECTION_COUNT,
            $this->countFlag('is_disabled', 1),
            'счёт инвалидов = 5 основных + 5 пересечения',
        );
        self::assertSame(
            CohortCatalog::PAID_BASE_COUNT,
            $this->countFlag('is_paid', 1),
            'счёт платников',
        );
        self::assertSame(
            CohortCatalog::PAID_DISABLED_INTERSECTION_COUNT,
            $this->countPaidDisabledIntersection(),
            'пересечение paid ∩ disabled = 5',
        );
    }

    public function testCohortCatalogSelections(): void
    {
        // (б) Выборки каталога: размеры и непересекаемость основных выборок.
        $segments = self::$report['segmentIds'];

        $targetIds = array_merge($segments['target.technical'], $segments['target.humanitarian']);
        $silentIds = $segments['silent.non_target'];
        $disabledIds = $segments['disabled.base'];
        $paidIds = $segments['paid.base'];

        self::assertCount(CohortCatalog::TARGET_QUOTA_COUNT, $targetIds, 'целевики 30');
        self::assertCount(CohortCatalog::SILENT_NON_TARGET_COUNT, $silentIds, 'молчуны 40');
        self::assertCount(CohortCatalog::DISABLED_BASE_COUNT, $disabledIds, 'инвалиды-основные 5');
        self::assertCount(CohortCatalog::PAID_BASE_COUNT, $paidIds, 'платники-основные 50');
        self::assertCount(
            CohortCatalog::PAID_DISABLED_INTERSECTION_COUNT,
            $segments['paid.intersection'],
            'пересечение = 5 первых платников',
        );
        self::assertSame(
            $segments['paid.intersection'],
            array_slice($paidIds, 0, CohortCatalog::PAID_DISABLED_INTERSECTION_COUNT),
            'пересечение — первые платники по порядку rows()',
        );

        // Непересекаемость основных выборок (попарно).
        self::assertSame([], array_intersect($targetIds, $silentIds), 'целевики ∩ молчуны = ∅');
        self::assertSame([], array_intersect($targetIds, $disabledIds), 'целевики ∩ инвалиды-основные = ∅');
        self::assertSame([], array_intersect($targetIds, $paidIds), 'целевики ∩ платники-основные = ∅');
        self::assertSame([], array_intersect($silentIds, $disabledIds), 'молчуны ∩ инвалиды-основные = ∅');
        self::assertSame([], array_intersect($silentIds, $paidIds), 'молчуны ∩ платники-основные = ∅');
        self::assertSame([], array_intersect($disabledIds, $paidIds), 'инвалиды-основные ∩ платники-основные = ∅');

        // Различных студентов когорт = 30 + 40 + 5 + 50 = 125; база = 275.
        $distinct = array_values(array_unique(array_merge($targetIds, $silentIds, $disabledIds, $paidIds)));
        self::assertCount(125, $distinct, 'различных студентов когорт 125');

        $allStudents = $this->orderedAllStudentIds();
        self::assertSame(SeedCatalog::STUDENT_COUNT - 125, count(array_diff($allStudents, $distinct)), 'база 275');
    }

    public function testSilentMolarsAndTargetWithoutApplication(): void
    {
        // «Молчуны-40» существуют с is_target_quota=0 (без заявки — только в B2-04).
        $silentIds = self::$report['segmentIds']['silent.non_target'];
        self::assertGreaterThan(0, count($silentIds));

        $targetFlagCount = $this->countFlagForIds('is_target_quota', 1, $silentIds);
        self::assertSame(0, $targetFlagCount, 'у «молчунов» is_target_quota = 0 (без пересечения с целевиками)');

        // «Целевики-8 без заявки» — с is_target_quota = 1.
        $targetWithoutApplication = self::$report['segmentIds']['target.without_application'];
        self::assertCount(CohortCatalog::TARGET_WITHOUT_APPLICATION_COUNT, $targetWithoutApplication);
        self::assertSame(
            count($targetWithoutApplication),
            $this->countFlagForIds('is_target_quota', 1, $targetWithoutApplication),
            'все целевики без заявки маркированы is_target_quota = 1',
        );
    }

    public function testEdgeSeederCapacityInvariant(): void
    {
        // Инвариант B2-03: Σmax_free ≥ W + H (по данным каталога; assert в раннере).
        $sumMaxFree = 0;
        foreach ((new SeedCatalog())->freeModules() as $module) {
            $sumMaxFree += $module['maxStudents'];
        }

        self::assertGreaterThanOrEqual(
            CohortCatalog::W + CohortCatalog::H,
            $sumMaxFree,
            'Σmax_sвободных ≥ W + H (135 ≥ 70)',
        );
    }

    /**
     * Снимок данных сидера: счётчики всех сидовых таблиц + контрольная сумма
     * выборки студентов (id, флаги когорт, ключевые метрики) и модулей.
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
            'SELECT id, group_id, full_name, is_target_quota, is_paid, is_disabled,
                    entrance_exams_sum, gpa_sem12, gpa_basic, entrance_test_score
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

    /**
     * Число студентов с заданным значением флага.
     */
    private function countFlag(string $column, int $value): int
    {
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM students WHERE `' . $column . '` = ?');
        $stmt->execute([$value]);
        /** @var int|string|false $result */
        $result = $stmt->fetchColumn();

        return (int) $result;
    }

    /**
     * Число студентов с is_paid=1 и is_disabled=1 (пересечение paid ∩ disabled).
     */
    private function countPaidDisabledIntersection(): int
    {
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM students WHERE is_paid = 1 AND is_disabled = 1');
        $stmt->execute();
        /** @var int|string|false $result */
        $result = $stmt->fetchColumn();

        return (int) $result;
    }

    /**
     * Число студентов из списка с заданным значением флага.
     *
     * @param list<int> $ids
     */
    private function countFlagForIds(string $column, int $value, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        $stmt = self::$pdo->prepare(
            'SELECT COUNT(*) FROM students WHERE `' . $column . '` = ?'
            . ' AND id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
        );
        $stmt->execute([$value, ...$ids]);
        /** @var int|string|false $result */
        $result = $stmt->fetchColumn();

        return (int) $result;
    }

    /**
     * Id всех студентов в порядке вставки (как в EdgeSeeder::orderedStudentIds()).
     *
     * @return list<int>
     */
    private function orderedAllStudentIds(): array
    {
        $stmt = self::$pdo->prepare('SELECT id FROM students ORDER BY id');
        $stmt->execute();
        $values = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $ids = [];
        foreach ($values as $value) {
            if (!is_int($value) && !is_string($value)) {
                self::fail('id студента не является скаляром');
            }
            $ids[] = (int) $value;
        }

        return $ids;
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
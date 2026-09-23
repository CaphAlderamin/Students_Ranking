<?php

declare(strict_types=1);

namespace App\Tests\Integration\Db;

use App\Domain\Enum\GroupType;
use App\Domain\Enum\ModuleType;
use App\Domain\Model\Application;
use App\Domain\Service\ApplicationValidator;
use App\Infrastructure\Db\PdoApplicationRepository;
use App\Infrastructure\Db\PdoFactory;
use App\Infrastructure\Seeder\ApplicationSeeder;
use App\Infrastructure\Seeder\CohortCatalog;
use App\Infrastructure\Seeder\EdgeSeeder;
use App\Infrastructure\Seeder\SeedCatalog;
use App\Infrastructure\Seeder\StudentGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Интеграционный тест сидера заявок (B2-04, критерии приёмки, план v2).
 *
 * Бутстрап по паттерну B2-03: guard config/db.php → подпроцесс
 * scripts/migrate.php → EdgeSeeder::seed() → ApplicationSeeder::seed()
 * в setUpBeforeClass. Прогон сидера заявок выполняется ДВАЖДЫ: первый —
 * наполнение, второй — проверка детерминизма (счётчики 352/1056 + SHA-256).
 *
 * Проверяемые обязательства и правила:
 *  - обязательство #1 (B2-03): «молчуны» (40 + 8) — 0 строк заявок;
 *  - обязательство #3 (B2-03): недоборный модуль — ровно D = 6 заявителей
 *    (4×prio1, 2×prio2) из базы 275, непересекаемо с молчунами/целевиками без
 *    заявки, валидные следующие приоритеты (R-07), D < min;
 *  - обязательство #2 (B2-03): H_actual = T = 12 ≤ H (30), Σmax_free (135)
 *    ≥ W (40) + 12 = 52;
 *  - R-12: окно 1 неделя, приоритеты {1..N}, N ∈ 1..3;
 *  - R-13: только свой тип модуля, свободных модулей в заявках нет;
 *  - v2: под-профиль хвоста T = 12 (все 3 приоритета — концентраторы);
 *  - счётчики: applications = 352, items = 1056 (346×3 + 6×3).
 */
final class ApplicationRoundTripTest extends TestCase
{
    /** Окно подачи (R-12) — зеркальная копия константы сидера. */
    private const string WINDOW_START = '2026-09-07 09:00:00';
    private const string WINDOW_END = '2026-09-13 18:00:00';

    private static PdoFactory $factory;
    private static \PDO $pdo;
    private static CohortCatalog $cohorts;
    private static SeedCatalog $catalog;

    /** @var array{segmentIds: array<string, list<int>>, moduleIds: list<int>} */
    private static array $report;

    /** @var array{applications: int, items: int, windowStart: string, windowEnd: string, underEnrollmentCount: int} */
    private static array $applicationReport;

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
        self::$catalog = new SeedCatalog();

        // Первый прогон — каталог, когорты, заявки.
        $edgeSeeder = new EdgeSeeder(self::$pdo, self::$catalog, self::$cohorts, new StudentGenerator());
        self::$report = $edgeSeeder->seed();
        self::$applicationReport = (new ApplicationSeeder(self::$pdo, self::$cohorts))
            ->seed(self::$report['segmentIds'], self::$report['moduleIds']);
    }

    public static function tearDownAfterClass(): void
    {
        // Изоляция (протокол B2-03, пункт 0): данные сидера не попадают в другие тесты.
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['assignments', 'application_items', 'applications', 'module_eligible_schools', 'disciplines', 'modules', 'students', 'student_groups', 'schools'] as $table) {
            self::$pdo->exec('TRUNCATE TABLE `' . $table . '`');
        }
        self::$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }

    public function testSilentCohortsHaveNoApplications(): void
    {
        $silentIds = array_merge(
            self::$report['segmentIds']['silent.non_target'],
            self::$report['segmentIds']['target.without_application'],
        );
        self::assertCount(
            CohortCatalog::SILENT_NON_TARGET_COUNT + CohortCatalog::TARGET_WITHOUT_APPLICATION_COUNT,
            $silentIds,
            'мощность «молчунов» = 40 + 8',
        );

        $stmt = self::$pdo->prepare(
            'SELECT COUNT(*) FROM applications
             WHERE student_id IN (' . implode(', ', array_fill(0, count($silentIds), '?')) . ')',
        );
        $stmt->execute($silentIds);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'у «молчунов» нет заявок (обязательство #1)');

        $itemsStmt = self::$pdo->prepare(
            'SELECT COUNT(*) FROM application_items
             WHERE application_id IN (
                 SELECT id FROM applications
                 WHERE student_id IN (' . implode(', ', array_fill(0, count($silentIds), '?')) . ')
             )',
        );
        $itemsStmt->execute($silentIds);
        self::assertSame(0, (int) $itemsStmt->fetchColumn(), 'у «молчунов» нет и items');
    }

    public function testApplicationsMatchWindowAndPriorities(): void
    {
        $stmt = self::$pdo->prepare('SELECT MIN(submitted_at) AS min_ts, MAX(submitted_at) AS max_ts FROM applications');
        $stmt->execute();
        /** @var array{min_ts: string, max_ts: string}|false $row */
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertNotSame(false, $row);
        \assert(is_array($row));

        self::assertGreaterThanOrEqual(self::WINDOW_START, $row['min_ts'], 'минимальный submitted_at внутри окна');
        self::assertLessThanOrEqual(self::WINDOW_END, $row['max_ts'], 'максимальный submitted_at внутри окна');

        foreach ($this->repository()->findAll() as $application) {
            self::assertGreaterThanOrEqual(1, count($application->items), 'R-12: минимум 1 приоритет');
            self::assertLessThanOrEqual(3, count($application->items), 'R-12: максимум 3 приоритета');
        }
    }

    public function testApplicationsRespectOwnModuleType(): void
    {
        $studentTypes = $this->studentGroupTypes();     // studentId → GroupType
        $moduleTypes = $this->moduleTypeMap();          // moduleId → ModuleType
        $validator = new ApplicationValidator();

        foreach ($this->repository()->findAll() as $application) {
            self::assertArrayHasKey($application->studentId, $studentTypes, 'известен студент заявки');
            // R-13: только свой тип; свободные модули отклоняются валидатором.
            $validator->validate($application, $studentTypes[$application->studentId], $moduleTypes);
        }
    }

    public function testUnderEnrollmentProfile(): void
    {
        $underEnrollmentId = self::$report['moduleIds'][0];
        $module = self::$cohorts->underEnrollmentModule();

        $stmt = self::$pdo->prepare(
            'SELECT a.id AS application_id, a.student_id, ai.priority
             FROM application_items ai
             JOIN applications a ON a.id = ai.application_id
             WHERE ai.module_id = ?
             ORDER BY ai.priority, a.student_id',
        );
        $stmt->execute([$underEnrollmentId]);
        /** @var list<array{application_id: string, student_id: string, priority: string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $demand = self::$cohorts->underEnrollmentDemand();
        self::assertCount($demand['count'], $rows, 'ровно D = 6 заявок на недоборный модуль');

        $priorityCounts = [];
        $studentIds = [];
        foreach ($rows as $row) {
            $priority = (int) $row['priority'];
            $priorityCounts[$priority] = ($priorityCounts[$priority] ?? 0) + 1;
            $studentIds[] = (int) $row['student_id'];
        }
        self::assertSame($demand['priorityOne'], $priorityCounts[1] ?? 0, '4× приоритет 1');
        self::assertSame($demand['priorityTwo'], $priorityCounts[2] ?? 0, '2× приоритет 2');
        self::assertSame($demand['count'], count($priorityCounts) === 1 ? $priorityCounts[1] ?? 0 : array_sum($priorityCounts));

        // Заявители — из базовой популяции (275), непересекаемо с молчунами/целевиками без заявки.
        $baseIds = $this->basePopulationIds();
        foreach ($studentIds as $studentId) {
            self::assertContains($studentId, $baseIds, 'заявитель недоборного модуля должен быть из базы 275');
        }
        $silentIds = array_merge(
            self::$report['segmentIds']['silent.non_target'],
            self::$report['segmentIds']['target.without_application'],
        );
        self::assertSame([], array_intersect($studentIds, $silentIds), 'пересечение с молчунами = ∅');

        // D < min (заведомый недобор каталога).
        self::assertLessThan($module['minStudents'], $demand['count'], 'D < min');

        // У каждого заявителя есть валидный следующий приоритет после недоборного (R-07).
        foreach ($rows as $row) {
            $appId = (int) $row['application_id'];
            $itemStmt = self::$pdo->prepare(
                'SELECT ai.module_id, m.module_type
                 FROM application_items ai
                 JOIN modules m ON m.id = ai.module_id
                 WHERE ai.application_id = ? AND ai.priority > ?
                 ORDER BY ai.priority',
            );
            $itemStmt->execute([$appId, (int) $row['priority']]);
            /** @var list<array{module_type: string}> $next */
            $next = $itemStmt->fetchAll(\PDO::FETCH_ASSOC);
            self::assertNotEmpty($next, 'R-07: после недоборного модуля есть следующий приоритет');
            foreach ($next as $item) {
                self::assertSame(ModuleType::Technical->value, $item['module_type'], 'следующий приоритет — технический');
            }
        }
    }

    public function testDeterministicTailProfile(): void
    {
        // Концентраторы перегрузки: конкурсные модули, где спрос приоритета-1 > max.
        $overloaded = $this->overloadedModules();
        self::assertNotEmpty($overloaded, 'должны быть модули с перегрузкой приоритета-1');

        $appStmt = self::$pdo->prepare(
            'SELECT a.id, a.student_id,
                    GROUP_CONCAT(ai.module_id ORDER BY ai.priority) AS modules
             FROM applications a
             JOIN application_items ai ON ai.application_id = a.id
             GROUP BY a.id, a.student_id',
        );
        $appStmt->execute();
        /** @var list<array{id: string, student_id: string, modules: string}> $applications */
        $applications = $appStmt->fetchAll(\PDO::FETCH_ASSOC);

        $tailStudentIds = [];
        foreach ($applications as $application) {
            $moduleIds = array_map('intval', explode(',', $application['modules']));
            self::assertCount(3, $moduleIds, 'все подают ровно 3 приоритета');
            if (array_diff($moduleIds, $overloaded) === []) {
                $tailStudentIds[] = (int) $application['student_id'];
            }
        }

        // Ровно T = 12 студентов «бюджетников» все 3 приоритета держат на концентраторах.
        self::assertCount(12, $tailStudentIds, 'T = 12 студентов с полной перегрузкой приоритетов');

        // Все они — из базовой популяции (275), вне когорт (примечание #2).
        $baseIds = $this->basePopulationIds();
        foreach ($tailStudentIds as $studentId) {
            self::assertContains($studentId, $baseIds, 'студент T должен быть из базы 275 (вне когорт)');
        }

        // Ни один не подаёт заявку на недоборный модуль (примечание #1).
        $underEnrollmentId = self::$report['moduleIds'][0];
        self::assertNotContains($underEnrollmentId, $overloaded, 'недоборный модуль не является концентратором');
    }

    public function testCounts(): void
    {
        // Отчёт сидера.
        self::assertSame(352, self::$applicationReport['applications']);
        self::assertSame(1056, self::$applicationReport['items']);
        self::assertSame(6, self::$applicationReport['underEnrollmentCount']);
        self::assertSame(self::WINDOW_START, self::$applicationReport['windowStart']);
        self::assertSame(self::WINDOW_END, self::$applicationReport['windowEnd']);

        // Жёсткие ожидания по таблицам (фикс. v2: 346×3 + 6×3 = 1056).
        self::assertSame(352, $this->countRows('applications'), 'applications = 352');
        self::assertSame(1056, $this->countRows('application_items'), 'items = 1056');

        // Свободных модулей в заявках нет (R-13).
        $stmt = self::$pdo->prepare(
            'SELECT COUNT(*)
             FROM application_items ai
             JOIN modules m ON m.id = ai.module_id
             WHERE m.module_type = ?',
        );
        $stmt->execute([ModuleType::Free->value]);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'в заявках нет свободных модулей');
    }

    public function testCapacityInvariantWithHactual(): void
    {
        // H_actual = Σ по конкурсным модулям max(0, спрос(m) − max(m)),
        // спрос(m) — число заявок с приоритетом 1 на модуль m.
        $stmt = self::$pdo->prepare(
            'SELECT m.id, m.module_type, m.max_students,
                    SUM(CASE WHEN ai.priority = 1 THEN 1 ELSE 0 END) AS top_demand
             FROM modules m
             LEFT JOIN application_items ai ON ai.module_id = m.id
             GROUP BY m.id
             ORDER BY m.id',
        );
        $stmt->execute();
        /** @var list<array{id: string, module_type: string, max_students: string, top_demand: string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $hActual = 0;
        foreach ($rows as $row) {
            if ($row['module_type'] === ModuleType::Free->value) {
                continue; // только конкурсные модули
            }
            $overflow = max(0, (int) $row['top_demand'] - (int) $row['max_students']);
            $hActual += $overflow;
        }

        self::assertSame(12, $hActual, 'H_actual = T = 12 (остаток перегрузки 0)');
        self::assertLessThanOrEqual(CohortCatalog::H, $hActual, 'H_actual ≤ H (30)');

        $sumMaxFree = array_sum(array_column(self::$catalog->freeModules(), 'maxStudents'));
        self::assertGreaterThanOrEqual(
            CohortCatalog::W + $hActual,
            $sumMaxFree,
            'Σmax_free (135) ≥ W (40) + H_actual (12) = 52',
        );
    }

    public function testDeterminism(): void
    {
        $snapshotFirst = $this->applicationSnapshot();

        // Второй прогон полной цепочки: EdgeSeeder (TRUNCATE + каталог/когорты)
        // + ApplicationSeeder — повторный запуск детерминирован (как scripts/seed.php).
        $reportSecond = (new EdgeSeeder(self::$pdo, self::$catalog, self::$cohorts, new StudentGenerator()))->seed();
        $secondReport = (new ApplicationSeeder(self::$pdo, self::$cohorts))
            ->seed($reportSecond['segmentIds'], $reportSecond['moduleIds']);
        $snapshotSecond = $this->applicationSnapshot();

        self::assertSame(self::$report['segmentIds'], $reportSecond['segmentIds'], 'когорты второго прогона идентичны');
        self::assertSame($snapshotFirst, $snapshotSecond, 'два прогона заявок идентичны (детерминизм)');
        self::assertSame(['applications' => 352, 'items' => 1056], [
            'applications' => $secondReport['applications'],
            'items' => $secondReport['items'],
        ]);
    }

    public function testRepositoryReadsGeneratedApplications(): void
    {
        $applications = $this->repository()->findAll();
        self::assertCount(352, $applications, 'репозиторий читает все сгенерированные заявки');

        foreach ($applications as $application) {
            self::assertInstanceOf(Application::class, $application);
            self::assertGreaterThanOrEqual(1, $application->studentId);
            self::assertCount(3, $application->items, 'ровно 3 items у каждого заявителя');
        }
    }

    private function repository(): PdoApplicationRepository
    {
        return new PdoApplicationRepository(self::$factory);
    }

    /**
     * Концентраторы перегрузки: конкурсные модули со спросом приоритета-1 > max.
     *
     * @return list<int>
     */
    private function overloadedModules(): array
    {
        $stmt = self::$pdo->prepare(
            'SELECT m.id, m.max_students,
                    SUM(CASE WHEN ai.priority = 1 THEN 1 ELSE 0 END) AS top_demand
             FROM modules m
             LEFT JOIN application_items ai ON ai.module_id = m.id
             WHERE m.module_type <> ?
             GROUP BY m.id, m.max_students
             ORDER BY m.id',
        );
        $stmt->execute([ModuleType::Free->value]);
        /** @var list<array{id: string, max_students: string, top_demand: string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $overloaded = [];
        foreach ($rows as $row) {
            if ((int) $row['top_demand'] > (int) $row['max_students']) {
                $overloaded[] = (int) $row['id'];
            }
        }

        return $overloaded;
    }

    /**
     * Студенты базовой популяции (275): все, кроме различных членов когорт.
     *
     * @return list<int>
     */
    private function basePopulationIds(): array
    {
        $cohortIds = array_values(array_unique(array_merge(
            self::$report['segmentIds']['target.technical'],
            self::$report['segmentIds']['target.humanitarian'],
            self::$report['segmentIds']['silent.non_target'],
            self::$report['segmentIds']['disabled.base'],
            self::$report['segmentIds']['paid.base'],
        )));

        $allIds = $this->orderedStudentIds();
        return array_values(array_diff($allIds, $cohortIds));
    }

    /** @return list<int> */
    private function orderedStudentIds(): array
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

    /**
     * Тип группы студентов: studentId → GroupType.
     *
     * @return array<int, GroupType>
     */
    private function studentGroupTypes(): array
    {
        $stmt = self::$pdo->prepare(
            'SELECT s.id, g.is_technical
             FROM students s
             JOIN student_groups g ON g.id = s.group_id',
        );
        $stmt->execute();
        /** @var list<array{id: string, is_technical: string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = (int) $row['is_technical'] === 1
                ? GroupType::Technical
                : GroupType::Humanitarian;
        }

        return $result;
    }

    /** @return array<int, ModuleType> */
    private function moduleTypeMap(): array
    {
        $stmt = self::$pdo->prepare('SELECT id, module_type FROM modules');
        $stmt->execute();
        /** @var list<array{id: string, module_type: string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['id']] = ModuleType::from($row['module_type']);
        }

        return $result;
    }

    /**
     * Снимок данных заявок: счётчики + SHA-256 по выборке applications/application_items.
     *
     * @return array{counts: array<string, int>, checksum: string}
     */
    private function applicationSnapshot(): array
    {
        $counts = [
            'applications' => $this->countRows('applications'),
            'application_items' => $this->countRows('application_items'),
        ];

        $stmt = self::$pdo->prepare(
            'SELECT a.id, a.student_id, a.submitted_at, ai.module_id, ai.priority
             FROM applications a
             JOIN application_items ai ON ai.application_id = a.id
             ORDER BY a.id, ai.priority',
        );
        $stmt->execute();
        /** @var list<array<string, string|null>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return ['counts' => $counts, 'checksum' => hash('sha256', serialize($rows))];
    }

    private function countRows(string $table): int
    {
        $stmt = self::$pdo->prepare('SELECT COUNT(*) FROM `' . $table . '`');
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }
}
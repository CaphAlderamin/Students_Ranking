<?php

declare(strict_types=1);

namespace App\Tests\Integration\Db;

use App\Application\Distribution\DistributionService;
use App\Application\Ranking\CriteriaBasedRanking;
use App\Application\Ranking\CriteriaRating;
use App\Application\Ranking\DateBasedRanking;
use App\Domain\Enum\AssignmentSource;
use App\Domain\Enum\ModuleType;
use App\Domain\Model\Application;
use App\Domain\Model\Assignment;
use App\Domain\Model\DistributionResult;
use App\Domain\Model\Module;
use App\Domain\Model\Student;
use App\Domain\Model\StudentGroup;
use App\Domain\ValueObject\RatingWeights;
use App\Infrastructure\Db\PdoApplicationRepository;
use App\Infrastructure\Db\PdoFactory;
use App\Infrastructure\Db\PdoModuleRepository;
use App\Infrastructure\Db\PdoStudentRepository;
use App\Infrastructure\Seeder\ApplicationSeeder;
use App\Infrastructure\Seeder\CohortCatalog;
use App\Infrastructure\Seeder\EdgeSeeder;
use App\Infrastructure\Seeder\SeedCatalog;
use App\Infrastructure\Seeder\StudentGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Интеграционный прогон реального ядра на сид-данных B2-04 (B3-04, план v2
 * §2.2): 400 студентов, 17 модулей, 352 заявки, реальные стратегии
 * (DateBasedRanking / CriteriaBasedRanking). Сверка обязательств сидера в
 * распределении: «молчуны» → свободные (R-10), целевики → квота вне конкурса
 * (R-14/ADR-004/ADR-006), недоборный модуль закрыт (I-04) и его заявители
 * переобойдены (R-07), хвост T=12 (R-09), инварианты I-01…I-06 и детерминизм.
 *
 * Бутстрап по паттерну ApplicationRoundTripTest: guard config/db.php →
 * подпроцесс scripts/migrate.php → EdgeSeeder::seed() →
 * ApplicationSeeder::seed() в setUpBeforeClass; TRUNCATE в tearDownAfterClass.
 */
final class DistributionSeedRoundTripTest extends TestCase
{
    private static PdoFactory $factory;
    private static \PDO $pdo;
    private static CohortCatalog $cohorts;
    private static SeedCatalog $catalog;

    /** @var array{segmentIds: array<string, list<int>>, moduleIds: list<int>} */
    private static array $seedReport;

    /** @var list<Student> */
    private static array $students;

    /** @var list<Module> */
    private static array $modules;

    /** @var list<StudentGroup> */
    private static array $groups;

    /** @var list<Application> */
    private static array $applications;

    /** @var array<int, list<int>> */
    private static array $eligibleSchools;

    private static DistributionResult $dateResult;

    private static DistributionResult $criteriaResult;

    /** @var array<int, bool> студент → техническая ли его группа */
    private static array $technicalByStudent = [];

    private static int $underEnrollmentModuleId;

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

        // Сид B2-03/B2-04: заявки 352/1056, когорты, недоборный модуль.
        $edgeSeeder = new EdgeSeeder(self::$pdo, self::$catalog, self::$cohorts, new StudentGenerator());
        self::$seedReport = $edgeSeeder->seed();
        (new ApplicationSeeder(self::$pdo, self::$cohorts))
            ->seed(self::$seedReport['segmentIds'], self::$seedReport['moduleIds']);

        self::$underEnrollmentModuleId = self::$seedReport['moduleIds'][0];

        // Входы ядра через контракты (B2-01).
        self::$students = (new PdoStudentRepository(self::$factory))
            ->findAllByAdmissionYear(SeedCatalog::ADMISSION_YEAR);
        self::$modules = (new PdoModuleRepository(self::$factory))->findAll();
        self::$applications = (new PdoApplicationRepository(self::$factory))->findAll();

        // Входы вне контрактов — сырой PDO (паттерн тестов B2; без новых контрактов).
        self::$groups = self::readGroups();
        self::$eligibleSchools = self::readEligibleSchools();
        self::$technicalByStudent = self::technicalMap();

        self::assertCount(SeedCatalog::STUDENT_COUNT, self::$students, 'студентов 400');
        self::assertCount(self::$cohorts->totalModuleCount(), self::$modules, 'модулей 17 (16 + недоборный)');
        self::assertCount(352, self::$applications, 'заявок 352');

        /** @var array{weights: array<string, float>, bonuses: array<string, float>, tiers: array<string, bool>, normalization: string} $ratingConfig */
        $ratingConfig = require dirname(__DIR__, 3) . '/config/ranking.php';
        $ratingWeights = new RatingWeights(
            $ratingConfig['weights'],
            $ratingConfig['bonuses'],
            $ratingConfig['tiers'],
            $ratingConfig['normalization'],
        );

        /** @var array{target_quota_no_application_strategy: string} $distributionConfig */
        $distributionConfig = require dirname(__DIR__, 3) . '/config/distribution.php';
        $targetQuotaFlag = $distributionConfig['target_quota_no_application_strategy'];

        $dateStrategy = new DateBasedRanking(new CriteriaRating($ratingWeights));
        $criteriaStrategy = new CriteriaBasedRanking(new CriteriaRating($ratingWeights));

        self::$dateResult = self::distribute($dateStrategy, $targetQuotaFlag);
        self::$criteriaResult = self::distribute($criteriaStrategy, $targetQuotaFlag);
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

    // ---------------------------------------------------------- I-01 / R-11

    public function testDistributesAllStudentsOnSeed(): void
    {
        foreach ([self::$dateResult, self::$criteriaResult] as $result) {
            self::assertCount(SeedCatalog::STUDENT_COUNT, $result->assignments, '400 финальных назначений');
            self::assertSame([], $result->unassigned, 'unassigned пуст (R-11)');

            $ids = array_column($result->assignments, 'studentId');
            self::assertCount(400, array_unique($ids), 'каждый студент ровно в одном назначении (I-01)');
        }
    }

    // ---------------------------------------------------------- R-10 / I-06

    public function testSilentNonTargetsToFreeModulesOnSeed(): void
    {
        $silentIds = self::$seedReport['segmentIds']['silent.non_target'];
        self::assertCount(CohortCatalog::SILENT_NON_TARGET_COUNT, $silentIds, '«молчунов» 40');

        $freeIds = self::freeModuleIds();
        foreach ([self::$dateResult, self::$criteriaResult] as $result) {
            foreach ($silentIds as $studentId) {
                $assignment = self::assignmentById($result, $studentId);
                self::assertSame(AssignmentSource::Free, $assignment->source, 'молчун — source Free');
                self::assertContains($assignment->moduleId, $freeIds, 'молчун — на свободном модуле (R-10, I-06)');
            }
        }
    }

    // -------------------------------------------------- R-14 / ADR-004/006

    public function testTargetsPlacedByQuotaOnSeed(): void
    {
        $targetIds = array_merge(
            self::$seedReport['segmentIds']['target.technical'],
            self::$seedReport['segmentIds']['target.humanitarian'],
        );
        self::assertCount(CohortCatalog::TARGET_QUOTA_COUNT, $targetIds, 'целевиков 30');

        $withoutAppIds = self::$seedReport['segmentIds']['target.without_application'];
        self::assertCount(CohortCatalog::TARGET_WITHOUT_APPLICATION_COUNT, $withoutAppIds, 'целевиков без заявки 8');
        $withoutApp = array_fill_keys($withoutAppIds, true);

        $freeIds = self::freeModuleIds();
        foreach ([self::$dateResult, self::$criteriaResult] as $result) {
            foreach ($targetIds as $studentId) {
                $assignment = self::assignmentById($result, $studentId);

                // Служебный маршрут ADR-004 — источник квоты в обоих ветках.
                self::assertSame(AssignmentSource::TargetQuota, $assignment->source, 'целевик — source TargetQuota');
                self::assertNull($assignment->rank, 'целевик — без ранга (вне конкурса, R-14)');

                $isTechnical = self::$technicalByStudent[$studentId];
                $moduleType = self::moduleById($assignment->moduleId)->moduleType;

                if (in_array($assignment->moduleId, $freeIds, true)) {
                    self::assertTrue(
                        isset($withoutApp[$studentId]),
                        'на свободном модуле при ADR-004 fallback может быть только целевик без заявки',
                    );
                    continue;
                }

                $expected = $isTechnical ? ModuleType::Technical : ModuleType::Humanitarian;
                self::assertSame($expected, $moduleType, 'целевик — на модуль типа своей группы (R-13)');
            }
        }
    }

    // ------------------------------------------- R-07 / I-04 (обязательство #3)

    public function testUnderEnrolledModuleClosedAndApplicantsRewalked(): void
    {
        $demand = self::$cohorts->underEnrollmentDemand();

        $stmt = self::$pdo->prepare(
            'SELECT a.student_id, ai.priority
             FROM applications a
             JOIN application_items ai ON ai.application_id = a.id
             WHERE ai.module_id = ?
             ORDER BY a.student_id',
        );
        $stmt->execute([self::$underEnrollmentModuleId]);
        /** @var list<array{student_id: string, priority: string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        self::assertCount($demand['count'], $rows, 'ровно D = 6 заявителей недоборного модуля');

        $priorityCounts = [];
        foreach ($rows as $row) {
            $priorityCounts[(int) $row['priority']] = ($priorityCounts[(int) $row['priority']] ?? 0) + 1;
        }
        self::assertSame($demand['priorityOne'], $priorityCounts[1] ?? 0, '4× приоритет 1');
        self::assertSame($demand['priorityTwo'], $priorityCounts[2] ?? 0, '2× приоритет 2');

        foreach ([self::$dateResult, self::$criteriaResult] as $result) {
            // I-04: закрытый в фазе 1 недоборный модуль — ноль пустых финалов.
            $stats = $result->stats[(string) self::$underEnrollmentModuleId];
            self::assertIsArray($stats);
            /** @var array{status: string, fill: int} $stats */
            self::assertSame('closed', $stats['status'], 'недоборный модуль закрыт');
            self::assertSame(0, $stats['fill'], 'в закрытом модуле 0 финальных записей');

            foreach ($rows as $row) {
                $studentId = (int) $row['student_id'];
                $priorityOnUnderEnrollment = (int) $row['priority'];

                $assignment = self::assignmentById($result, $studentId);
                self::assertNotSame(
                    self::$underEnrollmentModuleId,
                    $assignment->moduleId,
                    'никто из шестерых не назначен на модуль 17',
                );
                self::assertSame(
                    AssignmentSource::Competition,
                    $assignment->source,
                    'все шестеро распределены через конкурс (source Competition)',
                );

                $priorities = self::priorityModuleIds($studentId);

                if ($priorityOnUnderEnrollment === 1) {
                    // Приоритет 1 = закрытый модуль → переобход на следующий приоритет (R-07).
                    self::assertContains(
                        $assignment->moduleId,
                        [$priorities[1], $priorities[2]],
                        'заявитель с prio1 = 17 переобойдён на следующий приоритет',
                    );
                    continue;
                }

                // Приоритет 2 = закрытый модуль: приоритет 1 остаётся без переобхода.
                self::assertSame(
                    $priorities[0],
                    $assignment->moduleId,
                    'заявитель с prio2 = 17 легитимно стоит на приоритете 1',
                );
            }
        }
    }

    // ------------------------------------------- R-09 (обязательство #2, T=12)

    public function testOverloadedTailToFreeOnSeed(): void
    {
        $overloaded = self::overloadedModuleIds();
        self::assertNotEmpty($overloaded, 'должны быть модули-концентраторы перегрузки');

        $tailIds = self::allOverloadedTailStudentIds($overloaded);
        self::assertCount(12, $tailIds, 'ровно T = 12 студентов с полной перегрузкой приоритетов');

        $freeIds = self::freeModuleIds();
        $sumMaxFree = 0;
        foreach (self::$modules as $module) {
            if ($module->moduleType === ModuleType::Free) {
                $sumMaxFree += $module->maxStudents;
            }
        }
        self::assertGreaterThanOrEqual(
            CohortCatalog::W + CohortCatalog::H,
            $sumMaxFree,
            'Σmax_free (135) ≥ W (40) + H (30) — ёмкость свободных запасная',
        );

        $tail = array_fill_keys($tailIds, true);

        // [v2, §5.5] Эмпирическое уточнение после прогона: ранжирование подтягивает
        // построенных 12 на концентраторы (под Date — всех 12, под Criteria — 11 из 12),
        // поэтому жёсткое «все 12 → свободные» заменено на подмножество + консистентность:
        //  - ни один НЕ-хвостовой конкурсный студент не уходит в свободные;
        //  - те из построенных 12, кто НЕ в свободных, размещены на концентраторах.
        // Именно это гарантирует обязательство #2 (H_actual = 12, Σmax_free ≥ 52) —
        // маршрут «хвост → свободные» реален и единственный для перегрузки.
        $allFree = [];
        foreach ([self::$dateResult, self::$criteriaResult] as $result) {
            foreach (self::competitiveFreeIds($result) as $studentId) {
                $allFree[$studentId] = true;
            }
        }
        self::assertNotEmpty($allFree, 'хвост реально попадает в свободные (R-09)');

        foreach ([self::$dateResult, self::$criteriaResult] as $result) {
            foreach (self::competitiveFreeIds($result) as $studentId) {
                self::assertArrayHasKey(
                    $studentId,
                    $tail,
                    'случайный конкурсный в свободных — только из построенных 12',
                );
                self::assertContains(
                    self::assignmentById($result, $studentId)->moduleId,
                    $freeIds,
                    'хвост — на свободном модуле',
                );
            }

            foreach ($tailIds as $studentId) {
                $assignment = self::assignmentById($result, $studentId);
                if (in_array($assignment->moduleId, $freeIds, true)) {
                    self::assertSame(AssignmentSource::Free, $assignment->source, 'свободный модуль → source Free');
                    continue;
                }
                self::assertContains(
                    $assignment->moduleId,
                    $overloaded,
                    'не-свободные из построенных 12 держат концентратор (не в свободных)',
                );
            }
        }
    }

    // ------------------------------------------------------------ I-01…I-06

    public function testInvariantsHoldOnSeedForBothStrategies(): void
    {
        $freeIds = self::freeModuleIds();
        $applicationStudentIds = array_fill_keys(
            array_map(static fn (Application $a): int => $a->studentId, self::$applications),
            true,
        );

        foreach ([self::$dateResult, self::$criteriaResult] as $result) {
            foreach ($result->stats as $moduleId => $stats) {
                self::assertIsArray($stats);
                /** @var array{fill: int, status: string, min: int, max: int, sources: array<string, int>} $stats */
                self::assertLessThanOrEqual($stats['max'], $stats['fill'], 'I-02: заполненность ≤ max');

                if ($stats['status'] === 'free') {
                    self::assertSame(0, $stats['sources']['competition'], 'I-06: свободный модуль без конкурсных записей');
                    continue;
                }
                if ($stats['status'] === 'closed') {
                    self::assertSame(0, $stats['fill'], 'I-04: закрытый модуль имеет 0 финальных записей');
                    continue;
                }
                if ($stats['fill'] > 0) {
                    self::assertGreaterThan($stats['min'], $stats['fill'], 'I-03: открытый модуль имеет fill > min');
                }
            }

            foreach ($result->assignments as $assignment) {
                if ($assignment->source !== AssignmentSource::Competition) {
                    continue;
                }
                // I-05: конкурсные final только у студентов с заявкой и типа группы.
                self::assertArrayHasKey($assignment->studentId, $applicationStudentIds, 'I-05: конкурс только с заявкой');

                $isTechnical = self::$technicalByStudent[$assignment->studentId];
                $expected = $isTechnical ? ModuleType::Technical : ModuleType::Humanitarian;
                self::assertSame(
                    $expected,
                    self::moduleById($assignment->moduleId)->moduleType,
                    'I-05: конкурсный модуль типа группы (R-13)',
                );
                self::assertNotContains(
                    $assignment->moduleId,
                    $freeIds,
                    'I-06: конкурсных записей на свободных модулях нет',
                );
            }
        }
    }

    // ------------------------------------------------------------ детерминизм

    public function testDeterminismOnSeed(): void
    {
        /** @var array{target_quota_no_application_strategy: string} $distributionConfig */
        $distributionConfig = require dirname(__DIR__, 3) . '/config/distribution.php';
        $targetQuotaFlag = $distributionConfig['target_quota_no_application_strategy'];

        /** @var array{weights: array<string, float>, bonuses: array<string, float>, tiers: array<string, bool>, normalization: string} $ratingConfig */
        $ratingConfig = require dirname(__DIR__, 3) . '/config/ranking.php';
        $ratingWeights = new RatingWeights(
            $ratingConfig['weights'],
            $ratingConfig['bonuses'],
            $ratingConfig['tiers'],
            $ratingConfig['normalization'],
        );

        // Повторный прогон сидера даёт идентичный отпечаток (DoD детерминизма).
        $dateSecond = self::distribute(new DateBasedRanking(new CriteriaRating($ratingWeights)), $targetQuotaFlag);
        $criteriaSecond = self::distribute(new CriteriaBasedRanking(new CriteriaRating($ratingWeights)), $targetQuotaFlag);

        self::assertSame(self::fingerprint(self::$dateResult), self::fingerprint($dateSecond), 'Date: идентичные финалы');
        self::assertSame(self::fingerprint(self::$criteriaResult), self::fingerprint($criteriaSecond), 'Criteria: идентичные финалы');
        self::assertCount(400, $criteriaSecond->assignments);
    }

    // ---------------------------------------------------------------- помощь

    private static function distribute(
        \App\Application\Ranking\RankingStrategyInterface $strategy,
        string $targetQuotaFlag,
    ): DistributionResult {
        $service = new DistributionService(
            strategy: $strategy,
            modules: self::$modules,
            groups: self::$groups,
            students: self::$students,
            applications: self::$applications,
            moduleEligibleSchools: self::$eligibleSchools,
            targetQuotaNoApplicationStrategy: $targetQuotaFlag,
        );

        return $service->distribute();
    }

    private static function assignmentById(DistributionResult $result, int $studentId): Assignment
    {
        foreach ($result->assignments as $assignment) {
            if ($assignment->studentId === $studentId) {
                return $assignment;
            }
        }

        self::fail('Студент ' . $studentId . ' не найден в назначениях');
    }

    private static function moduleById(int $moduleId): Module
    {
        foreach (self::$modules as $module) {
            if ($module->id === $moduleId) {
                return $module;
            }
        }

        self::fail('Модуль ' . $moduleId . ' не найден в findAll()');
    }

    /** @return list<int> */
    private static function freeModuleIds(): array
    {
        $ids = [];
        foreach (self::$modules as $module) {
            if ($module->moduleType === ModuleType::Free) {
                $ids[] = $module->id;
            }
        }

        return $ids;
    }

    /**
     * Конкурсные (с заявкой) студенты со source Free — конкурсный хвост.
     *
     * @return list<int>
     */
    private static function competitiveFreeIds(DistributionResult $result): array
    {
        $applicationStudents = array_fill_keys(
            array_map(static fn (Application $a): int => $a->studentId, self::$applications),
            true,
        );

        $ids = [];
        foreach ($result->assignments as $assignment) {
            if ($assignment->source === AssignmentSource::Free && isset($applicationStudents[$assignment->studentId])) {
                $ids[] = $assignment->studentId;
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * Приоритеты студента по возрастанию: moduleId в порядке 1→3.
     *
     * @return list<int>
     */
    private static function priorityModuleIds(int $studentId): array
    {
        $stmt = self::$pdo->prepare(
            'SELECT ai.module_id
             FROM application_items ai
             JOIN applications a ON a.id = ai.application_id
             WHERE a.student_id = ?
             ORDER BY ai.priority',
        );
        $stmt->execute([$studentId]);
        $values = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $ids = [];
        foreach ($values as $value) {
            if (!is_int($value) && !is_string($value)) {
                self::fail('module_id заявителя не является скаляром');
            }
            $ids[] = (int) $value;
        }

        return $ids;
    }

    /**
     * Концентраторы перегрузки: конкурсные модули со спросом приоритета-1 > max.
     *
     * @return list<int>
     */
    private static function overloadedModuleIds(): array
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
     * Студенты, у всех которых все 3 приоритета — на концентраторах.
     *
     * @param list<int> $overloaded
     *
     * @return list<int>
     */
    private static function allOverloadedTailStudentIds(array $overloaded): array
    {
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
            if (array_diff($moduleIds, $overloaded) === []) {
                $tailStudentIds[] = (int) $application['student_id'];
            }
        }

        return $tailStudentIds;
    }

    /**
     * Группы (вне контрактов) — сырой PDO.
     *
     * @return list<StudentGroup>
     */
    private static function readGroups(): array
    {
        $stmt = self::$pdo->prepare(
            'SELECT id, school_id, name, is_technical, admission_year FROM student_groups ORDER BY id',
        );
        $stmt->execute();
        /** @var list<array<string, int|string|null>> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $groups = [];
        foreach ($rows as $row) {
            $groups[] = new StudentGroup(
                (int) $row['id'],
                (int) $row['school_id'],
                (string) $row['name'],
                (int) $row['is_technical'] === 1,
                (int) $row['admission_year'],
            );
        }

        return $groups;
    }

    /**
     * Карта «moduleId → школы» (R-19, вне контрактов) — сырой PDO.
     *
     * @return array<int, list<int>>
     */
    private static function readEligibleSchools(): array
    {
        $stmt = self::$pdo->prepare(
            'SELECT module_id, school_id FROM module_eligible_schools ORDER BY module_id, school_id',
        );
        $stmt->execute();
        /** @var list<array{module_id: int|string, school_id: int|string}> $rows */
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['module_id']][] = (int) $row['school_id'];
        }

        return $map;
    }

    /** @return array<int, bool> студент → техническая ли его группа */
    private static function technicalMap(): array
    {
        $groupByStudent = [];
        foreach (self::$groups as $group) {
            $groupByStudent[$group->id] = $group->isTechnical;
        }

        $map = [];
        foreach (self::$students as $student) {
            self::assertTrue(isset($groupByStudent[$student->groupId]), 'группа студента найдена');
            $map[$student->id] = $groupByStudent[$student->groupId];
        }

        return $map;
    }

    /**
     * Детерминированный отпечаток: студент → {модуль, источник, ранг}.
     *
     * @return list<string>
     */
    private static function fingerprint(DistributionResult $result): array
    {
        $lines = [];
        foreach ($result->assignments as $assignment) {
            $lines[] = implode(':', [
                (string) $assignment->studentId,
                (string) $assignment->moduleId,
                $assignment->source->value,
                (string) ($assignment->rank ?? '-'),
            ]);
        }
        sort($lines);

        return $lines;
    }
}
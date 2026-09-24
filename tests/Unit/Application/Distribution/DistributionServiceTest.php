<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Distribution;

use App\Application\Distribution\DistributionService;
use App\Application\Ranking\RankingStrategyInterface;
use App\Domain\Enum\AssignmentSource;
use App\Domain\Enum\ModuleType;
use App\Domain\Enum\RankingAlgorithm;
use App\Domain\Model\Application;
use App\Domain\Model\ApplicationItem;
use App\Domain\Model\Assignment;
use App\Domain\Model\Discipline;
use App\Domain\Model\DistributionResult;
use App\Domain\Model\Module;
use App\Domain\Model\RankingCandidate;
use App\Domain\Model\Student;
use App\Domain\Model\StudentGroup;
use App\Domain\ValueObject\AcademicYear;
use App\Domain\ValueObject\Semester;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты DistributionService (B3-03, план v3 §4): фазы 0/1/1.1/2/3/3.5/4/5,
 * инварианты I-01…I-06, переобход фазы 1.1, фикстура-обязательство ревью B2-04
 * («целевик с заявкой, приоритеты исчерпаны → свободные»), детерминизм и не-мутация
 * входов.
 *
 * Фикстуры: техническая группа id 1 (школа 10) и гуманитарная id 2 (школа 20);
 * свободные модули — только через module_eligible_schools. Стратегия-фейк — полный
 * детерминированный порядок по student.id ASC (прогнозируемое ранжирование).
 * Все открытые не-свободные модули фикстур заканчивают с fill > min (I-03/R-05).
 */
final class DistributionServiceTest extends TestCase
{
    private const int GROUP_TECH = 1;
    private const int SCHOOL_TECH = 10;

    /**
     * Стратегия-фейк: стабильный порядок конкурсного пула по student.id ASC.
     */
    private function strategy(): RankingStrategyInterface
    {
        return new class implements RankingStrategyInterface {
            public function sort(array $pool): array
            {
                $sorted = $pool;
                usort(
                    $sorted,
                    static fn (RankingCandidate $a, RankingCandidate $b): int => $a->student->id <=> $b->student->id,
                );

                return $sorted;
            }

            public function algorithm(): RankingAlgorithm
            {
                return RankingAlgorithm::Date;
            }
        };
    }

    /**
     * Запуск распределения на синтетической фикстуре.
     *
     * @param list<Module>          $modules
     * @param list<StudentGroup>    $groups
     * @param list<Student>         $students
     * @param list<Application>     $applications
     * @param array<int, list<int>> $eligibleSchools
     */
    private function distribute(
        array $modules,
        array $groups,
        array $students,
        array $applications,
        array $eligibleSchools = [],
        string $targetQuotaFlag = DistributionService::PROFILE_MODULE,
    ): DistributionResult {
        $service = new DistributionService(
            strategy: $this->strategy(),
            modules: $modules,
            groups: $groups,
            students: $students,
            applications: $applications,
            moduleEligibleSchools: $eligibleSchools,
            targetQuotaNoApplicationStrategy: $targetQuotaFlag,
        );

        return $service->distribute();
    }

    private static function group(int $id, int $schoolId, bool $technical = true): StudentGroup
    {
        return new StudentGroup(
            id: $id,
            schoolId: $schoolId,
            name: 'Группа ' . $id,
            isTechnical: $technical,
            admissionYear: 2025,
        );
    }

    private static function module(int $id, ModuleType $type, int $min, int $max, int $schoolId = 1): Module
    {
        $disciplines = [];
        foreach ([3, 4, 5] as $index => $semester) {
            $disciplines[] = new Discipline(
                id: $id * 1000 + $index + 1,
                title: 'Дисциплина модуля ' . $id . ', семестр ' . $semester,
                semester: new Semester($semester),
            );
        }

        return new Module(
            id: $id,
            schoolId: $schoolId,
            title: 'Модуль ' . $id,
            moduleType: $type,
            minStudents: $min,
            maxStudents: $max,
            academicYear: new AcademicYear('2026-2027'),
            disciplines: $disciplines,
        );
    }

    private static function student(int $id, int $groupId = self::GROUP_TECH, bool $target = false): Student
    {
        return new Student(
            id: $id,
            groupId: $groupId,
            fullName: 'Студент ' . $id,
            isTargetQuota: $target,
            isPaid: false,
            isDisabled: false,
            entranceExamsSum: 150,
            gpaSem12: 3.5,
            gpaBasic: 3.5,
            entranceTestScore: 50,
        );
    }

    /**
     * Заявка со списком модулей по возрастанию приоритета (приоритет = позиция + 1).
     *
     * @param list<int> $moduleIds модули по приоритетам 1→N (N ∈ 1..3, непрерывный ряд)
     */
    private static function application(int $id, int $studentId, string $submittedAt, array $moduleIds): Application
    {
        $items = [];
        foreach ($moduleIds as $priority => $moduleId) {
            $items[] = new ApplicationItem(moduleId: $moduleId, priority: $priority + 1);
        }

        return new Application(
            id: $id,
            studentId: $studentId,
            submittedAt: new \DateTimeImmutable($submittedAt),
            items: $items,
        );
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

    /** Модули без назначений в финале (для проверки I-04 и закрытий). */
    private static function fillOfModule(DistributionResult $result, int $moduleId): int
    {
        $stats = $result->stats[(string) $moduleId];
        self::assertIsArray($stats, 'В статистике нет модуля ' . $moduleId);

        /** @var array{fill: int} $stats */
        return $stats['fill'];
    }

    /** Статус модуля в статистике результата. */
    private static function statusOf(DistributionResult $result, int $moduleId): string
    {
        $stats = $result->stats[(string) $moduleId];
        self::assertIsArray($stats, 'В статистике нет модуля ' . $moduleId);

        /** @var array{status: string} $stats */
        return $stats['status'];
    }

    // ------------------------------------------------------------- фаза 0 / R-14

    public function testTargetsPlacedOutOfCompetition(): void
    {
        $m10 = self::module(10, ModuleType::Technical, 2, 3);
        $groups = [self::group(self::GROUP_TECH, self::SCHOOL_TECH)];
        $students = [
            self::student(1, target: true),
            self::student(2),
            self::student(3),
        ];
        $applications = [
            self::application(1, 1, '2026-09-07 09:00:00', [10]),
            self::application(2, 2, '2026-09-07 09:00:00', [10]),
            self::application(3, 3, '2026-09-07 09:00:00', [10]),
        ];

        $result = $this->distribute([$m10], $groups, $students, $applications);

        $target = self::assignmentById($result, 1);
        self::assertSame(10, $target->moduleId);
        self::assertSame(AssignmentSource::TargetQuota, $target->source);
        self::assertNull($target->rank);

        $first = self::assignmentById($result, 2);
        $second = self::assignmentById($result, 3);
        self::assertSame(AssignmentSource::Competition, $first->source);
        self::assertSame(1, $first->rank);
        self::assertSame(AssignmentSource::Competition, $second->source);
        self::assertSame(2, $second->rank);
    }

    // ------------------------------------------------------------- фазы 1 / 1.1

    public function testPhaseOneClosesModuleWithLowDemand(): void
    {
        $m10 = self::module(10, ModuleType::Technical, 5, 5);
        $m11 = self::module(11, ModuleType::Technical, 2, 3);
        $f100 = self::module(100, ModuleType::Free, 1, 4);
        $groups = [self::group(self::GROUP_TECH, self::SCHOOL_TECH)];
        $students = [
            self::student(1, target: true),
            self::student(2),
            self::student(3),
            self::student(4),
        ];
        $applications = [
            self::application(1, 1, '2026-09-07 09:00:00', [10, 11]),
            self::application(2, 2, '2026-09-07 09:00:00', [10]),
            self::application(3, 3, '2026-09-07 09:00:00', [11]),
            self::application(4, 4, '2026-09-07 09:00:00', [11]),
        ];

        $result = $this->distribute(
            [$m10, $m11, $f100],
            $groups,
            $students,
            $applications,
            [100 => [self::SCHOOL_TECH]],
        );

        // I-04: закрытый m10 (спрос 2 < min 5) — ноль финальных записей.
        self::assertSame(0, self::fillOfModule($result, 10));

        // Целевик переобойдён на приоритет 2 (фаза 1.1), источник квоты.
        $target = self::assignmentById($result, 1);
        self::assertSame(11, $target->moduleId);
        self::assertSame(AssignmentSource::TargetQuota, $target->source);

        // Конкурсные занимают открытый m11 (fill 3 > min 2).
        self::assertSame(AssignmentSource::Competition, self::assignmentById($result, 3)->source);
        self::assertSame(AssignmentSource::Competition, self::assignmentById($result, 4)->source);

        // s2 не прошёл ни на один приоритет (m10 закрыт) → свободный модуль (R-09).
        $free = self::assignmentById($result, 2);
        self::assertSame(100, $free->moduleId);
        self::assertSame(AssignmentSource::Free, $free->source);
    }

    public function testPhaseOneReleasedTargetReWalksBeforeCompetition(): void
    {
        $m10 = self::module(10, ModuleType::Technical, 5, 5);
        $m11 = self::module(11, ModuleType::Technical, 2, 3);
        $f100 = self::module(100, ModuleType::Free, 1, 4);
        $groups = [self::group(self::GROUP_TECH, self::SCHOOL_TECH)];
        $students = [
            self::student(1, target: true),
            self::student(2),
            self::student(3),
        ];
        $applications = [
            self::application(1, 1, '2026-09-07 09:00:00', [10, 11]),
            self::application(2, 2, '2026-09-07 09:00:00', [11]),
            self::application(3, 3, '2026-09-07 09:00:00', [11]),
        ];
        $eligible = [100 => [self::SCHOOL_TECH]];

        $result = $this->distribute([$m10, $m11, $f100], $groups, $students, $applications, $eligible);

        // Фаза 1 закрывает prio1-модуль целевика (спрос 1 < min 5); после фазы 3
        // у всех остальных модулей fill > min, каскад 3.5 НЕ стартует. Целевик
        // размещён на приоритете 2 (фаза 1.1) — НЕ в свободных.
        $target = self::assignmentById($result, 1);
        self::assertSame(11, $target->moduleId);
        self::assertSame(AssignmentSource::TargetQuota, $target->source);
        self::assertNotSame(100, $target->moduleId);

        self::assertSame(AssignmentSource::Competition, self::assignmentById($result, 2)->source);
        self::assertSame(AssignmentSource::Competition, self::assignmentById($result, 3)->source);
        self::assertSame(3, self::fillOfModule($result, 11));
    }

    public function testPhaseOneDemandCountsAllPriorities(): void
    {
        $m10 = self::module(10, ModuleType::Technical, 1, 4);
        $mP = self::module(20, ModuleType::Technical, 5, 5);
        $mQ = self::module(21, ModuleType::Technical, 5, 5);
        $groups = [self::group(self::GROUP_TECH, self::SCHOOL_TECH)];
        $students = [self::student(1), self::student(2)];
        $applications = [
            self::application(1, 1, '2026-09-07 09:00:00', [20, 10]),
            self::application(2, 2, '2026-09-07 09:00:00', [21, 10]),
        ];

        $result = $this->distribute([$m10, $mP, $mQ], $groups, $students, $applications);

        // Спрос m10 = 2 (оба заявки на приоритете 2) ≥ min 1 → модуль НЕ закрыт.
        self::assertSame('open', self::statusOf($result, 10));
        self::assertSame(2, self::fillOfModule($result, 10));

        // Контроль: модули с prio1-спросом 1 < min 5 — закрыты (спрос всё равно мал).
        self::assertSame('closed', self::statusOf($result, 20));
        self::assertSame('closed', self::statusOf($result, 21));

        self::assertSame(AssignmentSource::Competition, self::assignmentById($result, 1)->source);
        self::assertSame(AssignmentSource::Competition, self::assignmentById($result, 2)->source);
    }

    // ------------------------------------------------------------- фаза 3 / R-08

    public function testCompetitionFillsByRankNoEviction(): void
    {
        $m10 = self::module(10, ModuleType::Technical, 2, 3);
        $f100 = self::module(100, ModuleType::Free, 1, 4);
        $groups = [self::group(self::GROUP_TECH, self::SCHOOL_TECH)];
        $students = [self::student(1), self::student(2), self::student(3), self::student(4)];
        $applications = [
            self::application(1, 1, '2026-09-07 09:00:00', [10]),
            self::application(2, 2, '2026-09-07 09:00:00', [10]),
            self::application(3, 3, '2026-09-07 09:00:00', [10]),
            self::application(4, 4, '2026-09-07 09:00:00', [10]),
        ];

        $result = $this->distribute(
            [$m10, $f100],
            $groups,
            $students,
            $applications,
            [100 => [self::SCHOOL_TECH]],
        );

        // Три места m10 (max 3): худший по рангу s4 не вытесняет записанных (R-08/R-20).
        self::assertSame(3, self::fillOfModule($result, 10));
        self::assertSame(10, self::assignmentById($result, 1)->moduleId);
        self::assertSame(10, self::assignmentById($result, 2)->moduleId);
        self::assertSame(10, self::assignmentById($result, 3)->moduleId);

        $tail = self::assignmentById($result, 4);
        self::assertSame(100, $tail->moduleId);
        self::assertSame(AssignmentSource::Free, $tail->source);
    }

    // ------------------------------------------------------------- фаза 3.5 (каскад)

    public function testCascadeClosesUnderfilledModule(): void
    {
        $m10 = self::module(10, ModuleType::Technical, 2, 4);
        $m11 = self::module(11, ModuleType::Technical, 2, 4);
        $f100 = self::module(100, ModuleType::Free, 1, 4);
        $groups = [self::group(self::GROUP_TECH, self::SCHOOL_TECH)];
        $students = [self::student(1), self::student(2), self::student(3), self::student(4)];
        $applications = [
            self::application(1, 1, '2026-09-07 09:00:00', [10]),
            self::application(2, 2, '2026-09-07 09:00:00', [10]),
            self::application(3, 3, '2026-09-07 09:00:00', [11]),
            self::application(4, 4, '2026-09-07 09:00:00', [11]),
        ];

        $result = $this->distribute(
            [$m10, $m11, $f100],
            $groups,
            $students,
            $applications,
            [100 => [self::SCHOOL_TECH]],
        );

        // И m10, и m11 после фазы 3 имеют fill = min 2 → оба закрываются каскадом
        // (сосед падает на следующей итерации), записи аннулируются (void).
        self::assertSame('closed', self::statusOf($result, 10));
        self::assertSame('closed', self::statusOf($result, 11));
        self::assertSame(0, self::fillOfModule($result, 10));
        self::assertSame(0, self::fillOfModule($result, 11));

        foreach ([1, 2, 3, 4] as $studentId) {
            $assignment = self::assignmentById($result, $studentId);
            self::assertSame(100, $assignment->moduleId);
            self::assertSame(AssignmentSource::Free, $assignment->source);
        }
    }

    // ------- ADR-006 п.5: целевик с заявкой, исчерпавший приоритеты → свободные

    public function testReleasedTargetWithExhaustedPrioritiesGoesToFree(): void
    {
        $m10 = self::module(10, ModuleType::Technical, 3, 3);
        $f100 = self::module(100, ModuleType::Free, 1, 3);
        $groups = [self::group(self::GROUP_TECH, self::SCHOOL_TECH)];
        $students = [
            self::student(1, target: true),
            self::student(2),
        ];
        $applications = [
            self::application(1, 1, '2026-09-07 09:00:00', [10]),
            self::application(2, 2, '2026-09-07 09:00:00', [10]),
        ];

        $result = $this->distribute(
            [$m10, $f100],
            $groups,
            $students,
            $applications,
            [100 => [self::SCHOOL_TECH]],
        );

        // m10 закрыт на фазе 1 (спрос 2 < min 3) — единственный приоритет целевика
        // исчерпан → свободные модули по R-09 (исключение ТЗ только для «молчунов»,
        // ADR-006 п.5; фикстура-обязательство ревью B2-04).
        $target = self::assignmentById($result, 1);
        self::assertSame(100, $target->moduleId);
        self::assertSame(AssignmentSource::Free, $target->source);

        $other = self::assignmentById($result, 2);
        self::assertSame(100, $other->moduleId);
        self::assertSame(AssignmentSource::Free, $other->source);
    }

    // ------------------------------------------------------------- ADR-004 (кейс 5)

    public function testTargetWithoutApplicationProfileModule(): void
    {
        $m10 = self::module(10, ModuleType::Technical, 2, 4);
        $m11 = self::module(11, ModuleType::Technical, 2, 3);
        $groups = [self::group(self::GROUP_TECH, self::SCHOOL_TECH)];
        $students = [
            self::student(1, target: true),
            self::student(2),
            self::student(3),
            self::student(4),
        ];
        $applications = [
            self::application(1, 2, '2026-09-07 09:00:00', [10]),
            self::application(2, 3, '2026-09-07 09:00:00', [10]),
            self::application(3, 4, '2026-09-07 09:00:00', [10]),
        ];

        $result = $this->distribute([$m10, $m11], $groups, $students, $applications);

        // АДР-004 (profile_module): первый модуль своего типа с местом — m10 (open,
        // fill 3 < max 4); m11 закрыт (спрос 0 < min 2) — пропущен.
        $target = self::assignmentById($result, 1);
        self::assertSame(10, $target->moduleId);
        self::assertSame(AssignmentSource::TargetQuota, $target->source);
    }

    public function testTargetWithoutApplicationFreeFlag(): void
    {
        $m10 = self::module(10, ModuleType::Technical, 2, 3);
        $f100 = self::module(100, ModuleType::Free, 1, 4);
        $groups = [self::group(self::GROUP_TECH, self::SCHOOL_TECH)];
        $students = [
            self::student(1, target: true),
            self::student(2),
            self::student(3),
            self::student(4),
        ];
        $applications = [
            self::application(1, 2, '2026-09-07 09:00:00', [10]),
            self::application(2, 3, '2026-09-07 09:00:00', [10]),
            self::application(3, 4, '2026-09-07 09:00:00', [10]),
        ];

        $result = $this->distribute(
            [$m10, $f100],
            $groups,
            $students,
            $applications,
            [100 => [self::SCHOOL_TECH]],
            DistributionService::FREE_MODULE,
        );

        // Флаг free_module → целевик без заявки сразу на свободный модуль школы.
        $target = self::assignmentById($result, 1);
        self::assertSame(100, $target->moduleId);
        self::assertSame(AssignmentSource::TargetQuota, $target->source);
    }

    // ------------------------------------------------------------- R-10 (кейс 7)

    public function testSilentNonTargetToFreeModule(): void
    {
        $f100 = self::module(100, ModuleType::Free, 1, 3);
        $groups = [self::group(self::GROUP_TECH, self::SCHOOL_TECH)];
        $students = [self::student(1)];

        $result = $this->distribute([$f100], $groups, $students, [], [100 => [self::SCHOOL_TECH]]);

        $assignment = self::assignmentById($result, 1);
        self::assertSame(100, $assignment->moduleId);
        self::assertSame(AssignmentSource::Free, $assignment->source);
    }

    // ------------------------------------------------------------- инварианты

    public function testAllStudentsAssignedExactlyOnce(): void
    {
        $m10 = self::module(10, ModuleType::Technical, 2, 3);
        $groups = [self::group(self::GROUP_TECH, self::SCHOOL_TECH)];
        $students = [
            self::student(1, target: true),
            self::student(2),
            self::student(3),
        ];
        $applications = [
            self::application(1, 1, '2026-09-07 09:00:00', [10]),
            self::application(2, 2, '2026-09-07 09:00:00', [10]),
            self::application(3, 3, '2026-09-07 09:00:00', [10]),
        ];

        $result = $this->distribute([$m10], $groups, $students, $applications);

        // I-01: каждый студент ровно в одном final; unassigned пуст (R-11).
        self::assertSame(3, count($result->assignments));
        self::assertCount(3, array_unique(array_column($result->assignments, 'studentId')));
        self::assertSame([], $result->unassigned);
    }

    public function testMaxBoundsRespected(): void
    {
        $m10 = self::module(10, ModuleType::Technical, 2, 3);
        $f100 = self::module(100, ModuleType::Free, 1, 4);
        $groups = [self::group(self::GROUP_TECH, self::SCHOOL_TECH)];
        $students = [self::student(1), self::student(2), self::student(3), self::student(4)];
        $applications = [
            self::application(1, 1, '2026-09-07 09:00:00', [10]),
            self::application(2, 2, '2026-09-07 09:00:00', [10]),
            self::application(3, 3, '2026-09-07 09:00:00', [10]),
            self::application(4, 4, '2026-09-07 09:00:00', [10]),
        ];

        $result = $this->distribute(
            [$m10, $f100],
            $groups,
            $students,
            $applications,
            [100 => [self::SCHOOL_TECH]],
        );

        // I-02: заполненность ≤ max для всех модулей.
        foreach ($result->stats as $moduleId => $stats) {
            /** @var array{fill: int, max: int} $stats */
            self::assertLessThanOrEqual($stats['max'], $stats['fill'], 'Модуль ' . $moduleId . ' переполнен');
        }
    }

    public function testMinInvariantsHold(): void
    {
        $m10 = self::module(10, ModuleType::Technical, 5, 5);
        $m11 = self::module(11, ModuleType::Technical, 2, 3);
        $f100 = self::module(100, ModuleType::Free, 1, 4);
        $groups = [self::group(self::GROUP_TECH, self::SCHOOL_TECH)];
        $students = [
            self::student(1, target: true),
            self::student(2),
            self::student(3),
            self::student(4),
        ];
        $applications = [
            self::application(1, 1, '2026-09-07 09:00:00', [10, 11]),
            self::application(2, 2, '2026-09-07 09:00:00', [10]),
            self::application(3, 3, '2026-09-07 09:00:00', [11]),
            self::application(4, 4, '2026-09-07 09:00:00', [11]),
        ];

        $result = $this->distribute(
            [$m10, $m11, $f100],
            $groups,
            $students,
            $applications,
            [100 => [self::SCHOOL_TECH]],
        );

        foreach ($result->stats as $moduleId => $stats) {
            /** @var array{fill: int, status: string, min: int, max: int, sources: array<string, int>} $stats */
            if ($stats['status'] === 'free') {
                // I-06: свободные модули содержат только записи фазы 4 (source ≠ competition).
                self::assertSame(0, $stats['sources']['competition'], 'Модуль ' . $moduleId . ' имеет конкурсные записи');
                continue;
            }
            if ($stats['status'] === 'closed') {
                // I-04: Closed → 0 final.
                self::assertSame(0, $stats['fill'], 'Закрытый модуль ' . $moduleId . ' имеет записи');
                continue;
            }
            // I-03: открытый не-свободный модуль с записями имеет fill > min.
            if ($stats['fill'] > 0) {
                self::assertGreaterThan($stats['min'], $stats['fill'], 'Модуль ' . $moduleId . ' ≤ min');
            }
        }
    }

    public function testCompetitiveFinalsHaveApplicationAndOwnType(): void
    {
        $m10 = self::module(10, ModuleType::Technical, 2, 3);
        $groups = [self::group(self::GROUP_TECH, self::SCHOOL_TECH)];
        $students = [
            self::student(1, target: true),
            self::student(2),
            self::student(3),
        ];
        $applications = [
            self::application(1, 1, '2026-09-07 09:00:00', [10]),
            self::application(2, 2, '2026-09-07 09:00:00', [10]),
            self::application(3, 3, '2026-09-07 09:00:00', [10]),
        ];

        $result = $this->distribute([$m10], $groups, $students, $applications);

        // I-05: конкурсные final только с заявкой и на модуль типа группы (тех → Тех).
        foreach ($result->assignments as $assignment) {
            if ($assignment->source !== AssignmentSource::Competition) {
                continue;
            }
            $hasApplication = $assignment->studentId === 2 || $assignment->studentId === 3;
            self::assertTrue($hasApplication, 'Студент ' . $assignment->studentId . ' без заявки в конкурсе');
            self::assertSame(10, $assignment->moduleId);
        }
    }

    // ------------------------------------------------------------- кейс 6, DoD

    public function testCapacityOverflowThrows(): void
    {
        $m10 = self::module(10, ModuleType::Technical, 2, 3);
        $f100 = self::module(100, ModuleType::Free, 1, 2);
        $groups = [self::group(self::GROUP_TECH, self::SCHOOL_TECH)];
        $students = [self::student(1), self::student(2), self::student(3), self::student(4)];
        $applications = [
            self::application(1, 1, '2026-09-07 09:00:00', [10]),
            self::application(2, 2, '2026-09-07 09:00:00', [10]),
            self::application(3, 3, '2026-09-07 09:00:00', [10]),
            self::application(4, 4, '2026-09-07 09:00:00', [10]),
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/студент 4/');

        // Свободный модуль для школы 10 не указан в module_eligible_schools → s4
        // некуда идти → детерминированное исключение-отчёт (кейс 6).
        $this->distribute([$m10, $f100], $groups, $students, $applications, []);
    }

    public function testDeterminismAndNoMutation(): void
    {
        $m10 = self::module(10, ModuleType::Technical, 2, 3);
        $m11 = self::module(11, ModuleType::Technical, 2, 3);
        $f100 = self::module(100, ModuleType::Free, 1, 4);
        $groups = [self::group(self::GROUP_TECH, self::SCHOOL_TECH)];
        $students = [
            self::student(1, target: true),
            self::student(2),
            self::student(3),
            self::student(4),
            self::student(5),
        ];
        $applications = [
            self::application(1, 1, '2026-09-07 09:00:00', [10, 11]),
            self::application(2, 2, '2026-09-07 09:00:00', [10]),
            self::application(3, 3, '2026-09-07 09:00:00', [10]),
            self::application(4, 4, '2026-09-07 09:00:00', [10]),
        ];
        $eligible = [100 => [self::SCHOOL_TECH]];

        // Снапшоты входов до запусков.
        $studentIdsBefore = array_map(static fn (Student $s): int => $s->id, $students);
        $moduleIdsBefore = array_map(static fn (Module $m): int => $m->id, [$m10, $m11, $f100]);
        $applicationIdsBefore = array_map(static fn (Application $a): int => $a->id, $applications);

        $first = $this->distribute([$m10, $m11, $f100], $groups, $students, $applications, $eligible);
        $second = $this->distribute([$m10, $m11, $f100], $groups, $students, $applications, $eligible);

        // Детерминизм: идентичные прогоны дают идентичные финалы.
        self::assertSame($this->fingerprint($first), $this->fingerprint($second));

        // Не-мутация входов: списки и порядок сохранены.
        self::assertSame($studentIdsBefore, array_map(static fn (Student $s): int => $s->id, $students));
        self::assertSame($moduleIdsBefore, array_map(static fn (Module $m): int => $m->id, [$m10, $m11, $f100]));
        self::assertSame($applicationIdsBefore, array_map(static fn (Application $a): int => $a->id, $applications));
    }

    /**
     * Детерминированный отпечаток результата: студент → {модуль, источник, ранг}.
     *
     * @return list<string>
     */
    private function fingerprint(DistributionResult $result): array
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
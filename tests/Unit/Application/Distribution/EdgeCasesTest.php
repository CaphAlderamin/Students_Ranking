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
 * Юнит-тесты краевых сценариев фаз v2 (B3-04, план v2 §2.1): полный набор 7
 * сценариев «Краевые случаи» ред. 2, критерии приёмки №1/№3/№4 — углублённо
 * (комбинированные многомодульные фикстуры, каскад ≥ 2 итераций).
 *
 * Тесты ДОПОЛНЯЮТ 16 кейсов B3-03 (DistributionServiceTest): более насыщенные
 * фикстуры (3+ модуля, целевики и конкурсные вместе, независимые приоритеты
 * 1–3), не дублируются.
 *
 * Фикстуры: техническая группа id 1 (школа 10), гуманитарная id 2 (школа 20);
 * свободные модули — только через module_eligible_schools. Стратегия-фейк —
 * полный детерминированный порядок по student.id ASC.
 */
final class EdgeCasesTest extends TestCase
{
    private const int GROUP_TECH = 1;
    private const int SCHOOL_TECH = 10;
    private const int GROUP_HUM = 2;
    private const int SCHOOL_HUM = 20;

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
    ): DistributionResult {
        $service = new DistributionService(
            strategy: $this->strategy(),
            modules: $modules,
            groups: $groups,
            students: $students,
            applications: $applications,
            moduleEligibleSchools: $eligibleSchools,
            targetQuotaNoApplicationStrategy: DistributionService::PROFILE_MODULE,
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

    /** @param list<Assignment> $assignments */
    private static function assignmentById(array $assignments, int $studentId): Assignment
    {
        foreach ($assignments as $assignment) {
            if ($assignment->studentId === $studentId) {
                return $assignment;
            }
        }

        self::fail('Студент ' . $studentId . ' не найден в назначениях');
    }

    private static function fillOfModule(DistributionResult $result, int $moduleId): int
    {
        $stats = $result->stats[(string) $moduleId];
        self::assertIsArray($stats, 'В статистике нет модуля ' . $moduleId);

        /** @var array{fill: int} $stats */
        return $stats['fill'];
    }

    private static function statusOf(DistributionResult $result, int $moduleId): string
    {
        $stats = $result->stats[(string) $moduleId];
        self::assertIsArray($stats, 'В статистике нет модуля ' . $moduleId);

        /** @var array{status: string} $stats */
        return $stats['status'];
    }

    // --------------------------------------------------------- №1 (углублённо):
    // целевики фаз 0 на модуле, закрытом в фазе 1 → аннулирование + реобход 1.1

    public function testCase1TargetsOnPhaseOneClosedModule(): void
    {
        // m10 закрывается в фазе 1 (спрос 1 < min 5); целевик приоритетом 2
        // переобойдён на профильный m11 сразу после закрытия (фаза 1.1), до
        // конкурсного обхода. Комбинированная фикстура: целевик + конкурсные,
        // приоритеты 1–3 в заявках.
        $m10 = self::module(10, ModuleType::Technical, 5, 5);
        $m11 = self::module(11, ModuleType::Technical, 1, 4);
        $m12 = self::module(12, ModuleType::Technical, 1, 3);
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
            self::application(2, 2, '2026-09-07 09:00:00', [12, 11]),
            self::application(3, 3, '2026-09-07 09:00:00', [11, 12, 10]),
            self::application(4, 4, '2026-09-07 09:00:00', [12, 11, 10]),
        ];

        $result = $this->distribute(
            [$m10, $m11, $m12, $f100],
            $groups,
            $students,
            $applications,
            [100 => [self::SCHOOL_TECH]],
        );

        // В закрытом в фазе 1 модуле никто не остаётся (I-04).
        self::assertSame(0, self::fillOfModule($result, 10));
        self::assertSame('closed', self::statusOf($result, 10));

        // Целевик: переобход 1.1 → профильный приоритет 2 (m11), источник квоты.
        $target = self::assignmentById($result->assignments, 1);
        self::assertSame(11, $target->moduleId);
        self::assertSame(AssignmentSource::TargetQuota, $target->source);
        self::assertNull($target->rank);

        // Конкурсные: m11 (с целевиком, fill 2 > min 1) и m12 (fill 2 > min 1)
        // выживают после каскада 3.5 — никого в свободных не требуется.
        $s2 = self::assignmentById($result->assignments, 2);
        $s3 = self::assignmentById($result->assignments, 3);
        $s4 = self::assignmentById($result->assignments, 4);
        self::assertSame(AssignmentSource::Competition, $s2->source);
        self::assertSame(12, $s2->moduleId);
        self::assertSame(AssignmentSource::Competition, $s3->source);
        self::assertSame(11, $s3->moduleId);
        self::assertSame(AssignmentSource::Competition, $s4->source);
        self::assertSame(12, $s4->moduleId);

        self::assertSame(2, self::fillOfModule($result, 11));
        self::assertSame(2, self::fillOfModule($result, 12));
        self::assertSame(0, self::fillOfModule($result, 100));
    }

    // --------------------------------------------------------- №2: переполнение
    // приоритета — худший не вытесняет записанных, идёт на следующий приоритет

    public function testCase2OverflowNoEviction(): void
    {
        // m10 (prio1) перегружен: спрос 5 > max 2. Лучшие двое остаются (без
        // вытеснения), следующие уходят на приоритет 2 (m11); исчерпавшие всё → свободные.
        $m10 = self::module(10, ModuleType::Technical, 1, 2);
        $m11 = self::module(11, ModuleType::Technical, 1, 2);
        $f100 = self::module(100, ModuleType::Free, 1, 3);
        $groups = [self::group(self::GROUP_TECH, self::SCHOOL_TECH)];
        $students = [self::student(1), self::student(2), self::student(3), self::student(4), self::student(5)];
        $applications = [
            self::application(1, 1, '2026-09-07 09:00:00', [10]),
            self::application(2, 2, '2026-09-07 09:00:00', [10]),
            self::application(3, 3, '2026-09-07 09:00:00', [10, 11]),
            self::application(4, 4, '2026-09-07 09:00:00', [10, 11]),
            self::application(5, 5, '2026-09-07 09:00:00', [10, 11]),
        ];

        $result = $this->distribute(
            [$m10, $m11, $f100],
            $groups,
            $students,
            $applications,
            [100 => [self::SCHOOL_TECH]],
        );

        // Рейтинг 1–2 не вытесняются даже при спросе 5 (R-06/R-08).
        self::assertSame(2, self::fillOfModule($result, 10));
        self::assertSame(10, self::assignmentById($result->assignments, 1)->moduleId);
        self::assertSame(10, self::assignmentById($result->assignments, 2)->moduleId);

        // Переполненные приоритетом 1 перемещаются на приоритет 2 (m11).
        self::assertSame(11, self::assignmentById($result->assignments, 3)->moduleId);
        self::assertSame(11, self::assignmentById($result->assignments, 4)->moduleId);
        self::assertSame(2, self::fillOfModule($result, 11));

        // Все приоритеты переполнены → свободные (R-09); источник Free — без ранга.
        $tail = self::assignmentById($result->assignments, 5);
        self::assertSame(100, $tail->moduleId);
        self::assertSame(AssignmentSource::Free, $tail->source);
        self::assertNull($tail->rank);
    }

    // -------------------------------------------- №3 (углублённо): каскад 3.5,
    // цепочка ≥ 2 итераций, аннулирование (void) записей на каждом шаге

    public function testCase3CascadeMultiIterationWithVoid(): void
    {
        // После фазы 3 А и Б на заполненности = min (2): каскад закрывает А
        // (итерация 1), void-записи переобходятся на соседа В; при следующем
        // сканировании падает Б (итерация 2) и его void-записи тоже. В финале
        // сосед В заполнен до max, а вытесненные уходят в свободные (хвост).
        $m10 = self::module(10, ModuleType::Technical, 2, 3);
        $m11 = self::module(11, ModuleType::Technical, 2, 3);
        $m12 = self::module(12, ModuleType::Technical, 2, 4);
        $f100 = self::module(100, ModuleType::Free, 1, 4);
        $groups = [self::group(self::GROUP_TECH, self::SCHOOL_TECH)];
        $students = [
            self::student(1),
            self::student(2),
            self::student(3),
            self::student(4),
            self::student(5),
            self::student(6),
        ];
        $applications = [
            self::application(1, 1, '2026-09-07 09:00:00', [10, 12]),
            self::application(2, 2, '2026-09-07 09:00:00', [10, 12]),
            self::application(3, 3, '2026-09-07 09:00:00', [11, 12]),
            self::application(4, 4, '2026-09-07 09:00:00', [11, 12]),
            self::application(5, 5, '2026-09-07 09:00:00', [12]),
            self::application(6, 6, '2026-09-07 09:00:00', [12]),
        ];

        $result = $this->distribute(
            [$m10, $m11, $m12, $f100],
            $groups,
            $students,
            $applications,
            [100 => [self::SCHOOL_TECH]],
        );

        // Цепочка из двух итераций: А (10) и Б (11) закрыты навсегда (I-04).
        self::assertSame('closed', self::statusOf($result, 10));
        self::assertSame('closed', self::statusOf($result, 11));
        self::assertSame(0, self::fillOfModule($result, 10));
        self::assertSame(0, self::fillOfModule($result, 11));

        // Void-записи А переобошлись на приоритет 2 (В = 12); В заполнен до max 4.
        self::assertSame(12, self::assignmentById($result->assignments, 1)->moduleId);
        self::assertSame(12, self::assignmentById($result->assignments, 2)->moduleId);
        self::assertSame(12, self::assignmentById($result->assignments, 5)->moduleId);
        self::assertSame(12, self::assignmentById($result->assignments, 6)->moduleId);
        self::assertSame(4, self::fillOfModule($result, 12));
        self::assertSame('open', self::statusOf($result, 12));

        // Void-записи Б: все приоритеты переполнены → свободные (R-09), source Free.
        $s3 = self::assignmentById($result->assignments, 3);
        $s4 = self::assignmentById($result->assignments, 4);
        self::assertSame(100, $s3->moduleId);
        self::assertSame(AssignmentSource::Free, $s3->source);
        self::assertSame(100, $s4->moduleId);
        self::assertSame(AssignmentSource::Free, $s4->source);
    }

    // --------------------------------------------------------- №4: целевик,
    // освобождённый закрытием, с исчерпанными приоритетами 1–3 → свободные

    public function testCase4ReleasedTargetExhaustedPrioritiesToFree(): void
    {
        // t1 (prio1 = m10, закрыт в фазе 1; prio2 = m11, prio3 = m12 — заняты
        // другими целевиками до max 1). Каскад 3.5 закрывает m11 и m12, у t1 все
        // приоритеты исчерпаны → свободный модуль школы, source Free (R-09,
        // ADR-006 п.5). Конкурсные при этом выживают на m13.
        $m10 = self::module(10, ModuleType::Technical, 4, 4);
        $m11 = self::module(11, ModuleType::Technical, 1, 1);
        $m12 = self::module(12, ModuleType::Technical, 1, 1);
        $m13 = self::module(13, ModuleType::Technical, 1, 3);
        $f100 = self::module(100, ModuleType::Free, 1, 4);
        $groups = [self::group(self::GROUP_TECH, self::SCHOOL_TECH)];
        $students = [
            self::student(1, target: true),
            self::student(2, target: true),
            self::student(3, target: true),
            self::student(4),
            self::student(5),
        ];
        $applications = [
            self::application(1, 1, '2026-09-07 09:00:00', [10, 11, 12]),
            self::application(2, 2, '2026-09-07 09:00:00', [11]),
            self::application(3, 3, '2026-09-07 09:00:00', [12]),
            self::application(4, 4, '2026-09-07 09:00:00', [13]),
            self::application(5, 5, '2026-09-07 09:00:00', [13]),
        ];

        $result = $this->distribute(
            [$m10, $m11, $m12, $m13, $f100],
            $groups,
            $students,
            $applications,
            [100 => [self::SCHOOL_TECH]],
        );

        // Все три приоритета t1 исчерпаны → свободные модули школы (R-09).
        foreach ([1, 2, 3] as $targetId) {
            $assignment = self::assignmentById($result->assignments, $targetId);
            self::assertSame(100, $assignment->moduleId);
            self::assertSame(AssignmentSource::Free, $assignment->source);
            self::assertNull($assignment->rank);
        }

        // Закрытые модули пусты (I-04); конкурсные выжили на m13 (fill 2 > min 1).
        foreach ([10, 11, 12] as $moduleId) {
            self::assertSame('closed', self::statusOf($result, $moduleId));
            self::assertSame(0, self::fillOfModule($result, $moduleId));
        }
        self::assertSame(2, self::fillOfModule($result, 13));
        self::assertSame(13, self::assignmentById($result->assignments, 4)->moduleId);
        self::assertSame(13, self::assignmentById($result->assignments, 5)->moduleId);
    }

    // --------------------------------------------------------- №5: целевик-
    // «молчун»: profile_module, но профильных мест нет → свободный модуль школы

    public function testCase5TargetSilentProfileModuleFallback(): void
    {
        // Единственные профильные модули m10/m11 закрыты каскадом (fill = min 1
        // после фазы 3). Целевик-«молчун» не находит профильного места →
        // свободный модуль школы, но источник остаётся TargetQuota (ADR-004).
        $m10 = self::module(10, ModuleType::Technical, 1, 1);
        $m11 = self::module(11, ModuleType::Technical, 1, 1);
        $f100 = self::module(100, ModuleType::Free, 1, 3);
        $groups = [self::group(self::GROUP_TECH, self::SCHOOL_TECH)];
        $students = [
            self::student(1, target: true),
            self::student(2),
            self::student(3),
        ];
        $applications = [
            self::application(1, 2, '2026-09-07 09:00:00', [10]),
            self::application(2, 3, '2026-09-07 09:00:00', [11]),
        ];

        $result = $this->distribute(
            [$m10, $m11, $f100],
            $groups,
            $students,
            $applications,
            [100 => [self::SCHOOL_TECH]],
        );

        $target = self::assignmentById($result->assignments, 1);
        self::assertSame(100, $target->moduleId);
        self::assertSame(AssignmentSource::TargetQuota, $target->source);
        self::assertNull($target->rank);

        // Конкурсные из void-записей закрытых модулей — в свободные (source Free).
        self::assertSame(100, self::assignmentById($result->assignments, 2)->moduleId);
        self::assertSame(100, self::assignmentById($result->assignments, 3)->moduleId);
        self::assertSame(AssignmentSource::Free, self::assignmentById($result->assignments, 2)->source);
        self::assertSame(3, self::fillOfModule($result, 100));
    }

    // --------------------------------------------------------- №6: нехватка
    // ёмкости свободных модулей → детерминированное исключение-отчёт

    public function testCase6CapacityOverflowThrows(): void
    {
        // Свободный f100 (школы тех) вмещает одного — второй «молчун» хвоста
        // упирается в заполненный f100; f101 доступен только школе 999 (её нет
        // в группах). Места не хватает → RuntimeException c идентификатором студента.
        $m10 = self::module(10, ModuleType::Technical, 1, 2);
        $f100 = self::module(100, ModuleType::Free, 1, 1);
        $f101 = self::module(101, ModuleType::Free, 1, 2);
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

        // Валидный сидер это исключение исключает (Σmax_free 135 ≥ 52); здесь —
        // конструкция наоборот: ёмкость свободных (для школы 10) ровно 1 < 2 хвоста.
        $this->distribute(
            [$m10, $f100, $f101],
            $groups,
            $students,
            $applications,
            [100 => [self::SCHOOL_TECH], 101 => [999]],
        );
    }

    // -------------------------------------------- №7: «молчуны»-нецелевики →
    // свободные модули школы (R-19), порядок по id, детерминизм, multi-школы

    public function testCase7SilentNonTargetToFreeDeterministic(): void
    {
        // Молчуны двух школ: тех (10) и гум (20). Свободные модули разные по
        // карте module_eligible_schools; «молчун» попадает только в свой.
        // Конкурсный студент закрытого m10 — в свободные по R-09 (source Free).
        $m10 = self::module(10, ModuleType::Technical, 1, 3);
        $f100 = self::module(100, ModuleType::Free, 1, 4);
        $f101 = self::module(101, ModuleType::Free, 1, 4);
        $groups = [
            self::group(self::GROUP_TECH, self::SCHOOL_TECH),
            self::group(self::GROUP_HUM, self::SCHOOL_HUM, technical: false),
        ];
        $students = [
            self::student(1, self::GROUP_TECH),
            self::student(2, self::GROUP_HUM),
            self::student(3, self::GROUP_TECH),
            self::student(4, self::GROUP_TECH),
            self::student(5, self::GROUP_TECH),
        ];
        $applications = [
            self::application(1, 5, '2026-09-07 09:00:00', [10]),
        ];
        $eligible = [
            100 => [self::SCHOOL_TECH],
            101 => [self::SCHOOL_HUM],
        ];

        $first = $this->distribute([$m10, $f100, $f101], $groups, $students, $applications, $eligible);
        $second = $this->distribute([$m10, $f100, $f101], $groups, $students, $applications, $eligible);

        // Все молчуны → свободные модули своей школы (R-10/R-19), порядок по id.
        self::assertSame(100, self::assignmentById($first->assignments, 1)->moduleId);
        self::assertSame(101, self::assignmentById($first->assignments, 2)->moduleId);
        self::assertSame(100, self::assignmentById($first->assignments, 3)->moduleId);
        self::assertSame(100, self::assignmentById($first->assignments, 4)->moduleId);
        foreach ([1, 2, 3, 4] as $silentId) {
            self::assertSame(AssignmentSource::Free, self::assignmentById($first->assignments, $silentId)->source);
        }

        // Конкурсный хвост закрытого m10 — тоже в свободные (R-09).
        self::assertSame(100, self::assignmentById($first->assignments, 5)->moduleId);
        self::assertSame(AssignmentSource::Free, self::assignmentById($first->assignments, 5)->source);

        // Детерминизм: идентичные прогоны дают идентичные финалы.
        self::assertSame($this->fingerprint($first), $this->fingerprint($second));
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
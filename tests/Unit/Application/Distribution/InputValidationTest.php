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
 * Валидация входов ядра (B3-04, критерий покрытия линии ядра ≥ 90%): заведомо
 * невалидные входные данные должны отклоняться детерминированными исключениями
 * в конструкторах DistributionService/DistributionContext — задокументированные
 * контрактные границы, а не «тихие» повреждённые результаты.
 *
 * Проверяемые контракты:
 *  - флаг ADR-004 (profile_module | free_module) — иначе InvalidArgumentException;
 *  - группа студента обязана существовать в $groups (R-01);
 *  - заявка обязана ссылаться на существующего студента;
 *  - у студента не более одной заявки (uk_applications_student);
 *  - дублирующийся модуль в приоритетах заявки не задваивает спрос фазы 1 (R-07).
 */
final class InputValidationTest extends TestCase
{
    private const int GROUP_TECH = 1;
    private const int SCHOOL_TECH = 10;

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

    public function testRejectsUnknownTargetQuotaStrategyFlag(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('targetQuotaNoApplicationStrategy должен быть');

        new DistributionService(
            strategy: $this->strategy(),
            modules: [self::module(10, ModuleType::Technical, 1, 2)],
            groups: [self::group(self::GROUP_TECH, self::SCHOOL_TECH)],
            students: [self::student(1)],
            applications: [],
            moduleEligibleSchools: [],
            targetQuotaNoApplicationStrategy: 'unknown_strategy',
        );
    }

    public function testRejectsStudentGroupOutOfGroups(): void
    {
        $service = new DistributionService(
            strategy: $this->strategy(),
            modules: [self::module(10, ModuleType::Technical, 1, 2)],
            groups: [self::group(self::GROUP_TECH, self::SCHOOL_TECH)],
            students: [self::student(1, 999)],
            applications: [],
            moduleEligibleSchools: [],
            targetQuotaNoApplicationStrategy: DistributionService::PROFILE_MODULE,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('группа 999 студента 1 отсутствует');
        $service->distribute();
    }

    public function testRejectsApplicationForUnknownStudent(): void
    {
        $service = new DistributionService(
            strategy: $this->strategy(),
            modules: [self::module(10, ModuleType::Technical, 1, 2)],
            groups: [self::group(self::GROUP_TECH, self::SCHOOL_TECH)],
            students: [self::student(1)],
            applications: [self::application(1, 2, '2026-09-07 09:00:00', [10])],
            moduleEligibleSchools: [],
            targetQuotaNoApplicationStrategy: DistributionService::PROFILE_MODULE,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('заявка 1 ссылается на отсутствующего студента 2');
        $service->distribute();
    }

    public function testRejectsDuplicateApplicationPerStudent(): void
    {
        $service = new DistributionService(
            strategy: $this->strategy(),
            modules: [self::module(10, ModuleType::Technical, 1, 2)],
            groups: [self::group(self::GROUP_TECH, self::SCHOOL_TECH)],
            students: [self::student(1)],
            applications: [
                self::application(1, 1, '2026-09-07 09:00:00', [10]),
                self::application(2, 1, '2026-09-08 09:00:00', [10]),
            ],
            moduleEligibleSchools: [],
            targetQuotaNoApplicationStrategy: DistributionService::PROFILE_MODULE,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('более одной заявки (uk_applications_student)');
        $service->distribute();
    }

    public function testDuplicatePriorityModuleDoesNotDoubleDemand(): void
    {
        // Дублирующийся модуль в приоритетах заявки (R-07): реальный спрос фазы 1
        // = 1 (студент 1 с заявкой [10,10,10]) < min 2 → модуль 10 закрывается.
        // Будь спрос задвоен (3) — модуль 10 остался бы открыт, и студент встал бы
        // конкурсом на приоритет 1. Наблюдаем закрытие + переобход на свободный m20.
        $m10 = self::module(10, ModuleType::Technical, 2, 3, 1);
        $m20 = self::module(20, ModuleType::Free, 1, 2, self::SCHOOL_TECH);
        $service = new DistributionService(
            strategy: $this->strategy(),
            modules: [$m10, $m20],
            groups: [self::group(self::GROUP_TECH, self::SCHOOL_TECH)],
            students: [self::student(1)],
            applications: [
                self::application(1, 1, '2026-09-07 09:00:00', [10, 10, 10]),
            ],
            moduleEligibleSchools: [20 => [self::SCHOOL_TECH]],
            targetQuotaNoApplicationStrategy: DistributionService::PROFILE_MODULE,
        );

        $result = $service->distribute();
        self::assertCount(1, $result->assignments);
        self::assertSame(AssignmentSource::Free, $result->assignments[0]->source);
        self::assertSame('closed', self::statusOf($result, 10));
        self::assertSame(1, self::fillOfModule($result, 20));
    }

    private static function fillOfModule(DistributionResult $result, int $moduleId): int
    {
        $stats = $result->stats[(string) $moduleId];
        self::assertIsArray($stats);

        /** @var array{fill: int} $stats */
        return $stats['fill'];
    }

    private static function statusOf(DistributionResult $result, int $moduleId): string
    {
        $stats = $result->stats[(string) $moduleId];
        self::assertIsArray($stats);

        /** @var array{status: string} $stats */
        return $stats['status'];
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

    /** @param list<int> $moduleIds модули по приоритетам 1→N */
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
}
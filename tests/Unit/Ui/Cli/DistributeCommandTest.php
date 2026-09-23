<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ui\Cli;

use App\Application\Ranking\CriteriaRating;
use App\Domain\Enum\ModuleType;
use App\Domain\Model\Assignment;
use App\Domain\Model\Discipline;
use App\Domain\Model\Module;
use App\Domain\Model\Student;
use App\Domain\Model\StudentGroup;
use App\Domain\ValueObject\AcademicYear;
use App\Domain\ValueObject\RatingWeights;
use App\Domain\ValueObject\Semester;
use App\Infrastructure\Db\ApplicationRepositoryInterface;
use App\Infrastructure\Db\ModuleRepositoryInterface;
use App\Infrastructure\Db\StudentRepositoryInterface;
use App\Ui\Cli\DistributeCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Юнит-тесты CLI-команды distribute (B3-05, план v3 §4): CommandTester + DI
 * (моки репозиториев, фейковое значение флага ADR-004) БЕЗ БД и реальных
 * конфигов. Покрывают: невалидный --algorithm → exit≠0; невалидный флаг
 * ADR-004 → exit≠0; сводка содержат «алгоритм», «распределено 400/400» и
 * модульную таблицу на детерминированном in-memory датасете (400 студентов,
 * 7 групп, 3 свободных модуля с покрытием всех школ, заявок нет — все
 * «молчуны» → свободные, R-10).
 *
 * Fake-\PDO (без соединения): readGroups()/readEligibleSchools() из тестовых
 * строк, DELETE перехватывается (сырой PDO вне контрактов, план §2.2/§2.7).
 */
final class DistributeCommandTest extends TestCase
{
    private const int SCHOOL_TECH_1 = 10;
    private const int SCHOOL_TECH_2 = 11;
    private const int SCHOOL_TECH_3 = 12;
    private const int SCHOOL_TECH_4 = 13;
    private const int SCHOOL_TECH_5 = 14;
    private const int SCHOOL_TECH_6 = 15;
    private const int SCHOOL_HUM = 20;

    private const int ADMISSION_YEAR = 2025;

    private CriteriaRating $rating;

    protected function setUp(): void
    {
        $this->rating = new CriteriaRating(new RatingWeights(
            weights: ['entrance_exams' => 0.40, 'gpa_sem12' => 0.25, 'gpa_basic' => 0.20, 'entrance_test' => 0.15],
            bonuses: ['disability' => 10.0],
            tiers: ['paid_over_budget' => true],
            normalization: 'min_max_0_100',
        ));
    }

    public function testRejectsUnknownAlgorithm(): void
    {
        $command = new DistributeCommand(
            students: $this->emptyStudents(),
            modules: $this->emptyModules(),
            applications: $this->emptyApplications(),
            pdo: $this->fakePdo([]),
            criteriaRating: $this->rating,
            targetQuotaNoApplicationStrategy: DistributeCommand::PROFILE_MODULE_FLAG,
        );
        $application = $this->applicationWith($command);

        $tester = new CommandTester($command);
        $tester->execute(['--algorithm' => 'bogus'], ['capture_stderr_separately' => true]);

        self::assertNotSame(0, $tester->getStatusCode(), 'невалидный алгоритм — exit≠0 (R-17)');
        self::assertStringContainsString('Неизвестный алгоритм', $tester->getErrorOutput());
        self::assertNotNull($command->getApplication(), 'команда зарегистрирована в приложении');
    }

    public function testInvalidTargetQuotaFlagRejected(): void
    {
        $command = new DistributeCommand(
            students: $this->emptyStudents(),
            modules: $this->emptyModules(),
            applications: $this->emptyApplications(),
            pdo: $this->fakePdo([]),
            criteriaRating: $this->rating,
            targetQuotaNoApplicationStrategy: 'bogus',
        );
        $this->applicationWith($command);

        $tester = new CommandTester($command);
        $tester->execute(['--algorithm' => 'date'], ['capture_stderr_separately' => true]);

        self::assertNotSame(0, $tester->getStatusCode(), 'невалидный флаг ADR-004 — exit≠0');
        self::assertStringContainsString('targetQuotaNoApplicationStrategy', $tester->getErrorOutput());
    }

    public function testOutputContainsSummary(): void
    {
        $groups = $this->schoolGroups();
        $students = $this->students($groups);
        $modules = $this->freeModulesWithCapacity(600);
        $pdo = $this->fakePdo($groups, $modules);

        /** @var list<Assignment> $saved */
        $saved = [];
        $modulesRepo = $this->createMock(ModuleRepositoryInterface::class);
        $modulesRepo->expects(self::once())
            ->method('findAll')
            ->willReturn($modules);
        $modulesRepo->method('saveAssignments')
            ->willReturnCallback(
                static function (...$assignments) use (&$saved): void {
                    foreach ($assignments as $assignment) {
                        $saved[] = $assignment;
                    }
                },
            );

        $studentsRepo = $this->createMock(StudentRepositoryInterface::class);
        $studentsRepo->expects(self::once())
            ->method('findAllByAdmissionYear')
            ->with(self::ADMISSION_YEAR)
            ->willReturn($students);

        $applicationsRepo = $this->createMock(ApplicationRepositoryInterface::class);
        $applicationsRepo->expects(self::once())
            ->method('findAll')
            ->willReturn([]);

        $command = new DistributeCommand(
            students: $studentsRepo,
            modules: $modulesRepo,
            applications: $applicationsRepo,
            pdo: $pdo,
            criteriaRating: $this->rating,
            targetQuotaNoApplicationStrategy: DistributeCommand::PROFILE_MODULE_FLAG,
        );
        $this->applicationWith($command);

        $tester = new CommandTester($command);
        $tester->execute(['--algorithm' => 'criteria']);

        self::assertSame(0, $tester->getStatusCode(), 'успешный прогон — exit 0');
        $display = $tester->getDisplay();
        self::assertStringContainsString('Алгоритм: criteria', $display, 'сводка содержит «алгоритм»');
        self::assertStringContainsString('Распределено студентов: 400/400 (R-11)', $display, 'сводка содержит «распределено 400/400»');
        self::assertStringContainsString('Модуль', $display, 'сводка содержит заголовок таблицы');
        self::assertStringContainsString('free', $display, 'сводка содержит свободные модули');
        self::assertCount(400, $saved, 'в БД записано ровно 400 финалов (R-11)');
        self::assertSame(["DELETE FROM assignments"], $pdo->execCalls, 'повторный прогон заменяет прежние записи (план §2.7)');
        self::assertInstanceOf(Assignment::class, $saved[0] ?? null, 'первый финал — объект Assignment');
        self::assertContains(
            $saved[0]->moduleId,
            array_map(static fn (Module $m): int => $m->id, $modules),
            'финалы лежат на свободных модулях (R-10)',
        );
    }

    // ------------------------------------------------------------- фикстуры

    /** @return list<StudentGroup> */
    private function schoolGroups(): array
    {
        $counts = [
            self::SCHOOL_TECH_1 => 60,
            self::SCHOOL_TECH_2 => 60,
            self::SCHOOL_TECH_3 => 60,
            self::SCHOOL_TECH_4 => 60,
            self::SCHOOL_TECH_5 => 60,
            self::SCHOOL_TECH_6 => 60,
            self::SCHOOL_HUM => 40,
        ];
        $groups = [];
        $id = 1;
        foreach ($counts as $schoolId => $count) {
            $groups[] = new StudentGroup($id, $schoolId, 'Группа ' . $id, $schoolId !== self::SCHOOL_HUM, self::ADMISSION_YEAR);
            ++$id;
        }

        return $groups;
    }

    /**
     * 400 студентов: по группам (60×6 тех + 40 гум), нецелевики без заявок
     * («молчуны» — R-10 → свободные модули). ID = порядку вставки.
     *
     * @param list<StudentGroup> $groups
     *
     * @return list<Student>
     */
    private function students(array $groups): array
    {
        $counts = [
            0 => 60,
            1 => 60,
            2 => 60,
            3 => 60,
            4 => 60,
            5 => 60,
            6 => 40,
        ];
        $students = [];
        $id = 1;
        foreach ($counts as $groupIndex => $count) {
            for ($i = 0; $i < $count; ++$i) {
                $students[] = new Student(
                    id: $id,
                    groupId: $groups[$groupIndex]->id,
                    fullName: 'Студент ' . $id,
                    isTargetQuota: false,
                    isPaid: false,
                    isDisabled: false,
                    entranceExamsSum: 150,
                    gpaSem12: 3.5,
                    gpaBasic: 3.5,
                    entranceTestScore: 50,
                );
                ++$id;
            }
        }

        return $students;
    }

    /**
     * Три свободных модуля (R-19) с суммарной вместимостью ≥ 400 и покрытием
     * всех школ (инвариант (б) каталога): объединение eligible = все школы.
     *
     * @return list<Module>
     */
    private function freeModulesWithCapacity(int $totalCapacity): array
    {
        $modules = [];
        foreach ([0, 1, 2] as $index) {
            $id = 10 + $index;
            $modules[] = new Module(
                id: $id,
                schoolId: $id,
                title: 'Свободный модуль ' . $id,
                moduleType: ModuleType::Free,
                minStudents: 1,
                maxStudents: (int) ($totalCapacity / 3),
                academicYear: new AcademicYear('2026-2027'),
                disciplines: $this->disciplines($id),
            );
        }

        return $modules;
    }

    /** @return list<Discipline> 3 дисциплины на семестры 3/4/5 (R-02/ADR-005) */
    private function disciplines(int $id): array
    {
        $disciplines = [];
        foreach ([3, 4, 5] as $index => $semester) {
            $disciplines[] = new Discipline(
                id: $id * 1000 + $index + 1,
                title: 'Дисциплина модуля ' . $id . ' семестр ' . $semester,
                semester: new Semester($semester),
            );
        }

        return $disciplines;
    }

    /**
     * Фейковое PDO-соединение: prepare возвращает тестовые строки для
     * readGroups()/readEligibleSchools(), exec перехватывает DELETE.
     *
     * @param list<StudentGroup> $groups  группы для readGroups()
     * @param list<Module>       $modules модули для readEligibleSchools()
     *                                    (свободные — все школы обучения)
     */
    private function fakePdo(array $groups, array $modules = []): FakePdo
    {
        $fake = new FakePdo();

        $rowData = [];
        foreach ($groups as $group) {
            $rowData[] = [
                'id' => $group->id,
                'school_id' => $group->schoolId,
                'name' => $group->name,
                'is_technical' => $group->isTechnical ? 1 : 0,
                'admission_year' => self::ADMISSION_YEAR,
            ];
        }
        $fake->groupRows = $rowData;

        $eligibleRows = [];
        $schoolIds = array_map(static fn (StudentGroup $g): int => $g->schoolId, $groups);
        foreach ($modules as $module) {
            foreach ($schoolIds as $schoolId) {
                $eligibleRows[] = ['module_id' => $module->id, 'school_id' => $schoolId];
            }
        }
        $fake->eligibleRows = $eligibleRows;

        return $fake;
    }

    private function emptyStudents(): StudentRepositoryInterface
    {
        $repo = $this->createMock(StudentRepositoryInterface::class);
        $repo->method('findAllByAdmissionYear')->willReturn([]);

        return $repo;
    }

    private function emptyModules(): ModuleRepositoryInterface
    {
        $repo = $this->createMock(ModuleRepositoryInterface::class);
        $repo->method('findAll')->willReturn([]);
        $repo->method('saveAssignments');

        return $repo;
    }

    private function emptyApplications(): ApplicationRepositoryInterface
    {
        $repo = $this->createMock(ApplicationRepositoryInterface::class);
        $repo->method('findAll')->willReturn([]);

        return $repo;
    }

    private function applicationWith(DistributeCommand $command): Application
    {
        $application = new Application('Students Ranking', '1.0.0');
        $application->add($command);
        $application->setDefaultCommand('distribute', false);

        return $application;
    }
}
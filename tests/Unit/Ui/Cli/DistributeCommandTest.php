<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ui\Cli;

use App\Application\Ranking\CriteriaRating;
use App\Domain\Enum\ExportFormat;
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
 * Юнит-тесты CLI-команды distribute (B3-05, план v3 §4; экспорт и архив — B4-05):
 * CommandTester + DI (моки репозиториев, фейковое значение флага ADR-004) БЕЗ БД
 * и реальных конфигов. Покрывают: невалидный --algorithm → exit≠0; невалидный
 * флаг ADR-004 → exit≠0; сводка и экспорт (файлы csv/tsv + ZIP через ZipArchiver)
 * на детерминированном in-memory датасете (400 студентов, 7 групп, 3 свободных
 * модуля с покрытием всех школ, заявок нет — все «молчуны» → свободные, R-10);
 * невалидный --format → exit≠0 (ADR-003); содержимое архива совпадает с файлами.
 *
 * Fake-\PDO (без соединения): readGroups()/readEligibleSchools()/readSchoolCodes()
 * из тестовых строк, DELETE перехватывается (сырой PDO вне контрактов, §2.2/§2.7).
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

    private string $exportDir;

    protected function setUp(): void
    {
        $this->rating = new CriteriaRating(new RatingWeights(
            weights: ['entrance_exams' => 0.40, 'gpa_sem12' => 0.25, 'gpa_basic' => 0.20, 'entrance_test' => 0.15],
            bonuses: ['disability' => 10.0],
            tiers: ['paid_over_budget' => true],
            normalization: 'min_max_0_100',
        ));
        $this->exportDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sr-b4-05-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->exportDir);
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
            defaultFormat: ExportFormat::Csv,
            exportDirectory: $this->exportDir,
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
            defaultFormat: ExportFormat::Csv,
            exportDirectory: $this->exportDir,
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
                static function (Assignment ...$assignments) use (&$saved): void {
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
            defaultFormat: ExportFormat::Csv,
            exportDirectory: $this->exportDir,
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
        self::assertStringContainsString('Файлы отчётов (' . $this->schoolsWithAssignments($saved, $modules) . '):', $display, 'сводка содержит список экспортированных файлов (B4-05)');
        self::assertStringContainsString('Архив: ', $display, 'сводка содержит путь к ZIP-архиву (B4-05)');
        self::assertCount(400, $saved, 'в БД записано ровно 400 финалов (R-11)');
        self::assertSame(["DELETE FROM assignments"], $pdo->execCalls, 'повторный прогон заменяет прежние записи (план §2.7)');
        self::assertInstanceOf(Assignment::class, $saved[0] ?? null, 'первый финал — объект Assignment');
        self::assertContains(
            $saved[0]->moduleId,
            array_map(static fn (Module $m): int => $m->id, $modules),
            'финалы лежат на свободных модулях (R-10)',
        );
    }

    public function testFormatTsvExportsTsvFiles(): void
    {
        /** @var list<Assignment> $saved */
        $saved = [];
        [$command, $modules] = $this->buildCommand(ExportFormat::Tsv, $saved);
        $this->applicationWith($command);

        $tester = new CommandTester($command);
        $tester->execute(['--algorithm' => 'criteria', '--format' => 'tsv']);

        self::assertSame(0, $tester->getStatusCode(), 'успешный прогон с --format=tsv — exit 0');
        $display = $tester->getDisplay();
        $filesCount = $this->schoolsWithAssignments($saved, $modules);
        self::assertStringContainsString('Файлы отчётов (' . $filesCount . '):', $display, 'сводка содержит список файлов');
        self::assertStringContainsString('.tsv', $display, 'файлы отчётов в формате tsv (ADR-003)');

        $tsvFiles = $this->globFiles($this->exportDir . DIRECTORY_SEPARATOR . '*.tsv');
        self::assertCount($filesCount, $tsvFiles, 'в каталоге создано столько tsv-файлов, сколько школ с назначениями');
        foreach ($tsvFiles as $path) {
            self::assertFileExists($path);
            self::assertStringContainsString("\t", (string) file_get_contents($path), 'разделитель TSV — табуляция (ADR-003)');
        }
    }

    public function testUnknownFormatRejected(): void
    {
        // Полный датасет: экспорт достижим, ошибка формата — до записи файлов.
        /** @var list<Assignment> $saved */
        $saved = [];
        [$command] = $this->buildCommand(ExportFormat::Csv, $saved);
        $this->applicationWith($command);

        $tester = new CommandTester($command);
        $tester->execute(['--algorithm' => 'criteria', '--format' => 'bogus'], ['capture_stderr_separately' => true]);

        self::assertNotSame(0, $tester->getStatusCode(), 'невалидный формат — exit≠0 (ADR-003)');
        self::assertStringContainsString('Неизвестный формат', $tester->getErrorOutput());
        self::assertSame([], $this->globFiles($this->exportDir . DIRECTORY_SEPARATOR . '*.csv'), 'csv-файлы не создаются при ошибке формата');
        self::assertSame([], $this->globFiles($this->exportDir . DIRECTORY_SEPARATOR . '*.zip'), 'архив не создаётся при ошибке формата');
    }

    public function testCliExportsAndArchives(): void
    {
        /** @var list<Assignment> $saved */
        $saved = [];
        [$command, $modules] = $this->buildCommand(ExportFormat::Csv, $saved);
        $this->applicationWith($command);

        $tester = new CommandTester($command);
        $tester->execute(['--algorithm' => 'criteria', '--format' => 'csv']);

        self::assertSame(0, $tester->getStatusCode(), 'успешный прогон с --format=csv — exit 0');
        $display = $tester->getDisplay();
        self::assertStringContainsString('Архив: ' . $this->exportDir, $display, 'сводка содержит путь к ZIP-архиву');

        $filesCount = $this->schoolsWithAssignments($saved, $modules);
        $csvFiles = $this->globFiles($this->exportDir . DIRECTORY_SEPARATOR . '*.csv');
        self::assertCount($filesCount, $csvFiles, 'создано csv-файлов столько, сколько школ с назначениями (ADR-001)');

        $archives = $this->globFiles($this->exportDir . DIRECTORY_SEPARATOR . 'students-ranking_*.zip');
        self::assertCount(1, $archives, 'создан ровно один ZIP-архив (B4-05)');
        $archivePath = $archives[0];
        self::assertFileExists($archivePath);

        // Содержимое архива побайтово совпадает с файлами (ZipArchiver STORE, B4-04).
        $entries = $this->readZipEntries($archivePath);
        self::assertCount($filesCount, $entries, 'в архиве по записи на отчётный файл');
        foreach ($csvFiles as $path) {
            $name = basename($path);
            self::assertArrayHasKey($name, $entries, 'в архиве есть запись ' . $name);
            self::assertSame(
                (string) file_get_contents($path),
                $entries[$name],
                'содержимое записи ' . $name . ' совпадает с файлом (ADR-003)',
            );
        }
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
     * readGroups()/readEligibleSchools()/readSchoolCodes(), exec перехватывает DELETE.
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

        // Коды школ (ADR-001): код = «SCH<id>», покрывает школы групп и модулей.
        $fake->schoolRows = [];
        $ids = array_unique([
            ...array_map(static fn (StudentGroup $g): int => $g->schoolId, $groups),
            ...array_map(static fn (Module $m): int => $m->schoolId, $modules),
        ]);
        foreach ($ids as $schoolId) {
            $fake->schoolRows[] = ['id' => $schoolId, 'code' => 'SCH' . $schoolId];
        }

        return $fake;
    }

    /**
     * Собирает полностью оснащённую команду на in-memory датасете: 400 студентов,
     * 7 групп, 3 свободных модуля (R-19), пустые заявки («молчуны», R-10).
     *
     * @param list<Assignment> $saved выходной массив сохранённых финалов (по ссылке)
     *
     * @return array{DistributeCommand, list<Module>}
     */
    private function buildCommand(ExportFormat $defaultFormat, array &$saved): array
    {
        $groups = $this->schoolGroups();
        $students = $this->students($groups);
        $modules = $this->freeModulesWithCapacity(600);
        $pdo = $this->fakePdo($groups, $modules);

        /** @var list<Assignment> $saved */
        $saved = [];
        $modulesRepo = $this->createMock(ModuleRepositoryInterface::class);
        $modulesRepo->method('findAll')->willReturn($modules);
        $modulesRepo->method('saveAssignments')
            ->willReturnCallback(
                static function (Assignment ...$assignments) use (&$saved): void {
                    foreach ($assignments as $assignment) {
                        $saved[] = $assignment;
                    }
                },
            );

        $studentsRepo = $this->createMock(StudentRepositoryInterface::class);
        $studentsRepo->method('findAllByAdmissionYear')->willReturn($students);

        $applicationsRepo = $this->createMock(ApplicationRepositoryInterface::class);
        $applicationsRepo->method('findAll')->willReturn([]);

        $command = new DistributeCommand(
            students: $studentsRepo,
            modules: $modulesRepo,
            applications: $applicationsRepo,
            pdo: $pdo,
            criteriaRating: $this->rating,
            targetQuotaNoApplicationStrategy: DistributeCommand::PROFILE_MODULE_FLAG,
            defaultFormat: $defaultFormat,
            exportDirectory: $this->exportDir,
        );

        return [$command, $modules];
    }

    /**
     * Число школ-организаторов с назначениями (RC): уникальные schoolId модулей
     * среди сохранённых финалов — столько файлов отчёта создаст экспорт (ADR-001).
     *
     * @param list<Assignment> $assignments сохранённые финалы
     * @param list<Module>     $modules      каталог для schoolId по moduleId
     */
    private function schoolsWithAssignments(array $assignments, array $modules): int
    {
        $schoolByModule = [];
        foreach ($modules as $module) {
            $schoolByModule[$module->id] = $module->schoolId;
        }

        $schoolIds = [];
        foreach ($assignments as $assignment) {
            $schoolIds[$schoolByModule[$assignment->moduleId] ?? 0] = true;
        }

        return count($schoolIds);
    }

    /**
     * Извлекает записи из ZIP (метод STORE, ZipArchiver B4-04) — без ext-zip,
     * по спецификации PKWARE: имя → содержимое.
     *
     * @return array<string, string>
     */
    private function readZipEntries(string $zipPath): array
    {
        $bytes = (string) file_get_contents($zipPath);
        $entries = [];
        $offset = 0;

        while (($bytes[$offset] ?? '') !== '') {
            /** @var array{sig: int, version: int, flags: int, method: int, vmtime: int, vmdate: int, crc: int, comp: int, uncomp: int, namelen: int, extralen: int}|false $header */
            $header = unpack('Vsig/vversion/vflags/vmethod/vmtime/vmdate/Vcrc/Vcomp/Vuncomp/vnamelen/vextralen', substr($bytes, $offset, 30));
            if ($header === false || $header['sig'] !== 0x04034b50) {
                break;
            }
            $name = substr($bytes, $offset + 30, $header['namelen']);
            $data = substr($bytes, $offset + 30 + $header['namelen'] + $header['extralen'], $header['comp']);
            $entries[$name] = $data;
            $offset += 30 + $header['namelen'] + $header['extralen'] + $header['comp'];
        }

        return $entries;
    }

    /**
     * Обёртка над glob(): false (ошибка/нет совпадений) → пустой список.
     *
     * @return list<string>
     */
    private function globFiles(string $pattern): array
    {
        $files = glob($pattern);

        return $files === false ? [] : $files;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = array_diff(scandir($dir, SCANDIR_SORT_NONE), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
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
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ui\Web;

use App\Domain\Enum\ExportFormat;
use App\Domain\Enum\ModuleType;
use App\Domain\Model\Application;
use App\Domain\Model\ApplicationItem;
use App\Domain\Model\Discipline;
use App\Domain\Model\Module;
use App\Domain\Model\Student;
use App\Domain\Model\StudentGroup;
use App\Domain\ValueObject\AcademicYear;
use App\Domain\ValueObject\RatingWeights;
use App\Domain\ValueObject\Semester;
use App\Infrastructure\File\ImportException;
use App\Ui\Web\WebWorkflow;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты веб-конвейера (B4-04, план v2 §4): WebWorkflow на in-memory каталогах
 * БЕЗ БД и реальных конфигов (паттерн DistributionServiceTest/SchoolReportAssemblerTest).
 *
 * Покрывают 7 кейсов B4-04: полный цикл CSV; полный цикл TSV; блокировка при ошибках
 * импорта; блокировка при неизвестном студенте файла (импорт-уровень, B4-03);
 * блокировка при нарушении R-13 (до распределения); недоступный файл → ImportException;
 * невалидный алгоритм → \InvalidArgumentException в конструкторе (R-17).
 *
 * Плюс 3 кейса B4-06: распределение из готового списка заявок (источник «БД»):
 * полный цикл; блокировка R-13; пустой список → \InvalidArgumentException (Q-B4-06-2).
 */
final class WebWorkflowTest extends TestCase
{
    private const int SCHOOL_ID = 1;

    private string $workDir;

    private string $exportDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sr-b4-04-' . bin2hex(random_bytes(4));
        mkdir($this->workDir, 0777, true);
        $this->exportDir = $this->workDir . DIRECTORY_SEPARATOR . 'export';
        mkdir($this->exportDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workDir);
    }

    public function testImportsCsvDistributesAndExports(): void
    {
        $workflow = $this->buildWorkflow();
        $path = $this->writeFile("student_id;module_id;priority\r\n1001;1;1\r\n1002;1;1\r\n");

        $result = $workflow->run($path);

        self::assertSame([], $result->errors);
        self::assertSame([], $result->validationErrors);
        self::assertSame(2, $result->assignedCount);
        self::assertSame(2, $result->totalStudents);
        self::assertCount(1, $result->exportFilePaths);
        self::assertFileExists($result->exportFilePaths[0]);
        self::assertStringEndsWith('TECH_2025-2026.csv', $result->exportFilePaths[0]);
        self::assertNotNull($result->archiveBytes);
        self::assertNotNull($result->archiveName);
        self::assertStringEndsWith('.zip', $result->archiveName);
    }

    public function testImportsTsvDistributesAndExports(): void
    {
        $workflow = $this->buildWorkflow();
        $path = $this->writeFile("student_id\tmodule_id\tpriority\n1001\t1\t1\n1002\t1\t1\n");

        $result = $workflow->run($path);

        self::assertSame([], $result->errors);
        self::assertSame(2, $result->assignedCount);
        self::assertNotNull($result->archiveBytes);
    }

    public function testImportErrorsBlockDistribution(): void
    {
        $workflow = $this->buildWorkflow();
        $path = $this->writeFile("student_id;module_id\n1001;1\n");

        $result = $workflow->run($path);

        self::assertNotSame([], $result->errors);
        self::assertStringContainsString('нет обязательной колонки "priority"', $result->errors[0]);
        self::assertSame(0, $result->assignedCount);
        self::assertNull($result->archiveBytes);
    }

    public function testUnknownStudentInFileProducesImportError(): void
    {
        $workflow = $this->buildWorkflow();
        $path = $this->writeFile("student_id;module_id;priority\n999;1;1\n");

        $result = $workflow->run($path);

        self::assertNotSame([], $result->errors);
        self::assertStringContainsString('нет студента #999', $result->errors[0]);
        self::assertSame(0, $result->assignedCount);
        self::assertNull($result->archiveBytes);
    }

    public function testR13ViolationBlocksDistribution(): void
    {
        $students = [$this->student(1001, $this->technicalGroup())];
        $modules = [
            $this->module(1, ModuleType::Technical),
            $this->module(2, ModuleType::Humanitarian),
        ];
        $workflow = $this->buildWorkflow($students, $modules);
        // Заявка технического студента содержит гуманитарный модуль (R-13).
        $path = $this->writeFile("student_id;module_id;priority\n1001;1;1\n1001;2;2\n");

        $result = $workflow->run($path);

        self::assertSame([], $result->errors);
        self::assertNotSame([], $result->validationErrors);
        self::assertStringContainsString('R-13', $result->validationErrors[0]);
        self::assertSame(0, $result->assignedCount);
        self::assertNull($result->archiveBytes);
    }

    public function testMissingFileThrowsImportException(): void
    {
        $workflow = $this->buildWorkflow();

        $this->expectException(ImportException::class);

        $workflow->run($this->workDir . DIRECTORY_SEPARATOR . 'missing.csv');
    }

    public function testInvalidAlgorithmRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Неизвестный алгоритм');

        $this->buildWorkflow(
            students: [$this->student(1001, $this->technicalGroup())],
            modules: [$this->module(1, ModuleType::Technical)],
            algorithm: 'bogus',
        );
    }

    public function testRunWithApplicationsDistributes(): void
    {
        $workflow = $this->buildWorkflow();
        $applications = [
            $this->application(1, 1001, [1]),
            $this->application(2, 1002, [1]),
        ];

        $result = $workflow->runWithApplications($applications);

        self::assertSame([], $result->errors);
        self::assertSame([], $result->validationErrors);
        self::assertSame(2, $result->assignedCount);
        self::assertSame(2, $result->totalStudents);
        self::assertCount(1, $result->exportFilePaths);
        self::assertFileExists($result->exportFilePaths[0]);
        self::assertNotNull($result->archiveBytes);
        self::assertNotNull($result->archiveName);
        self::assertStringEndsWith('.zip', $result->archiveName);
    }

    public function testRunWithApplicationsR13ViolationBlocks(): void
    {
        $students = [$this->student(1001, $this->technicalGroup())];
        $modules = [
            $this->module(1, ModuleType::Technical),
            $this->module(2, ModuleType::Humanitarian),
        ];
        $workflow = $this->buildWorkflow($students, $modules);
        // Заявка «из БД» технического студента содержит гуманитарный модуль (R-13).
        $applications = [
            $this->application(1, 1001, [1, 2]),
        ];

        $result = $workflow->runWithApplications($applications);

        self::assertSame([], $result->errors);
        self::assertNotSame([], $result->validationErrors);
        self::assertStringContainsString('R-13', $result->validationErrors[0]);
        self::assertSame(0, $result->assignedCount);
        self::assertNull($result->archiveBytes);
    }

    public function testRunWithApplicationsEmptyThrows(): void
    {
        $workflow = $this->buildWorkflow();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('В базе данных нет заявок');

        $workflow->runWithApplications([]);
    }

    /**
     * Минимальный детерминированный каталог: по умолчанию 2 студента + 1 технический МДС-модуль.
     *
     * @param list<Student> $students  вместо каталога по умолчанию (#1001, #1002)
     * @param list<Module>  $modules   вместо каталога по умолчанию (технический #1)
     */
    private function buildWorkflow(
        array $students = [],
        array $modules = [],
        string $algorithm = WebWorkflow::DEFAULT_ALGORITHM,
    ): WebWorkflow {
        return new WebWorkflow(
            students: $students === []
                ? [$this->student(1001, $this->technicalGroup()), $this->student(1002, $this->technicalGroup())]
                : $students,
            modules: $modules === [] ? [$this->module(1, ModuleType::Technical)] : $modules,
            groups: [$this->technicalGroup()],
            moduleEligibleSchools: [],
            schoolCodes: [self::SCHOOL_ID => 'TECH'],
            ratingWeights: $this->ratingWeights(),
            algorithm: $algorithm,
            format: ExportFormat::Csv,
            exportDirectory: $this->exportDir,
        );
    }

    private function technicalGroup(): StudentGroup
    {
        return new StudentGroup(1, self::SCHOOL_ID, 'Т-1', true, 2025);
    }

    private function student(int $id, StudentGroup $group): Student
    {
        return new Student(
            $id,
            $group->id,
            'Студент ' . $id,
            false,
            false,
            false,
            150,
            4.0,
            4.0,
            50,
        );
    }

    private function module(int $id, ModuleType $type): Module
    {
        return new Module(
            $id,
            self::SCHOOL_ID,
            'Модуль ' . $id,
            $type,
            1,
            2,
            new AcademicYear('2025-2026'),
            [
                new Discipline($id * 10 + 1, 'Дисциплина 1', new Semester(3)),
                new Discipline($id * 10 + 2, 'Дисциплина 2', new Semester(4)),
                new Discipline($id * 10 + 3, 'Дисциплина 3', new Semester(5)),
            ],
        );
    }

    /**
     * Заявка из «БД»/списка (B4-06): модули = приоритеты {1..N} непрерывно (R-12).
     *
     * @param list<int> $moduleIds модули в порядке приоритетов
     */
    private function application(int $id, int $studentId, array $moduleIds): Application
    {
        $items = [];
        foreach ($moduleIds as $priority => $moduleId) {
            $items[] = new ApplicationItem($moduleId, $priority + 1);
        }

        return new Application($id, $studentId, new \DateTimeImmutable('2026-09-01 10:00:00'), $items);
    }

    private function ratingWeights(): RatingWeights
    {
        return new RatingWeights(
            ['entrance_exams' => 0.40, 'gpa_sem12' => 0.25, 'gpa_basic' => 0.20, 'entrance_test' => 0.15],
            ['disability' => 10.0],
            ['paid_over_budget' => true],
            'min_max_0_100',
        );
    }

    /**
     * Пишет файл заявок во временный каталог (путь заведомо непустой).
     *
     * @return non-empty-string
     */
    private function writeFile(string $content): string
    {
        $path = $this->workDir . DIRECTORY_SEPARATOR . 'applications.csv';
        file_put_contents($path, $content);

        return $path;
    }

    private function removeDir(string $dir): void
    {
        $items = is_dir($dir) ? scandir($dir) : false;
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\File;

use App\Domain\Enum\ModuleType;
use App\Domain\Model\Application;
use App\Domain\Model\ApplicationItem;
use App\Domain\Model\Discipline;
use App\Domain\Model\Module;
use App\Domain\Model\Student;
use App\Domain\ValueObject\AcademicYear;
use App\Domain\ValueObject\Semester;
use App\Infrastructure\File\ImportException;
use App\Infrastructure\File\ReportApplicationImporter;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты импорта исходных данных заявок (B4-03, R-12/Р1 п. 6.2, ADR-003):
 * разбор и валидация файла csv/tsv в Domain Application; ошибки — читаемые с номером строки.
 * Каталоги модулей/студентов — фейковые (как SchoolReportAssemblerTest, B4-02).
 */
final class ReportApplicationImporterTest extends TestCase
{
    public function testValidCsvProducesApplications(): void
    {
        $path = self::write(
            "student_id;module_id;priority\r\n"
            . "101;1;1\r\n"
            . "101;2;2\r\n"
            . "102;3;1\r\n",
        );

        $result = self::importer()->import($path);

        self::assertSame([], $result->errors);
        self::assertCount(2, $result->applications);
        // Порядок — как в файле; id синтетический (1, 2), submittedAt = момент импорта.
        self::assertSame(1, $result->applications[0]->id);
        self::assertSame(101, $result->applications[0]->studentId);
        self::assertSame(['moduleId' => 1, 'priority' => 1], self::item($result->applications[0], 0));
        self::assertSame(['moduleId' => 2, 'priority' => 2], self::item($result->applications[0], 1));
        self::assertSame(2, $result->applications[1]->id);
        self::assertSame(102, $result->applications[1]->studentId);
        self::assertSame(['moduleId' => 3, 'priority' => 1], self::item($result->applications[1], 0));
    }

    public function testValidTsv(): void
    {
        $path = self::write("student_id\tmodule_id\tpriority\n101\t1\t1\n101\t2\t2\n");

        $result = self::importer()->import($path);

        self::assertSame([], $result->errors);
        self::assertCount(1, $result->applications);
        self::assertSame(101, $result->applications[0]->studentId);
        self::assertSame([1, 2], self::priorities($result->applications[0]));
    }

    public function testHeaderOrderInsensitive(): void
    {
        $path = self::write("priority;student_id;module_id\r\n1;101;1\r\n");

        $result = self::importer()->import($path);

        // Заголовок — идентификатор колонки: порядок в файле не важен (§2.2).
        self::assertSame([], $result->errors);
        self::assertCount(1, $result->applications);
        self::assertSame(101, $result->applications[0]->studentId);
        self::assertSame(['moduleId' => 1, 'priority' => 1], self::item($result->applications[0], 0));
    }

    public function testMissingColumnStopsImmediately(): void
    {
        $path = self::write("student_id;module_id\r\n101;1\r\n");

        $result = self::importer()->import($path);

        self::assertSame([], $result->applications);
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('нет обязательной колонки "priority"', $result->errors[0]);
    }

    public function testNonNumericIdRejected(): void
    {
        $path = self::write("student_id;module_id;priority\r\nabc;1;1\r\n");

        $result = self::importer()->import($path);

        self::assertSame([], $result->applications);
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('строка 2', $result->errors[0]);
        self::assertStringContainsString('не является целым числом', $result->errors[0]);
        self::assertStringContainsString('"student_id"', $result->errors[0]);
    }

    public function testPriorityRangeRejected(): void
    {
        $path = self::write(
            "student_id;module_id;priority\r\n"
            . "101;1;0\r\n"
            . "101;2;4\r\n",
        );

        $result = self::importer()->import($path);

        // Приоритет 0/4 вне данных подачи заявки: заявка студента отклоняется целиком (R-12).
        self::assertSame([], $result->applications);
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('приоритеты вне диапазона 1..3 (R-12): 0, 4', $result->errors[0]);
        self::assertStringContainsString('строка 2', $result->errors[0]);
    }

    public function testPriorityContinuityGapRejected(): void
    {
        $path = self::write(
            "student_id;module_id;priority\r\n"
            . "101;1;1\r\n"
            . "101;3;3\r\n",
        );

        $result = self::importer()->import($path);

        // Приоритеты 1 и 3 без 2 — ряд не непрерывен {1..2} (Р1 п. 6.2); группа отклонена.
        self::assertSame([], $result->applications);
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('не образуют непрерывный ряд {1..2}: 1, 3', $result->errors[0]);
        self::assertStringContainsString('строка 2', $result->errors[0]);
    }

    public function testDuplicatePriorityRejected(): void
    {
        $path = self::write(
            "student_id;module_id;priority\r\n"
            . "101;1;1\r\n"
            . "101;2;1\r\n",
        );

        $result = self::importer()->import($path);

        self::assertStringContainsString('приоритет 1 уже указан', $result->errors[0]);
        // Сохранённая строка (101, модуль 1, приоритет 1) образует валидную заявку.
        self::assertCount(1, $result->applications);
        self::assertSame(101, $result->applications[0]->studentId);
    }

    public function testUnknownModuleRejected(): void
    {
        $path = self::write("student_id;module_id;priority\r\n101;999;1\r\n");

        $result = self::importer()->import($path);

        self::assertSame([], $result->applications);
        self::assertStringContainsString('нет модуля #999', $result->errors[0]);
        self::assertStringContainsString('строка 2', $result->errors[0]);
    }

    public function testUnknownStudentRejected(): void
    {
        $path = self::write("student_id;module_id;priority\r\n999;1;1\r\n");

        $result = self::importer()->import($path);

        self::assertSame([], $result->applications);
        self::assertStringContainsString('нет студента #999', $result->errors[0]);
        self::assertStringContainsString('строка 2', $result->errors[0]);
    }

    public function testMoreThanThreePrioritiesRejected(): void
    {
        $path = self::write(
            "student_id;module_id;priority\r\n"
            . "101;1;1\r\n"
            . "101;2;2\r\n"
            . "101;3;3\r\n"
            . "101;4;4\r\n",
        );

        $result = self::importer()->import($path);

        // Ряд {1..4} непрерывен, но R-12 допускает не более 3 приоритетов (N ∈ 1..3).
        self::assertSame([], $result->applications);
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('приоритетов должно быть от 1 до 3 (R-12), получено: 4', $result->errors[0]);
        self::assertStringContainsString('строка 2', $result->errors[0]);
    }

    public function testEmptyFileNoApplications(): void
    {
        $empty = self::write('');
        $headerOnly = self::write("student_id;module_id;priority\r\n");

        foreach ([$empty, $headerOnly] as $path) {
            $result = self::importer()->import($path);
            self::assertSame([], $result->applications);
            self::assertSame([], $result->errors);
        }
    }

    public function testMissingFileThrowsImportException(): void
    {
        $importer = self::importer();

        $this->expectException(ImportException::class);
        $this->expectExceptionMessage('не удалось прочитать файл');

        $importer->import('/nonexistent/b403-does-not-exist.csv');
    }

    public function testErrorsCarryRowNumbers(): void
    {
        $path = self::write(
            "student_id;module_id;priority\r\n"
            . "10a;1;1\r\n"       // строка 2: не целое
            . "999;1;1\r\n"       // строка 3: нет студента
            . "101;1;1\r\n"       // строка 4: валидна
            . "101;999;1\r\n"     // строка 5: нет модуля
            . "102;3;1\r\n",      // строка 6: валидна
        );

        $result = self::importer()->import($path);

        self::assertCount(3, $result->errors);
        self::assertStringContainsString('строка 2', $result->errors[0]);
        self::assertStringContainsString('строка 3', $result->errors[1]);
        self::assertStringContainsString('строка 5', $result->errors[2]);
        // Корректные строки импортированы, несмотря на ошибки соседних.
        self::assertCount(2, $result->applications);
        self::assertSame([101, 102], self::studentIds($result->applications));
    }

    public function testRoundTripDumpImportCompare(): void
    {
        $source = [
            new Application(1, 101, new \DateTimeImmutable('2026-09-10 09:00:00'), [
                new ApplicationItem(1, 1),
                new ApplicationItem(2, 2),
            ]),
            new Application(2, 102, new \DateTimeImmutable('2026-09-10 10:00:00'), [
                new ApplicationItem(3, 1),
            ]),
        ];
        $path = self::write(self::dump($source));

        $result = self::importer()->import($path);

        // Круговая проверка: создание → дамп по §2.2 → импорт → сравнение с исходными.
        self::assertSame([], $result->errors);
        self::assertCount(2, $result->applications);
        self::assertSame(self::signature($source[0]), self::signature($result->applications[0]));
        self::assertSame(self::signature($source[1]), self::signature($result->applications[1]));
    }

    /**
     * Канонический дамп заявок в формат §2.2: строки student_id;module_id;priority,
     * элементы — по возрастанию приоритета.
     *
     * @param list<Application> $applications
     */
    private static function dump(array $applications): string
    {
        $rows = ['student_id;module_id;priority'];
        foreach ($applications as $application) {
            foreach ($application->items as $item) {
                $rows[] = implode(';', [$application->studentId, $item->moduleId, $item->priority]);
            }
        }

        return implode("\r\n", $rows);
    }

    /** @return array{studentId: int, items: list<array{moduleId: int, priority: int}>} */
    private static function signature(Application $application): array
    {
        $items = [];
        foreach ($application->items as $item) {
            $items[] = ['moduleId' => $item->moduleId, 'priority' => $item->priority];
        }
        usort($items, static fn (array $left, array $right): int => $left['priority'] <=> $right['priority']);

        return ['studentId' => $application->studentId, 'items' => $items];
    }

    /** @param list<Application> $applications
     *
     * @return list<int>
     */
    private static function studentIds(array $applications): array
    {
        return array_map(static fn (Application $application): int => $application->studentId, $applications);
    }

    /** @return list<int> */
    private static function priorities(Application $application): array
    {
        return array_map(static fn (ApplicationItem $item): int => $item->priority, $application->items);
    }

    /** @return array{moduleId: int, priority: int} */
    private static function item(Application $application, int $index): array
    {
        return [
            'moduleId' => $application->items[$index]->moduleId,
            'priority' => $application->items[$index]->priority,
        ];
    }

    private static function importer(): ReportApplicationImporter
    {
        return new ReportApplicationImporter(
            [
                1 => self::module(1),
                2 => self::module(2),
                3 => self::module(3),
                4 => self::module(4),
            ],
            [
                101 => self::student(101),
                102 => self::student(102),
            ],
        );
    }

    private static function module(int $id): Module
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
            schoolId: 1,
            title: 'Модуль ' . $id,
            moduleType: ModuleType::Technical,
            minStudents: 1,
            maxStudents: 5,
            academicYear: new AcademicYear('2026-2027'),
            disciplines: $disciplines,
        );
    }

    private static function student(int $id): Student
    {
        return new Student(
            id: $id,
            groupId: 1,
            fullName: 'Студент ' . $id,
            isTargetQuota: false,
            isPaid: false,
            isDisabled: false,
            entranceExamsSum: 300,
            gpaSem12: 4.0,
            gpaBasic: 4.0,
            entranceTestScore: 80,
        );
    }

    private static function write(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'b403_');
        self::assertNotFalse(file_put_contents($path, $content), 'не удалось записать тестовый файл');

        return $path;
    }
}
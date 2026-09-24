<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Export;

use App\Application\Export\ExportReportFile;
use App\Application\Export\SchoolReportAssembler;
use App\Domain\Enum\AssignmentSource;
use App\Domain\Enum\ModuleType;
use App\Domain\Enum\RankingAlgorithm;
use App\Domain\Model\Assignment;
use App\Domain\Model\Discipline;
use App\Domain\Model\DistributionResult;
use App\Domain\Model\Module;
use App\Domain\ValueObject\AcademicYear;
use App\Domain\ValueObject\Semester;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты SchoolReportAssembler (B4-02, R-18/ADR-001/ADR-003):
 * сборка пакетов по школам-организаторам на фейковых каталогах (БД не нужна —
 * readonly-конструкторы доменных моделей уже валидируют инварианты).
 */
final class SchoolReportAssemblerTest extends TestCase
{
    public function testOneSchoolOneFile(): void
    {
        $assembler = self::assembler(
            [1 => self::module(1, schoolId: 1, year: '2026-2027')],
            [1 => 'AAA'],
        );

        $files = $assembler->assemble(self::distribution(
            self::assignment(studentId: 101, moduleId: 1, source: AssignmentSource::Competition),
            self::assignment(studentId: 102, moduleId: 1, source: AssignmentSource::Free),
        ));

        self::assertCount(1, $files);
        self::assertSame('AAA', $files[0]->schoolCode);
        self::assertSame('2026-2027', $files[0]->academicYear);
        // Заголовок + 6 строк (2 студента × 3 дисциплины); источник не важен (§2.2).
        // Сортировка (§2.2): (moduleId, semester, studentId) asc.
        self::assertSame([
            ['academic_year', 'semester', 'discipline_id', 'student_id'],
            ['2026-2027', '3', '1001', '101'],
            ['2026-2027', '3', '1001', '102'],
            ['2026-2027', '4', '1002', '101'],
            ['2026-2027', '4', '1002', '102'],
            ['2026-2027', '5', '1003', '101'],
            ['2026-2027', '5', '1003', '102'],
        ], $files[0]->rows);
    }

    public function testThreeDisciplinesPerAssignment(): void
    {
        $module = self::module(1, schoolId: 1, year: '2025-2026');
        $assembler = self::assembler([1 => $module], [1 => 'AAA']);

        $files = $assembler->assemble(self::distribution(
            self::assignment(studentId: 7, moduleId: 1, source: AssignmentSource::TargetQuota),
        ));

        self::assertCount(1, $files);
        self::assertCount(4, $files[0]->rows); // заголовок + 3 строки
        $semesters = array_slice(array_column($files[0]->rows, 1), 1);
        self::assertSame(['3', '4', '5'], $semesters);
        $disciplineIds = array_slice(array_column($files[0]->rows, 2), 1);
        self::assertSame(['1001', '1002', '1003'], $disciplineIds);
        // Дисциплины взяты из данных модуля, не хардкодятся (R-02).
        self::assertSame(
            array_map(static fn (Discipline $d): string => (string) $d->id, $module->disciplines),
            $disciplineIds,
        );
    }

    public function testRowsSortedDeterministically(): void
    {
        $assembler = self::assembler(
            [
                1 => self::module(1, schoolId: 1, year: '2026-2027'),
                2 => self::module(2, schoolId: 1, year: '2026-2027'),
            ],
            [1 => 'AAA'],
        );

        $files = $assembler->assemble(self::distribution(
            self::assignment(studentId: 5, moduleId: 2),
            self::assignment(studentId: 3, moduleId: 1),
            self::assignment(studentId: 7, moduleId: 2),
            self::assignment(studentId: 1, moduleId: 1),
        ));

        self::assertCount(1, $files);
        self::assertSame([
            ['academic_year', 'semester', 'discipline_id', 'student_id'],
            ['2026-2027', '3', '1001', '1'],
            ['2026-2027', '3', '1001', '3'],
            ['2026-2027', '4', '1002', '1'],
            ['2026-2027', '4', '1002', '3'],
            ['2026-2027', '5', '1003', '1'],
            ['2026-2027', '5', '1003', '3'],
            ['2026-2027', '3', '2001', '5'],
            ['2026-2027', '3', '2001', '7'],
            ['2026-2027', '4', '2002', '5'],
            ['2026-2027', '4', '2002', '7'],
            ['2026-2027', '5', '2003', '5'],
            ['2026-2027', '5', '2003', '7'],
        ], $files[0]->rows);

// Повторный прогон на том же результате → идентичные пакеты (детерминизм).
        self::assertSame(
            self::canonical($files),
            self::canonical($assembler->assemble(self::distribution(
                self::assignment(studentId: 5, moduleId: 2),
                self::assignment(studentId: 3, moduleId: 1),
                self::assignment(studentId: 7, moduleId: 2),
                self::assignment(studentId: 1, moduleId: 1),
            ))),
        );
    }

    public function testTwoSchoolsTwoFiles(): void
    {
        $assembler = self::assembler(
            [
                1 => self::module(1, schoolId: 1, year: '2026-2027'),
                2 => self::module(2, schoolId: 2, year: '2026-2027'),
            ],
            [1 => 'AAA', 2 => 'BBB'],
        );

        $files = $assembler->assemble(self::distribution(
            self::assignment(studentId: 101, moduleId: 1),
            self::assignment(studentId: 202, moduleId: 2),
        ));

        self::assertCount(2, $files);
        self::assertSame(['AAA', 'BBB'], [$files[0]->schoolCode, $files[1]->schoolCode]);
        // Каждый файл — только студенты своих модулей (ADR-001).
        self::assertSame(['101', '101', '101'], array_slice(array_column($files[0]->rows, 3), 1));
        self::assertSame(['202', '202', '202'], array_slice(array_column($files[1]->rows, 3), 1));
    }

    public function testSameSchoolDifferentYearsTwoFiles(): void
    {
        $assembler = self::assembler(
            [
                1 => self::module(1, schoolId: 1, year: '2025-2026'),
                2 => self::module(2, schoolId: 1, year: '2026-2027'),
            ],
            [1 => 'AAA'],
        );

        $files = $assembler->assemble(self::distribution(
            self::assignment(studentId: 101, moduleId: 2),
            self::assignment(studentId: 102, moduleId: 1),
        ));

        self::assertCount(2, $files);
        // Год участвует в имени файла — разный учебный год = разные файлы (ADR-003).
        self::assertSame(['2025-2026', '2026-2027'], [$files[0]->academicYear, $files[1]->academicYear]);
        self::assertSame(['AAA', 'AAA'], [$files[0]->schoolCode, $files[1]->schoolCode]);
    }

    public function testGroupsSortedBySchoolCode(): void
    {
        // Школа 2 имеет код 'AAA' и идёт первой несмотря на меньший id модуля (детерминизм).
        $assembler = self::assembler(
            [
                1 => self::module(1, schoolId: 1, year: '2026-2027'),
                2 => self::module(2, schoolId: 2, year: '2026-2027'),
            ],
            [1 => 'BBB', 2 => 'AAA'],
        );

        $files = $assembler->assemble(self::distribution(
            self::assignment(studentId: 101, moduleId: 1),
            self::assignment(studentId: 202, moduleId: 2),
        ));

        self::assertCount(2, $files);
        self::assertSame('AAA', $files[0]->schoolCode);
        self::assertSame('BBB', $files[1]->schoolCode);
    }

    public function testHeaderRowIsFirst(): void
    {
        $assembler = self::assembler([1 => self::module(1, schoolId: 1, year: '2026-2027')], [1 => 'AAA']);

        $files = $assembler->assemble(self::distribution(self::assignment(studentId: 1, moduleId: 1)));

        self::assertSame(['academic_year', 'semester', 'discipline_id', 'student_id'], $files[0]->rows[0]);
    }

    public function testEmptyAssignmentsNoFiles(): void
    {
        $assembler = self::assembler([1 => self::module(1, schoolId: 1, year: '2026-2027')], [1 => 'AAA']);

        self::assertSame([], $assembler->assemble(self::distribution()));
    }

    public function testMissingModuleThrows(): void
    {
        $assembler = self::assembler([1 => self::module(1, schoolId: 1, year: '2026-2027')], [1 => 'AAA']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('нет модуля #999');

        $assembler->assemble(self::distribution(self::assignment(studentId: 1, moduleId: 999)));
    }

    public function testMissingSchoolCodeThrows(): void
    {
        $assembler = self::assembler([1 => self::module(1, schoolId: 99, year: '2026-2027')], []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('школа #99 вне карты кодов');

        $assembler->assemble(self::distribution(self::assignment(studentId: 1, moduleId: 1)));
    }

    /**
     * @param array<int, Module> $modules
     * @param array<int, string> $schoolCodes
     */
    private static function assembler(array $modules, array $schoolCodes): SchoolReportAssembler
    {
        return new SchoolReportAssembler($modules, $schoolCodes);
    }

    private static function module(int $id, int $schoolId, string $year): Module
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
            moduleType: ModuleType::Technical,
            minStudents: 1,
            maxStudents: 5,
            academicYear: new AcademicYear($year),
            disciplines: $disciplines,
        );
    }

    private static function assignment(int $studentId, int $moduleId, AssignmentSource $source = AssignmentSource::Competition): Assignment
    {
        return new Assignment(
            studentId: $studentId,
            moduleId: $moduleId,
            algorithm: RankingAlgorithm::Date,
            source: $source,
            rank: $source === AssignmentSource::Competition ? 1 : null,
        );
    }

    private static function distribution(Assignment ...$assignments): DistributionResult
    {
        return new DistributionResult(array_values($assignments), [], []);
    }

    /**
     * Каноническое представление пакета для сравнения независимо от идентичности объектов.
     *
     * @param list<ExportReportFile> $files
     *
     * @return list<array{schoolCode: string, academicYear: string, rows: list<list<string>>}>
     */
    private static function canonical(array $files): array
    {
        return array_map(
            static fn ($file): array => [
                'schoolCode' => $file->schoolCode,
                'academicYear' => $file->academicYear,
                'rows' => $file->rows,
            ],
            $files,
        );
    }
}
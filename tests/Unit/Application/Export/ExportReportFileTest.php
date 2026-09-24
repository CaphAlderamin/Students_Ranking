<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Export;

use App\Application\Export\ExportReportFile;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты ExportReportFile (B4-01): паспорт пакета отчёта — непустые schoolCode/
 * academicYear и строки как list<list<string>> (runtime-валидация перед записью файла).
 */
final class ExportReportFileTest extends TestCase
{
    public function testValidFileKeepsRows(): void
    {
        $file = new ExportReportFile('ISH', '2025-2026', [
            ['academic_year', 'semester', 'discipline_id', 'student_id'],
            ['2025-2026', '3', '11', '42'],
        ]);

        self::assertSame('ISH', $file->schoolCode);
        self::assertSame('2025-2026', $file->academicYear);
        self::assertSame('2025-2026', $file->rows[1][0]);
        self::assertCount(2, $file->rows);
    }

    public function testCodesAreTrimmed(): void
    {
        $file = new ExportReportFile('  ISH  ', ' 2025-2026 ', [['a', 'b']]);

        self::assertSame('ISH', $file->schoolCode);
        self::assertSame('2025-2026', $file->academicYear);
    }

    public function testEmptySchoolCodeRejected(): void
    {
        self::expectException(\InvalidArgumentException::class);
        new ExportReportFile('  ', '2025-2026', [['a', 'b']]);
    }

    public function testEmptyAcademicYearRejected(): void
    {
        self::expectException(\InvalidArgumentException::class);
        new ExportReportFile('ISH', '', [['a', 'b']]);
    }

    public function testNonArrayRowRejected(): void
    {
        self::expectException(\InvalidArgumentException::class);
        new ExportReportFile('ISH', '2025-2026', ['строка']);
    }

    public function testNonStringCellRejected(): void
    {
        self::expectException(\InvalidArgumentException::class);
        new ExportReportFile('ISH', '2025-2026', [[42]]);
    }

    public function testEmptyRowsAllowed(): void
    {
        $file = new ExportReportFile('ISH', '2025-2026', []);

        self::assertSame([], $file->rows);
    }
}
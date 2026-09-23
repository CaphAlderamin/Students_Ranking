<?php

declare(strict_types=1);

namespace App\Application\Export;

/**
 * Пакет одного отчётного файла (B4-01): школа-организатор (ADR-001) и строки R-18.
 *
 * Формат-классы пишут `rows` побайтово (включая заголовок, если его добавит
 * ассемблер B4-02); здесь же держатся данные для имени файла
 * `{school_code}_{academic_year}.{ext}` (ADR-003).
 */
final readonly class ExportReportFile
{
    public string $schoolCode;

    public string $academicYear;

    /** @var list<list<string>> строки файла (ячейки — строки) */
    public array $rows;

    /**
     * @param array<mixed> $rows строки; валидируются как list<list<string>>
     */
    public function __construct(string $schoolCode, string $academicYear, array $rows)
    {
        $schoolCode = trim($schoolCode);
        if ($schoolCode === '') {
            throw new \InvalidArgumentException('ExportReportFile: schoolCode не может быть пустым');
        }
        $academicYear = trim($academicYear);
        if ($academicYear === '') {
            throw new \InvalidArgumentException('ExportReportFile: academicYear не может быть пустым');
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('ExportReportFile: rows должны содержать только массивы строк');
            }
            $cells = [];
            foreach ($row as $cell) {
                if (!is_string($cell)) {
                    throw new \InvalidArgumentException('ExportReportFile: ячейка строки должна быть строкой');
                }
                $cells[] = $cell;
            }
            $normalized[] = $cells;
        }

        $this->schoolCode = $schoolCode;
        $this->academicYear = $academicYear;
        $this->rows = $normalized;
    }
}
<?php

declare(strict_types=1);

namespace App\Application\Export;

/**
 * Экспортёр CSV (B4-01, ADR-003): разделитель `;`, BOM UTF-8, перенос CRLF — совместимость
 * с русскоязычным Excel (аналогично BOM; решение ревью v2). Расширение `.csv`.
 */
final class CsvExporter extends AbstractFileExporter
{
    protected function delimiter(): string
    {
        return ';';
    }

    protected function newline(): string
    {
        return "\r\n";
    }

    protected function extension(): string
    {
        return 'csv';
    }

    protected function withBom(): bool
    {
        return true;
    }
}
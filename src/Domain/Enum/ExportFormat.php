<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Формат файла экспорта (ADR-003): CSV или TSV.
 */
enum ExportFormat: string
{
    case Csv = 'csv';
    case Tsv = 'tsv';
}
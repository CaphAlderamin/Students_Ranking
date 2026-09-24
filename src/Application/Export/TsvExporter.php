<?php

declare(strict_types=1);

namespace App\Application\Export;

/**
 * Экспортёр TSV (B4-01, ADR-003): разделитель `\t`, без BOM, перенос LF
 * (решение ревью v2). Расширение `.tsv`.
 */
final class TsvExporter extends AbstractFileExporter
{
    protected function delimiter(): string
    {
        return "\t";
    }

    protected function newline(): string
    {
        return "\n";
    }

    protected function extension(): string
    {
        return 'tsv';
    }

    protected function withBom(): bool
    {
        return false;
    }
}
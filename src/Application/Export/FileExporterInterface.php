<?php

declare(strict_types=1);

namespace App\Application\Export;

use App\Domain\Model\DistributionResult;

/**
 * Контракт экспорта (contracts.md, ADR-001/ADR-003): формирует отчётные файлы
 * по школам-организаторам и возвращает пути созданных файлов.
 *
 * Сигнатура закреплена в structure/server/contracts.md и не изменяется без нового ADR.
 */
interface FileExporterInterface
{
    /** @return string[] пути созданных файлов (по школе-организатору, ADR-001) */
    public function export(DistributionResult $result, string $targetDir): array;
}
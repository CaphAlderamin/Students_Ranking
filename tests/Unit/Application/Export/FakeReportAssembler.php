<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Export;

use App\Application\Export\ExportReportAssemblerInterface;
use App\Application\Export\ExportReportFile;
use App\Domain\Model\DistributionResult;

/**
 * Фейковый собиратель отчёта (B4-01, юнит-тесты форматов): возвращает предзаданный
 * пакет файлов — golden-тесты проверяют ТОЛЬКО байты формата (ADR-003), без БД.
 *
 * Именованный класс (не анонимный) — PHPStan level max типизирует реализации контрактов.
 */
final class FakeReportAssembler implements ExportReportAssemblerInterface
{
    /** @var list<ExportReportFile> */
    private array $files;

    /** @param list<ExportReportFile> $files */
    public function __construct(array $files)
    {
        $this->files = $files;
    }

    public function assemble(DistributionResult $result): array
    {
        return $this->files;
    }
}
<?php

declare(strict_types=1);

namespace App\Application\Export;

use App\Domain\Enum\ExportFormat;

/**
 * Фабрика экспортёров (B4-01, ADR-003): `create(ExportFormat)` возвращает реализацию
 * `FileExporterInterface` (CsvExporter/TsvExporter) для единого контура CLI/веба.
 *
 * Ассемблер отчёта (реализация — B4-02) прокидывается во все форматы; дефолтный формат
 * приходит из `config/export.php` на этапе связки (DI-паттерн B3-05: конфиг читает сборка,
 * не команда) — механизм дефолта реализован здесь методом `createDefault()`.
 */
final class ExporterFactory
{
    /**
     * Фабрика экспортёров — единая точка создания форматов (B4-01, ADR-003).
     *
     * @param ExportReportAssemblerInterface $assembler     ассемблер отчёта для всех форматов
     * @param ExportFormat                   $defaultFormat дефолтный формат (config/export.php)
     */
    public function __construct(
        private readonly ExportReportAssemblerInterface $assembler,
        private readonly ExportFormat $defaultFormat = ExportFormat::Csv,
    ) {
    }

    /**
     * Создаёт экспортёр заданного формата.
     *
     * @param ExportFormat $format формат экспорта (csv|tsv, ADR-003)
     *
     * @return FileExporterInterface реализация, пишущая файлы в выбранном формате
     */
    public function create(ExportFormat $format): FileExporterInterface
    {
        return match ($format) {
            ExportFormat::Csv => new CsvExporter($this->assembler),
            ExportFormat::Tsv => new TsvExporter($this->assembler),
        };
    }

    /**
     * Создаёт экспортёр дефолтного формата (config/export.php, ADR-003).
     *
     * @return FileExporterInterface экспортёр дефолтного формата
     */
    public function createDefault(): FileExporterInterface
    {
        return $this->create($this->defaultFormat);
    }
}
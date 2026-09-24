<?php

declare(strict_types=1);

namespace App\Application\Export;

use App\Domain\Model\DistributionResult;
use League\Csv\Bom;
use League\Csv\Writer;

/**
 * Базовый экспортёр отчёта (B4-01, ADR-003): общий цикл записи отчётных файлов.
 *
 * Пакеты файлов (школа-организатор, колонки R-18) поставляет ассемблер — реализация
 * `ExportReportAssemblerInterface` будет создана в B4-02; здесь же конкретные форматы
 * (CsvExporter/TsvExporter) задают разделитель, перенос строки, BOM и расширение.
 *
 * Формат-классы — слой представления: файлы пишутся именно здесь; бизнес-логика
 * отчёта (ассемблер) к файлам/БД не обращается (AGENTS.md §3).
 */
abstract class AbstractFileExporter implements FileExporterInterface
{
    /**
     * Базовый экспортёр отчётов — общий цикл записи отчётных файлов (B4-01, ADR-003).
     *
     * @param ExportReportAssemblerInterface $assembler ассемблер пакетов отчётных файлов
     */
    public function __construct(
        private readonly ExportReportAssemblerInterface $assembler,
    ) {
    }

    /**
     * Экспортирует отчётные файлы школ по пакетам ассемблера.
     *
     * Каталог вывода создаётся рекурсивно при первом пакете; имена файлов —
     * `{school_code}_{academic_year}.{ext}` (ADR-003).
     *
     * @param DistributionResult $result    итоги распределения (R-18)
     * @param string             $targetDir каталог вывода (создаётся при отсутствии)
     *
     * @return list<string> пути созданных файлов
     *
     * @throws \RuntimeException при недоступном каталоге или ошибке записи файла
     */
    public function export(DistributionResult $result, string $targetDir): array
    {
        $paths = [];
        foreach ($this->assembler->assemble($result) as $file) {
            if ($paths === []) {
                $this->ensureTargetDir($targetDir);
            }
            $path = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR
                . $file->schoolCode . '_' . $file->academicYear . '.' . $this->extension();
            $this->writeFile($path, $file->rows);
            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * Создаёт каталог вывода рекурсивно; при неудаче или отсутствии записи — RuntimeException.
     */
    private function ensureTargetDir(string $targetDir): void
    {
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('AbstractFileExporter: не удалось создать каталог: ' . $targetDir);
        }
        if (!is_writable($targetDir)) {
            throw new \RuntimeException('AbstractFileExporter: каталог недоступен для записи: ' . $targetDir);
        }
    }

    /**
     * Сериализует строки в формат и пишет файл (league/csv: разделитель, перенос,
     * экранирование; BOM через output-BOM).
     *
     * @param list<list<string>> $rows
     */
    private function writeFile(string $path, array $rows): void
    {
        $writer = Writer::createFromString();
        $writer->setDelimiter($this->delimiter());
        $writer->setNewline($this->newline());
        $writer->setEscape('');
        if ($this->withBom()) {
            $writer->setOutputBOM(Bom::Utf8);
        }
        $writer->insertAll($rows);

        $bytes = file_put_contents($path, (string) $writer);
        if ($bytes === false) {
            throw new \RuntimeException('AbstractFileExporter: не удалось записать файл: ' . $path);
        }
    }

    abstract protected function delimiter(): string;

    abstract protected function newline(): string;

    abstract protected function extension(): string;

    abstract protected function withBom(): bool;
}
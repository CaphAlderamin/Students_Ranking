<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Export;

use App\Application\Export\CsvExporter;
use App\Application\Export\ExportReportFile;
use App\Domain\Model\DistributionResult;
use PHPUnit\Framework\TestCase;

/**
 * Golden-тесты CsvExporter (B4-01, ADR-003): байт-в-байт эталон — BOM UTF-8,
 * разделитель `;`, перенос CRLF (решение ревью v2), экранирование RFC 4180,
 * имена `{school_code}_{academic_year}.csv`, пути созданных файлов.
 */
final class CsvExporterTest extends TestCase
{
    private string $targetDir;

    protected function setUp(): void
    {
        $this->targetDir = sys_get_temp_dir() . '/b4-01-csv-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->targetDir);
    }

    public function testGoldenBytes(): void
    {
        $exporter = $this->exporter([
            new ExportReportFile('ISH', '2025-2026', [
                ['academic_year', 'semester', 'discipline_id', 'student_id'],
                ['2025-2026', '3', '11', '42'],
            ]),
        ]);

        $paths = $exporter->export(new DistributionResult([], [], []), $this->targetDir);

        $expected = "\xEF\xBB\xBF"
            . "academic_year;semester;discipline_id;student_id\r\n"
            . "2025-2026;3;11;42\r\n";

        self::assertSame([$this->targetDir . '/ISH_2025-2026.csv'], $paths);
        self::assertSame($expected, file_get_contents($paths[0]));
    }

    public function testEscapesFieldsPerRfc4180(): void
    {
        $exporter = $this->exporter([
            new ExportReportFile('ISH', '2025-2026', [
                ['a;b', 'say "hi"', "line1\nline2", 'plain'],
            ]),
        ]);

        $paths = $exporter->export(new DistributionResult([], [], []), $this->targetDir);

        $expected = "\xEF\xBB\xBF"
            . "\"a;b\";\"say \"\"hi\"\"\";\"line1\nline2\";plain\r\n";

        self::assertSame($expected, file_get_contents($paths[0]));
    }

    public function testFileNameUsesSchoolCodeAndYear(): void
    {
        $exporter = $this->exporter([
            new ExportReportFile('ISHNP', '2025-2026', [['a', 'b']]),
        ]);

        $paths = $exporter->export(new DistributionResult([], [], []), $this->targetDir);

        self::assertSame($this->targetDir . '/ISHNP_2025-2026.csv', $paths[0]);
        self::assertFileExists($paths[0]);
    }

    public function testCreatesTargetDirRecursively(): void
    {
        $exporter = $this->exporter([
            new ExportReportFile('ISH', '2025-2026', [['a', 'b']]),
        ]);
        $nested = $this->targetDir . '/sub/deep';

        $paths = $exporter->export(new DistributionResult([], [], []), $nested);

        self::assertFileExists($nested . '/ISH_2025-2026.csv');
        self::assertSame($paths[0], $nested . '/ISH_2025-2026.csv');
    }

    public function testMultipleSchoolsProduceMultipleFiles(): void
    {
        $exporter = $this->exporter([
            new ExportReportFile('ISH', '2025-2026', [['a', 'b']]),
            new ExportReportFile('ISHNZ', '2025-2026', [['c', 'd']]),
        ]);

        $paths = $exporter->export(new DistributionResult([], [], []), $this->targetDir);

        self::assertCount(2, $paths);
        self::assertFileExists($this->targetDir . '/ISH_2025-2026.csv');
        self::assertFileExists($this->targetDir . '/ISHNZ_2025-2026.csv');
    }

    public function testEmptyResultProducesNoFiles(): void
    {
        $exporter = $this->exporter([]);

        $paths = $exporter->export(new DistributionResult([], [], []), $this->targetDir);

        self::assertSame([], $paths);
        self::assertDirectoryDoesNotExist($this->targetDir);
    }

    public function testRejectsUnwritableTargetDir(): void
    {
        mkdir($this->targetDir, 0755, true);
        $blocker = $this->targetDir . '/occupied';
        file_put_contents($blocker, 'файл');
        $exporter = $this->exporter([
            new ExportReportFile('ISH', '2025-2026', [['a', 'b']]),
        ]);

        self::expectException(\RuntimeException::class);
        $exporter->export(new DistributionResult([], [], []), $blocker);
    }

    /**
     * @param list<ExportReportFile> $files
     */
    private function exporter(array $files): CsvExporter
    {
        return new CsvExporter(new FakeReportAssembler($files));
    }

    private static function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . DIRECTORY_SEPARATOR . $item;
            if (is_dir($full)) {
                self::removeDirectory($full);
            } elseif (is_file($full)) {
                unlink($full);
            }
        }
        rmdir($path);
    }
}
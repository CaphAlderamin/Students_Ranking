<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Export;

use App\Application\Export\ExportReportFile;
use App\Application\Export\TsvExporter;
use App\Domain\Model\DistributionResult;
use PHPUnit\Framework\TestCase;

/**
 * Golden-тесты TsvExporter (B4-01, ADR-003): байт-в-байт эталон — разделитель `\t`,
 * без BOM, перенос LF (решение ревью v2), расширение `.tsv`.
 */
final class TsvExporterTest extends TestCase
{
    private string $targetDir;

    protected function setUp(): void
    {
        $this->targetDir = sys_get_temp_dir() . '/b4-01-tsv-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->targetDir)) {
            $items = scandir($this->targetDir);
            if ($items !== false) {
                foreach ($items as $item) {
                    if ($item === '.' || $item === '..') {
                        continue;
                    }
                    @unlink($this->targetDir . '/' . $item);
                }
            }
            @rmdir($this->targetDir);
        }
    }

    public function testGoldenBytes(): void
    {
        $exporter = new TsvExporter(new FakeReportAssembler([
            new ExportReportFile('ISH', '2025-2026', [
                ['academic_year', 'semester', 'discipline_id', 'student_id'],
                ['2025-2026', '3', '11', '42'],
            ]),
        ]));

        $paths = $exporter->export(new DistributionResult([], [], []), $this->targetDir);

        $expected = "academic_year\tsemester\tdiscipline_id\tstudent_id\n"
            . "2025-2026\t3\t11\t42\n";

        self::assertSame([$this->targetDir . '/ISH_2025-2026.tsv'], $paths);
        self::assertSame($expected, file_get_contents($paths[0]));
    }

    public function testNoBomIsWritten(): void
    {
        $exporter = new TsvExporter(new FakeReportAssembler([
            new ExportReportFile('ISH', '2025-2026', [['a', 'b']]),
        ]));

        $paths = $exporter->export(new DistributionResult([], [], []), $this->targetDir);

        $raw = file_get_contents($paths[0]);
        self::assertNotFalse($raw);
        self::assertStringStartsWith('a', $raw);
        self::assertNotSame("\xEF\xBB\xBF", substr($raw, 0, 3));
    }

    public function testExtendsWithTsv(): void
    {
        $exporter = new TsvExporter(new FakeReportAssembler([
            new ExportReportFile('ISH', '2025-2026', [['a', 'b']]),
        ]));

        $paths = $exporter->export(new DistributionResult([], [], []), $this->targetDir);

        self::assertSame($this->targetDir . '/ISH_2025-2026.tsv', $paths[0]);
        self::assertFileExists($paths[0]);
    }
}
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Export;

use App\Application\Export\CsvExporter;
use App\Application\Export\ExportReportFile;
use App\Application\Export\ExporterFactory;
use App\Application\Export\TsvExporter;
use App\Domain\Enum\ExportFormat;
use App\Domain\Model\DistributionResult;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты ExporterFactory (B4-01, ADR-003): по ExportFormat поднимается нужная
 * реализация FileExporterInterface; createDefault() использует формат из конфига;
 * во все форматы прокидывается один ассемблер (общий контур CLI/веб).
 */
final class ExporterFactoryTest extends TestCase
{
    private ExporterFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ExporterFactory(new FakeReportAssembler([]), ExportFormat::Csv);
    }

    public function testCreateReturnsExpectedImplementation(): void
    {
        self::assertInstanceOf(CsvExporter::class, $this->factory->create(ExportFormat::Csv));
        self::assertInstanceOf(TsvExporter::class, $this->factory->create(ExportFormat::Tsv));
    }

    public function testSharedAssemblerFeedsEveryFormat(): void
    {
        $files = [new ExportReportFile('ISH', '2025-2026', [['a', 'b']])];
        $factory = new ExporterFactory(new FakeReportAssembler($files));
        $targetDir = sys_get_temp_dir() . '/b4-01-factory-' . bin2hex(random_bytes(6));

        $csvPaths = $factory->create(ExportFormat::Csv)->export(new DistributionResult([], [], []), $targetDir . '/csv');
        $tsvPaths = $factory->create(ExportFormat::Tsv)->export(new DistributionResult([], [], []), $targetDir . '/tsv');

        self::assertSame('ISH_2025-2026', basename($csvPaths[0], '.csv'));
        self::assertSame('ISH_2025-2026', basename($tsvPaths[0], '.tsv'));

        $this->removeDirectory($targetDir);
    }

    public function testCreateDefaultUsesConfiguredFormat(): void
    {
        $factory = new ExporterFactory(new FakeReportAssembler([]), ExportFormat::Tsv);

        self::assertInstanceOf(TsvExporter::class, $factory->createDefault());
    }

    public function testCreateDefaultFallsBackToCsv(): void
    {
        $factory = new ExporterFactory(new FakeReportAssembler([]));

        self::assertInstanceOf(CsvExporter::class, $factory->createDefault());
    }

    public function testEveryFormatWritesExactlyOneFilePerSchool(): void
    {
        $file = new ExportReportFile('ISH', '2025-2026', [['a', 'b']]);
        $factory = new ExporterFactory(new FakeReportAssembler([$file]));
        $result = new DistributionResult([], [], []);
        $targetDir = sys_get_temp_dir() . '/b4-01-factory-' . bin2hex(random_bytes(6));

        foreach (ExportFormat::cases() as $format) {
            $paths = $factory->create($format)->export($result, $targetDir . '/' . $format->value);
            self::assertCount(1, $paths, 'формат ' . $format->value . ' создаёт ровно один файл на школу');
            self::assertFileExists($paths[0]);
        }

        $this->removeDirectory($targetDir);
    }

    public function testFactoryKeepsCsvDefaultWhenBuiltViaCreateDefault(): void
    {
        self::assertInstanceOf(CsvExporter::class, $this->factory->createDefault());
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = scandir($path);
        foreach ($items === false ? [] : $items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path . '/' . $item;
            if (is_dir($full)) {
                $this->removeDirectory($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
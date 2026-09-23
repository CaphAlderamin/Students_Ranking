<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Export;

use App\Domain\Enum\ExportFormat;
use PHPUnit\Framework\TestCase;

/**
 * Smoke-тесты конфигурации экспорта (B4-01, ADR-003): config/export.php содержит
 * дефолтный формат (csv) и каталог вывода, которые сборка отдаёт ExporterFactory.
 */
final class ExportConfigTest extends TestCase
{
    public function testConfigContainsFormatAndDirectory(): void
    {
        /** @var array{format: string, directory: string} $config */
        $config = require dirname(__DIR__, 4) . '/config/export.php';

        self::assertArrayHasKey('format', $config);
        self::assertArrayHasKey('directory', $config);
    }

    public function testConfiguredFormatIsKnownExportFormat(): void
    {
        /** @var array{format: string, directory: string} $config */
        $config = require dirname(__DIR__, 4) . '/config/export.php';

        $format = ExportFormat::tryFrom($config['format']);

        self::assertInstanceOf(ExportFormat::class, $format);
        self::assertSame(ExportFormat::Csv, $format);
    }

    public function testDirectoryConfigured(): void
    {
        /** @var array{format: string, directory: string} $config */
        $config = require dirname(__DIR__, 4) . '/config/export.php';

        self::assertSame('var/export/', $config['directory']);
    }
}
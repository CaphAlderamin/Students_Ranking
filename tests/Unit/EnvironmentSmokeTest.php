<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Смоук-тест окружения: проверяет, что рантайм соответствует стеку проекта.
 * Создан в B1-03, чтобы PHPUnit не был пустым и PHPStan имел файлы для анализа.
 */
final class EnvironmentSmokeTest extends TestCase
{
    public function testPhpVersionMatchesStack(): void
    {
        $this->assertGreaterThanOrEqual(80300, PHP_VERSION_ID);
    }
}
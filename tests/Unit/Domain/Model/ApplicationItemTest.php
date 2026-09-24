<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Model;

use App\Domain\Model\ApplicationItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты ApplicationItem (B1-07): moduleId > 0, priority в диапазоне 1..3 (R-12).
 */
final class ApplicationItemTest extends TestCase
{
    public function testValid(): void
    {
        $item = new ApplicationItem(7, 2);

        self::assertSame(7, $item->moduleId);
        self::assertSame(2, $item->priority);
    }

    public function testFirstAndLastPriorityAccepted(): void
    {
        self::assertSame(1, (new ApplicationItem(1, 1))->priority);
        self::assertSame(3, (new ApplicationItem(2, 3))->priority);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'moduleId = 0' => [0, 1];
        yield 'priority = 0' => [1, 0];
        yield 'priority = 4' => [1, 4];
        yield 'priority отрицательный' => [1, -1];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalid(int $moduleId, int $priority): void
    {
        self::expectException(\InvalidArgumentException::class);
        new ApplicationItem($moduleId, $priority);
    }
}
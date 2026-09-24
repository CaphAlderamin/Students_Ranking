<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Model;

use App\Domain\Model\Application;
use App\Domain\Model\ApplicationItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты Application (B1-07): приоритеты образуют непрерывный ряд {1..N}, N ∈ 1..3
 * (контракт #2, Р1 п. 6.2, R-12); id/studentId > 0.
 */
final class ApplicationTest extends TestCase
{
    private static function item(int $priority): ApplicationItem
    {
        return new ApplicationItem(1, $priority);
    }

    /**
     * @return iterable<string, array{list<ApplicationItem>}>
     */
    public static function validProvider(): iterable
    {
        yield 'один модуль' => [[new ApplicationItem(5, 1)]];
        yield 'два модуля' => [[self::item(1), self::item(2)]];
        yield 'три модуля' => [[self::item(1), self::item(2), self::item(3)]];
    }

    /**
     * @param list<ApplicationItem> $items
     */
    #[DataProvider('validProvider')]
    public function testValid(array $items): void
    {
        $application = new Application(1, 1, new \DateTimeImmutable('2026-09-01 10:00:00'), $items);

        self::assertSame(1, $application->id);
        self::assertSame(1, $application->studentId);
        self::assertSame($items, $application->items);
    }

    /**
     * @return iterable<string, array{list<ApplicationItem>, int}>
     */
    public static function invalidPriorityProvider(): iterable
    {
        yield 'пропуск приоритета {1,3}' => [[self::item(1), self::item(3)], 2];
        yield 'дубль приоритета {1,1}' => [[self::item(1), self::item(1)], 2];
    }

    /**
     * @param list<ApplicationItem> $items
     */
    #[DataProvider('invalidPriorityProvider')]
    public function testGapAndDuplicatePrioritiesRejected(array $items, int $studentId): void
    {
        self::expectException(\InvalidArgumentException::class);
        new Application(1, $studentId, new \DateTimeImmutable('2026-09-01 10:00:00'), $items);
    }

    public function testEmptyApplicationRejected(): void
    {
        self::expectException(\InvalidArgumentException::class);
        new Application(1, 1, new \DateTimeImmutable('2026-09-01 10:00:00'), []);
    }

    public function testTooManyItemsRejected(): void
    {
        self::expectException(\InvalidArgumentException::class);
        new Application(
            1,
            1,
            new \DateTimeImmutable('2026-09-01 10:00:00'),
            [self::item(1), self::item(2), self::item(3), self::item(3)],
        );
    }

    public function testInvalidIdAndStudentId(): void
    {
        self::expectException(\InvalidArgumentException::class);
        new Application(0, 0, new \DateTimeImmutable('2026-09-01 10:00:00'), [self::item(1)]);
    }
}
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\ValueObject;

use App\Domain\ValueObject\AcademicYear;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты VO AcademicYear (B1-07): формат строго YYYY-YYYY, только дефис (Q-1).
 */
final class AcademicYearTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validProvider(): iterable
    {
        yield 'обычный учебный год' => ['2025-2026', '2025-2026'];
        yield 'граничные цифры' => ['0000-9999', '0000-9999'];
        yield 'пробелы по краям обрезаются' => ['  2025-2026  ', '2025-2026'];
    }

    #[DataProvider('validProvider')]
    public function testValid(string $value, string $expected): void
    {
        $year = new AcademicYear($value);
        self::assertSame($expected, $year->value);
        self::assertSame($expected, $year->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'слэш вместо дефиса (Windows)' => ['2025/2026'];
        yield 'короткий второй год' => ['2025-26'];
        yield 'без дефиса' => ['20252026'];
        yield 'пустая строка' => [''];
        yield 'только пробелы' => ['   '];
        yield 'хвостовой мусор' => ['2025-2026!'];
        yield 'нецифры' => ['abcd-efgh'];
        yield 'лишний дефис' => ['2025-2026-2027'];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalid(string $value): void
    {
        self::expectException(\InvalidArgumentException::class);
        new AcademicYear($value);
    }
}
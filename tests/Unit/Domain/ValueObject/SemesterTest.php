<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\ValueObject;

use App\Domain\ValueObject\Semester;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты VO Semester (B1-07): допустимый диапазон 1..12 (Q-2; в данных МДС — 3,4,5 по ADR-005).
 */
final class SemesterTest extends TestCase
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function validProvider(): iterable
    {
        yield 'первый семестр' => [1];
        yield 'семестр МДС 3' => [3];
        yield 'семестр МДС 4' => [4];
        yield 'семестр МДС 5' => [5];
        yield 'последний семестр' => [12];
    }

    #[DataProvider('validProvider')]
    public function testValid(int $value): void
    {
        $semester = new Semester($value);
        self::assertSame($value, $semester->asInt());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'ноль' => [0];
        yield 'отрицательный' => [-1];
        yield 'больше 12' => [13];
        yield 'значительно больше' => [100];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalid(int $value): void
    {
        self::expectException(\InvalidArgumentException::class);
        new Semester($value);
    }
}
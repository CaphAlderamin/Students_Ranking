<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Model;

use App\Domain\Model\Discipline;
use App\Domain\ValueObject\Semester;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты Discipline (B1-07): id > 0, непустое title; семестр — через VO Semester (в данных, ADR-005).
 */
final class DisciplineTest extends TestCase
{
    public function testValid(): void
    {
        $discipline = new Discipline(1, ' Математика ', new Semester(3));

        self::assertSame(1, $discipline->id);
        self::assertSame('Математика', $discipline->title);
        self::assertSame(3, $discipline->semester->asInt());
    }

    /**
     * @return iterable<string, array{int, string, int}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'id = 0' => [0, 'Математика', 3];
        yield 'пустое title' => [1, '  ', 3];
        yield 'семестр вне диапазона' => [1, 'Математика', 0];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalid(int $id, string $title, int $semester): void
    {
        self::expectException(\InvalidArgumentException::class);
        new Discipline($id, $title, new Semester($semester));
    }
}
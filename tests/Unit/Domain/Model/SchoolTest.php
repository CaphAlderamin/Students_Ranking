<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Model;

use App\Domain\Model\School;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты School (B1-07): id > 0, code 1..16 символов (varchar(16)), непустые code/name.
 */
final class SchoolTest extends TestCase
{
    public function testValid(): void
    {
        $school = new School(1, ' ИШПР ', ' Инженерная школа ');

        self::assertSame(1, $school->id);
        self::assertSame('ИШПР', $school->code);
        self::assertSame('Инженерная школа', $school->name);
    }

    public function testCodeOfMaxLengthAccepted(): void
    {
        $code = '0123456789abcdef';
        $school = new School(1, $code, 'name');

        self::assertSame($code, $school->code);
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'id = 0' => [0, 'ИШПР', 'Инженерная школа'];
        yield 'пустой code' => [1, '', 'Инженерная школа'];
        yield 'code только пробелы' => [1, '   ', 'Инженерная школа'];
        yield 'code длиннее 16' => [1, '0123456789abcdef0', 'Инженерная школа'];
        yield 'пустое name' => [1, 'ИШПР', ''];
        yield 'name только пробелы' => [1, 'ИШПР', '   '];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalid(int $id, string $code, string $name): void
    {
        self::expectException(\InvalidArgumentException::class);
        new School($id, $code, $name);
    }
}
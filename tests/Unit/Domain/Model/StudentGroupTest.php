<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Model;

use App\Domain\Model\StudentGroup;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты StudentGroup (B1-07): id/schoolId > 0, непустое name, год приёма 1900..2100 (Q-5).
 */
final class StudentGroupTest extends TestCase
{
    public function testValid(): void
    {
        $group = new StudentGroup(1, 1, ' ИШПР-1-1 ', true, 2026);

        self::assertSame(1, $group->id);
        self::assertSame(1, $group->schoolId);
        self::assertSame('ИШПР-1-1', $group->name);
        self::assertTrue($group->isTechnical);
        self::assertSame(2026, $group->admissionYear);
    }

    public function testYearBoundariesAccepted(): void
    {
        self::assertSame(1900, (new StudentGroup(1, 1, 'a', false, 1900))->admissionYear);
        self::assertSame(2100, (new StudentGroup(1, 1, 'b', false, 2100))->admissionYear);
    }

    /**
     * @return iterable<string, array{int, int, string, bool, int}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'id = 0' => [0, 1, 'Г-1', true, 2026];
        yield 'schoolId = 0' => [1, 0, 'Г-1', true, 2026];
        yield 'пустое name' => [1, 1, '', true, 2026];
        yield 'год меньше 1900' => [1, 1, 'Г-1', true, 1899];
        yield 'год больше 2100' => [1, 1, 'Г-1', true, 2101];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalid(int $id, int $schoolId, string $name, bool $isTechnical, int $admissionYear): void
    {
        self::expectException(\InvalidArgumentException::class);
        new StudentGroup($id, $schoolId, $name, $isTechnical, $admissionYear);
    }
}
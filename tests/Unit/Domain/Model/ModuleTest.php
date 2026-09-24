<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Model;

use App\Domain\Enum\ModuleType;
use App\Domain\Model\Discipline;
use App\Domain\Model\Module;
use App\Domain\ValueObject\AcademicYear;
use App\Domain\ValueObject\Semester;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты Module (B1-07): ровно 3 дисциплины с семестрами {3,4,5} (R-02/ADR-005),
 * вместимость min ≥ 1 и max ≥ min (Q-6), id/schoolId > 0, непустой title.
 */
final class ModuleTest extends TestCase
{
    private static function discipline(int $semester): Discipline
    {
        return new Discipline($semester, 'Дисциплина ' . $semester, new Semester($semester));
    }

    public function testValidAcceptsDisciplinesInAnyOrder(): void
    {
        $module = new Module(
            1,
            1,
            ' МДС инженерных школ ',
            ModuleType::Technical,
            10,
            15,
            new AcademicYear('2025-2026'),
            [self::discipline(5), self::discipline(3), self::discipline(4)],
        );

        self::assertSame('МДС инженерных школ', $module->title);
        self::assertSame(10, $module->minStudents);
        self::assertSame(15, $module->maxStudents);
        self::assertCount(3, $module->disciplines);
    }

    public function testMinEqualsMaxAccepted(): void
    {
        $module = new Module(
            1,
            1,
            'МДС',
            ModuleType::Free,
            1,
            1,
            new AcademicYear('2025-2026'),
            [self::discipline(3), self::discipline(4), self::discipline(5)],
        );

        self::assertSame(1, $module->minStudents);
        self::assertSame(1, $module->maxStudents);
    }

    /**
     * @return iterable<string, array{list<int>}>
     */
    public static function durationProvider(): iterable
    {
        yield '2 дисциплины' => [[3, 4]];
        yield '4 дисциплины' => [[3, 4, 5, 6]];
    }

    /**
     * @param list<int> $semesters
     */
    #[DataProvider('durationProvider')]
    public function testRequiresExactlyThreeDisciplines(array $semesters): void
    {
        self::expectException(\InvalidArgumentException::class);
        new Module(
            1,
            1,
            'МДС',
            ModuleType::Technical,
            1,
            2,
            new AcademicYear('2025-2026'),
            array_map(static fn (int $semester): Discipline => self::discipline($semester), $semesters),
        );
    }

    public function testDuplicateSemestersRejected(): void
    {
        self::expectException(\InvalidArgumentException::class);
        new Module(
            1,
            1,
            'МДС',
            ModuleType::Technical,
            1,
            2,
            new AcademicYear('2025-2026'),
            [self::discipline(3), self::discipline(3), self::discipline(4)],
        );
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function capacityProvider(): iterable
    {
        yield 'min = 0' => [0, 2];
        yield 'max меньше min' => [5, 4];
        yield 'min отрицательный' => [-1, 2];
    }

    #[DataProvider('capacityProvider')]
    public function testCapacityInvalid(int $min, int $max): void
    {
        self::expectException(\InvalidArgumentException::class);
        new Module(
            1,
            1,
            'МДС',
            ModuleType::Technical,
            $min,
            $max,
            new AcademicYear('2025-2026'),
            [self::discipline(3), self::discipline(4), self::discipline(5)],
        );
    }

    public function testInvalidIdAndTitle(): void
    {
        self::expectException(\InvalidArgumentException::class);
        new Module(
            0,
            1,
            ' ',
            ModuleType::Technical,
            1,
            2,
            new AcademicYear('2025-2026'),
            [self::discipline(3), self::discipline(4), self::discipline(5)],
        );
    }
}
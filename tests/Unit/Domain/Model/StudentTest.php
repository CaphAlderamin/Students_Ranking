<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Model;

use App\Domain\Model\Student;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты Student (B1-07): границы метрик Q-4 (ВИ 0..300, GPA 2.0..5.0, тест 0..100),
 * id/groupId > 0, непустое fullName.
 */
final class StudentTest extends TestCase
{
    public function testValidTrimsName(): void
    {
        $student = new Student(1, 1, ' Иванов Иван ', false, false, true, 300, 5.0, 2.0, 0);

        self::assertSame(1, $student->id);
        self::assertSame('Иванов Иван', $student->fullName);
        self::assertTrue($student->isDisabled);
        self::assertSame(300, $student->entranceExamsSum);
        self::assertSame(5.0, $student->gpaSem12);
        self::assertSame(2.0, $student->gpaBasic);
        self::assertSame(0, $student->entranceTestScore);
    }

    public function testMetricBoundariesAccepted(): void
    {
        $student = new Student(1, 1, 'Границы', false, false, false, 0, 2.0, 5.0, 100);

        self::assertSame(0, $student->entranceExamsSum);
        self::assertSame(2.0, $student->gpaSem12);
        self::assertSame(5.0, $student->gpaBasic);
        self::assertSame(100, $student->entranceTestScore);
    }

    /**
     * @return iterable<string, array{int, int, string, int, float, float, int}>
     */
    public static function invalidProvider(): iterable
    {
        yield 'id = 0' => [0, 1, 'Студент', 200, 4.0, 4.0, 80];
        yield 'groupId = 0' => [1, 0, 'Студент', 200, 4.0, 4.0, 80];
        yield 'пустое fullName' => [1, 1, ' ', 200, 4.0, 4.0, 80];
        yield 'ВИ меньше 0' => [1, 1, 'Студент', -1, 4.0, 4.0, 80];
        yield 'ВИ больше 300' => [1, 1, 'Студент', 301, 4.0, 4.0, 80];
        yield 'gpaSem12 меньше 2.0' => [1, 1, 'Студент', 200, 1.99, 4.0, 80];
        yield 'gpaSem12 больше 5.0' => [1, 1, 'Студент', 200, 5.01, 4.0, 80];
        yield 'gpaBasic меньше 2.0' => [1, 1, 'Студент', 200, 4.0, 1.99, 80];
        yield 'gpaBasic больше 5.0' => [1, 1, 'Студент', 200, 4.0, 5.01, 80];
        yield 'тест меньше 0' => [1, 1, 'Студент', 200, 4.0, 4.0, -1];
        yield 'тест больше 100' => [1, 1, 'Студент', 200, 4.0, 4.0, 101];
    }

    #[DataProvider('invalidProvider')]
    public function testInvalid(
        int $id,
        int $groupId,
        string $fullName,
        int $entranceExamsSum,
        float $gpaSem12,
        float $gpaBasic,
        int $entranceTestScore,
    ): void {
        self::expectException(\InvalidArgumentException::class);
        new Student($id, $groupId, $fullName, false, false, false, $entranceExamsSum, $gpaSem12, $gpaBasic, $entranceTestScore);
    }
}
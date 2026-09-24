<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Enum;

use App\Domain\Enum\AssignmentSource;
use App\Domain\Enum\ExportFormat;
use App\Domain\Enum\GroupType;
use App\Domain\Enum\ModuleStatus;
use App\Domain\Enum\ModuleType;
use App\Domain\Enum\RankingAlgorithm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты доменных enum (B1-07): фиксирует строковые значения, которые обязаны
 * совпадать с колонками БД (module_type, schools.isTechnical ↔ group_type,
 * assignment.algorithm/source, формат экспорта ADR-003), и round-trip через from().
 */
final class EnumValuesTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<\BackedEnum>, string, string}>
     */
    public static function enumProvider(): iterable
    {
        yield 'ModuleType:technical' => [ModuleType::class, 'Technical', 'technical'];
        yield 'ModuleType:humanitarian' => [ModuleType::class, 'Humanitarian', 'humanitarian'];
        yield 'ModuleType:free' => [ModuleType::class, 'Free', 'free'];
        yield 'ModuleStatus:open' => [ModuleStatus::class, 'Open', 'open'];
        yield 'ModuleStatus:closed' => [ModuleStatus::class, 'Closed', 'closed'];
        yield 'GroupType:technical' => [GroupType::class, 'Technical', 'technical'];
        yield 'GroupType:humanitarian' => [GroupType::class, 'Humanitarian', 'humanitarian'];
        yield 'RankingAlgorithm:date' => [RankingAlgorithm::class, 'Date', 'date'];
        yield 'RankingAlgorithm:criteria' => [RankingAlgorithm::class, 'Criteria', 'criteria'];
        yield 'AssignmentSource:competition' => [AssignmentSource::class, 'Competition', 'competition'];
        yield 'AssignmentSource:free' => [AssignmentSource::class, 'Free', 'free'];
        yield 'AssignmentSource:target_quota' => [AssignmentSource::class, 'TargetQuota', 'target_quota'];
        yield 'ExportFormat:csv' => [ExportFormat::class, 'Csv', 'csv'];
        yield 'ExportFormat:tsv' => [ExportFormat::class, 'Tsv', 'tsv'];
    }

    /**
     * @param class-string<\BackedEnum> $enumClass
     */
    #[DataProvider('enumProvider')]
    public function testValueAndRoundTrip(string $enumClass, string $caseName, string $value): void
    {
        $case = $enumClass::from($value);
        self::assertInstanceOf($enumClass, $case);
        self::assertSame($caseName, $case->name);
        self::assertSame($value, $case->value);
        self::assertSame($case, $enumClass::from($case->value));
    }

    /**
     * @return iterable<string, array{class-string<\BackedEnum>}>
     */
    public static function enumClassProvider(): iterable
    {
        yield 'ModuleType' => [ModuleType::class];
        yield 'ModuleStatus' => [ModuleStatus::class];
        yield 'GroupType' => [GroupType::class];
        yield 'RankingAlgorithm' => [RankingAlgorithm::class];
        yield 'AssignmentSource' => [AssignmentSource::class];
        yield 'ExportFormat' => [ExportFormat::class];
    }

    /**
     * Запрос неизвестного значения любой из доменных колонок должен падать с ValueError.
     *
     * @param class-string<\BackedEnum> $enumClass
     */
    #[DataProvider('enumClassProvider')]
    public function testFromRejectsUnknownValue(string $enumClass): void
    {
        self::expectException(\ValueError::class);
        $enumClass::from('unknown');
    }
}
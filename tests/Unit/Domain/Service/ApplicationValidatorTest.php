<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Service;

use App\Domain\Enum\GroupType;
use App\Domain\Enum\ModuleType;
use App\Domain\Model\Application;
use App\Domain\Model\ApplicationItem;
use App\Domain\Service\ApplicationValidator;
use PHPUnit\Framework\TestCase;

/**
 * Юнит-тесты ApplicationValidator (B2-04, план v2 §4): критерий приёмки
 * «валидатор отклоняет чужой тип» + граничные случаи R-13.
 *
 * Проверки:
 *  - тех→тех и гум→гум принимает (свой тип);
 *  - тех→гум и гум→тех отклоняет;
 *  - свободный модуль (ModuleType::Free) в заявке отклонён;
 *  - невалидный moduleId (нет в карте типов) отклонён;
 *  - бросает \InvalidArgumentException (вариант Б ревью B2-04) с перечнем нарушений.
 */
final class ApplicationValidatorTest extends TestCase
{
    private ApplicationValidator $validator;

    /** Карта «moduleId → ModuleType» фикстуры: 1..3 технические, 10..12 гуманитарные, 20 свободный. */
    /** @var array<int, ModuleType> */
    private const array MODULE_TYPES = [
        1 => ModuleType::Technical,
        2 => ModuleType::Technical,
        3 => ModuleType::Technical,
        10 => ModuleType::Humanitarian,
        11 => ModuleType::Humanitarian,
        12 => ModuleType::Humanitarian,
        20 => ModuleType::Free,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ApplicationValidator();
    }

    public function testAcceptsTechnicalStudentForOwnType(): void
    {
        $application = new Application(
            1,
            1,
            new \DateTimeImmutable('2026-09-07 09:00:00'),
            [self::item(1, 1), self::item(2, 2), self::item(3, 3)],
        );

        $this->validator->validate($application, GroupType::Technical, self::MODULE_TYPES);

        // Без исключения — принято (валидатор кидает исключение при нарушении R-13).
        $this->addToAssertionCount(1);
    }

    public function testAcceptsHumanitarianStudentForOwnType(): void
    {
        $application = new Application(
            1,
            2,
            new \DateTimeImmutable('2026-09-07 09:00:00'),
            [self::item(10, 1), self::item(11, 2), self::item(12, 3)],
        );

        $this->validator->validate($application, GroupType::Humanitarian, self::MODULE_TYPES);

        $this->addToAssertionCount(1);
    }

    public function testRejectsTechnicalStudentChoosingHumanitarianModule(): void
    {
        $application = new Application(
            1,
            3,
            new \DateTimeImmutable('2026-09-07 09:00:00'),
            [self::item(1, 1), self::item(10, 2), self::item(2, 3)],
        );

        self::expectException(\InvalidArgumentException::class);
        $this->validator->validate($application, GroupType::Technical, self::MODULE_TYPES);
    }

    public function testRejectsHumanitarianStudentChoosingTechnicalModule(): void
    {
        $application = new Application(
            1,
            4,
            new \DateTimeImmutable('2026-09-07 09:00:00'),
            [self::item(10, 1), self::item(1, 2), self::item(11, 3)],
        );

        self::expectException(\InvalidArgumentException::class);
        $this->validator->validate($application, GroupType::Humanitarian, self::MODULE_TYPES);
    }

    public function testRejectsFreeModuleInApplication(): void
    {
        $application = new Application(
            1,
            5,
            new \DateTimeImmutable('2026-09-07 09:00:00'),
            [self::item(1, 1), self::item(20, 2), self::item(2, 3)],
        );

        self::expectException(\InvalidArgumentException::class);
        $this->validator->validate($application, GroupType::Technical, self::MODULE_TYPES);
    }

    public function testRejectsUnknownModuleId(): void
    {
        $application = new Application(
            1,
            6,
            new \DateTimeImmutable('2026-09-07 09:00:00'),
            [self::item(1, 1), self::item(2, 2), self::item(99, 3)],
        );

        self::expectException(\InvalidArgumentException::class);
        $this->validator->validate($application, GroupType::Technical, self::MODULE_TYPES);
    }

    public function testCollectsAllViolationsInMessage(): void
    {
        $application = new Application(
            1,
            7,
            new \DateTimeImmutable('2026-09-07 09:00:00'),
            [self::item(10, 1), self::item(20, 2), self::item(99, 3)],
        );

        try {
            $this->validator->validate($application, GroupType::Technical, self::MODULE_TYPES);
            self::fail('Validator должен бросить исключение для всех нарушений сразу');
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();
            self::assertStringContainsString('humanitarian', $message);
            self::assertStringContainsString('free', $message);
            self::assertStringContainsString('99', $message);
        }
    }

    private static function item(int $moduleId, int $priority): ApplicationItem
    {
        return new ApplicationItem($moduleId, $priority);
    }
}
<?php

declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\Enum\GroupType;
use App\Domain\Enum\ModuleType;
use App\Domain\Model\Application;

/**
 * Валидатор заявки по R-13: студент выбирает только модули СВОЕГО типа группы
 * (техническая группа → технические модули, гуманитарная → гуманитарные).
 *
 * Чистый Domain-класс (final readonly): доступ к БД отсутствует — карта
 * «moduleId → ModuleType» передаётся извне (правило: бизнес-логика не знает
 * о БД). Свободные модули (ModuleType::Free) в заявках запрещены: студент
 * выбирает только свой тип тех/гум, свободные — лишь фаза 4 (R-09/R-10).
 *
 * Исключение: \InvalidArgumentException с перечнем всех нарушений — консистентно
 * с Application/ApplicationItem (B1-06), вариант Б ревью B2-04 («DomainException»
 * не вводится).
 */
final readonly class ApplicationValidator
{
    /**
     * Проверяет, что каждый элемент заявки ссылается на модуль типа группы студента.
     *
     * @param GroupType              $studentGroupType тип группы студента (R-13)
     * @param array<int, ModuleType> $moduleTypes      карта «moduleId → ModuleType»
     *
     * @throws \InvalidArgumentException при неизвестном moduleId, свободном модуле
     *                                   в заявке или несоответствии типа (R-13)
     */
    public function validate(Application $application, GroupType $studentGroupType, array $moduleTypes): void
    {
        $violations = [];

        foreach ($application->items as $item) {
            $moduleType = $moduleTypes[$item->moduleId] ?? null;

            if ($moduleType === null) {
                $violations[] = 'модуль ' . $item->moduleId . ' неизвестен (нет в карте типов)';
                continue;
            }

            if ($moduleType === ModuleType::Free) {
                $violations[] = 'модуль ' . $item->moduleId . ' свободный (free) — свободные модули в заявках запрещены (R-13)';
                continue;
            }

            $expected = $studentGroupType === GroupType::Technical
                ? ModuleType::Technical
                : ModuleType::Humanitarian;

            if ($moduleType !== $expected) {
                $violations[] = 'модуль ' . $item->moduleId . ' типа ' . $moduleType->value
                    . ' не соответствует типу группы студента ' . $studentGroupType->value . ' (R-13)';
            }
        }

        if ($violations !== []) {
            throw new \InvalidArgumentException(
                'ApplicationValidator: заявка студента ' . $application->studentId
                . ' нарушает R-13 (только свой тип модуля): ' . implode('; ', $violations),
            );
        }
    }
}
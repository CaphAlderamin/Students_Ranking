<?php

declare(strict_types=1);

namespace App\Infrastructure\Seeder;

use Faker\Factory;
use Faker\Generator;

/**
 * Генератор студентов (B2-02): Faker ru_RU с фиксированным seed.
 *
 * Детерминизм: каждый вызов {@see StudentGenerator::rows()} пересоздаёт
 * генератор с одним seed, поэтому два запуска сидера дают одинаковые длины.
 * Метрики генерируются строго внутри границ Q-4/ADR-002:
 * сумма ВИ 0..300, GPA 2.0..5.0, входное тестирование 0..100.
 * Флаги (целевик/платник/инвалид) — по умолчанию false; активные когорты
 * добавляет B2-03 через собственный генератор без правки этого файла.
 */
final class StudentGenerator
{
    /** Фиксированный seed для детерминированного набора сидера. */
    public const int FAKER_SEED = 20260923;

    private Generator $faker;

    public function __construct()
    {
        $this->faker = Factory::create('ru_RU');
    }

    /**
     * Формирует строки студентов для одной группы.
     *
     * Детерминизм: генератор перезасеивается перед каждой группой с базы,
     * зависящей от id группы (FAKER_SEED + groupId). Пер-групповой seed даёт
     * уникальные имена/метрики в разных группах, при этом два запуска сидера
     * полностью воспроизводимы (id группы стабилен: TRUNCATE сбрасывает
     * AUTO_INCREMENT, группы вставляются в одном порядке).
     *
     * @param int $groupId идентификатор группы в БД
     * @param int $count   число студентов
     *
     * @return list<array{fullName: non-empty-string, isTargetQuota: bool, isPaid: bool,
     *                    isDisabled: bool, entranceExamsSum: int, gpaSem12: float,
     *                    gpaBasic: float, entranceTestScore: int}>
     */
    public function rows(int $groupId, int $count): array
    {
        $this->faker->seed(self::FAKER_SEED + $groupId);
        $rows = [];

        for ($i = 0; $i < $count; ++$i) {
            $fullName = $this->faker->name('male'); // имя мужского рода, однородно для всех
            \assert($fullName !== '');

            $rows[] = [
                'fullName' => $fullName,
                'isTargetQuota' => false,
                'isPaid' => false,
                'isDisabled' => false,
                'entranceExamsSum' => $this->faker->numberBetween(0, 300),
                'gpaSem12' => $this->faker->randomFloat(3, 2.0, 5.0),
                'gpaBasic' => $this->faker->randomFloat(3, 2.0, 5.0),
                'entranceTestScore' => $this->faker->numberBetween(0, 100),
            ];
        }

        return $rows;
    }
}
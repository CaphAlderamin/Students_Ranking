<?php

declare(strict_types=1);

namespace App\Domain\Model;

/**
 * Результат распределения: итоговые назначения, нераспределённые студенты и статистика.
 *
 * `unassigned` в финальном результате пуст (R-11: распределены ВСЕ — не подавшие уходят на
 * свободные модули, ADR-004). Допустимо непустое значение только как промежуточное состояние.
 *
 * `stats` — статистика по модулям (заполнение, источники); форма не фиксирована контрактом,
 * хранится как произвольный ассоциативный массив без жёсткой валидации.
 */
final readonly class DistributionResult
{
    /** @var list<Assignment> */
    public array $assignments;

    /** @var list<Student> студенты без назначения (в финале — пусто, R-11) */
    public array $unassigned;

    /** @var array<int|string, mixed> статистика по модулям (ключ — id модуля; число приводится к int) */
    public array $stats;

    /**
     * @param array<int, mixed> $assignments итоговые назначения (приманяются только Assignment)
     * @param array<int, mixed> $unassigned  нераспределённые (приманяются только Student)
     * @param array<int|string, mixed> $stats статистика по модулям
     */
    public function __construct(array $assignments, array $unassigned, array $stats)
    {
        $normalizedAssignments = [];
        foreach ($assignments as $assignment) {
            if (!$assignment instanceof Assignment) {
                throw new \InvalidArgumentException('DistributionResult: assignments должны содержать только Assignment');
            }
            $normalizedAssignments[] = $assignment;
        }

        $normalizedUnassigned = [];
        foreach ($unassigned as $student) {
            if (!$student instanceof Student) {
                throw new \InvalidArgumentException('DistributionResult: unassigned должны содержать только Student');
            }
            $normalizedUnassigned[] = $student;
        }

        $this->assignments = $normalizedAssignments;
        $this->unassigned = $normalizedUnassigned;
        $this->stats = $stats;
    }
}
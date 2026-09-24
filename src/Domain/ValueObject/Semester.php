<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

/**
 * Номер семестра обучения.
 *
 * Диапазон 1..12 (Q-2, подтверждено пользователем 2026-09-22). Конкретные семестры модуля
 * (3, 4, 5 по ADR-005) хранятся в данных (`disciplines.semester`), а не хардкодом (R-02).
 */
final readonly class Semester
{
    public int $value;

    /**
     * Номер семестра — диапазон 1..12 (Q-2).
     *
     * @param int $value номер семестра 1..12
     *
     * @throws \InvalidArgumentException при выходе за 1..12
     */
    public function __construct(int $value)
    {
        if ($value < 1 || $value > 12) {
            throw new \InvalidArgumentException('Semester: номер семестра должен быть в диапазоне 1..12, получено: ' . $value);
        }
        $this->value = $value;
    }

    /**
     * Возвращает значение семестра как целое число.
     *
     * @return int номер семестра (1..12)
     */
    public function asInt(): int
    {
        return $this->value;
    }
}
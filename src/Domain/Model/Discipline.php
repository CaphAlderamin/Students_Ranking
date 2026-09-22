<?php

declare(strict_types=1);

namespace App\Domain\Model;

use App\Domain\ValueObject\Semester;

/**
 * Дисциплина модуля (таблица `disciplines`).
 * Семестр — в данных (R-02/ADR-005: для МДС — 3, 4, 5), не хардкод.
 */
final readonly class Discipline
{
    public int $id;
    public string $title;
    public Semester $semester;

    public function __construct(int $id, string $title, Semester $semester)
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Discipline: id должен быть положительным числом, получено: ' . $id);
        }
        $title = trim($title);
        if ($title === '') {
            throw new \InvalidArgumentException('Discipline: title не может быть пустым');
        }

        $this->id = $id;
        $this->title = $title;
        $this->semester = $semester;
    }
}
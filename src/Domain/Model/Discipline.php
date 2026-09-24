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

    /**
     * Дисциплина модуля (таблица disciplines).
     *
     * @param int      $id       идентификатор дисциплины (≥ 1)
     * @param string   $title    название (непустое; тримится)
     * @param Semester $semester семестр изучения (R-02/ADR-005: для МДС — 3, 4, 5)
     *
     * @throws \InvalidArgumentException при id < 1 или пустом title
     */
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
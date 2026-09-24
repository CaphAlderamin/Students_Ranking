<?php

declare(strict_types=1);

namespace App\Domain\Model;

/**
 * Заявка студента на модули (таблица `applications` + `application_items`).
 *
 * Инвариант (контракт #2, уточнён 2026-09-22 по Р1 п. 6.2): приоритеты в заявке образуют
 * непрерывный ряд с 1 — множество равно {1..N}, где N — число элементов (без пропусков и дублей),
 * N ∈ 1..3 (R-12).
 */
final readonly class Application
{
    public int $id;
    public int $studentId;
    public \DateTimeImmutable $submittedAt;

    /** @var list<ApplicationItem> */
    public array $items;

    /**
     * @param int                 $id          первичный ключ (> 0)
     * @param int                 $studentId   студент (> 0; одна заявка на студента — uk_applications_student)
     * @param \DateTimeImmutable  $submittedAt момент подачи (окно — 1 учебная неделя, R-12)
     * @param list<ApplicationItem> $items      элементы заявки, приоритеты = {1..N} непрерывно (Р1 п. 6.2)
     */
    public function __construct(int $id, int $studentId, \DateTimeImmutable $submittedAt, array $items)
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('Application: id должен быть положительным числом, получено: ' . $id);
        }
        if ($studentId < 1) {
            throw new \InvalidArgumentException('Application: studentId должен быть положительным числом, получено: ' . $studentId);
        }
        $count = count($items);
        if ($count < 1 || $count > 3) {
            throw new \InvalidArgumentException(
                'Application: в заявке должно быть от 1 до 3 модулей (R-12), получено: ' . $count,
            );
        }

        $priorities = array_map(static fn (ApplicationItem $item): int => $item->priority, $items);
        sort($priorities);
        if ($priorities !== range(1, $count)) {
            throw new \InvalidArgumentException(
                'Application: приоритеты должны образовывать непрерывный ряд {1..' . $count
                . '} (Р1 п. 6.2), получено: ' . implode(', ', $priorities),
            );
        }

        $this->id = $id;
        $this->studentId = $studentId;
        $this->submittedAt = $submittedAt;
        $this->items = $items;
    }
}
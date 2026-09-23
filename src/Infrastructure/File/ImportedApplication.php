<?php

declare(strict_types=1);

namespace App\Infrastructure\File;

use App\Domain\Model\ApplicationItem;

/**
 * Внутренний этап импорта заявок (B4-03): «заявка как в файле» — без персистентного id.
 *
 * Формируется после группировки строк по студенту и валидации непрерывности приоритетов;
 * персистентный id присвоит БД при сохранении (B4-04). Дублирует доменные инварианты
 * (число элементов 1..3 по R-12, непрерывный ряд приоритетов {1..N} по Р1 п. 6.2) как
 * защиту от регрессии: строка группировки и Domain `Application` (B1-06) не рассинхронизируются.
 */
final readonly class ImportedApplication
{
    public int $studentId;

    public \DateTimeImmutable $submittedAt;

    /** @var list<ApplicationItem> элементы заявки, приоритеты = {1..N} непрерывно, N ∈ 1..3 */
    public array $items;

    /**
     * @param int                       $studentId   студент (> 0)
     * @param \DateTimeImmutable        $submittedAt момент подачи (= момент импорта, решение §2.3 плана)
     * @param list<ApplicationItem>     $items       элементы, приоритеты = {1..N} (Р1 п. 6.2)
     */
    public function __construct(int $studentId, \DateTimeImmutable $submittedAt, array $items)
    {
        if ($studentId < 1) {
            throw new \InvalidArgumentException(
                'ImportedApplication: studentId должен быть положительным числом, получено: ' . $studentId,
            );
        }
        $count = count($items);
        if ($count < 1 || $count > 3) {
            throw new \InvalidArgumentException(
                'ImportedApplication: в заявке должно быть от 1 до 3 модулей (R-12), получено: ' . $count,
            );
        }

        $priorities = array_map(static fn (ApplicationItem $item): int => $item->priority, $items);
        sort($priorities);
        if ($priorities !== range(1, $count)) {
            throw new \InvalidArgumentException(
                'ImportedApplication: приоритеты должны образовывать непрерывный ряд {1..' . $count
                . '} (Р1 п. 6.2), получено: ' . implode(', ', $priorities),
            );
        }

        $this->studentId = $studentId;
        $this->submittedAt = $submittedAt;
        $this->items = $items;
    }
}
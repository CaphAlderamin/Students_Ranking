<?php

declare(strict_types=1);

namespace App\Domain\Model;

/**
 * Элемент заявки: выбор модуля по приоритету (таблица `application_items`).
 * Приоритет — 1..3 (R-12); внутри заявки образует непрерывный ряд с 1 (Р1 п. 6.2, см. Application).
 */
final readonly class ApplicationItem
{
    public int $moduleId;
    public int $priority;

    /**
     * Элемент заявки — выбор модуля по приоритету (таблица application_items).
     *
     * @param int $moduleId идентификатор модуля (≥ 1)
     * @param int $priority приоритет выбора 1..3 (R-12)
     *
     * @throws \InvalidArgumentException при moduleId < 1 или priority вне 1..3
     */
    public function __construct(int $moduleId, int $priority)
    {
        if ($moduleId < 1) {
            throw new \InvalidArgumentException('ApplicationItem: moduleId должен быть положительным числом, получено: ' . $moduleId);
        }
        if ($priority < 1 || $priority > 3) {
            throw new \InvalidArgumentException('ApplicationItem: priority должен быть в диапазоне 1..3 (R-12), получено: ' . $priority);
        }

        $this->moduleId = $moduleId;
        $this->priority = $priority;
    }
}
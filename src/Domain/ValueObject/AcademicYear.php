<?php

declare(strict_types=1);

namespace App\Domain\ValueObject;

/**
 * Учебный год (например, 2025-2026).
 *
 * Формат строго `YYYY-YYYY` (Q-1, подтверждено пользователем 2026-09-22): строка используется
 * в именах отчётных файлов экспорта, поэтому допускается только дефис (слэш недопустим в Windows).
 */
final readonly class AcademicYear
{
    private const string PATTERN = '/^[0-9]{4}-[0-9]{4}$/';

    public string $value;

    public function __construct(string $value)
    {
        $value = trim($value);
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new \InvalidArgumentException(
                'AcademicYear: ожидается формат YYYY-YYYY (например, 2025-2026), получено: ' . $value,
            );
        }
        $this->value = $value;
    }

    /** Возвращает значение как строку (для вывода в файлы экспорта). */
    public function toString(): string
    {
        return $this->value;
    }
}
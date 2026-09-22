<?php

declare(strict_types=1);

namespace App\Domain\Model;

/**
 * Учебная группа (таблица `student_groups`).
 */
final readonly class StudentGroup
{
    public int $id;
    public int $schoolId;
    public string $name;
    public bool $isTechnical;
    public int $admissionYear;

    /**
     * @param int    $id            первичный ключ (> 0)
     * @param int    $schoolId      идентификатор школы (> 0)
     * @param string $name          название группы
     * @param bool   $isTechnical   техническая группа (R-13; true = техническая)
     * @param int    $admissionYear год приёма (Q-5, подтверждено: 1900..2100)
     */
    public function __construct(int $id, int $schoolId, string $name, bool $isTechnical, int $admissionYear)
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('StudentGroup: id должен быть положительным числом, получено: ' . $id);
        }
        if ($schoolId < 1) {
            throw new \InvalidArgumentException('StudentGroup: schoolId должен быть положительным числом, получено: ' . $schoolId);
        }
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('StudentGroup: name не может быть пустым');
        }
        if ($admissionYear < 1900 || $admissionYear > 2100) {
            throw new \InvalidArgumentException(
                'StudentGroup: admissionYear вне диапазона 1900..2100 (Q-5), получено: ' . $admissionYear,
            );
        }

        $this->id = $id;
        $this->schoolId = $schoolId;
        $this->name = $name;
        $this->isTechnical = $isTechnical;
        $this->admissionYear = $admissionYear;
    }
}
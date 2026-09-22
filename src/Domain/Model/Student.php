<?php

declare(strict_types=1);

namespace App\Domain\Model;

/**
 * Студент (таблица `students`): метрики рейтинга и флаги.
 * Границы метрик (Q-4, подтверждено пользователем 2026-09-22, из ADR-002):
 * сумма ВИ 0..300, GPA 2.0..5.0, входное тестирование 0..100.
 */
final readonly class Student
{
    public int $id;
    public int $groupId;
    public string $fullName;
    public bool $isTargetQuota;
    public bool $isPaid;
    public bool $isDisabled;
    public int $entranceExamsSum;
    public float $gpaSem12;
    public float $gpaBasic;
    public int $entranceTestScore;

    public function __construct(
        int $id,
        int $groupId,
        string $fullName,
        bool $isTargetQuota,
        bool $isPaid,
        bool $isDisabled,
        int $entranceExamsSum,
        float $gpaSem12,
        float $gpaBasic,
        int $entranceTestScore,
    ) {
        if ($id < 1) {
            throw new \InvalidArgumentException('Student: id должен быть положительным числом, получено: ' . $id);
        }
        if ($groupId < 1) {
            throw new \InvalidArgumentException('Student: groupId должен быть положительным числом, получено: ' . $groupId);
        }
        $fullName = trim($fullName);
        if ($fullName === '') {
            throw new \InvalidArgumentException('Student: fullName не может быть пустым');
        }
        if ($entranceExamsSum < 0 || $entranceExamsSum > 300) {
            throw new \InvalidArgumentException('Student: entranceExamsSum вне диапазона 0..300 (Q-4, ADR-002): ' . $entranceExamsSum);
        }
        if ($gpaSem12 < 2.0 || $gpaSem12 > 5.0) {
            throw new \InvalidArgumentException('Student: gpaSem12 вне диапазона 2.0..5.0 (Q-4): ' . $gpaSem12);
        }
        if ($gpaBasic < 2.0 || $gpaBasic > 5.0) {
            throw new \InvalidArgumentException('Student: gpaBasic вне диапазона 2.0..5.0 (Q-4): ' . $gpaBasic);
        }
        if ($entranceTestScore < 0 || $entranceTestScore > 100) {
            throw new \InvalidArgumentException('Student: entranceTestScore вне диапазона 0..100 (Q-4): ' . $entranceTestScore);
        }

        $this->id = $id;
        $this->groupId = $groupId;
        $this->fullName = $fullName;
        $this->isTargetQuota = $isTargetQuota;
        $this->isPaid = $isPaid;
        $this->isDisabled = $isDisabled;
        $this->entranceExamsSum = $entranceExamsSum;
        $this->gpaSem12 = $gpaSem12;
        $this->gpaBasic = $gpaBasic;
        $this->entranceTestScore = $entranceTestScore;
    }
}
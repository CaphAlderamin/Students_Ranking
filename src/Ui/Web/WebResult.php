<?php

declare(strict_types=1);

namespace App\Ui\Web;

/**
 * Результат выполнения веб-контура (B4-04, план §2.2).
 *
 * Значения по умолчанию = «чистое» состояние: поля заполняются по мере прохождения
 * конвейера {@see WebWorkflow}. При блокировках (ошибки импорта B4-03 или нарушения
 * R-13) распределение и экспорт НЕ выполняются — archiveBytes/archiveName пусты,
 * assignedCount/totalStudents остаются 0 (политика §2.5: «всё или ничего»).
 *
 * Лоуз-типы на входе DTO (паттерн ImportedApplications, B4-03) здесь не нужны:
 * конвейер формирует результат из уже валидированных доменных данных.
 */
final readonly class WebResult
{
    /**
     * @param list<string> $errors           ошибки импорта файла: «файл: строка N: причина» (B4-03)
     * @param list<string> $validationErrors нарушения R-13 (ApplicationValidator, B2-04)
     * @param list<string> $exportFilePaths  пути созданных отчётных файлов (R-18, ADR-003)
     * @param int          $assignedCount    число распределённых студентов (счётчик final-назначений)
     * @param int          $totalStudents    контингент года приёма (R-01)
     * @param string|null  $archiveBytes     бинарное содержимое ZIP-архива (null — блокировка)
     * @param string|null  $archiveName      имя архива для Content-Disposition (null — блокировка)
     */
    public function __construct(
        public array $errors = [],
        public array $validationErrors = [],
        public array $exportFilePaths = [],
        public int $assignedCount = 0,
        public int $totalStudents = 0,
        public ?string $archiveBytes = null,
        public ?string $archiveName = null,
    ) {
    }
}
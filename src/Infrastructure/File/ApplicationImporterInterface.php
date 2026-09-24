<?php

declare(strict_types=1);

namespace App\Infrastructure\File;

/**
 * Контракт импорта исходных данных заявок (B4-03, R-12) — альтернатива ручному
 * вводу в веб-форме B4-04. Заголовок файла идентифицирует колонки: `student_id`,
 * `module_id`, `priority` (порядок не важен).
 *
 * Невалидная строка не валит файл целиком: фиксируется в `ImportedApplications::errors`
 * форматом «файл: строка N: причина» (критерий ветки). Глубокие бизнес-проверки
 * (R-13, окно R-12) выполняет потребитель — `ApplicationValidator` (B2-04) на B4-04.
 */
interface ApplicationImporterInterface
{
    /** @return ImportedApplications результат импорта: доменные заявки + читаемые ошибки */
    public function import(string $filePath): ImportedApplications;
}
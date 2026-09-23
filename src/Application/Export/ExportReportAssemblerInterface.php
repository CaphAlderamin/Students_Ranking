<?php

declare(strict_types=1);

namespace App\Application\Export;

use App\Domain\Model\DistributionResult;

/**
 * Собиратель отчёта экспорта (B4-01): преобразует результат распределения
 * в пакеты файлов по школам-организаторам (ADR-001, колонки R-18).
 *
 * OCP-шов для B4-02: формат-классы пишут пакеты как есть; бизнес-логика отчёта
 * (группировка по модулю, разворачивание на дисциплины {3,4,5}, заголовок файла)
 * реализуется в B4-02 без правки классов B4-01.
 */
interface ExportReportAssemblerInterface
{
    /** @return list<ExportReportFile> отчётные файлы по школам-организаторам (ADR-001, R-18) */
    public function assemble(DistributionResult $result): array;
}
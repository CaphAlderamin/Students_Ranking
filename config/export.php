<?php

declare(strict_types=1);

/**
 * Конфигурация экспорта (B4-01, ADR-003): дефолтный формат и каталог вывода.
 *
 * Дефолт формата используется при сборке ExporterFactory на этапе связки (CLI/веб);
 * CLI-флаг --format (B4-05) перекрывает значение. Имена файлов — `{school_code}_{academic_year}.{ext}`
 * (ADR-003) в каталоге вывода. Файл не содержит секретов и трекается git (по образцу config/ranking.php).
 */
return [
    'format' => 'csv',
    'directory' => 'var/export/',
];
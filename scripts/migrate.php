<?php

declare(strict_types=1);

/**
 * Раннер SQL-миграций (test assignment: Миграции SQL по схеме, B1-05).
 *
 * Запуск: вызывается из scripts/migrate.sh внутри php-контейнера
 * (docker compose exec -T php sh /app/scripts/migrate.sh).
 *
 * ОГРАНИЧЕНИЕ: миграции — только простые DDL-операторы (CREATE TABLE ...),
 * разделённые ';' в конце строки. В операторах не допускаются ';' внутри
 * строковых литералов и процедурные конструкции (DELIMITER, BEGIN...END).
 *
 * Поведение:
 *  - журнал schema_migrations создаёт сам раннер при старте
 *    (CREATE TABLE IF NOT EXISTS), миграционные файлы его не содержат;
 *  - файлы database/migrations/*.sql применяются в отсортированном порядке;
 *  - каждый файл применяется пооператорно (без MYSQL_ATTR_MULTI_STATEMENTS);
 *  - уже применённый файл пропускается; расхождение SHA-256 содержимого —
 *    ошибка (конфликт версии), журнал не записывается и файл не применяется.
 *
 * @see database/schema.dbml — источник истины для миграций.
 */

$root = dirname(__DIR__);
$migrationsDir = $root . '/database/migrations';

$requiredEnv = ['MYSQL_HOST', 'MYSQL_DATABASE', 'MYSQL_USER', 'MYSQL_PASSWORD'];
foreach ($requiredEnv as $key) {
    env($key);
}

$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=utf8mb4',
    env('MYSQL_HOST'),
    env('MYSQL_DATABASE'),
);

try {
    $pdo = new PDO($dsn, env('MYSQL_USER'), env('MYSQL_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, '[B1-05] подключение к MySQL не удалось: ' . $e->getMessage() . "\n");
    exit(1);
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(255) PRIMARY KEY,
        checksum CHAR(64) NOT NULL,
        applied_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
);

$selectApplied = $pdo->prepare('SELECT checksum FROM schema_migrations WHERE version = ?');
$insertApplied = $pdo->prepare('INSERT INTO schema_migrations (version, checksum, applied_at) VALUES (?, ?, ?)');

$migrationFiles = glob($migrationsDir . '/*.sql');
if ($migrationFiles === false || $migrationFiles === []) {
    fwrite(STDERR, '[B1-05] миграции не найдены: создайте database/migrations/*.sql' . "\n");
    exit(1);
}

sort($migrationFiles, SORT_STRING);

$applied = 0;
$skipped = 0;

foreach ($migrationFiles as $file) {
    $version = basename($file);
    $checksum = (string) hash_file('sha256', $file);

    $selectApplied->execute([$version]);
    /** @var array{checksum: string}|false $row */
    $row = $selectApplied->fetch(PDO::FETCH_ASSOC);

    if ($row !== false) {
        if (hash_equals((string) $row['checksum'], $checksum)) {
            ++$skipped;
            echo "[B1-05] уже применена, пропуск: {$version}\n";
            continue;
        }
        fwrite(
            STDERR,
            "[B1-05] конфликт версии: {$version} применена ранее, но SHA-256 содержимого не совпадает; "
            . 'измените файл в новой миграции или сбросьте журнал schema_migrations'
            . "\n"
        );
        exit(1);
    }

    $statements = splitStatements((string) file_get_contents($file));
    if ($statements === []) {
        fwrite(STDERR, "[B1-05] ошибка в {$version}: в файле нет ни одного SQL-оператора\n");
        exit(1);
    }

    foreach ($statements as $statement) {
        try {
            $pdo->exec($statement);
        } catch (PDOException $e) {
            fwrite(STDERR, "[B1-05] ошибка применения {$version}:\n");
            fwrite(STDERR, '  файл: ' . $file . "\n");
            fwrite(STDERR, '  оператор: ' . excerpt($statement) . "\n");
            fwrite(STDERR, '  причина: ' . $e->getMessage() . "\n");
            exit(1);
        }
    }

    $insertApplied->execute([$version, $checksum, date('Y-m-d H:i:s')]);
    ++$applied;
    echo "[B1-05] применена: {$version}\n";
}

echo "[B1-05] готово: применено {$applied}, пропущено {$skipped}\n";
exit(0);

/**
 * Читает обязательную переменную окружения; при отсутствии — ошибка и exit 1.
 *
 * @return non-empty-string значение переменной
 */
function env(string $key): string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        fwrite(STDERR, "[B1-05] ошибка конфигурации: отсутствует переменная окружения {$key}\n");
        exit(1);
    }
    return $value;
}

/**
 * Разбивает содержимое файла миграции на операторы по ';' в конце строки.
 * Строки-комментарии ('--'), пустые и концевые пробелы игнорируются.
 *
 * @return non-empty-list<string>|array{} массив операторов (может быть пустым)
 */
function splitStatements(string $sql): array
{
    $statements = [];
    $chunk = [];
    foreach (preg_split('/\r?\n/', $sql) ?: [] as $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            continue;
        }
        $chunk[] = $trimmed;
        if (str_ends_with($trimmed, ';')) {
            $statements[] = implode(' ', $chunk);
            $chunk = [];
        }
    }
    if ($chunk !== []) {
        throw new RuntimeException('файл миграции заканчивается неполным оператором');
    }
    return $statements;
}

/** Краткая форма оператора для сообщения об ошибке. */
function excerpt(string $statement): string
{
    $oneLine = preg_replace('/\s+/u', ' ', $statement) ?? $statement;
    return mb_strlen($oneLine) > 80 ? mb_substr($oneLine, 0, 77) . '...' : $oneLine;
}
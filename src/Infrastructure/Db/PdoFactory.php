<?php

declare(strict_types=1);

namespace App\Infrastructure\Db;

/**
 * Единая точка создания PDO-соединения с MySQL (B2-01).
 *
 * Параметры можно передать в конструкторе или прочитать из config/db.php
 * через fromConfigFile(). Используется тот же стек, что и в раннере миграций
 * B1-05 (scripts/migrate.php): драйвер pdo_mysql штатно обслуживает
 * caching_sha2_password MySQL 8.4, спец-опции не требуются.
 */
final readonly class PdoFactory
{
    public function __construct(
        private string $host,
        private string $database,
        private string $username,
        private string $password,
        private int $port = 3306,
        private string $charset = 'utf8mb4',
    ) {
    }

    /**
     * Создаёт новое PDO-соединение (ERRMODE_EXCEPTION).
     */
    public function create(): \PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->host,
            $this->port,
            $this->database,
            $this->charset,
        );

        return new \PDO($dsn, $this->username, $this->password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);
    }

    /**
     * Создаёт фабрику из php-файла конфигурации (config/db.php).
     *
     * Ожидаемая форма возвращаемого массива:
     * array{host: non-empty-string, port?: positive-int, database: non-empty-string,
     *      username: non-empty-string, password: non-empty-string, charset?: non-empty-string}
     *
     * @param string $path путь к файлу, который возвращает массив конфигурации
     *
     * @throws \InvalidArgumentException при невалидном содержимом конфигурации
     */
    public static function fromConfigFile(string $path): self
    {
        $config = require $path;

        if (!is_array($config)) {
            throw new \InvalidArgumentException('PdoFactory: config/db.php должен возвращать массив');
        }

        /** @var array<string, mixed> $config */
        $host = self::requiredString($config['host'] ?? null, 'host');
        $database = self::requiredString($config['database'] ?? null, 'database');
        $username = self::requiredString($config['username'] ?? null, 'username');
        $password = self::requiredString($config['password'] ?? null, 'password');
        $charset = self::requiredString($config['charset'] ?? 'utf8mb4', 'charset');

        $port = $config['port'] ?? 3306;
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new \InvalidArgumentException(
                'PdoFactory: port должен быть int в диапазоне 1..65535, получено: ' . var_export($port, true),
            );
        }

        return new self($host, $database, $username, $password, $port, $charset);
    }

    /**
     * Валидирует ключ конфигурации: обязан быть непустой строкой.
     *
     * @return non-empty-string
     */
    private static function requiredString(mixed $value, string $key): string
    {
        if (!is_string($value) || $value === '') {
            throw new \InvalidArgumentException('PdoFactory: невалидный ключ конфигурации: ' . $key);
        }

        return $value;
    }
}
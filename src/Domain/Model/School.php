<?php

declare(strict_types=1);

namespace App\Domain\Model;

/**
 * Инженерная школа (справочник, таблица `schools`).
 */
final readonly class School
{
    public int $id;
    public string $code;
    public string $name;

    /**
     * @param int    $id   первичный ключ (> 0)
     * @param string $code короткий код школы (≤ 16, varchar(16))
     * @param string $name название школы
     */
    public function __construct(int $id, string $code, string $name)
    {
        if ($id < 1) {
            throw new \InvalidArgumentException('School: id должен быть положительным числом, получено: ' . $id);
        }
        $code = trim($code);
        if ($code === '') {
            throw new \InvalidArgumentException('School: code не может быть пустым');
        }
        if (mb_strlen($code) > 16) {
            throw new \InvalidArgumentException('School: code длиннее 16 символов (varchar(16)): ' . $code);
        }
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('School: name не может быть пустым');
        }

        $this->id = $id;
        $this->code = $code;
        $this->name = $name;
    }
}
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ui\Cli;

/**
 * Фейковое PDO-соединение (B3-05, юнит-тесты команды): не соединяется с БД,
 * prepare() отдаёт строки для readGroups()/readEligibleSchools(), exec()
 * перехватывает DELETE (план §2.2/§2.7 — сырой PDO вне контрактов).
 *
 * Свойства groupRows/eligibleRows заполняются тестом; execCalls фиксирует
 * удаления для проверки замены при повторном запуске.
 */
final class FakePdo extends \PDO
{
    /** @var list<array<string, int|string|null>> */
    public array $groupRows = [];

    /** @var list<array<string, int|string>> */
    public array $eligibleRows = [];

    /** @var list<string> */
    public array $execCalls = [];

    /** @phpstan-ignore-next-line constructor.missingParentCall (нет соединения — фиктивная реализация) */
    public function __construct()
    {
    }

    /**
     * @param array<string, mixed> $options
     *
     * @phpstan-ignore-next-line return.unusedType (иначе PDOStatement|false — несовместимость с переопределением)
     */
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        return new FakeStatement(query: $query, fake: $this);
    }

    /** @phpstan-ignore-next-line return.unusedType (метод фиктивного PDO) */
    public function exec(string $statement): int|false
    {
        $this->execCalls[] = $statement;

        return 0;
    }
}
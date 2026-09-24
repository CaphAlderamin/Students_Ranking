<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ui\Cli;

/**
 * Фейковый PDOStatement (B3-05, B4-05): execute() без БД, fetchAll() отдаёт
 * предзаданные строки — для readGroups()/readEligibleSchools()/readSchoolCodes().
 */
final class FakeStatement extends \PDOStatement
{
    /** @var list<array<string, int|string|null>> */
    private array $rows = [];

    /**
     * @param string $query SQL запрос (маршрутизация строк)
     */
    public function __construct(string $query, FakePdo $fake)
    {
        if (str_contains($query, 'student_groups')) {
            $this->rows = $fake->groupRows;
        }
        if (str_contains($query, 'module_eligible_schools')) {
            $this->rows = array_map(
                static fn (array $row): array => array_map(
                    static fn (int|string $value): int|string => $value,
                    $row,
                ),
                $fake->eligibleRows,
            );
        }
        if (str_contains($query, 'FROM schools')) {
            $this->rows = $fake->schoolRows;
        }
    }

    /** @param array<int|string, mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        return true;
    }

    /**
     * @return list<array<string, int|string|null>>
     */
    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }
}
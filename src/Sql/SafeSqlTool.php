<?php

declare(strict_types=1);

namespace SamirMhsnv\LaravelAgent\Sql;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;
use SamirMhsnv\LaravelAgent\Contracts\Tool;
use SamirMhsnv\LaravelAgent\Exceptions\ToolException;
use SamirMhsnv\LaravelAgent\Exceptions\UnsafeQueryException;
use Throwable;

/**
 * Lets the model answer questions from your database with read-only SQL.
 *
 * Every query is validated (see SqlValidator), wrapped so a row limit is
 * always applied, and run inside a transaction that is rolled back.
 *
 *     'sql' => [
 *         'connection' => 'reporting',      // ideally a SELECT-only database user
 *         'tables' => ['orders', 'products'],
 *         'max_rows' => 100,
 *     ],
 */
class SafeSqlTool implements Tool
{
    private readonly SqlValidator $validator;

    /**
     * @param  list<string>  $tables  Allowlisted table names (optionally schema-qualified).
     */
    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly array $tables,
        private readonly ?string $connection = null,
        private readonly int $maxRows = 100,
        int $maxQueryLength = 4000,
        private readonly bool $describeSchema = true,
        private readonly bool $useTransaction = true,
    ) {
        $this->validator = new SqlValidator($tables, $maxQueryLength);
    }

    /**
     * @param  array<string, mixed>  $config  The "agent.sql" config array.
     */
    public static function fromConfig(ConnectionResolverInterface $db, array $config): self
    {
        return new self(
            $db,
            array_values($config['tables'] ?? []),
            $config['connection'] ?? null,
            (int) ($config['max_rows'] ?? 100),
            (int) ($config['max_query_length'] ?? 4000),
            (bool) ($config['describe_schema'] ?? true),
            (bool) ($config['transaction'] ?? true),
        );
    }

    public function name(): string
    {
        return 'query_database';
    }

    public function description(): string
    {
        $description = 'Run a single read-only SQL SELECT query against the application database and get the rows back as JSON. '
            ."Only these tables can be queried: {$this->tableList()}. "
            ."Results are capped at {$this->maxRows} rows, so aggregate (COUNT, SUM, GROUP BY) instead of fetching everything. "
            .'Give every selected column a unique name (alias duplicates such as two id columns). '
            .'Comments, multiple statements and anything that writes data are rejected.';

        $dialect = $this->connection()->getDriverName();
        $description .= " SQL dialect: {$dialect}.";

        if ($this->describeSchema && ($schema = $this->schemaSummary()) !== '') {
            $description .= "\n\nSchema:\n{$schema}";
        }

        return $description;
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sql' => [
                    'type' => 'string',
                    'description' => 'One SELECT statement. No comments, no semicolon-separated statements.',
                ],
            ],
            'required' => ['sql'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, row_count: int, truncated: bool}
     *
     * @throws UnsafeQueryException when the query is not allowed
     * @throws ToolException when the database reports an error
     */
    public function execute(array $arguments): array
    {
        $sql = $arguments['sql'] ?? null;

        if (! is_string($sql)) {
            throw UnsafeQueryException::because('the "sql" argument must be a string.');
        }

        return $this->query($sql);
    }

    /**
     * Validate and run a query. Usable outside the agent too.
     *
     * @return array{columns: list<string>, rows: list<array<string, mixed>>, row_count: int, truncated: bool}
     */
    public function query(string $sql): array
    {
        $sql = $this->validator->validate($sql);

        // Wrapping guarantees the limit no matter what LIMIT the query has (or lacks).
        $limit = $this->maxRows + 1;
        $wrapped = "SELECT * FROM ({$sql}) AS agent_query LIMIT {$limit}";

        $connection = $this->connection();

        try {
            $rows = $this->useTransaction
                ? $this->inRolledBackTransaction($connection, fn () => $connection->select($wrapped))
                : $connection->select($wrapped);
        } catch (QueryException $e) {
            // Show the database's own message (useful for the model to fix its SQL), not the bindings.
            throw new ToolException('The database rejected the query: '.($e->getPrevious()?->getMessage() ?? $e->getMessage()));
        }

        $rows = array_map(fn ($row) => (array) $row, $rows);
        $truncated = count($rows) > $this->maxRows;
        $rows = array_slice($rows, 0, $this->maxRows);

        return [
            'columns' => $rows === [] ? [] : array_keys($rows[0]),
            'rows' => $rows,
            'row_count' => count($rows),
            'truncated' => $truncated,
        ];
    }

    public function validator(): SqlValidator
    {
        return $this->validator;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function inRolledBackTransaction(ConnectionInterface $connection, callable $callback): mixed
    {
        $connection->beginTransaction();

        try {
            return $callback();
        } finally {
            $connection->rollBack();
        }
    }

    private function connection(): ConnectionInterface
    {
        return $this->db->connection($this->connection);
    }

    private function tableList(): string
    {
        return $this->tables === [] ? '(none configured)' : implode(', ', $this->tables);
    }

    private function schemaSummary(): string
    {
        $lines = [];

        foreach ($this->tables as $table) {
            try {
                $columns = $this->connection()->getSchemaBuilder()->getColumns($table);
            } catch (Throwable) {
                continue;
            }

            $columns = array_map(fn (array $c) => "{$c['name']} {$c['type_name']}", $columns);

            if ($columns !== []) {
                $lines[] = "- {$table}(".implode(', ', $columns).')';
            }
        }

        return implode("\n", $lines);
    }
}

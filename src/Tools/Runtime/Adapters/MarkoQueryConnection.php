<?php

declare(strict_types=1);

namespace Marko\Mcp\Tools\Runtime\Adapters;

use Marko\Database\Connection\ConnectionInterface;
use Marko\Mcp\Exceptions\McpException;

/**
 * Wraps marko/database ConnectionInterface for the query_database tool so it can run queries against the live
 * app database.
 *
 * Reads go through readOnlyQuery(), which runs the statement inside a read-only transaction and always rolls it
 * back, so the database itself refuses a write the tool's SQL check missed. The transaction statements go through
 * execute() so a read/write-split connection keeps the whole sequence on one (the write) connection.
 */
readonly class MarkoQueryConnection
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    /**
     * Run a statement inside a read-only transaction that is always rolled back.
     *
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     * @throws McpException When the driver has no read-only transaction support
     */
    public function readOnlyQuery(
        string $sql,
        array $params = [],
    ): array {
        [$begin, $end] = $this->readOnlyTransactionStatements($this->connection->driverName());

        foreach ($begin as $statement) {
            $this->connection->execute($statement);
        }

        try {
            return array_values($this->connection->query($sql, $params));
        } finally {
            foreach ($end as $statement) {
                $this->connection->execute($statement);
            }
        }
    }

    /**
     * Run a statement with no read-only protection. query_database only calls this for a write the server
     * config (mcp.database.allow_writes) has enabled.
     *
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function query(
        string $sql,
        array $params = [],
    ): array {
        return array_values($this->connection->query($sql, $params));
    }

    /**
     * @return array{list<string>, list<string>} The statements that open and close the read-only transaction
     * @throws McpException When the driver has no read-only transaction support
     */
    private function readOnlyTransactionStatements(
        string $driver,
    ): array {
        return match ($driver) {
            'mysql', 'mariadb' => [['START TRANSACTION READ ONLY'], ['ROLLBACK']],
            'pgsql' => [['BEGIN READ ONLY'], ['ROLLBACK']],
            // SQLite has no read-only transaction mode; query_only makes the connection refuse writes instead.
            'sqlite' => [['PRAGMA query_only = ON', 'BEGIN'], ['ROLLBACK', 'PRAGMA query_only = OFF']],
            default => throw McpException::readOnlyUnsupportedDriver($driver),
        };
    }
}

<?php

declare(strict_types=1);

namespace Marko\Mcp\Tests\Fixtures;

use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Connection\StatementInterface;
use RuntimeException;
use Throwable;

/**
 * A ConnectionInterface that records every statement it receives, in order, as "query: <sql>" or
 * "execute: <sql>", so tests can assert the read-only transaction wrapped around a query.
 */
class RecordingConnection implements ConnectionInterface
{
    /** @var list<string> */
    public array $statements = [];

    /**
     * @param list<array<string, mixed>> $rows Rows every query() returns
     * @param Throwable|null $queryFailure Thrown by query() after it is recorded
     */
    public function __construct(
        private string $driver = 'pgsql',
        private array $rows = [],
        private ?Throwable $queryFailure = null,
    ) {}

    public function connect(): void {}

    public function disconnect(): void {}

    public function isConnected(): bool
    {
        return true;
    }

    public function query(
        string $sql,
        array $bindings = [],
    ): array {
        $this->statements[] = "query: $sql";

        if ($this->queryFailure !== null) {
            throw $this->queryFailure;
        }

        return $this->rows;
    }

    public function execute(
        string $sql,
        array $bindings = [],
    ): int {
        $this->statements[] = "execute: $sql";

        return 0;
    }

    public function prepare(
        string $sql,
    ): StatementInterface {
        throw new RuntimeException('RecordingConnection does not support prepare()');
    }

    public function lastInsertId(): int
    {
        return 0;
    }

    public function driverName(): string
    {
        return $this->driver;
    }

    public function supportsReturning(): bool
    {
        return false;
    }

    public function quoteIdentifier(
        string $identifier,
    ): string {
        return '"' . $identifier . '"';
    }
}

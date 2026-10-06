<?php

declare(strict_types=1);

use Marko\Database\Connection\ConnectionInterface;
use Marko\Database\Exceptions\QueryException;
use Marko\Database\MySql\Connection\MySqlConnection;
use Marko\Database\MySql\Tests\Fixtures\IntegrationDatabase as MySqlIntegrationDatabase;
use Marko\Database\PgSql\Connection\PgSqlConnection;
use Marko\Database\PgSql\Tests\Fixtures\IntegrationDatabase as PgSqlIntegrationDatabase;
use Marko\Mcp\Tools\Runtime\Adapters\MarkoQueryConnection;

/*
 * MarkoQueryConnection::readOnlyQuery() against real PostgreSQL and MySQL servers (#378): the database itself must
 * refuse a write that reaches it through the query_database read path, and the read-only transaction must be closed
 * afterwards so the connection is usable again. Settings come from the database-pgsql and database-mysql
 * IntegrationDatabase fixtures (MARKO_TEST_PGSQL_* / MARKO_TEST_MYSQL_*); each case skips without its host and fails
 * instead with MARKO_INTEGRATION_REQUIRED set. The tests create and drop the mcp_read_only_probe table.
 */

pest()->group('integration-services');

/**
 * A connection to the given driver's test server with a fresh one-row mcp_read_only_probe table, or a skip reason.
 *
 * @return array{connection: ?ConnectionInterface, skipReason: ?string}
 */
function readOnlyProbeConnection(
    string $driver,
): array {
    if ($driver === 'pgsql') {
        $config = PgSqlIntegrationDatabase::config();

        if ($config === null) {
            return ['connection' => null, 'skipReason' => PgSqlIntegrationDatabase::SKIP_REASON];
        }

        $connection = new PgSqlConnection($config);
    } else {
        $config = MySqlIntegrationDatabase::config();

        if ($config === null) {
            return ['connection' => null, 'skipReason' => MySqlIntegrationDatabase::SKIP_REASON];
        }

        $connection = new MySqlConnection($config);
    }

    $connection->execute('DROP TABLE IF EXISTS mcp_read_only_probe');
    $connection->execute('CREATE TABLE mcp_read_only_probe (id INT PRIMARY KEY, name VARCHAR(50))');
    $connection->execute("INSERT INTO mcp_read_only_probe (id, name) VALUES (1, 'Alice')");

    return ['connection' => $connection, 'skipReason' => null];
}

function readOnlyProbeRowCount(
    ConnectionInterface $connection,
): int {
    return (int) $connection->query('SELECT COUNT(*) AS total FROM mcp_read_only_probe')[0]['total'];
}

beforeEach(function (): void {
    unset($this->connection);
});

afterEach(function (): void {
    if (isset($this->connection)) {
        $this->connection->execute('DROP TABLE IF EXISTS mcp_read_only_probe');
        $this->connection->disconnect();
    }
});

dataset('read-only drivers', ['pgsql', 'mysql']);

it('reads rows inside the read-only transaction', function (string $driver): void {
    ['connection' => $connection, 'skipReason' => $skipReason] = readOnlyProbeConnection($driver);

    if ($connection === null) {
        $this->markTestSkipped((string) $skipReason);
    }

    $this->connection = $connection;

    $rows = new MarkoQueryConnection($connection)->readOnlyQuery('SELECT id, name FROM mcp_read_only_probe');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('Alice');
})->with('read-only drivers')->issue(378);

it('has the database refuse a write sent through the read path', function (string $driver): void {
    ['connection' => $connection, 'skipReason' => $skipReason] = readOnlyProbeConnection($driver);

    if ($connection === null) {
        $this->markTestSkipped((string) $skipReason);
    }

    $this->connection = $connection;
    $readOnly = new MarkoQueryConnection($connection);

    expect(fn () => $readOnly->readOnlyQuery('DELETE FROM mcp_read_only_probe'))->toThrow(QueryException::class)
        ->and(readOnlyProbeRowCount($connection))->toBe(1);

    // The transaction was rolled back, so the connection writes normally again.
    $connection->execute("INSERT INTO mcp_read_only_probe (id, name) VALUES (2, 'Bob')");

    expect(readOnlyProbeRowCount($connection))->toBe(2);
})->with('read-only drivers')->issue(378);

it('has PostgreSQL refuse a data-modifying CTE sent through the read path', function (): void {
    ['connection' => $connection, 'skipReason' => $skipReason] = readOnlyProbeConnection('pgsql');

    if ($connection === null) {
        $this->markTestSkipped((string) $skipReason);
    }

    $this->connection = $connection;

    expect(fn () => new MarkoQueryConnection($connection)->readOnlyQuery(
        'WITH d AS (DELETE FROM mcp_read_only_probe RETURNING *) SELECT * FROM d',
    ))->toThrow(QueryException::class)
        ->and(readOnlyProbeRowCount($connection))->toBe(1);
})->issue(378);

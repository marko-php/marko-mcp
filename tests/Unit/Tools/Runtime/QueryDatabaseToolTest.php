<?php

declare(strict_types=1);

use Marko\Mcp\Tests\Fixtures\RecordingConnection;
use Marko\Mcp\Tools\Runtime\Adapters\MarkoQueryConnection;
use Marko\Mcp\Tools\Runtime\QueryDatabaseTool;

/**
 * @param list<array<string, mixed>> $rows
 * @return array{tool: QueryDatabaseTool, connection: RecordingConnection}
 */
function makeQueryTool(
    array $rows = [],
    bool $writesEnabled = false,
    string $driver = 'pgsql',
    ?Throwable $queryFailure = null,
): array {
    $connection = new RecordingConnection($driver, $rows, $queryFailure);
    $tool = QueryDatabaseTool::definition(new MarkoQueryConnection($connection), $writesEnabled)->handler;

    return ['tool' => $tool, 'connection' => $connection];
}

it('registers the query_database tool and runs a SELECT', function (): void {
    $connection = new RecordingConnection(rows: [['id' => 1, 'name' => 'Alice']]);

    $definition = QueryDatabaseTool::definition(new MarkoQueryConnection($connection));
    $result = $definition->handler->handle(['sql' => 'SELECT * FROM users']);

    expect($definition->name)->toBe('query_database')
        ->and($result['content'][0]['text'])->toContain('Alice')
        ->and($result['isError'] ?? false)->toBeFalse();
});

it('runs every read inside a read-only transaction that is always rolled back', function (): void {
    ['tool' => $tool, 'connection' => $connection] = makeQueryTool([['id' => 1]]);

    $tool->handle(['sql' => 'SELECT * FROM users']);

    expect($connection->statements)->toBe([
        'execute: BEGIN READ ONLY',
        'query: SELECT * FROM users',
        'execute: ROLLBACK',
    ]);
});

it('rolls the read-only transaction back when the read fails', function (): void {
    ['tool' => $tool, 'connection' => $connection] = makeQueryTool(
        queryFailure: new RuntimeException('cannot execute DELETE in a read-only transaction'),
    );

    $result = $tool->handle(['sql' => 'SELECT 1']);

    expect($result['isError'])->toBeTrue()
        ->and($result['content'][0]['text'])->toContain('read-only transaction')
        ->and($connection->statements)->toBe([
            'execute: BEGIN READ ONLY',
            'query: SELECT 1',
            'execute: ROLLBACK',
        ]);
});

it('enforces a SELECT/WITH/SHOW/EXPLAIN/DESCRIBE prefix allowlist', function (): void {
    ['tool' => $tool, 'connection' => $connection] = makeQueryTool();

    $result = $tool->handle(['sql' => 'INSERT INTO users VALUES (1)']);

    expect($result['content'][0]['text'])->toContain('not permitted')
        ->and($result['isError'] ?? false)->toBeTrue()
        ->and($connection->statements)->toBe([]);
});

it('rejects the read-only bypass payloads from issue #378 without sending them to the database', function (
    string $sql,
): void {
    ['tool' => $tool, 'connection' => $connection] = makeQueryTool();

    $result = $tool->handle(['sql' => $sql]);

    expect($result['isError'] ?? false)->toBeTrue()
        ->and($result['content'][0]['text'])->toContain('not permitted')
        ->and($connection->statements)->toBe([]);
})->with([
    'semicolon hidden by a quote inside a block comment' => "SELECT 1 /* ' */ ; DELETE FROM users; /* ' */",
    'semicolon hidden by a quote inside a line comment' => "SELECT 1 -- '\n; DROP TABLE users; -- '",
    'semicolon hidden by a quote inside a hash comment' => "SELECT 1 # '\n; DROP TABLE users; # '",
    'data-modifying CTE (DELETE)' => 'WITH d AS (DELETE FROM users RETURNING *) SELECT * FROM d',
    'data-modifying CTE (INSERT)' => "WITH i AS (INSERT INTO users (name) VALUES ('x') RETURNING *) SELECT * FROM i",
    'data-modifying CTE (UPDATE)' => "WITH u AS (UPDATE users SET name = 'x' RETURNING *) SELECT * FROM u",
    'data-modifying CTE (MERGE)' => 'WITH m AS (MERGE INTO users USING src ON true WHEN MATCHED THEN DELETE) SELECT 1',
    'SELECT INTO OUTFILE' => "SELECT * FROM users INTO OUTFILE '/tmp/users.csv'",
    'EXPLAIN ANALYZE of a DELETE' => 'EXPLAIN ANALYZE DELETE FROM users',
    'MySQL executable comment' => 'SELECT 1 /*!50000 ; DELETE FROM users */',
    'MySQL double-dash without a space is not a comment' => 'SELECT 1 --1; DELETE FROM users',
    'MySQL backslash-escaped quote' => "SELECT 'a\\'' ; DELETE FROM users; -- '",
    'PostgreSQL dollar-quoted string' => "SELECT \$\$'\$\$; DELETE FROM users; -- '",
    'MySQL backtick identifier' => "SELECT 1 AS `'`; DELETE FROM users; -- '",
    'unterminated block comment' => 'SELECT 1 /* ; DELETE FROM users',
    'unterminated string' => "SELECT 'abc; DELETE FROM users",
    'side-effecting dblink_exec' => "SELECT dblink_exec('host=x', 'DELETE FROM users')",
    'quoted side-effecting function name' => "SELECT \"pg_read_file\"('/etc/passwd')",
    'setval' => "SELECT setval('users_id_seq', 1)",
    'leading comment hiding the real statement' => '/* SELECT */ DELETE FROM users',
]);

it('rejects a stacked statement that starts with SELECT', function (): void {
    ['tool' => $tool] = makeQueryTool();

    $result = $tool->handle(['sql' => 'SELECT 1; DELETE FROM users']);

    expect($result['isError'] ?? false)->toBeTrue()
        ->and($result['content'][0]['text'])->toContain('not permitted');
});

it('allows a single SELECT statement with a trailing semicolon', function (): void {
    ['tool' => $tool] = makeQueryTool([['id' => 1]]);

    $result = $tool->handle(['sql' => 'SELECT 1;']);

    expect($result['isError'] ?? false)->toBeFalse()
        ->and($result['content'][0]['text'])->toContain('id');
});

it('does not treat a semicolon or keyword inside a string literal as a statement separator', function (): void {
    ['tool' => $tool] = makeQueryTool([['val' => 'hello; delete world']]);

    $result = $tool->handle(['sql' => "SELECT 'hello; delete world' AS val -- a trailing comment"]);

    expect($result['isError'] ?? false)->toBeFalse()
        ->and($result['content'][0]['text'])->toContain('val');
});

it('allows read-only CTEs and columns whose names only contain a write keyword', function (): void {
    ['tool' => $tool] = makeQueryTool([['id' => 1]]);

    $result = $tool->handle([
        'sql' => 'WITH recent AS (SELECT id, updated_at, deleted_at FROM users) SELECT * FROM recent',
    ]);

    expect($result['isError'] ?? false)->toBeFalse();
});

it('rejects writes when the server config does not enable them, even with allowWrite and confirm', function (): void {
    ['tool' => $tool, 'connection' => $connection] = makeQueryTool([['affected' => 1]]);

    $result = $tool->handle([
        'sql' => 'DELETE FROM users WHERE id = 1',
        'allowWrite' => true,
        'confirm' => true,
    ]);

    expect($result['isError'] ?? false)->toBeTrue()
        ->and($result['content'][0]['text'])->toContain('mcp.database.allow_writes')
        ->and($result['content'][0]['text'])->toContain('MCP_ALLOW_WRITES')
        ->and($connection->statements)->toBe([]);
});

it('does not advertise allowWrite or confirm when writes are disabled', function (): void {
    $definition = QueryDatabaseTool::definition(new MarkoQueryConnection(new RecordingConnection()));

    expect($definition->inputSchema['properties'])->not->toHaveKeys(['allowWrite', 'confirm'])
        ->and($definition->description)->toContain('Read-only');
});

it('advertises allowWrite and confirm when writes are enabled by the server config', function (): void {
    $definition = QueryDatabaseTool::definition(new MarkoQueryConnection(new RecordingConnection()), true);

    expect($definition->inputSchema['properties'])->toHaveKeys(['allowWrite', 'confirm']);
});

it('requires confirm when writes are enabled and allowWrite is set', function (): void {
    ['tool' => $tool, 'connection' => $connection] = makeQueryTool(writesEnabled: true);

    $result = $tool->handle(['sql' => 'DELETE FROM users WHERE id = 1', 'allowWrite' => true]);

    expect($result['isError'] ?? false)->toBeTrue()
        ->and($result['content'][0]['text'])->toContain('confirm=true')
        ->and($connection->statements)->toBe([]);
});

it('still runs reads read-only when writes are enabled but allowWrite is not set', function (): void {
    ['tool' => $tool, 'connection' => $connection] = makeQueryTool(writesEnabled: true);

    $result = $tool->handle(['sql' => 'DELETE FROM users WHERE id = 1']);

    expect($result['isError'] ?? false)->toBeTrue()
        ->and($result['content'][0]['text'])->toContain('not permitted')
        ->and($connection->statements)->toBe([]);
});

it('runs a write unguarded with a loud warning when config and both flags allow it', function (): void {
    ['tool' => $tool, 'connection' => $connection] = makeQueryTool([['affected' => 1]], writesEnabled: true);

    $result = $tool->handle([
        'sql' => 'DELETE FROM users WHERE id = 1',
        'allowWrite' => true,
        'confirm' => true,
    ]);

    expect($result['isError'] ?? false)->toBeFalse()
        ->and($result['content'][0]['text'])->toContain('WRITE OPERATION')
        ->and($result['content'][0]['text'])->toContain('affected')
        ->and($connection->statements)->toBe(['query: DELETE FROM users WHERE id = 1']);
});

it('propagates database errors from a write as an error result', function (): void {
    ['tool' => $tool] = makeQueryTool(
        writesEnabled: true,
        queryFailure: new RuntimeException('duplicate key'),
    );

    $result = $tool->handle([
        'sql' => 'INSERT INTO users VALUES (1)',
        'allowWrite' => true,
        'confirm' => true,
    ]);

    expect($result['content'][0]['text'])->toContain('ERROR: duplicate key')
        ->and($result['isError'] ?? false)->toBeTrue();
});

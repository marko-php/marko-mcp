<?php

declare(strict_types=1);

use Marko\Mcp\Exceptions\McpException;
use Marko\Mcp\Tests\Fixtures\RecordingConnection;
use Marko\Mcp\Tools\Runtime\Adapters\MarkoQueryConnection;

it('wraps a MySQL read in START TRANSACTION READ ONLY and rolls it back', function (): void {
    $connection = new RecordingConnection('mysql', [['id' => 1]]);

    $rows = (new MarkoQueryConnection($connection))->readOnlyQuery('SELECT id FROM users');

    expect($rows)->toBe([['id' => 1]])
        ->and($connection->statements)->toBe([
            'execute: START TRANSACTION READ ONLY',
            'query: SELECT id FROM users',
            'execute: ROLLBACK',
        ]);
});

it('wraps a PostgreSQL read in BEGIN READ ONLY and rolls it back', function (): void {
    $connection = new RecordingConnection('pgsql');

    (new MarkoQueryConnection($connection))->readOnlyQuery('SELECT 1');

    expect($connection->statements)->toBe([
        'execute: BEGIN READ ONLY',
        'query: SELECT 1',
        'execute: ROLLBACK',
    ]);
});

it('wraps a SQLite read in PRAGMA query_only and a rolled-back transaction', function (): void {
    $connection = new RecordingConnection('sqlite');

    (new MarkoQueryConnection($connection))->readOnlyQuery('SELECT 1');

    expect($connection->statements)->toBe([
        'execute: PRAGMA query_only = ON',
        'execute: BEGIN',
        'query: SELECT 1',
        'execute: ROLLBACK',
        'execute: PRAGMA query_only = OFF',
    ]);
});

it('rolls back even when the read throws', function (): void {
    $connection = new RecordingConnection('mysql', queryFailure: new RuntimeException('read-only transaction'));

    expect(fn () => (new MarkoQueryConnection($connection))->readOnlyQuery('SELECT 1'))
        ->toThrow(RuntimeException::class, 'read-only transaction')
        ->and($connection->statements)->toBe([
            'execute: START TRANSACTION READ ONLY',
            'query: SELECT 1',
            'execute: ROLLBACK',
        ]);
});

it('refuses to run a read on a driver it cannot make read-only', function (): void {
    $connection = new RecordingConnection('oracle');

    expect(fn () => (new MarkoQueryConnection($connection))->readOnlyQuery('SELECT 1'))
        ->toThrow(McpException::class, "driver 'oracle'")
        ->and($connection->statements)->toBe([]);
});

it('runs a write through the plain connection without a transaction', function (): void {
    $connection = new RecordingConnection('pgsql', [['affected' => 1]]);

    $rows = (new MarkoQueryConnection($connection))->query('DELETE FROM users');

    expect($rows)->toBe([['affected' => 1]])
        ->and($connection->statements)->toBe(['query: DELETE FROM users']);
});

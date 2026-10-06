<?php

declare(strict_types=1);

use Marko\Config\ConfigRepositoryInterface;
use Marko\Core\Command\CommandDefinition;
use Marko\Core\Command\CommandRegistry;
use Marko\Core\Container\BindingRegistry;
use Marko\Core\Container\Container;
use Marko\Core\Container\ContainerInterface;
use Marko\Core\Module\ManifestParser;
use Marko\Core\Path\ProjectPaths;
use Marko\Database\Connection\ConnectionInterface;
use Marko\Mcp\Commands\ServeCommand;
use Marko\Mcp\Server\McpServer;
use Marko\Mcp\Tests\Fixtures\RecordingConnection;
use Marko\Mcp\Tools\ToolDefinition;
use Marko\Testing\Fake\FakeConfigRepository;

/**
 * The mcp config keys the module reads, with the shipped defaults unless overridden.
 *
 * @param array<string, mixed> $overrides
 */
function mcpConfig(
    array $overrides = [],
): FakeConfigRepository {
    return new FakeConfigRepository([
        'mcp.allow_production' => false,
        'mcp.console.allowed_commands' => ['route:list', 'module:list'],
        'mcp.console.allow_destructive' => false,
        'mcp.database.allow_writes' => false,
        ...$overrides,
    ]);
}

function bootMcpContainer(): Container
{
    $container = new Container();
    $container->instance(ContainerInterface::class, $container);
    $container->instance(ProjectPaths::class, new ProjectPaths(sys_get_temp_dir()));
    $container->instance(ConfigRepositoryInterface::class, mcpConfig());

    $parser = new ManifestParser();
    $registry = new BindingRegistry($container);

    $registry->registerModule($parser->parse(dirname(__DIR__, 3) . '/codeindexer'));
    $registry->registerModule($parser->parse(dirname(__DIR__, 2)));

    return $container;
}

it('resolves the mcp:serve command through the container', function (): void {
    $container = bootMcpContainer();

    expect($container->get(ServeCommand::class))
        ->toBeInstanceOf(ServeCommand::class);
});

it('resolves McpServer with all autowireable tools registered', function (): void {
    $container = bootMcpContainer();

    $server = $container->get(McpServer::class);

    expect($server)->toBeInstanceOf(McpServer::class);

    $reflection = new ReflectionClass($server);
    $toolsProp = $reflection->getProperty('tools');
    $tools = $toolsProp->getValue($server);

    expect($tools)->toBeArray()
        ->and(array_keys($tools))->toContain(
            'check_config_key',
            'find_event_observers',
            'find_plugins_targeting',
            'get_config_schema',
            'list_commands',
            'list_modules',
            'list_routes',
            'resolve_preference',
            'resolve_template',
            'validate_module',
            'app_info',
            'read_log_entries',
            'run_console_command',
        );
});

/**
 * Boot the container with a database connection and mcp.database.allow_writes, and return the query_database tool.
 */
function wiredQueryDatabaseTool(
    RecordingConnection $connection,
    bool $allowWrites,
): ToolDefinition {
    $container = bootMcpContainer();
    $container->instance(ConnectionInterface::class, $connection);
    $container->instance(ConfigRepositoryInterface::class, mcpConfig([
        'mcp.database.allow_writes' => $allowWrites,
    ]));

    $tools = new ReflectionClass(McpServer::class)
        ->getProperty('tools')
        ->getValue($container->get(McpServer::class));

    return $tools['query_database'];
}

it('registers query_database with writes disabled when mcp.database.allow_writes is false', function (): void {
    $connection = new RecordingConnection();

    $result = wiredQueryDatabaseTool($connection, allowWrites: false)->handler->handle([
        'sql' => 'DELETE FROM users',
        'allowWrite' => true,
        'confirm' => true,
    ]);

    expect($result['isError'] ?? false)->toBeTrue()
        ->and($result['content'][0]['text'])->toContain('Write operations are disabled')
        ->and($connection->statements)->toBe([]);
});

it('registers query_database with writes enabled when mcp.database.allow_writes is true', function (): void {
    $connection = new RecordingConnection();

    $result = wiredQueryDatabaseTool($connection, allowWrites: true)->handler->handle([
        'sql' => 'DELETE FROM users',
        'allowWrite' => true,
        'confirm' => true,
    ]);

    expect($result['content'][0]['text'])->toContain('WRITE OPERATION')
        ->and($connection->statements)->toBe(['query: DELETE FROM users']);
});

it('ships mcp.database.allow_writes off by default', function (): void {
    $previous = getenv('MCP_ALLOW_WRITES');
    putenv('MCP_ALLOW_WRITES');

    try {
        $config = require dirname(__DIR__, 2) . '/config/mcp.php';
    } finally {
        if ($previous !== false) {
            putenv("MCP_ALLOW_WRITES=$previous");
        }
    }

    expect($config['database']['allow_writes'])->toBeFalse();
});

/**
 * Boot the container with the given mcp config and a registry holding route:list and db:reset, and return the
 * run_console_command tool.
 *
 * @param array<string, mixed> $config
 */
function wiredRunConsoleCommandTool(
    array $config,
): ToolDefinition {
    $container = bootMcpContainer();
    $container->instance(ConfigRepositoryInterface::class, mcpConfig($config));

    $registry = new CommandRegistry();
    $registry->register(new CommandDefinition('Marko\\Mcp\\Tests\\MissingRouteListCommand', 'route:list'));
    $registry->register(
        new CommandDefinition(
            'Marko\\Mcp\\Tests\\MissingResetCommand',
            'db:reset',
            flags: ['force'],
            destructive: true,
        ),
    );
    $container->instance(CommandRegistry::class, $registry);

    $tools = new ReflectionClass(McpServer::class)
        ->getProperty('tools')
        ->getValue($container->get(McpServer::class));

    return $tools['run_console_command'];
}

it('registers run_console_command with the mcp.console.allowed_commands allowlist', function (): void {
    $result = wiredRunConsoleCommandTool([
        'mcp.console.allowed_commands' => ['route:list'],
    ])->handler->handle(['command' => 'db:reset', 'args' => ['--force']]);

    expect($result['isError'] ?? false)->toBeTrue()
        ->and($result['content'][0]['text'])->toContain("Command 'db:reset' is not allowed");
});

it('refuses wired destructive commands unless mcp.console.allow_destructive', function (): void {
    $refused = wiredRunConsoleCommandTool([
        'mcp.console.allowed_commands' => ['db:reset'],
        'mcp.console.allow_destructive' => false,
    ])->handler->handle(['command' => 'db:reset', 'args' => ['--force']]);

    expect($refused['isError'] ?? false)->toBeTrue()
        ->and($refused['content'][0]['text'])->toContain('mcp.console.allow_destructive');

    $tool = wiredRunConsoleCommandTool([
        'mcp.console.allowed_commands' => ['db:reset'],
        'mcp.console.allow_destructive' => true,
    ]);

    // Dispatched through the CommandRunner; the registered class does not exist, so it reports exit code 1.
    expect($tool->handler->handle(['command' => 'db:reset'])['content'][0]['text'])
        ->toStartWith('exitCode: 1');
});

it('ships a read-only console allowlist with destructive commands and production serving off', function (): void {
    $variables = ['MCP_ALLOW_DESTRUCTIVE', 'MCP_ALLOW_PRODUCTION'];
    $previous = array_combine($variables, array_map(getenv(...), $variables));

    foreach ($variables as $variable) {
        putenv($variable);
    }

    try {
        $config = require dirname(__DIR__, 2) . '/config/mcp.php';
    } finally {
        foreach ($previous as $variable => $value) {
            if ($value !== false) {
                putenv("$variable=$value");
            }
        }
    }

    expect($config['allow_production'])->toBeFalse()
        ->and($config['console']['allow_destructive'])->toBeFalse()
        ->and($config['console']['allowed_commands'])->toContain('route:list', 'module:list')
        ->and($config['console']['allowed_commands'])->not->toContain(
            'db:reset',
            'db:rollback',
            'db:rebuild',
            'db:seed',
            'db:migrate',
            'cache:clear',
            'queue:clear',
            'log:clear',
            'mcp:serve',
        );
});

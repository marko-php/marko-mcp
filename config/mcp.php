<?php

declare(strict_types=1);

use Marko\Config\Env;

return [
    // Lets `marko mcp:serve` start when the app environment is production (APP_ENV/MARKO_ENV unset counts as
    // production). Off by default: an agent steered by prompt-injected content should not reach a live system.
    'allow_production' => Env::bool('MCP_ALLOW_PRODUCTION', false),
    'console' => [
        // Commands the run_console_command tool may run, by name or alias. Read-only commands by default; any
        // command not listed here is refused, whatever arguments the agent passes.
        'allowed_commands' => [
            'list',
            'module:list',
            'route:list',
            'db:status',
            'db:diff',
            'cache:status',
            'page-cache:status',
            'queue:status',
            'queue:failed',
        ],
        // Lets run_console_command run an allowed command marked #[Command(destructive: true)] (db:reset,
        // cache:clear, queue:clear...). Off by default: the agent can pass --force itself, so that is no guard.
        'allow_destructive' => Env::bool('MCP_ALLOW_DESTRUCTIVE', false),
    ],
    'database' => [
        // Lets the query_database tool run writes when the agent passes allowWrite and confirm. Off by default:
        // reads always run in a read-only transaction, and the agent's own arguments can never turn writes on.
        'allow_writes' => Env::bool('MCP_ALLOW_WRITES', false),
    ],
];

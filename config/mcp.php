<?php

declare(strict_types=1);

use Marko\Config\Env;

return [
    'database' => [
        // Lets the query_database tool run writes when the agent passes allowWrite and confirm. Off by default:
        // reads always run in a read-only transaction, and the agent's own arguments can never turn writes on.
        'allow_writes' => Env::bool('MCP_ALLOW_WRITES', false),
    ],
];

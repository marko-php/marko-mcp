<?php

declare(strict_types=1);

namespace Marko\Mcp\Tools\Runtime\Contracts;

interface LogReaderInterface
{
    /**
     * Return the last $count lines (oldest first) without loading the whole log into memory.
     * A $count below 1 returns no lines.
     *
     * @return list<string>
     */
    public function readLast(int $count): array;
}

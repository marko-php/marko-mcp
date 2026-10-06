<?php

declare(strict_types=1);

namespace Marko\Mcp\Exceptions;

use Marko\Core\Exceptions\MarkoException;

class McpException extends MarkoException
{
    private const int PARSE_ERROR = -32700;

    private const int INVALID_REQUEST = -32600;

    private const int METHOD_NOT_FOUND = -32601;

    private const int INTERNAL_ERROR = -32603;

    private int $jsonRpcCode;

    public function __construct(
        string $message,
        int $jsonRpcCode,
        string $context = '',
        string $suggestion = '',
    ) {
        parent::__construct(
            message: $message,
            context: $context,
            suggestion: $suggestion,
            code: $jsonRpcCode,
        );
        $this->jsonRpcCode = $jsonRpcCode;
    }

    public function getJsonRpcCode(): int
    {
        return $this->jsonRpcCode;
    }

    public static function methodNotFound(string $method): self
    {
        return new self(
            message: "Method not found: $method",
            jsonRpcCode: self::METHOD_NOT_FOUND,
        );
    }

    public static function invalidRequest(string $reason): self
    {
        return new self(
            message: "Invalid Request: $reason",
            jsonRpcCode: self::INVALID_REQUEST,
        );
    }

    public static function parseError(string $reason): self
    {
        return new self(
            message: "Parse error: $reason",
            jsonRpcCode: self::PARSE_ERROR,
        );
    }

    public static function readOnlyUnsupportedDriver(string $driver): self
    {
        return new self(
            message: "query_database cannot run a read-only query on database driver '$driver'",
            jsonRpcCode: self::INTERNAL_ERROR,
            context: 'query_database runs every read inside a read-only transaction so a crafted query cannot write. '
                . "It only knows how to open one on mysql, pgsql and sqlite connections, so it refuses to run the query on '$driver' at all.",
            suggestion: 'Use a mysql, pgsql or sqlite connection for the MCP server, or add read-only transaction support for this driver to MarkoQueryConnection.',
        );
    }

    public static function internalError(string $message): self
    {
        return new self(
            message: "Internal error: $message",
            jsonRpcCode: self::INTERNAL_ERROR,
        );
    }
}

<?php

namespace App\Mcp\Support;

/**
 * Validated, hard-bounded pagination arguments (Phase 11). There is no
 * "return everything" option: the page size defaults to 25 and can never
 * exceed 100.
 */
final readonly class McpPage
{
    public const int DEFAULT_LIMIT = 25;

    public const int MAX_LIMIT = 100;

    public const int MAX_PAGE = 10_000;

    public function __construct(public int $limit, public int $page) {}

    /**
     * @param  array<string,mixed>  $arguments
     */
    public static function fromArguments(array $arguments): self
    {
        $limit = $arguments['limit'] ?? self::DEFAULT_LIMIT;
        $page = $arguments['page'] ?? 1;

        if (! is_int($limit) || $limit < 1 || $limit > self::MAX_LIMIT) {
            throw new McpError(McpErrorCode::InvalidArguments, 'limit must be an integer between 1 and '.self::MAX_LIMIT.'.');
        }

        if (! is_int($page) || $page < 1 || $page > self::MAX_PAGE) {
            throw new McpError(McpErrorCode::InvalidArguments, 'page must be an integer between 1 and '.self::MAX_PAGE.'.');
        }

        return new self($limit, $page);
    }

    /**
     * @return array<string,mixed>
     */
    public function envelope(int $total, int $returned): array
    {
        return [
            'page' => $this->page,
            'limit' => $this->limit,
            'total' => $total,
            'has_more' => $this->page * $this->limit < $total,
            'returned' => $returned,
        ];
    }
}

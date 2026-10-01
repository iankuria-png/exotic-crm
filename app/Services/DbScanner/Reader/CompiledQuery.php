<?php

namespace App\Services\DbScanner\Reader;

/**
 * A statement produced by the closed QueryCompiler. The reader accepts only
 * these, never strings, so no caller can hand it arbitrary SQL.
 */
final class CompiledQuery
{
    private function __construct(
        public readonly string $template,
        public readonly string $sql,
        public readonly array $bindings,
    ) {}

    /**
     * @internal Only QueryCompiler builds statements.
     */
    public static function compiled(QueryCompiler $compiler, string $template, string $sql, array $bindings = []): self
    {
        return new self($template, $sql, array_values($bindings));
    }
}

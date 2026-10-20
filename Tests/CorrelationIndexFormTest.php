<?php

declare(strict_types=1);

namespace Storm\Chronicler\Tests;

use const GLOB_BRACE;
use const T_COMMENT;
use const T_DOC_COMMENT;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
use function dirname;
use function file_get_contents;
use function glob;
use function implode;
use function is_array;
use function preg_match_all;
use function sprintf;
use function str_contains;
use function token_get_all;

/**
 * SQL that filters `event_store` on the correlation header writes the form its index is built on.
 *
 * `event_store_correlation_idx` indexes the expression `header #>> '{__correlation_id}'`. PostgreSQL
 * matches an expression index structurally, so `header->>'__correlation_id'` misses it entirely while
 * returning byte-identical text. Nothing reports that: the rows are right, every gate stays green,
 * and the query silently becomes a full scan of the largest table in the system.
 *
 * The framework declares the index, so the framework owes the guard. `InspectFilter` binds the path
 * as `#>> string_to_array(:p, ',')`, a live site that conforms, so the zero offenders this guard
 * reads come from an exercised rule. That spelling reaches the index only when the planner folds the
 * bound parameter back into the indexed expression, which a custom plan does; a generic plan keeps
 * the parameter opaque and matches the form to no index at all.
 *
 * A comparison against a column pinned `COLLATE "C"` misses the index the same way, resolving to a
 * collation it is not built under. Collate to the side whose index must serve.
 *
 * The guard refuses the equality an index could have served. A projection of the header is no
 * filter and may use either form, and `LIKE 'prefix%'` is left out too: a btree serves a prefix only
 * under a byte-ordered collation, which this index does not carry.
 */
final class CorrelationIndexFormTest extends TestCase
{
    /**
     * The key form under an operator an equality index could have served, in both operand orders.
     *
     * Warning: matching only `=` is a trap, since `NOT IN` would slip through and live code writes
     * that form.
     *
     * @var list<string>
     */
    private const array ARROW_FILTERS = [
        '/header\s*->>\s*\\\\?\'__correlation_id\\\\?\'\s*(=|<>|!=|(not\s+)?in\b)/i',
        '/(=|<>|!=)\s*[a-z_]*\.?header\s*->>\s*\\\\?\'__correlation_id\\\\?\'/i',
        '/header\s*->>\s*(?:\\\\?[a-z_][a-z0-9_\\\\]*::)?CorrelationId->value\s*(=|<>|!=|(not\s+)?in\b)/i',
    ];

    #[Test]
    public function no_sql_filters_the_correlation_header_with_the_key_form(): void
    {
        $sources = self::sourceFiles();
        // a glob that silently matched nothing would make this guard pass forever
        self::assertGreaterThan(500, count($sources), 'the source sweep lost its target');

        $offenders = [];

        foreach ($sources as $file) {
            $code = self::withoutComments((string) file_get_contents($file));

            if (! str_contains($code, '__correlation_id') && ! str_contains($code, 'CorrelationId->value')) {
                continue;
            }

            if (self::containsArrowFilter($code)) {
                $offenders[] = $file;
            }
        }

        self::assertSame([], $offenders, sprintf(
            'these files filter `event_store` on the correlation header with `->>`, which matches no index '
            ."and scans the whole store:\n  %s\n"
            ."Write `header #>> '{__correlation_id}'`, the expression `event_store_correlation_idx` is built on. "
            .'Comparing against a `COLLATE "C"` column also needs `COLLATE pg_catalog."default"` on that side.',
            implode("\n  ", $offenders),
        ));
    }

    #[Test]
    public function the_enum_backed_key_form_is_detected(): void
    {
        self::assertTrue(self::containsArrowFilter(
            'e.header ->> Header::'.'CorrelationId->value IN (:correlationIds)',
        ));
    }

    /**
     * @return list<string>
     */
    private static function sourceFiles(): array
    {
        return glob(dirname(__DIR__, 2).'/*/{,*/,*/*/,*/*/*/}*.php', GLOB_BRACE) ?: [];
    }

    /**
     * Comments are stripped so a docblock quoting the wrong form is not read as a use of it.
     */
    private static function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    private static function containsArrowFilter(string $code): bool
    {
        return array_any(self::ARROW_FILTERS, fn ($pattern) => preg_match_all($pattern, $code) > 0);
    }
}

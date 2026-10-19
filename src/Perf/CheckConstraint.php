<?php

declare(strict_types=1);

namespace Mk\Director\Perf;

use Closure;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

/**
 * One CHECK constraint of a table, as the engine prints it, parsed so the volume generator can write rows that
 * satisfy it. A generic row breaks them (`ranks_color_format`, `members_nick_format`, `school_uploads_one_owner`
 * left half of a module empty), and every row of a table refused is a table measured empty.
 *
 * The shapes it satisfies: a format (`col ~ '^#[0-9A-F]{6}$'`, `col REGEXP …`), a list (`= ANY (ARRAY[…])`,
 * `IN (…)`), a range (`col >= 0`, `col > 0::numeric`), nullness (`IS [NOT] NULL`, and `(a IS NULL) <> (b IS NULL)`:
 * exactly one of them), a boolean column, `col = <expression of other columns>`, and any AND / OR / NOT of those.
 * Anything else is left to the database: a row it refuses is retried and, at worst, the table is reported.
 *
 * ponytail: a small recursive-descent parser for the expressions CHECKs are made of, not SQL. The OR picks its
 * branch by the row number, so the rows spread over every branch.
 */
final class CheckConstraint
{
    private const KEYWORDS = ['and', 'or', 'not', 'is', 'null', 'in', 'any', 'some', 'array', 'regexp', 'rlike', 'like', 'between', 'true', 'false'];

    /** @var list<array{0: string, 1: mixed}> */
    private array $tokens = [];

    private int $at = 0;

    private function __construct(public readonly string $name, private readonly array $ast) {}

    /**
     * The table's CHECK constraints that could be parsed. `NOT VALID` ones too: they still apply to new rows.
     *
     * @return list<self>
     */
    public static function of(Connection $db, string $table): array
    {
        $rows = match ($db->getDriverName()) {
            'pgsql' => $db->select("select conname as name, pg_get_constraintdef(oid) as clause from pg_constraint where contype = 'c' and conrelid = ?::regclass", [$table]),
            'mysql', 'mariadb' => $db->select('select constraint_name as name, check_clause as clause from information_schema.check_constraints where constraint_schema = database() and table_name = ?', [$table]),
            default => [],
        };
        $out = [];
        foreach ($rows as $r) {
            if (($check = self::parse((string) $r->name, (string) $r->clause, $db->getDriverName() !== 'pgsql')) !== null) {
                $out[] = $check;
            }
        }

        return $out;
    }

    /** Null when the clause is not one this parser reads: the database still enforces it. */
    public static function parse(string $name, string $clause, bool $backslashEscapes = false): ?self
    {
        $clause = (string) preg_replace(['/^\s*CHECK\s*/i', '/\s+NOT\s+VALID\s*$/i'], '', $clause);
        try {
            $parser = new self($name, []);
            $parser->tokens = self::tokenize($clause, $backslashEscapes);
            $ast = $parser->orExpr();
            if ($parser->at !== count($parser->tokens)) {
                return null;
            }

            return new self($name, $ast);
        } catch (Throwable) {
            return null;
        }
    }

    /** False only when the row breaks it: SQL passes a CHECK that evaluates to NULL. A column absent from the row is NULL. */
    public function holds(array $row): bool
    {
        return self::value($this->ast, $row) !== false;
    }

    /**
     * `$row` changed as little as it takes to satisfy the constraint, or null when it cannot be (a NOT NULL column
     * it would have to empty).
     *
     * @param  array<string, array<string, mixed>>  $columns  name => column as `Schema::getColumns()` reports it
     * @param  Closure(string): mixed  $fill  a value for a column the constraint needs set (a foreign key's parent id)
     */
    public function satisfy(array $row, int $seed, array $columns, Closure $fill): ?array
    {
        return self::solve($this->ast, true, $row, $seed, $columns, $fill);
    }

    // ---- solving -------------------------------------------------------------------------------------------------

    private static function solve(array $n, bool $want, array $row, int $seed, array $cols, Closure $fill): ?array
    {
        $kind = $n[0];
        if ($kind === 'not') {
            return self::solve($n[1], ! $want, $row, $seed, $cols, $fill);
        }
        if ($kind === 'and' || $kind === 'or') {
            // AND wanted true (or OR wanted false) needs every part; the other two need one part, chosen by the seed.
            if (($kind === 'and') === $want) {
                foreach ($n[1] as $part) {
                    $row = self::solve($part, $want, $row, $seed, $cols, $fill) ?? null;
                    if ($row === null) {
                        return null;
                    }
                }

                return $row;
            }
            $parts = $n[1];
            $k = $seed % count($parts);
            foreach ([...array_slice($parts, $k), ...array_slice($parts, 0, $k)] as $part) {
                $try = self::solve($part, $want, $row, $seed, $cols, $fill);
                if ($try !== null && self::value($part, $try) === $want) {
                    return $try;
                }
            }

            return null;
        }
        if ($kind === 'col') {
            return [...$row, $n[1] => $want]; // a boolean column used as a condition
        }
        if ($kind === 'isnull') {
            if ($n[1][0] !== 'col') {
                return $row;
            }
            $null = $want !== $n[2]; // [isnull, expr, negated]

            return self::setNull($n[1][1], $null, $row, $cols, $fill);
        }
        if ($kind === 'in') {
            [, $expr, $list, $negated] = $n;
            if ($expr[0] !== 'col') {
                return $row;
            }
            $values = array_map(static fn (array $v): mixed => self::value($v, $row), $list);
            if ($want !== $negated) {
                return [...$row, $expr[1] => $values[self::skew(count($values), $seed)]];
            }
            $numeric = array_filter($values, 'is_numeric');

            return [...$row, $expr[1] => $numeric === $values && $values !== [] ? max($numeric) + 1 : 'zz'];
        }
        if ($kind === 're') {
            [, $expr, $pattern, $negated] = $n;
            if ($expr[0] !== 'col' || ! is_string($p = self::value($pattern, $row))) {
                return $row;
            }

            return [...$row, $expr[1] => $want !== $negated ? self::sample($p, $seed) : '!'];
        }
        if ($kind === 'cmp') {
            return self::solveCmp($n, $want, $row, $seed, $cols, $fill);
        }

        return $row; // a function, a literal: nothing to set
    }

    private static function solveCmp(array $n, bool $want, array $row, int $seed, array $cols, Closure $fill): ?array
    {
        [, $op, $left, $right] = $n;
        if (self::value($n, $row) === $want) {
            return $row;
        }
        // `(a IS NULL) <> (b IS NULL)`: two conditions compared as booleans.
        if (self::isCondition($left) && self::isCondition($right) && in_array($op, ['=', '<>', '!='], true)) {
            $same = ($op === '=') === $want;
            foreach ($seed % 2 === 0 ? [true, false] : [false, true] as $b) {
                $try = self::solve($left, $b, $row, $seed, $cols, $fill);
                $try = $try === null ? null : self::solve($right, $same ? $b : ! $b, $try, $seed, $cols, $fill);
                if ($try !== null && self::value($n, $try) === $want) {
                    return $try;
                }
            }

            return null;
        }
        $op = $want ? $op : self::negate($op);
        // `COALESCE(col, 0) >= …` sets the column.
        $unwrap = static fn (array $x): array => $x[0] === 'fn' && $x[1] === 'coalesce' && ($x[2][0][0] ?? '') === 'col' ? $x[2][0] : $x;
        [$left, $right] = [$unwrap($left), $unwrap($right)];
        // The column on the left; the other side must already have a value.
        if ($left[0] !== 'col' && $right[0] === 'col') {
            [$left, $right, $op] = [$right, $left, self::flip($op)];
        }
        if ($left[0] !== 'col' || ($v = self::value($right, $row)) === null) {
            return $row;
        }
        $column = $cols[$left[1]] ?? null;
        $integer = $column !== null && preg_match('/int|serial/i', (string) $column['type_name']) === 1;

        return [...$row, $left[1] => self::target($op, $v, $seed, $integer)];
    }

    /** A value that makes `col <op> $v` true. */
    private static function target(string $op, mixed $v, int $seed, bool $integer): mixed
    {
        if (is_bool($v) || ! is_numeric($v)) {
            return match ($op) {
                '=' => $v,
                default => is_bool($v) ? ! $v : $v.'x',
            };
        }
        $v = $integer ? (int) ceil((float) $v) : $v + 0;
        $d = $seed % 10;

        return match ($op) {
            '=' => $v,
            '<>', '!=' => $v + 1,
            '>' => $v + 1 + $d,
            '>=' => $v + $d,
            '<' => $v - 1 - $d,
            '<=' => $v - $d,
            default => $v,
        };
    }

    private static function setNull(string $column, bool $null, array $row, array $cols, Closure $fill): ?array
    {
        if ($null) {
            return ($cols[$column]['nullable'] ?? false) ? [...$row, $column => null] : null;
        }
        if (($row[$column] ?? null) !== null) {
            return $row;
        }
        $value = $fill($column);

        return $value === null ? null : [...$row, $column => $value];
    }

    private static function isCondition(array $n): bool
    {
        return in_array($n[0], ['isnull', 'cmp', 'in', 're', 'and', 'or', 'not'], true);
    }

    private static function negate(string $op): string
    {
        return ['=' => '<>', '<>' => '=', '!=' => '=', '<' => '>=', '>=' => '<', '>' => '<=', '<=' => '>'][$op] ?? $op;
    }

    private static function flip(string $op): string
    {
        return ['<' => '>', '>' => '<', '<=' => '>=', '>=' => '<='][$op] ?? $op;
    }

    /** An index in [0, n) that favours the first ones: real data is uneven, and uniform data hides bad plans. */
    public static function skew(int $n, ?int $seed = null): int
    {
        if ($n <= 1) {
            return 0;
        }
        $r = $seed === null ? mt_rand() / mt_getrandmax() : ((($seed % 1000003) * 2654435761) % 1000) / 1000;

        return min($n - 1, (int) floor($n * $r ** 3));
    }

    // ---- evaluation ----------------------------------------------------------------------------------------------

    /** SQL's three values: true, false, or null (unknown) for a condition; the value for an expression. */
    private static function value(array $n, array $row): mixed
    {
        switch ($n[0]) {
            case 'lit':
                return $n[1];
            case 'col':
                return $row[$n[1]] ?? null;
            case 'not':
                $v = self::value($n[1], $row);

                return $v === null ? null : ! $v;
            case 'and':
            case 'or':
                $unknown = false;
                foreach ($n[1] as $part) {
                    $v = self::value($part, $row);
                    if ($v === null) {
                        $unknown = true;
                    } elseif ((bool) $v === ($n[0] === 'or')) {
                        return $n[0] === 'or';
                    }
                }

                return $unknown ? null : $n[0] === 'and';
            case 'isnull':
                return (self::value($n[1], $row) === null) !== $n[2];
            case 'in':
                $v = self::value($n[1], $row);
                if ($v === null) {
                    return null;
                }
                foreach ($n[2] as $item) {
                    if (self::equal($v, self::value($item, $row))) {
                        return ! $n[3];
                    }
                }

                return $n[3];
            case 're':
                $v = self::value($n[1], $row);
                $p = self::value($n[2], $row);
                if ($v === null || ! is_string($p)) {
                    return null;
                }
                $m = @preg_match('~'.str_replace('~', '\~', $p).'~u'.($n[4] ? 'i' : ''), (string) $v);

                return $m === false ? null : ($m === 1) !== $n[3];
            case 'cmp':
                $a = self::value($n[2], $row);
                $b = self::value($n[3], $row);
                if ($a === null || $b === null) {
                    return null;
                }
                $c = is_numeric($a) && is_numeric($b) ? $a + 0 <=> $b + 0 : (is_bool($a) || is_bool($b) ? (bool) $a <=> (bool) $b : strcmp((string) $a, (string) $b));

                return match ($n[1]) {
                    '=' => $c === 0,
                    '<>', '!=' => $c !== 0,
                    '<' => $c < 0,
                    '<=' => $c <= 0,
                    '>' => $c > 0,
                    '>=' => $c >= 0,
                    default => null,
                };
            case 'math':
                $a = self::value($n[2], $row);
                $b = self::value($n[3], $row);
                if ($a === null || $b === null) {
                    return null;
                }
                if ($n[1] === '||') {
                    return $a.$b;
                }
                if (! is_numeric($a) || ! is_numeric($b) || ($n[1] === '/' && (float) $b === 0.0)) {
                    return null;
                }

                return match ($n[1]) {
                    '+' => $a + $b,
                    '-' => $a - $b,
                    '*' => $a * $b,
                    '/' => $a / $b,
                    default => null,
                };
            case 'fn':
                $args = array_map(static fn (array $a): mixed => self::value($a, $row), $n[2]);

                return match ($n[1]) {
                    'coalesce' => array_find($args, static fn (mixed $a): bool => $a !== null),
                    'char_length', 'length', 'character_length' => $args[0] === null ? null : mb_strlen((string) $args[0]),
                    'lower' => $args[0] === null ? null : strtolower((string) $args[0]),
                    'upper' => $args[0] === null ? null : strtoupper((string) $args[0]),
                    'trim', 'btrim' => $args[0] === null ? null : trim((string) $args[0]),
                    'json_valid' => $args[0] === null ? null : json_validate((string) $args[0]),
                    default => null,
                };
        }

        return null;
    }

    private static function equal(mixed $a, mixed $b): bool
    {
        return is_numeric($a) && is_numeric($b) ? $a + 0 == $b + 0 : (string) $a === (string) $b;
    }

    // ---- parsing -------------------------------------------------------------------------------------------------

    /** @return list<array{0: string, 1: mixed}> [kind, value]: id, str, num, op, kw */
    private static function tokenize(string $s, bool $backslashEscapes): array
    {
        $out = [];
        $i = 0;
        $n = strlen($s);
        while ($i < $n) {
            $c = $s[$i];
            if (ctype_space($c)) {
                $i++;
            } elseif ($c === "'" || (($c === 'E' || $c === 'e') && ($s[$i + 1] ?? '') === "'")) {
                $escapes = $backslashEscapes || $c !== "'";
                $i += $c === "'" ? 1 : 2;
                $text = '';
                while (true) {
                    if ($i >= $n) {
                        throw new RuntimeException('unterminated string');
                    }
                    $ch = $s[$i++];
                    if ($ch === '\\' && $escapes) {
                        $text .= $s[$i++] ?? '';
                    } elseif ($ch === "'" && ($s[$i] ?? '') === "'") {
                        $text .= "'";
                        $i++;
                    } elseif ($ch === "'") {
                        break;
                    } else {
                        $text .= $ch;
                    }
                }
                $out[] = ['str', $text];
            } elseif (ctype_digit($c)) {
                preg_match('/\d+(\.\d+)?/A', $s, $m, 0, $i);
                $out[] = ['num', str_contains($m[0], '.') ? (float) $m[0] : (int) $m[0]];
                $i += strlen($m[0]);
            } elseif ($c === '"' || $c === '`') {
                $end = strpos($s, $c, $i + 1);
                if ($end === false) {
                    throw new RuntimeException('unterminated identifier');
                }
                $out[] = ['id', substr($s, $i + 1, $end - $i - 1)];
                $i = $end + 1;
            } elseif (preg_match('/[A-Za-z_][A-Za-z0-9_$]*/A', $s, $m, 0, $i)) {
                $word = strtolower($m[0]);
                $out[] = in_array($word, self::KEYWORDS, true) ? ['kw', $word] : ['id', $m[0]];
                $i += strlen($m[0]);
            } elseif (preg_match('/::|<=|>=|<>|!=|!~\*|!~|~\*|\|\||[~=<>+\-*\/%(),\[\].]/A', $s, $m, 0, $i)) {
                $out[] = ['op', $m[0]];
                $i += strlen($m[0]);
            } else {
                throw new RuntimeException("unexpected {$c}");
            }
        }

        return $out;
    }

    private function peek(string $kind, ?string $value = null, int $ahead = 0): bool
    {
        $t = $this->tokens[$this->at + $ahead] ?? null;

        return $t !== null && $t[0] === $kind && ($value === null || $t[1] === $value);
    }

    private function take(string $kind, ?string $value = null): mixed
    {
        if (! $this->peek($kind, $value)) {
            throw new RuntimeException("expected {$kind} {$value}");
        }

        return $this->tokens[$this->at++][1];
    }

    private function accept(string $kind, string $value): bool
    {
        if ($this->peek($kind, $value)) {
            $this->at++;

            return true;
        }

        return false;
    }

    private function orExpr(): array
    {
        $parts = [$this->andExpr()];
        while ($this->accept('kw', 'or')) {
            $parts[] = $this->andExpr();
        }

        return count($parts) === 1 ? $parts[0] : ['or', $parts];
    }

    private function andExpr(): array
    {
        $parts = [$this->notExpr()];
        while ($this->accept('kw', 'and')) {
            $parts[] = $this->notExpr();
        }

        return count($parts) === 1 ? $parts[0] : ['and', $parts];
    }

    private function notExpr(): array
    {
        return $this->accept('kw', 'not') ? ['not', $this->notExpr()] : $this->condition();
    }

    /** A comparison; each side may carry `IS [NOT] NULL`, so `a IS NULL <> b IS NULL` reads as two conditions. */
    private function condition(): array
    {
        $left = $this->isNull($this->sum());
        while (true) {
            $negated = $this->peek('kw', 'not') && ($this->peek('kw', 'in', 1) || $this->peek('kw', 'regexp', 1) || $this->peek('kw', 'rlike', 1) || $this->peek('kw', 'between', 1));
            if ($negated) {
                $this->at++;
            }
            if ($this->accept('kw', 'in')) {
                $left = ['in', $left, $this->list('(', ')'), $negated];
            } elseif ($this->accept('kw', 'regexp') || $this->accept('kw', 'rlike')) {
                $left = ['re', $left, $this->sum(), $negated, true];
            } elseif ($this->accept('kw', 'between')) {
                $low = $this->sum();
                $this->take('kw', 'and');
                $range = ['and', [['cmp', '>=', $left, $low], ['cmp', '<=', $left, $this->sum()]]];
                $left = $negated ? ['not', $range] : $range;
            } elseif ($this->peek('op') && in_array($op = $this->tokens[$this->at][1], ['~', '~*', '!~', '!~*'], true)) {
                $this->at++;
                $left = ['re', $left, $this->sum(), $op[0] === '!', str_ends_with($op, '*')];
            } elseif ($this->peek('op') && in_array($op = $this->tokens[$this->at][1], ['=', '<>', '!=', '<', '<=', '>', '>='], true)) {
                $this->at++;
                if ($this->accept('kw', 'any') || $this->accept('kw', 'some')) {
                    $this->take('op', '(');
                    $this->accept('kw', 'array');
                    $items = $this->peek('op', '[') ? $this->list('[', ']') : $this->list(null, ')', false);
                    $this->cast();
                    if ($this->peek('op', ')')) {
                        $this->at++;
                    }
                    $left = ['in', $left, $items, $op !== '='];
                } else {
                    $left = ['cmp', $op, $left, $this->isNull($this->sum())];
                }
            } else {
                return $left;
            }
        }
    }

    private function isNull(array $expr): array
    {
        while ($this->accept('kw', 'is')) {
            $negated = $this->accept('kw', 'not');
            if ($this->accept('kw', 'null')) {
                $expr = ['isnull', $expr, $negated];
            } else {
                $bool = $this->accept('kw', 'true') || ! $this->take('kw', 'false');
                $expr = ['cmp', $negated ? '<>' : '=', $expr, ['lit', $bool]];
            }
        }

        return $expr;
    }

    /** @return list<array> the items of `( … )` or `[ … ]` */
    private function list(?string $open, string $close, bool $consumeClose = true): array
    {
        if ($open !== null) {
            $this->take('op', $open);
        }
        $items = [$this->sum()];
        while ($this->accept('op', ',')) {
            $items[] = $this->sum();
        }
        if ($consumeClose) {
            $this->take('op', $close);
        }

        return $items;
    }

    private function sum(): array
    {
        $left = $this->product();
        while ($this->peek('op') && in_array($op = $this->tokens[$this->at][1], ['+', '-', '||'], true)) {
            $this->at++;
            $left = ['math', $op, $left, $this->product()];
        }

        return $left;
    }

    private function product(): array
    {
        $left = $this->unary();
        while ($this->peek('op') && in_array($op = $this->tokens[$this->at][1], ['*', '/', '%'], true)) {
            $this->at++;
            $left = ['math', $op, $left, $this->unary()];
        }

        return $left;
    }

    private function unary(): array
    {
        if ($this->accept('op', '-')) {
            $inner = $this->unary();

            return $inner[0] === 'lit' && is_numeric($inner[1]) ? ['lit', -$inner[1]] : ['math', '-', ['lit', 0], $inner];
        }

        return $this->primary();
    }

    private function primary(): array
    {
        if ($this->accept('op', '(')) {
            $node = $this->orExpr();
            $this->take('op', ')');
        } elseif ($this->peek('str') || $this->peek('num')) {
            $node = ['lit', $this->tokens[$this->at++][1]];
        } elseif ($this->accept('kw', 'null')) {
            $node = ['lit', null];
        } elseif ($this->accept('kw', 'true') || $this->accept('kw', 'false')) {
            $node = ['lit', $this->tokens[$this->at - 1][1] === 'true'];
        } elseif ($this->accept('kw', 'array')) {
            $node = ['list', $this->list('[', ']')];
        } elseif ($this->peek('id')) {
            $name = $this->take('id');
            if ($this->peek('op', '(')) {
                $this->at++;
                $args = $this->peek('op', ')') ? [] : $this->list(null, ')', false);
                $this->take('op', ')');
                $node = ['fn', strtolower($name), $args];
            } else {
                while ($this->accept('op', '.')) {
                    $name = $this->take('id'); // `table.column` → the column
                }
                $node = ['col', $name];
            }
        } else {
            throw new RuntimeException('unexpected token');
        }
        $this->cast();

        return $node;
    }

    /** `::numeric`, `::character varying(20)`, `::text[]`: a cast changes nothing here. */
    private function cast(): void
    {
        while ($this->accept('op', '::')) {
            $this->take('id');
            while ($this->peek('id')) {
                $this->at++;
            }
            if ($this->peek('op', '(') && ($this->tokens[$this->at + 1][0] ?? '') === 'num') {
                $this->list('(', ')');
            }
            if ($this->accept('op', '[')) {
                $this->take('op', ']');
            }
        }
    }

    // ---- formats -------------------------------------------------------------------------------------------------

    /**
     * A string the regular expression matches, different for each seed where the pattern leaves room: the seed is
     * spent as digits on the character classes (`[a-z0-9._]{3,20}` gives a distinct nick per row, so a unique column
     * holds). Literals, classes, `.`, `\d \w \s`, groups, `|` and the quantifiers; anchors are implied.
     */
    public static function sample(string $pattern, int $seed): string
    {
        $at = 0;
        $tree = self::reAlt($pattern, $at);

        return self::reGen($tree, $seed);
    }

    private static function reAlt(string $p, int &$at): array
    {
        $branches = [self::reSeq($p, $at)];
        while (($p[$at] ?? '') === '|') {
            $at++;
            $branches[] = self::reSeq($p, $at);
        }

        return ['alt', $branches];
    }

    private static function reSeq(string $p, int &$at): array
    {
        $items = [];
        while ($at < strlen($p) && $p[$at] !== '|' && $p[$at] !== ')') {
            $atom = self::reAtom($p, $at);
            [$min, $max] = [1, 1];
            if (preg_match('/\{(\d+)(,(\d*))?\}|[?*+]/A', $p, $m, 0, $at)) {
                $at += strlen($m[0]);
                [$min, $max] = match ($m[0]) {
                    '?' => [0, 1],
                    '*' => [0, 8],
                    '+' => [1, 8],
                    default => [(int) $m[1], isset($m[2]) ? ($m[3] === '' ? (int) $m[1] + 8 : (int) $m[3]) : (int) $m[1]],
                };
                if (($p[$at] ?? '') === '?') {
                    $at++; // lazy: same strings
                }
            }
            $items[] = [$atom, $min, $max];
        }

        return ['seq', $items];
    }

    private static function reAtom(string $p, int &$at): array
    {
        $c = $p[$at++];
        $range = static fn (string $a, string $b): array => array_map('chr', range(ord($a), ord($b)));
        $digits = $range('0', '9');
        $word = [...$range('a', 'z'), ...$range('A', 'Z'), ...$digits, '_'];

        switch ($c) {
            case '^':
            case '$':
                return ['lit', ''];
            case '.':
                return ['set', $range('a', 'z')];
            case '(':
                if (substr($p, $at, 2) === '?:') {
                    $at += 2;
                }
                $inner = self::reAlt($p, $at);
                $at++; // ')'

                return ['group', $inner];
            case '\\':
                $e = $p[$at++] ?? '';

                return match ($e) {
                    'd' => ['set', $digits],
                    'w' => ['set', $word],
                    's' => ['lit', ' '],
                    'D', 'W', 'S' => ['lit', '-'],
                    default => ['lit', $e],
                };
            case '[':
                $negated = ($p[$at] ?? '') === '^';
                $at += $negated ? 1 : 0;
                $set = [];
                $first = true;
                while ($at < strlen($p) && ($p[$at] !== ']' || $first)) {
                    $first = false;
                    if (preg_match('/\[:(\w+):\]/A', $p, $m, 0, $at)) {
                        $at += strlen($m[0]);
                        array_push($set, ...match ($m[1]) {
                            'digit' => $digits,
                            'alpha' => [...$range('a', 'z'), ...$range('A', 'Z')],
                            'lower' => $range('a', 'z'),
                            'upper' => $range('A', 'Z'),
                            'space' => [' '],
                            default => $word,
                        });

                        continue;
                    }
                    $ch = $p[$at++];
                    if ($ch === '\\') {
                        $e = $p[$at++] ?? '';
                        array_push($set, ...match ($e) {
                            'd' => $digits,
                            'w' => $word,
                            's' => [' '],
                            default => [$e],
                        });
                    } elseif (($p[$at] ?? '') === '-' && ($p[$at + 1] ?? ']') !== ']') {
                        array_push($set, ...$range($ch, $p[$at + 1]));
                        $at += 2;
                    } else {
                        $set[] = $ch;
                    }
                }
                $at++; // ']'
                if ($negated) {
                    $set = array_values(array_diff([...$range('a', 'z'), ...$digits], $set)) ?: ['~'];
                }

                return ['set', array_values(array_unique($set))];
            default:
                return ['lit', $c];
        }
    }

    private static function reGen(array $n, int &$seed): string
    {
        switch ($n[0]) {
            case 'lit':
                return $n[1];
            case 'set':
                $c = $n[1][$seed % count($n[1])];
                $seed = intdiv($seed, count($n[1]));

                return $c;
            case 'group':
                return self::reGen($n[1], $seed);
            case 'alt':
                $branch = $n[1][$seed % count($n[1])];
                $seed = intdiv($seed, count($n[1]));

                return self::reGen($branch, $seed);
        }
        $out = '';
        foreach ($n[1] as [$atom, $min, $max]) {
            for ($k = 0; $k < $min; $k++) {
                $out .= self::reGen($atom, $seed);
            }
            // More repetitions only while the seed has digits left to spend and the atom can vary.
            for ($k = $min; $k < $max && $seed > 0 && $atom[0] !== 'lit'; $k++) {
                $out .= self::reGen($atom, $seed);
            }
        }

        return $out;
    }
}

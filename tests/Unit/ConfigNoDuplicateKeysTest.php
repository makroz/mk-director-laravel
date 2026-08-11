<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * Guard against a config key being declared more than once in the same array
 * literal.
 *
 * Background: `config/mk_director.php` declared the top-level key `'debug'`
 * TWICE — a flat `env('MK_DIRECTOR_DEBUG', false)` boolean near the top
 * (v1.0.1) and a nested `['enabled' => ..., 'explain_enabled' => ...]` block
 * lower down (rc13, R-PKG-024). PHP silently keeps the LAST one, so the flat
 * boolean was dead code and `config('mk_director.debug')` always returned a
 * non-empty array. Every call site that read it as a boolean was therefore
 * pinned ON and `MK_DIRECTOR_DEBUG` was inert.
 *
 * PHP emits no warning for this and no amount of behavioral testing catches it
 * — the suite had 189 test files and none did. The only reliable signal is
 * comparing what the SOURCE declares against what the parser actually returns,
 * which is what this test does.
 *
 * The check is structural (token-based), not behavioral, so it protects every
 * config file the package ships, not just the one key that broke.
 */
uses(MkLaravelTestCase::class);

/**
 * Every config file the package ships.
 *
 * @return array<string, string> basename => absolute path
 */
function packageConfigFiles(): array
{
    $dir = dirname(__DIR__, 2).'/config';
    $files = glob($dir.'/*.php') ?: [];

    $map = [];
    foreach ($files as $path) {
        $map[basename($path)] = $path;
    }

    return $map;
}

/**
 * Actually parse a config file and return its top-level keys.
 *
 * Runs in a SUBPROCESS with a real `Illuminate\Foundation\Application` bound as
 * the container, because the config files call framework helpers (`app_path()`,
 * `env()`, …) that the package's minimal {@see MkLaravelTestCase} container
 * deliberately does not provide — it binds only `config`, `cache`, `db` and
 * `files`, not a full application kernel. A subprocess also keeps the throwaway
 * Application from clobbering the facade/container state of the running suite.
 *
 * @return array<int, string>
 */
function parsedTopLevelKeys(string $path): array
{
    $probe = <<<'PHP'
        require $argv[1];
        $app = new Illuminate\Foundation\Application($argv[3]);
        Illuminate\Container\Container::setInstance($app);
        echo json_encode(array_keys(require $argv[2]));
        PHP;

    $command = implode(' ', array_map('escapeshellarg', [
        PHP_BINARY,
        '-r',
        $probe,
        dirname(__DIR__, 2).'/vendor/autoload.php',
        $path,
        sys_get_temp_dir().'/mk-director-config-probe',
    ]));

    $output = shell_exec($command.' 2>&1');
    $keys = json_decode((string) $output, true);

    expect($keys)->toBeArray(
        'could not parse '.basename($path)." in a subprocess. Output was:\n".$output
    );

    return $keys;
}

/**
 * Collect duplicate literal keys per array literal, at EVERY nesting depth.
 *
 * Walks the token stream and keeps one "seen keys" set per open array literal,
 * pushed on `[` and popped on `]`. A key is a quoted string immediately
 * followed by `=>` (ignoring whitespace/comments) at the current depth.
 *
 * Only literal string keys are considered — a computed key cannot be compared
 * statically, and the package's config files do not use any.
 *
 * @return array<int, array{key: string, depth: int, first_line: int, duplicate_line: int}>
 */
function duplicateLiteralKeys(string $source): array
{
    $tokens = token_get_all($source);

    // Drop whitespace/comments so "string followed by =>" is a simple lookahead.
    $significant = [];
    foreach ($tokens as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $significant[] = $token;
    }

    /** @var array<int, array<string, int>> $stack seen key => line, one frame per open array */
    $stack = [];
    $duplicates = [];

    foreach ($significant as $i => $token) {
        // `[` and `]` arrive as plain strings from token_get_all().
        if ($token === '[') {
            $stack[] = [];

            continue;
        }

        if ($token === ']') {
            array_pop($stack);

            continue;
        }

        if ($stack === []) {
            continue; // Outside any array literal (e.g. the file's `return`).
        }

        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        $next = $significant[$i + 1] ?? null;
        $isKey = is_array($next) && $next[0] === T_DOUBLE_ARROW;
        if (! $isKey) {
            continue; // A value, not a key.
        }

        $key = trim($token[1], "'\"");
        $line = $token[2];
        $frame = count($stack) - 1;

        if (isset($stack[$frame][$key])) {
            $duplicates[] = [
                'key' => $key,
                'depth' => $frame + 1,
                'first_line' => $stack[$frame][$key],
                'duplicate_line' => $line,
            ];

            continue;
        }

        $stack[$frame][$key] = $line;
    }

    return $duplicates;
}

test('no config file declares the same key twice in the same array literal', function () {
    $files = packageConfigFiles();
    expect($files)->not->toBeEmpty('the package must ship at least one config file');

    foreach ($files as $name => $path) {
        $duplicates = duplicateLiteralKeys((string) file_get_contents($path));

        $detail = implode(', ', array_map(
            fn (array $d): string => "'{$d['key']}' at depth {$d['depth']} (line {$d['first_line']} then line {$d['duplicate_line']})",
            $duplicates
        ));

        expect($duplicates)->toBe(
            [],
            "config/{$name} declares duplicate key(s): {$detail}. PHP keeps only the LAST "
            .'declaration, so the earlier one is dead code and the effective value may not '
            .'be the one you are reading in the source.'
        );
    }
});

test('every top-level key the source declares survives into the parsed array', function () {
    // The counting cross-check: if a top-level key is declared twice, PHP
    // collapses it and the parsed array is SHORTER than the source declares.
    // This catches the exact failure mode of the `debug` bug even if the
    // tokenizer above ever drifts.
    foreach (packageConfigFiles() as $name => $path) {
        $source = (string) file_get_contents($path);
        $tokens = token_get_all($source);

        $significant = array_values(array_filter(
            $tokens,
            fn ($t): bool => ! (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))
        ));

        $depth = 0;
        $declared = [];
        foreach ($significant as $i => $token) {
            if ($token === '[') {
                $depth++;

                continue;
            }
            if ($token === ']') {
                $depth--;

                continue;
            }
            if ($depth !== 1 || ! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $next = $significant[$i + 1] ?? null;
            if (is_array($next) && $next[0] === T_DOUBLE_ARROW) {
                $declared[] = trim($token[1], "'\"");
            }
        }

        $parsed = parsedTopLevelKeys($path);

        $repeated = array_keys(array_filter(
            array_count_values($declared),
            fn (int $n): bool => $n > 1
        ));

        expect(count($parsed))->toBe(
            count($declared),
            "config/{$name} declares ".count($declared).' top-level keys but the parsed array has '
            .count($parsed).'. A key is declared more than once and PHP kept only the last one. '
            .'Duplicated: '.(implode(', ', $repeated) ?: '(none detected by name)').'.'
        );
    }
});

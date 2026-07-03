<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Controllers;

use Mk\Director\Tests\MkLaravelTestCase;

/**
 * R-PKG-024 (v1.7.0 GA) — SINGLE-LEVEL ENVELOPE pineado.
 *
 * Spec: the canonical response shape emitted by BaseController::sendResponse()
 * (and CRUDSmart::index() / Controller::index() that delegate to it) is
 *
 *   {
 *     "success": true,
 *     "message": "...",
 *     "data": [...items...],          // ← array directo (NO paginator nested)
 *     "__extraData": { ... },         // ← top-level sibling of `data`
 *     "debugMsg": []
 *   }
 *
 * PROHIBIDO in any controller source under src/:
 *   - `sendResponse([ ... 'data' => $paginate* ... '__extraData' => ... ])`
 *     (legacy nested shape — produces `data: { data: [...], links, meta }`).
 *   - Hand-crafted arrays passed to sendResponse() with `data` key wrapping
 *     a paginator (heuristic: `$paginate`, `$paginator`, `$cursor` variables,
 *     or `->paginate(` / `->cursorPaginate(` call results).
 *
 * This is the source-parsing INTENCIÓN side per HALLAZGO-NEW-03. The
 * EFECTIVIDAD side (runtime test that actually emits the canonical shape)
 * lives in `tests/Feature/BaseControllerSingleLevelEnvelopeE2ETest.php`.
 *
 * @see R-PKG-024 (binding rule, rules_orchestration.md)
 */
uses(MkLaravelTestCase::class);

function packageRootRPkg024(): string
{
    return dirname(__DIR__, 3);
}

function readSourceRPkg024(string $relativePath): string
{
    $fullPath = packageRootRPkg024() . '/' . $relativePath;
    expect(file_exists($fullPath))->toBeTrue("Source must exist at $fullPath");

    return file_get_contents($fullPath);
}

describe('R-PKG-024 — BaseController::sendResponse() emits single-level envelope', function (): void {
    $baseController = readSourceRPkg024('src/Controllers/BaseController.php');

    test('sendResponse() signature includes the 4th parameter array $extra = [] (BC from rc12)', function () use ($baseController): void {
        // The 4th parameter is what carries caller-side extras that get
        // merged into __extraData top-level.
        expect($baseController)->toContain('protected function sendResponse($result, $message = \'\', $code = 200, array $extra = [])');
    });

    test('sendResponse() detects paginator and extracts items to data, metadata to __extraData (R-PKG-024 GA)', function () use ($baseController): void {
        // The flag check is GONE — single-level is always canonical.
        expect($baseController)->toContain('instanceof AbstractPaginator');
        expect($baseController)->toContain('instanceof CursorPaginator');
        expect($baseController)->toContain('extractPaginationMetadata');
        expect($baseController)->toContain('autoTransform(new Collection($items))');
    });

    test('sendResponse() emits __extraData as top-level sibling of data (no nested shape)', function () use ($baseController): void {
        // The __extraData is added as a TOP-LEVEL key, NOT nested inside data.
        expect($baseController)->toContain("\$response['__extraData'] = \$extra");
        // The conditional emission gate (paginator OR non-empty $extra).
        expect($baseController)->toContain('if ($isPaginator || $extra !== [])');
    });

    test('sendResponse() does NOT check the removed top_level_extra_data config flag (R-PKG-024 GA removes the flag)', function () use ($baseController): void {
        // R-PKG-023 rc12 flag is GONE — single-level is unconditional post-GA.
        expect($baseController)->not->toContain('top_level_extra_data');
        expect($baseController)->not->toContain("config('mk_director.response.top_level_extra_data'");
    });

    test('BaseController declares extractPaginationMetadata() helper (R-PKG-024 new method)', function () use ($baseController): void {
        // Helper method that emits snake_case pagination keys.
        expect($baseController)->toContain('protected function extractPaginationMetadata');
        expect($baseController)->toContain('current_page');
        expect($baseController)->toContain('last_page');
        expect($baseController)->toContain('per_page');
        expect($baseController)->toContain('total');
    });

    test('extractPaginationMetadata() emits snake_case keys (frontend useMkInfiniteList consumes these)', function () use ($baseController): void {
        // NO camelCase keys (legacy ListManager::getExtraData had `lastPage` etc).
        expect($baseController)->not->toContain("'lastPage'");
        expect($baseController)->not->toContain("'perPage'");
        expect($baseController)->not->toContain("'hasMorePages'");
        // NO `page` (should be `current_page`).
        expect($baseController)->not->toContain("'page' => ");
    });
});

describe('R-PKG-024 — CRUDSmart::index() passes paginator directly to sendResponse() (no legacy array wrapping)', function (): void {
    $crudSmart = readSourceRPkg024('src/Traits/CRUDSmart.php');

    test('CRUDSmart::index() does NOT wrap response in [data => ..., __extraData => ...] array (legacy nested)', function () use ($crudSmart): void {
        // R-PKG-023 rc12 conditional flag is GONE.
        expect($crudSmart)->not->toContain("config('mk_director.response.top_level_extra_data'");
        // Legacy nested pattern: sendResponse([data => $items, __extraData => $extra])
        expect($crudSmart)->not->toContain("'__extraData' => \$extra");
    });

    test('CRUDSmart::index() delegates pagination extraction to BaseController', function () use ($crudSmart): void {
        // Pass paginator directly — BaseController auto-extracts items + meta.
        expect($crudSmart)->toContain('return $this->sendResponse($paginator, \'\', 200, $extra)');
        // Plugin hook receives paginator (so plugins can read total / currentPage).
        expect($crudSmart)->toContain('fireAfterResponse($paginator)');
    });
});

describe('R-PKG-024 — Controller::index() (legacy template method) passes paginator directly', function (): void {
    $controller = readSourceRPkg024('src/Controllers/Controller.php');

    test('Controller::index() does NOT wrap response in [data => ..., __extraData => ...] array', function () use ($controller): void {
        expect($controller)->not->toContain("config('mk_director.response.top_level_extra_data'");
        expect($controller)->not->toContain("'__extraData' => \$extra");
    });

    test('Controller::index() delegates pagination extraction to BaseController', function () use ($controller): void {
        expect($controller)->toContain('return $this->sendResponse($paginator, \'\', 200, $extra)');
    });
});

describe('R-PKG-024 — Package-wide invariant: NO data.data nesting', function (): void {
    // Scan all PHP files under src/ for any pattern that could produce
    // `data: { data: [...] }` in the JSON response.
    $srcFiles = glob(packageRootRPkg024() . '/src/**/*.php');
    expect($srcFiles)->not->toBeEmpty('Package must have source files');

    test('No src/ file passes a paginator wrapped in `data` key to sendResponse()', function () use ($srcFiles): void {
        $violations = [];
        foreach ($srcFiles as $file) {
            $contents = file_get_contents($file);
            $relative = str_replace(packageRootRPkg024() . '/', '', $file);

            // Pattern: sendResponse([ followed by 'data' => $paginate*$ or ->paginate(
            if (preg_match_all(
                '/sendResponse\s*\(\s*\[[\s\S]{0,500}?[\'"]data[\'"]\s*=>\s*(\$[a-zA-Z_]*pag[a-zA-Z_]*|\$[a-zA-Z_]*cursor[a-zA-Z_]*|[^,]+->paginate\s*\([^)]*\)|[^,]+->cursorPaginate\s*\([^)]*\))/',
                $contents,
                $matches
            )) {
                foreach ($matches[0] as $match) {
                    $violations[] = $relative . ': ' . trim($match);
                }
            }
        }

        expect($violations)->toBeEmpty(
            "Package must NOT contain `data.data` nesting patterns. Found:\n" . implode("\n", $violations)
        );
    });

    test('No src/ file checks the removed top_level_extra_data config flag (GA removes the flag)', function () use ($srcFiles): void {
        $violations = [];
        foreach ($srcFiles as $file) {
            $contents = file_get_contents($file);
            $relative = str_replace(packageRootRPkg024() . '/', '', $file);

            if (str_contains($contents, 'top_level_extra_data')) {
                $violations[] = $relative;
            }
        }

        expect($violations)->toBeEmpty(
            "Package must NOT reference the removed rc12 flag. Found in:\n" . implode("\n", $violations)
        );
    });
});

describe('R-PKG-024 — Config flag removed', function (): void {
    $config = readSourceRPkg024('config/mk_director.php');

    test('config/mk_director.php does NOT declare the response.top_level_extra_data flag (R-PKG-024 GA removes the flag)', function () use ($config): void {
        expect($config)->not->toContain("'top_level_extra_data'");
        expect($config)->not->toContain('top_level_extra_data');
    });

    test('config/mk_director.php response section is empty (no opt-in flags remaining)', function () use ($config): void {
        // The `response` key exists for future use but is empty post-R-PKG-024.
        expect($config)->toContain("'response' => [");
    });
});
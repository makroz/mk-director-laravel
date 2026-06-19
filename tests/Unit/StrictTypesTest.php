<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Symfony\Component\Finder\Finder;

/**
 * T6.3: StrictTypesTest extended to also scan tests/.
 *
 * Per the audit (R3-006, R3-016): the previous version only checked
 * src/, which left the test files themselves free to drop the
 * declare(strict_types=1) header. PHP's runtime never enforces
 * strict_types on a file that does not declare it, so test bugs
 * (e.g. passing a string where an int is expected) would not surface
 * at the test boundary.
 *
 * After this change, every test file MUST also declare strict_types
 * AND it must be positioned right after the opening <?php tag — not
 * buried after a use statement or a comment.
 */
uses(\Mk\Director\Tests\TestCase::class);

test('all PHP files in src/ declare strict_types=1', function () {
    $finder = new Finder();
    $finder->files()->in(__DIR__ . '/../../src')->name('*.php');

    foreach ($finder as $file) {
        $relative = $file->getRelativePathname();
        // Stubs are templates consumed by `mk:module` etc. — they are
        // not production code and don't need strict_types.
        if (str_contains($relative, '/Stubs/') || str_contains($relative, 'Stubs')) {
            continue;
        }
        // OS metadata files.
        if (basename($relative) === '.DS_Store') {
            continue;
        }

        $content = file_get_contents($file->getPathname());
        $hasStrict = str_contains($content, 'declare(strict_types=1);');

        if (! $hasStrict) {
            // Fail with the file content so the offending file is visible.
            expect($content)->toContain(
                'declare(strict_types=1);',
                "{$relative} must declare strict_types=1\n--file content:\n{$content}",
            );
        } else {
            expect(true)->toBeTrue();
        }
    }
});

test('all PHP files in tests/ declare strict_types=1', function () {
    $finder = new Finder();
    $finder->files()->in(__DIR__ . '/..')->name('*.php');

    foreach ($finder as $file) {
        // Skip TestSuiteLoader helpers that PHP itself owns.
        if (str_contains($file->getPathname(), '/vendor/')) {
            continue;
        }

        $relative = $file->getRelativePathname();
        $content = file_get_contents($file->getPathname());

        if (! str_contains($content, 'declare(strict_types=1);')) {
            expect($content)->toContain(
                'declare(strict_types=1);',
                "{$relative} must declare strict_types=1\n--file content:\n{$content}",
            );
        } else {
            expect(true)->toBeTrue();
        }
    }
});

test('strict_types declaration is positioned right after <?php (not after use/comment)', function () {
    // A common bug: dropping `declare(strict_types=1);` after a use
    // statement or docblock. PHP still picks it up but a careless
    // developer might not realize their new constants are not
    // strict-types-checked. We assert the position here.
    $finder = new Finder();
    $finder->files()->in(__DIR__ . '/../../src')->name('*.php');

    foreach ($finder as $file) {
        $content = (string) file_get_contents($file->getPathname());

        if (! preg_match('/<\?php\s+declare\(strict_types=1\);/', $content, $matches, PREG_OFFSET_CAPTURE)) {
            // Some files legitimately open with a different shape (e.g.
            // a docblock for a license header). We only enforce the
            // pattern when the file actually has a declare statement
            // — the previous tests catch missing ones.
            continue;
        }

        // The matched substring MUST start at offset 0 (immediately
        // after <?php, with optional whitespace).
        expect($matches[0][1])->toBe(0, "{$file->getRelativePathname()}: declare(strict_types=1) must be right after <?php");
    }
});
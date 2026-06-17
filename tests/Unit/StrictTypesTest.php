<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit;

use Symfony\Component\Finder\Finder;

uses(\Mk\Director\Tests\TestCase::class);

test('all PHP files in src declare strict_types', function () {
    $finder = new Finder();
    $finder->files()->in(__DIR__ . '/../../src')->name('*.php');

    foreach ($finder as $file) {
        $content = file_get_contents($file->getPathname());

        // El declare debe estar presente
        expect($content)->toContain('declare(strict_types=1);');
    }
});

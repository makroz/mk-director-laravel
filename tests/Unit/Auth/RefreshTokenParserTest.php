<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Unit\Auth;

use Mk\Director\Auth\Services\InvalidRefreshTokenException;
use Mk\Director\Auth\Services\RefreshTokenParser;

/**
 * Unit tests for RefreshTokenParser — R-PKG-014 BUG-07 fix.
 *
 * Cubre los edge cases del parsing `<id>|<plaintext>` de Sanctum v4.
 */
uses(\PHPUnit\Framework\TestCase::class);

beforeEach(function () {
    $this->parser = new RefreshTokenParser();
});

test('parse token válido retorna [id, plaintext]', function () {
    [$id, $plaintext] = $this->parser->parse('123|abcdefghijklmnopqrstuvwxyz123456');

    expect($id)->toBe(123);
    expect($plaintext)->toBe('abcdefghijklmnopqrstuvwxyz123456');
});

test('parse token con id muy grande retorna int correcto', function () {
    $bigId = 999999999;
    [$id, $plaintext] = $this->parser->parse("{$bigId}|some-plaintext-1234567890");

    expect($id)->toBe($bigId);
});

test('parse sin pipe lanza excepción malformed', function () {
    $this->parser->parse('no-pipe-token');
})->throws(InvalidRefreshTokenException::class);

test('parse con id no numérico lanza excepción', function () {
    $this->parser->parse('abc|plaintext-1234567890');
})->throws(InvalidRefreshTokenException::class);

test('parse con plaintext muy corto lanza excepción', function () {
    $this->parser->parse('123|short');
})->throws(InvalidRefreshTokenException::class);

test('parse con pipe al final lanza excepción', function () {
    $this->parser->parse('123|');
})->throws(InvalidRefreshTokenException::class);

test('parse con pipe al inicio lanza excepción', function () {
    $this->parser->parse('|plaintext');
})->throws(InvalidRefreshTokenException::class);
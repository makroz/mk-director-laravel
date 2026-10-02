<?php

declare(strict_types=1);

use Mk\Director\Push\PushMessage;

/**
 * El canal de un mensaje: mensaje > canal de la config > default, y la
 * validación de lo que viaja a FCM como nombre de recurso o URL.
 */
const PUSH_CHANNELS = [
    'payments' => ['name' => 'Pagos', 'sound' => 'chime.wav', 'importance' => 'high', 'icon' => 'ic_payment', 'color' => '#16A34A'],
    'default' => ['name' => 'Avisos', 'color' => '#1D4ED8'],
];

test('message fields override channel config', function () {
    $message = (new PushMessage('Hola', 'Uno', channel: 'payments', sound: 'default', icon: 'ic_other', color: '#000000'))
        ->resolvedWith(PUSH_CHANNELS, 'default');

    expect([$message->channel, $message->sound, $message->icon, $message->color])
        ->toBe(['payments', 'default', 'ic_other', '#000000']);
});

test('channel config fills missing fields', function () {
    $message = (new PushMessage('Hola', 'Uno', ['kind' => 'x'], url: '/wallet', channel: 'payments', image: 'https://cdn.test/a.png'))
        ->resolvedWith(PUSH_CHANNELS, 'default');

    expect([$message->channel, $message->sound, $message->icon, $message->color, $message->image, $message->url, $message->data])
        ->toBe(['payments', 'chime.wav', 'ic_payment', '#16A34A', 'https://cdn.test/a.png', '/wallet', ['kind' => 'x']]);
});

test('a message without channel goes to the default channel', function () {
    $message = (new PushMessage('Hola', 'Uno'))->resolvedWith(PUSH_CHANNELS, 'default');

    expect([$message->channel, $message->sound, $message->color])->toBe(['default', null, '#1D4ED8']);
});

test('without a default channel nor config the message stays as is', function () {
    $message = (new PushMessage('Hola', 'Uno', channel: 'unknown'))->resolvedWith([], null);

    expect([$message->channel, $message->sound, $message->icon, $message->color])->toBe(['unknown', null, null, null]);
    expect((new PushMessage('Hola', 'Uno'))->resolvedWith([], null)->channel)->toBeNull();
});

test('a message serialized before channels existed still resolves', function () {
    // Lo que dejó en la cola el corte 5: el mismo objeto con sus 4 propiedades.
    $old = 'O:'.strlen(PushMessage::class).':"'.PushMessage::class.'":4:{s:5:"title";s:4:"Hola";s:4:"body";s:3:"Uno";s:4:"data";a:0:{}s:3:"url";s:7:"/wallet";}';

    $message = unserialize($old)->resolvedWith(PUSH_CHANNELS, 'default');

    expect([$message->title, $message->url, $message->channel, $message->image, $message->color])
        ->toBe(['Hola', '/wallet', 'default', null, '#1D4ED8']);
});

test('invalid customization is rejected without echoing the value', function (array $fields, string $error) {
    try {
        new PushMessage('Hola', 'Uno', ...$fields);
        $this->fail('debió rechazarlo');
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain($error)
            ->and($e->getMessage())->not->toContain((string) array_values($fields)[0]);
    }
})->with([
    'http image' => [['image' => 'http://cdn.test/a.png'], "'image'"],
    'not a url' => [['image' => 'https://'], "'image'"],
    'javascript' => [['image' => 'javascript:alert(1)'], "'image'"],
    'sound path' => [['sound' => '../evil.wav'], "'sound'"],
    'sound url' => [['sound' => 'https://x.test/a.wav'], "'sound'"],
    'sound ext' => [['sound' => 'chime.exe'], "'sound'"],
    'sound upper' => [['sound' => 'Chime'], "'sound'"],
    'sound newline' => [['sound' => "chime\n"], "'sound'"],
    'icon dash' => [['icon' => 'ic-payment'], "'icon'"],
    'icon ext' => [['icon' => 'ic_payment.png'], "'icon'"],
    'color short' => [['color' => '#FFF'], "'color'"],
    'color name' => [['color' => 'blue'], "'color'"],
    'color newline' => [['color' => "#16A34A\n"], "'color'"],
]);

test('channel config is validated like the message', function () {
    expect(fn () => (new PushMessage('Hola', 'Uno', channel: 'bad'))->resolvedWith(['bad' => ['color' => 'green']]))
        ->toThrow(InvalidArgumentException::class, "'color'");
});

test('valid customization is accepted', function () {
    $message = new PushMessage('Hola', 'Uno', channel: 'payments', image: 'https://cdn.test/a.png?x=1', sound: 'chime.wav', icon: 'ic_payment', color: '#16a34a');

    expect($message->sound)->toBe('chime.wav')
        ->and(new PushMessage('Hola', 'Uno', sound: 'default'))->toBeInstanceOf(PushMessage::class)
        ->and(new PushMessage('Hola', 'Uno', sound: 'chime'))->toBeInstanceOf(PushMessage::class);
});

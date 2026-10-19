<?php

declare(strict_types=1);

namespace Mk\Director\Tests\Concerns;

use Aws\CommandInterface;
use Aws\Result;
use Closure;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\RequestInterface;
use Throwable;

/**
 * El transporte de un disk `s3` REAL en los tests: registra cada comando que el
 * SDK manda y contesta con lo que diga `$responses`, sin red.
 *
 * El disk es de verdad (driver `s3`, credenciales falsas): firmar un link es un
 * HMAC local, así que los links que se miden son los que vería un navegador. Lo
 * único falso es la otra punta del cable.
 */
final class FakeS3
{
    /** @var list<CommandInterface> */
    public array $commands = [];

    /** @var array<string, array<string, mixed>|Closure|Throwable> */
    public array $responses = [
        'CreateMultipartUpload' => ['UploadId' => 'mp-upload-1'],
    ];

    /** Arma el disk `$name` sobre este transporte y lo devuelve listo para mirar. */
    public static function disk(string $name, string $bucket = 'bucket-test'): self
    {
        $fake = new self;

        config(["filesystems.disks.{$name}" => [
            'driver' => 's3',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'region' => 'auto',
            'bucket' => $bucket,
            'endpoint' => 'http://s3.test',
            'use_path_style_endpoint' => true,
            'throw' => true,
            'handler' => $fake,
        ]]);
        Storage::forgetDisk($name);

        return $fake;
    }

    public function __invoke(CommandInterface $command, RequestInterface $request): PromiseInterface
    {
        $this->commands[] = $command;
        $response = $this->responses[$command->getName()] ?? [];
        $response = $response instanceof Closure ? $response($command) : $response;

        return $response instanceof Throwable ? Create::rejectionFor($response) : Create::promiseFor(new Result($response));
    }

    /** @return list<CommandInterface> */
    public function named(string $name): array
    {
        return array_values(array_filter($this->commands, fn (CommandInterface $c): bool => $c->getName() === $name));
    }
}

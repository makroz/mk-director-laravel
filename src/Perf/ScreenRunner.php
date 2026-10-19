<?php

declare(strict_types=1);

namespace Mk\Director\Perf;

use Closure;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The module's screens, requested through the app's own HTTP kernel in-process: routes, middleware, auth,
 * policies, controller, resource, exactly as a client gets them, on the scratch database.
 *
 * This version measures the default list of each CRUD record (the `index` GET route of each SmartController of
 * the module). Filters, search, sort, count and show are the next slice.
 *
 * What is switched off for the measure, and why: rate limiting (50 requests in a second would be 429s, not
 * timings), the cache store (`array`, so a cached answer from a previous run never stands in for the query) and
 * `auto_cache`.
 */
final class ScreenRunner
{
    /** @var list<string> */
    private array $skipped = [];

    private bool $isolated = false;

    /**
     * @param  array{models: list<array>, routes: list<array>, crud: list<array>}  $intro  `Introspector::collect()` of the app
     * @return list<array{record: string, screen: string, param: ?string, uri: string, token: string}>
     */
    public function screens(string $module, string $token, array $intro, string $scope = 'admin'): array
    {
        $this->skipped = [];
        $controllers = [];
        foreach ($intro['crud'] as $c) {
            if ($c['module'] === $module) {
                $controllers[$c['controller']] = $c['mkConfig'];
            }
        }
        $out = [];
        foreach ($intro['routes'] as $route) {
            [$class, $method] = array_pad(explode('@', $route['action'], 2), 2, null);
            if ($method !== 'index' || ! isset($controllers[$class]) || ! in_array('GET', $route['methods'], true)) {
                continue;
            }
            $record = class_basename($controllers[$class]['model'] ?? $class);
            if ($route['scopes'] !== [] && ! in_array($scope, $route['scopes'], true)) {
                $this->skipped[] = "{$record}: GET /{$route['uri']} no es una ruta de {$scope}.";

                continue;
            }
            $uri = $this->withParameters($route['uri'], $module, $intro['models']);
            if ($uri === null) {
                $this->skipped[] = "{$record}: GET /{$route['uri']} tiene un parámetro sin fila a la que apuntar.";

                continue;
            }
            $out[] = ['record' => $record, 'screen' => 'list', 'param' => null, 'uri' => $uri, 'token' => $token];
        }

        return $out;
    }

    /** @return list<string> screens that could not be measured, and why (in Spanish: the studio shows it) */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /**
     * One warm-up request, then `$runs` measured ones.
     *
     * @param  array{record: string, screen: string, param: ?string, uri: string, token: string}  $screen
     * @return array{status: int, medianMs: float, p95Ms: float, queryCount: int, duplicates: int, sql: string}
     */
    public function measure(array $screen, int $runs, QueryRecorder $rec): array
    {
        $this->isolate();
        $kernel = app(HttpKernel::class);
        $send = static function () use ($kernel, $screen): int {
            // A guard memoizes its user: without this every request after the first skips the token lookup.
            app('auth')->forgetGuards();
            $request = Request::create('/'.ltrim($screen['uri'], '/'), 'GET', server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$screen['token'],
            ]);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            return $response->getStatusCode();
        };

        $status = $send();
        $times = [];
        $queries = [];
        for ($n = 0; $n < $runs; $n++) {
            $start = hrtime(true);
            $queries = $rec->record(static function () use ($send, &$status): void {
                $status = $send();
            });
            $times[] = (hrtime(true) - $start) / 1e6;
        }
        sort($times);
        usort($queries, static fn (array $a, array $b): int => $b['ms'] <=> $a['ms']);

        return [
            'status' => $status,
            'medianMs' => round($times[intdiv(count($times), 2)], 2),
            'p95Ms' => round($times[(int) ceil(count($times) * 0.95) - 1], 2),
            'queryCount' => count($queries),
            'duplicates' => count($queries) - count(array_unique(array_column($queries, 'sql'))),
            'sql' => $queries[0]['sql'] ?? '',
        ];
    }

    /** `{course}` -> the key of the first row of the module's model named so; null when there is none. */
    private function withParameters(string $uri, string $module, array $models): ?string
    {
        $missing = false;
        $uri = preg_replace_callback('/\{(\w+)\??\}/', static function (array $m) use ($module, $models, &$missing): string {
            foreach ($models as $model) {
                if ($model['module'] === $module && Str::snake(class_basename($model['class'])) === $m[1]) {
                    $key = DB::table($model['table'])->value((new $model['class'])->getKeyName());
                    if ($key !== null) {
                        return (string) $key;
                    }
                }
            }
            $missing = true;

            return $m[0];
        }, $uri);

        return $missing ? null : (string) $uri;
    }

    private function isolate(): void
    {
        if ($this->isolated) {
            return;
        }
        $this->isolated = true;
        $passThrough = static fn (): object => new class
        {
            public function handle(Request $request, Closure $next, mixed ...$limits): mixed
            {
                return $next($request);
            }
        };
        app()->bind(ThrottleRequests::class, $passThrough);
        app()->bind(ThrottleRequestsWithRedis::class, $passThrough);
        config(['cache.default' => 'array', 'mk_director.features.auto_cache' => false]);
        app('cache')->setDefaultDriver('array');
    }
}

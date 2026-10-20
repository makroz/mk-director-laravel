<?php

declare(strict_types=1);

namespace Mk\Director\Perf;

use Closure;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * The module's screens, requested through the app's own HTTP kernel in-process: routes, middleware, auth,
 * policies, controller, resource, exactly as a client gets them, on the scratch database.
 *
 * Every screen of each CRUD record of the module (its SmartController's `index` and `show` GET routes and its
 * `$mkConfig`), with the worst case the volume holds:
 *
 *  - `list`: the default list;
 *  - `filter`: each `features.allowed_filters` column, with its most frequent value;
 *  - `search`: `?q=` with the first characters of a value of the first `searchable` column (every generated value
 *    of a column starts the same way, so it matches the most rows);
 *  - `sort`: each allowed filter the list can sort by (fillable, key or timestamps), unless `features.sorting` is off;
 *  - `count`: the paginator's `count(*)` of the default list (not with cursor pagination). It is not a request of
 *    its own: its time is that query's own time, not the request's;
 *  - `show`: the detail of the record's first row (the seeder hands its children to the first rows most).
 *
 * Every list-like screen is also requested at `per_page` 1, 8 and 20 (`queriesAt`): a query count that grows with
 * the rows is a query per row (N+1).
 *
 * What is switched off for the measure, and why: rate limiting (50 requests in a second would be 429s, not
 * timings), the cache store (`array`, so a cached answer from a previous run never stands in for the query) and
 * `auto_cache`.
 */
final class ScreenRunner
{
    public const SLOPE = [1, 8, 20];

    /** @var list<string> */
    private array $skipped = [];

    private bool $isolated = false;

    /**
     * @param  array{models: list<array>, routes: list<array>, crud: list<array>}  $intro  `Introspector::collect()` of the app
     * @return list<array{record: string, table: ?string, screen: string, param: ?string, uri: string, query: array, token: string}>
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
            if (! in_array($method, ['index', 'show'], true) || ! isset($controllers[$class]) || ! in_array('GET', $route['methods'], true)) {
                continue;
            }
            $config = $controllers[$class];
            $model = $config['model'] ?? null;
            $record = class_basename($model ?? $class);
            if ($route['scopes'] !== [] && ! in_array($scope, $route['scopes'], true)) {
                $this->skipped[] = "{$record}: GET /{$route['uri']} no es una ruta de {$scope}.";

                continue;
            }
            [$uri, $id] = $this->withParameters($route['uri'], $module, $intro['models'], $method === 'show' ? $model : null);
            if ($uri === null) {
                $this->skipped[] = "{$record}: GET /{$route['uri']} tiene un parámetro sin fila a la que apuntar.";

                continue;
            }
            $table = $model !== null && is_subclass_of($model, Model::class) ? (new $model)->getTable() : null;
            $base = ['record' => $record, 'table' => $table, 'uri' => $uri, 'token' => $token];
            if ($method === 'show') {
                $out[] = [...$base, 'screen' => 'show', 'param' => $id, 'query' => []];

                continue;
            }
            foreach ($this->listScreens($record, $model, $config) as [$screen, $param, $query]) {
                $out[] = [...$base, 'screen' => $screen, 'param' => $param, 'query' => $query];
            }
        }

        return $out;
    }

    /** @return list<string> screens that could not be measured, and why (in Spanish: the studio shows it) */
    public function skipped(): array
    {
        return $this->skipped;
    }

    /**
     * One warm-up request, then `$runs` measured ones; then, for a list-like screen, one request at each SLOPE size.
     *
     * `sql` is the screen's main query: the paginator's count on `count`; else the slowest shape (median of its own
     * time) that reads the record's table and is not that count, so neither the total (it has its own screen) nor the
     * token and ability lookups every request makes stand in for the screen. `shapes` is every SELECT shape the screen ran, with what really ran, for the plan readers.
     *
     * @param  array{record: string, table: ?string, screen: string, param: ?string, uri: string, query: array, token: string}  $screen
     * @return array{status: int, medianMs: float, p95Ms: float, queryCount: int, duplicates: int, sql: string,
     *     queriesAt: ?array{perPage1: int, perPage8: int, perPage20: int}, shapes: array<string, array{raw: string, bindings: array}>}
     */
    public function measure(array $screen, int $runs, QueryRecorder $rec): array
    {
        $this->isolate();
        $kernel = app(HttpKernel::class);
        $send = static function (array $extra = []) use ($kernel, $screen): int {
            // A guard memoizes its user: without this every request after the first skips the token lookup.
            app('auth')->forgetGuards();
            $query = http_build_query([...$screen['query'], ...$extra]);
            $request = Request::create('/'.ltrim($screen['uri'], '/').($query === '' ? '' : "?{$query}"), 'GET', server: [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$screen['token'],
            ]);
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);

            return $response->getStatusCode();
        };

        $status = $send();
        $times = [];
        $log = [];
        $shapes = [];
        for ($n = 0; $n < $runs; $n++) {
            $start = hrtime(true);
            $log = $rec->record(static function () use ($send, &$status): void {
                $status = $send();
            });
            $times[] = (hrtime(true) - $start) / 1e6;
            foreach ($log as $q) {
                $shapes[$q['sql']] ??= ['raw' => $q['raw'], 'bindings' => $q['bindings'], 'ms' => []];
                $shapes[$q['sql']]['ms'][] = $q['ms'];
            }
        }

        $count = '/^select count\\(\\*\\) as \\W?aggregate\\b/i';
        $table = $screen['table'] === null ? null : '/\\bfrom\\W+'.preg_quote($screen['table'], '/').'\\b/i';
        $own = $table === null ? [] : array_filter($shapes, static fn (string $sql): bool => preg_match($table, $sql) === 1, ARRAY_FILTER_USE_KEY);
        // The page's own query, not its total: the total has its own screen.
        $page = array_filter($own, static fn (string $sql): bool => preg_match($count, $sql) !== 1, ARRAY_FILTER_USE_KEY);
        $main = null;
        foreach ($screen['screen'] === 'count' ? $shapes : ($page ?: $own ?: $shapes) as $sql => $s) {
            $better = $screen['screen'] === 'count'
                ? preg_match($count, $sql) === 1
                : $main === null || self::stats($s['ms'])[0] > self::stats($shapes[$main]['ms'])[0];
            if ($better) {
                $main = $sql;
            }
        }
        [$median, $p95] = $screen['screen'] === 'count' && $main !== null ? self::stats($shapes[$main]['ms']) : self::stats($times);

        $queriesAt = null;
        if (in_array($screen['screen'], ['list', 'filter', 'search', 'sort'], true)) {
            $at = [];
            foreach (self::SLOPE as $perPage) {
                $send(['per_page' => $perPage]); // its own warm-up: a first request at a size may load what the next reuse
                $at["perPage{$perPage}"] = count($rec->record(static fn () => $send(['per_page' => $perPage])));
            }
            $queriesAt = $at;
        }

        return [
            'status' => $status,
            'medianMs' => $median,
            'p95Ms' => $p95,
            'queryCount' => count($log),
            'duplicates' => QueryRecorder::duplicates($log),
            'sql' => $main ?? '',
            'queriesAt' => $queriesAt,
            'shapes' => array_map(static fn (array $s): array => ['raw' => $s['raw'], 'bindings' => $s['bindings']], array_filter(
                $shapes,
                static fn (array $s): bool => QueryRecorder::isSelect($s['raw']),
            )),
        ];
    }

    /** @return array{0: float, 1: float} median and p95 of `$ms` */
    public static function stats(array $ms): array
    {
        sort($ms);
        $n = count($ms);

        return $n === 0 ? [0.0, 0.0] : [round($ms[intdiv($n, 2)], 2), round($ms[(int) ceil($n * 0.95) - 1], 2)];
    }

    /** @return list<array{0: string, 1: ?string, 2: array}> screen, param and query string of each list-like screen */
    private function listScreens(string $record, ?string $modelClass, array $config): array
    {
        $features = (array) ($config['features'] ?? []);
        $on = static fn (string $f, mixed $default = true): mixed => $features[$f] ?? config("mk_director.features.{$f}", $default);
        $model = $modelClass !== null && is_subclass_of($modelClass, Model::class) ? new $modelClass : null;
        $out = [['list', null, []]];

        $filters = $on('filters') ? array_values((array) ($features['allowed_filters'] ?? [])) : [];
        foreach ($filters as $column) {
            $value = $model === null ? null : $this->frequent($model->getTable(), $column);
            if ($value === null) {
                $this->skipped[] = "{$record}: el filtro {$column} no tiene un valor con el que probarlo.";

                continue;
            }
            $out[] = ['filter', $column, ['filter' => [$column => $value]]];
        }

        $searchable = $on('search') ? (array) ($config['searchable'] ?? []) : [];
        if ($searchable !== []) {
            $term = $model === null ? null : $this->term($model, $searchable);
            if ($term === null) {
                $this->skipped[] = "{$record}: la búsqueda no encontró un valor con el que probarla.";
            } else {
                $out[] = ['search', $term, ['q' => $term]];
            }
        }

        if ($model !== null && $on('sorting')) {
            // What `ListManager::applySorting()` accepts.
            $sortable = [...$model->getFillable(), $model->getKeyName(), 'created_at', 'updated_at'];
            foreach (array_intersect($filters, $sortable) as $column) {
                $out[] = ['sort', $column, ['sort' => $column]];
            }
        }

        if ($on('pagination_type', 'length_aware') !== 'cursor') {
            $out[] = ['count', null, []];
        }

        return $out;
    }

    /** The column's most frequent value (the worst case of the filter), as a query string carries it. */
    private function frequent(string $table, string $column): ?string
    {
        try {
            $row = DB::table($table)->select($column)->selectRaw('count(*) as n')->whereNotNull($column)
                ->groupBy($column)->orderByDesc('n')->first();
        } catch (Throwable) {
            return null; // not a column of the table: the module filters it in a hook
        }
        $value = $row === null ? null : ((array) $row)[$column];

        return is_bool($value) ? (string) (int) $value : ($value === null ? null : (string) $value);
    }

    /** The first characters of a value of the first searchable field that has one (a `relation.column` too). */
    private function term(Model $model, array $searchable): ?string
    {
        $length = max(4, (int) config('mk_director.search.min_chars', 3));
        foreach ($searchable as $field) {
            try {
                $target = $model;
                $parts = explode('.', $field);
                $column = array_pop($parts);
                foreach ($parts as $relation) {
                    $target = $target->{$relation}()->getRelated();
                }
                $value = DB::table($target->getTable())->whereNotNull($column)->orderBy($target->getKeyName())->value($column);
            } catch (Throwable) {
                continue;
            }
            $term = is_string($value) ? mb_substr(trim($value), 0, $length) : null;
            if ($term !== null && mb_strlen($term) >= $length) {
                return $term;
            }
        }

        return null;
    }

    /**
     * `{course}` -> the key of the first row of the module's model named so; on a `show` route the last parameter is
     * the record itself, whatever its name. `[null, null]` when a parameter has no row to point at.
     *
     * @return array{0: ?string, 1: ?string} the uri and the record's own key (show only)
     */
    private function withParameters(string $uri, string $module, array $models, ?string $showModel): array
    {
        preg_match_all('/\{(\w+)\??\}/', $uri, $all);
        $last = end($all[0]) ?: null;
        $missing = false;
        $id = null;
        $uri = preg_replace_callback('/\{(\w+)\??\}/', static function (array $m) use ($module, $models, $showModel, $last, &$missing, &$id): string {
            $classes = $showModel !== null && $m[0] === $last ? [$showModel] : array_column(array_filter(
                $models,
                static fn (array $model): bool => $model['module'] === $module && Str::snake(class_basename($model['class'])) === $m[1],
            ), 'class');
            foreach ($classes as $class) {
                $instance = new $class;
                $key = DB::table($instance->getTable())->orderBy($instance->getKeyName())->value($instance->getKeyName());
                if ($key !== null) {
                    if ($showModel !== null && $m[0] === $last) {
                        $id = (string) $key;
                    }

                    return (string) $key;
                }
            }
            $missing = true;

            return $m[0];
        }, $uri);

        return $missing ? [null, null] : [(string) $uri, $id];
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

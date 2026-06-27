<?php

declare(strict_types=1);

namespace Mk\Director\Controllers;

use Illuminate\Routing\Controller as LaravelController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class BaseController extends LaravelController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected $debugMsgs = [];

    /**
     * Standard JSON Success Response
     *
     * R-PKG-023 (rc12): added optional 4th parameter `array $extra = []`.
     * When the caller passes a non-empty `$extra` AND the config flag
     * `mk_director.response.top_level_extra_data` is true, the response
     * envelope emits `__extraData` as a TOP-LEVEL sibling of `data` —
     * matching the @makroz/core `MkResponse<T>` contract and the
     * `useMkInfiniteList` consumption shape in web + mobile.
     *
     * BC strategy:
     *   - When `$extra` is empty (default), behavior is IDENTICAL to rc11.
     *   - When the flag is `false` (rc12 default), behavior is IDENTICAL
     *     to rc11 even if the caller passes `$extra`.
     *   - The flag flips to `true` at GA. Between rc12 and GA, consumers
     *     opt-in per-environment via `MK_DIRECTOR_RESPONSE_TOP_LEVEL_EXTRA_DATA=true`.
     *
     * Coexistence during rc12 → GA: callers that don't pass `$extra` (or
     * keep the flag off) see the legacy nested shape. Callers that opt
     * in (flag on + passing `$extra`) get the top-level shape.
     */
    protected function sendResponse($result, $message = '', $code = 200, array $extra = [])
    {
        $response = [
            'success'   => true,
            'message'   => $message,
            'data'      => $this->autoTransform($result),
            'debugMsg'  => $this->debugMsgs
        ];

        // R-PKG-023: top-level __extraData when caller passes $extra
        // AND the config flag is enabled. BC-safe: when $extra is empty
        // OR the flag is off, the response shape is unchanged.
        if ($extra !== [] && config('mk_director.response.top_level_extra_data', false)) {
            $response['__extraData'] = $extra;
        }

        if (config('mk_director.debug', false)) {
            $response = array_merge($response, $this->getDebugData());
        }

        return response()->json($response, $code);
    }

    /**
     * Standard JSON Error Response
     */
    protected function sendError($message, $errors = [], $code = 404)
    {
        $response = [
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
            'debugMsg'=> $this->debugMsgs
        ];

        return response()->json($response, $code);
    }

    /**
     * Automatic transformation based on Model's apiResource property.
     */
    protected function autoTransform($data)
    {
        if ($data instanceof \Illuminate\Database\Eloquent\Model) {
            return isset($data->apiResource) ? new $data->apiResource($data) : $data;
        }

        if ($data instanceof \Illuminate\Support\Collection || $data instanceof \Illuminate\Pagination\LengthAwarePaginator) {
            $first = $data->first();
            if ($first && isset($first->apiResource)) {
                return $first->apiResource::collection($data);
            }
        }

        return $data;
    }

    /**
     * Sophisticated Query Debugger (Explain Analysis)
     *
     * R2-010 hardening (1.2.2): the debug payload includes raw EXPLAIN
     * output and query bindings, which can leak PII / schema details
     * (column names, values) to anyone who can hit a JSON endpoint with
     * `?debug=true&_debug=1`. The `mk_director.debug` config flag
     * already gates entry to this method, but a misconfigured production
     * app that flips the flag to true is one footgun away from leaking
     * data. We now require an authenticated user with `super-admin` or
     * `dev` role via `hasRole()` — apps that do not implement `hasRole`
     * on their User model get nothing (fail-safe).
     */
    protected function getDebugData()
    {
        if (request()->input('_debug') != 1) return [];

        // Role gate (R2-010): the debug payload leaks EXPLAIN + bindings,
        // so it must only flow to operators, not regular users. If the
        // consumer app's User model does not implement `hasRole`, return
        // empty (fail-safe — no leak by default).
        $user = request()->user();
        if (! $user || ! method_exists($user, 'hasRole')) {
            return [];
        }
        if (! $user->hasRole('super-admin') && ! $user->hasRole('dev')) {
            return [];
        }

        $queries = DB::getQueryLog();
        $totalTime = 0;

        foreach ($queries as &$query) {
            $totalTime += $query['time'];
            if ($query['time'] > 100) {
                 $query['optimization_alert'] = "Slow query detected (>100ms).";
            }

            // R-PKG-024 (rc13): the previous code interpolated the query
            // directly into a database call that prepended the string
            // `EXPLAIN` to the SQL, which is a SQL injection vector when
            // the query contains user-controlled values (an authenticated
            // `super-admin` or `dev` could pass `?debug=true&_debug=1` to
            // reach this path). The new behavior:
            //
            //   1. Always log slow-query candidates via `Log::debug()` for
            //      offline analysis. Safe default.
            //   2. When the config flag `mk_director.debug.explain_enabled`
            //      is true, log the SQL as a `warning` so a developer can
            //      run the explain manually in a safe environment. The query
            //      is NOT interpolated into any database call.
            //
            // Migration: see CHANGELOG rc13. No BC break for consumers that
            // never set `explain_enabled` — they get the same shape, just
            // safer.
            if (isset($query['query']) && str_starts_with(strtolower($query['query']), 'select')) {
                Log::debug('[mk-director] slow query candidate', [
                    'sql' => $query['query'],
                    'bindings' => $query['bindings'],
                    'time_ms' => $query['time'],
                ]);

                if (config('mk_director.debug.explain_enabled', false)) {
                    Log::warning('[mk-director] EXPLAIN query (run manually in safe env):', [
                        'sql' => $query['query'],
                        'bindings' => $query['bindings'],
                    ]);
                    $query['optimization_alert'] = "EXPLAIN logged. Run manually in safe env.";
                }
            }
        }

        return [
            'debug_queries' => $queries,
            'debug_summary' => [
                'count' => count($queries),
                'total_time_ms' => $totalTime
            ]
        ];
    }
}

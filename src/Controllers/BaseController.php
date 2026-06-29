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
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

abstract class BaseController extends LaravelController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected $debugMsgs = [];

    /**
     * Standard JSON Success Response
     *
     * R-PKG-024 (v1.7.0 GA) — SINGLE-LEVEL ENVELOPE. The canonical shape is:
     *
     *   {
     *     "success": true,
     *     "message": "...",
     *     "data": [...items...],            // ← array directo para colecciones
     *     "__extraData": { ... },           // ← SIEMPRE top-level (sibling de `data`)
     *     "debugMsg": []
     *   }
     *
     * PROHIBIDO en este envelope:
     *   ❌ `data: { data: [...], links, meta }` (paginator Laravel nested → data.data)
     *   ❌ `data: { data: {...resource...} }` (resource nested → data.data)
     *   ❌ `__extraData` nested inside `data`
     *
     * When `$result` is a Laravel paginator (LengthAwarePaginator or
     * CursorPaginator), the items are extracted to `data` (array directo
     * via `autoTransform()`) and the pagination metadata (`current_page`,
     * `last_page`, `per_page`, `total`, `next_cursor`, `prev_cursor`) is
     * emitted in `__extraData` top-level (sibling of `data`). The legacy
     * `data: { data: [...], links, meta }` nested shape is REMOVED — no
     * flag, no opt-in, no BC bridge.
     *
     * Migration from rc12 opt-in: the rc12 flag (removed in v1.7.0) is no
     * longer referenced. Consumers that ran with the rc12 flag on see no
     * change. Consumers that ran with the rc12 flag off see the new shape —
     * RETO migration tracked in sprint `2026-06-28-fase-12-retos-bump-v170`.
     *
     * @param mixed $result  The response payload. Can be a Model, Resource,
     *                       Collection, ResourceCollection, scalar, or a
     *                       Laravel paginator (LengthAwarePaginator /
     *                       CursorPaginator). Paginators are auto-flattened.
     * @param string $message  Optional human-readable message (Spanish default).
     * @param int $code  HTTP status code (default 200).
     * @param array $extra  Optional metadata merged into `__extraData` top-level.
     *                      For paginator responses, pagination metadata is
     *                      prepended automatically; caller keys win on conflict.
     */
    protected function sendResponse($result, $message = '', $code = 200, array $extra = [])
    {
        $isPaginator = $result instanceof AbstractPaginator || $result instanceof CursorPaginator;

        if ($isPaginator) {
            $items = $result->items();
            // Wrap as Collection so autoTransform() picks up the model
            // apiResource and emits a flat array of resources (not a paginator).
            $dataPayload = $this->autoTransform(new Collection($items));
            // Pagination metadata ALWAYS emitted in __extraData top-level.
            // Caller-supplied $extra keys win on conflict (merge order: pagination defaults, then $extra).
            $extra = array_merge($this->extractPaginationMetadata($result), $extra);
        } else {
            $dataPayload = $this->autoTransform($result);
        }

        $response = [
            'success'  => true,
            'message'  => $message,
            'data'     => $dataPayload,
            'debugMsg' => $this->debugMsgs,
        ];

        // R-PKG-024: __extraData is ALWAYS top-level (sibling of `data`).
        // Emit whenever paginator (auto-pagination meta) OR caller passed
        // non-empty $extra. Empty __extraData is omitted to keep the
        // response shape minimal for non-list endpoints.
        if ($isPaginator || $extra !== []) {
            $response['__extraData'] = $extra;
        }

        if (config('mk_director.debug', false)) {
            $response = array_merge($response, $this->getDebugData());
        }

        return response()->json($response, $code);
    }

    /**
     * Extract pagination metadata for the `__extraData` top-level field.
     *
     * R-PKG-024: paginator metadata is NO LONGER nested inside `data`.
     * It lives at the top level of the envelope as a sibling of `data`,
     * matching the `@makroz/core` `MkResponse<T>.__extraData` contract
     * and the `useMkInfiniteList` consumption shape in web + mobile.
     *
     * R-PKG-031 PKG-NEW-18 fix (2026-06-28, RETO fase 12 feedback):
     * `has_more_pages` is part of the canonical 5-key snake_case contract
     * for LengthAwarePaginator (pineado in skill § R-PKG-024 + `@makroz/core`
     * `MkListResponse<T>.__extraData` type línea 53). Without it, frontend
     * infinite-scroll hooks that read `has_more_pages` (vs `last_page`)
     * see `undefined` at runtime. `ListManager::getExtraData()` already
     * emitted the 5-key shape; this helper is the canonical path used by
     * `BaseController::sendResponse()` for any controller extending
     * BaseController directly (auth, webhooks, OpenAPI) and was missing
     * the 5th key. Drift fixed: both helpers now emit identical 5-key
     * snake_case shape for LengthAwarePaginator (current_page, last_page,
     * per_page, total, has_more_pages).
     *
     * @param AbstractPaginator|CursorPaginator $paginator
     * @return array{current_page?: int, last_page?: int, per_page?: int, total?: int, has_more_pages?: bool, next_cursor?: string|null, prev_cursor?: string|null}
     */
    protected function extractPaginationMetadata($paginator): array
    {
        $meta = [];

        if ($paginator instanceof LengthAwarePaginator) {
            $meta['current_page']   = $paginator->currentPage();
            $meta['last_page']      = $paginator->lastPage();
            $meta['per_page']       = $paginator->perPage();
            $meta['total']          = $paginator->total();
            $meta['has_more_pages'] = $paginator->hasMorePages();
        }

        if ($paginator instanceof CursorPaginator) {
            $meta['per_page']    = $paginator->perPage();
            $meta['next_cursor'] = $paginator->nextCursor() ? $paginator->nextCursor()->encode() : null;
            $meta['prev_cursor'] = $paginator->previousCursor() ? $paginator->previousCursor()->encode() : null;
        }

        return $meta;
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

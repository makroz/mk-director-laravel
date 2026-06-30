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
     * R-PKG-024 (v1.7.0 GA) — SINGLE-LEVEL ENVELOPE.
     * R-PKG-032 (v1.8.0 MAJOR) — PAGINATION ENVELOPE: pagination metadata is
     * GROUPED under `__extraData.pagination`. The canonical shape is:
     *
     *   {
     *     "success": true,
     *     "message": "...",
     *     "data": [...items...],                  // ← array directo para colecciones
     *     "__extraData": {                        // ← SIEMPRE top-level (sibling de `data`)
     *       "pagination": {                       // ← R-PKG-032: pagination metadata grouped here
     *         "current_page": 1,
     *         "last_page": 5,
     *         "per_page": 20,
     *         "total": 100,
     *         "has_more_pages": true
     *         // CursorPaginator emits per_page + next_cursor + prev_cursor instead
     *       },
     *       "audit_checked": true,                // ← consumer custom keys live FLAT here
     *       "request_id": "req-xxx"
     *     },
     *     "debugMsg": []
     *   }
     *
     * PROHIBIDO en este envelope:
     *   ❌ `data: { data: [...], links, meta }` (paginator Laravel nested → data.data)
     *   ❌ `data: { data: {...resource...} }` (resource nested → data.data)
     *   ❌ `__extraData` nested inside `data`
     *   ❌ Pagination metadata flat at `__extraData.current_page` etc. — must be under `__extraData.pagination` (R-PKG-032)
     *
     * When `$result` is a Laravel paginator (LengthAwarePaginator or
     * CursorPaginator), the items are extracted to `data` (array directo
     * via `autoTransform()`) and the pagination metadata is wrapped under
     * `__extraData.pagination` (LengthAware emits 5 snake_case keys;
     * CursorPaginator emits per_page + next_cursor + prev_cursor). The
     * legacy `data: { data: [...], links, meta }` nested shape is REMOVED
     * — no flag, no opt-in, no BC bridge.
     *
     * Migration from v1.7.x (R-PKG-024):
     *   v1.7.x emitted pagination keys FLAT in `__extraData`
     *   (`response.__extraData.last_page`).
     *   v1.8.0+ groups them under `__extraData.pagination`
     *   (`response.__extraData.pagination.last_page`). Wrap your navigation.
     *
     * Migration from rc12 opt-in: the rc12 flag (removed in v1.7.0) is no
     * longer referenced. Consumers that ran with the rc12 flag on see no
     * change. Consumers that ran with the rc12 flag off see the new shape.
     *
     * @param mixed $result  The response payload. Can be a Model, Resource,
     *                       Collection, ResourceCollection, scalar, or a
     *                       Laravel paginator (LengthAwarePaginator /
     *                       CursorPaginator). Paginators are auto-flattened.
     * @param string $message  Optional human-readable message (Spanish default).
     * @param int $code  HTTP status code (default 200).
     * @param array $extra  Optional metadata merged into `__extraData` top-level.
     *                      For paginator responses, `pagination` is prepended
     *                      automatically under `__extraData.pagination`; caller
     *                      keys win on conflict (caller can override the entire
     *                      `pagination` sub-object by passing `'pagination' => [...]`).
     */
    protected function sendResponse($result, $message = '', $code = 200, array $extra = [])
    {
        $isPaginator = $result instanceof AbstractPaginator || $result instanceof CursorPaginator;

        if ($isPaginator) {
            $items = $result->items();
            // Wrap as Collection so autoTransform() picks up the model
            // apiResource and emits a flat array of resources (not a paginator).
            $dataPayload = $this->autoTransform(new Collection($items));
            // R-PKG-032 (v1.8.0) — pagination metadata is GROUPED under
            // `__extraData.pagination` (not flat anymore). Caller-supplied
            // $extra keys win on conflict (merge order: pagination group
            // defaults, then $extra — so caller can override the entire
            // `pagination` sub-object by passing `'pagination' => [...]`).
            $extra = array_merge(['pagination' => $this->extractPaginationMetadata($result)], $extra);
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
     * Extract pagination metadata for the `__extraData.pagination` grouped field.
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
     * R-PKG-032 (v1.8.0 MAJOR) — PAGINATION ENVELOPE grouping.
     * This helper RETURNS the inner metadata (5 keys for LengthAware,
     * 3 keys for Cursor). The wrapping under `__extraData.pagination`
     * happens in `sendResponse()` (and in `ListManager::getExtraData()`).
     * The helper is intentionally low-level / composable — callers that
     * want pagination metadata without the envelope can call it directly.
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
     *
     * R-PKG-035 HALLAZGO-NEW-FASE15-07 fix (v1.8.3-rc0):
     * Previously this method used `isset($data->apiResource)`, which
     * returns `false` for `protected` properties (PHP visibility quirk)
     * and forces every consumer model to declare `$apiResource` as
     * `public` to work — a footgun that broke the cross-stack RBAC
     * contract (`@makroz/web AdminDto.abilities: string[]`) in RETO
     * fase 15. The fix uses `property_exists()` which inspects the
     * declaration regardless of visibility, then we add an explicit
     * `!== null` check so the abstract `Models\Model::$apiResource = null`
     * default does not instantiate a Resource of `null`.
     *
     * Adicionalmente (v1.8.3-rc0), ahora se procesan arrays recursivamente:
     * si un valor del array es un Model con `$apiResource`, se transforma.
     * Esto fixea el caso `AuthController::login()` que retorna un array
     * `['access_token' => ..., 'admin' => $user]` — antes el user nested
     * salía como modelo Eloquent crudo (con `password`, `pivot`, etc.) en
     * vez de pasar por `AdminResource::toArray()`. Ahora se transforma
     * automáticamente sin que el consumer tenga que envolver manualmente.
     */
    protected function autoTransform($data)
    {
        if ($data instanceof \Illuminate\Database\Eloquent\Model) {
            return property_exists($data, 'apiResource') && $data->apiResource !== null
                ? new $data->apiResource($data)
                : $data;
        }

        if ($data instanceof \Illuminate\Support\Collection || $data instanceof \Illuminate\Pagination\LengthAwarePaginator) {
            $first = $data->first();
            if ($first && property_exists($first, 'apiResource') && $first->apiResource !== null) {
                return $first->apiResource::collection($data);
            }
        }

        // HALLAZGO-NEW-FASE15-07 (recursivo): arrays con valores Model.
        // Casos típicos: AuthController::login() retorna
        // `['access_token' => ..., 'admin' => $user]`. Antes el user
        // nested salía crudo. Ahora se transforma cada Model nested.
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if ($value instanceof \Illuminate\Database\Eloquent\Model
                    && property_exists($value, 'apiResource')
                    && $value->apiResource !== null
                ) {
                    $data[$key] = new $value->apiResource($value);
                }
            }

            return $data;
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

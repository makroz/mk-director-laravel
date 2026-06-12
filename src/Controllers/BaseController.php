<?php

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
     */
    protected function sendResponse($result, $message = '', $code = 200)
    {
        $response = [
            'success'   => true,
            'message'   => $message,
            'data'      => $this->autoTransform($result),
            'debugMsg'  => $this->debugMsgs
        ];

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
     */
    protected function getDebugData()
    {
        if (request()->input('_debug') != 1) return [];

        $queries = DB::getQueryLog();
        $totalTime = 0;
        
        foreach ($queries as &$query) {
            $totalTime += $query['time'];
            if ($query['time'] > 100) {
                 $query['optimization_alert'] = "Slow query detected (>100ms).";
            }
            
            // Auto-Explain strategy
            if (isset($query['query']) && str_starts_with(strtolower($query['query']), 'select')) {
                try {
                    $explain = DB::select("EXPLAIN " . $query['query'], $query['bindings']);
                    if (!empty($explain) && $explain[0]->type == 'ALL') {
                        $query['optimization_alert'] = "FULL TABLE SCAN detected. Indexing highly recommended.";
                    }
                } catch (\Exception $e) {}
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

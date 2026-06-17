<?php

declare(strict_types=1);

namespace Mk\Director\Controllers;

use Mk\Director\Controllers\BaseController;
use Mk\Director\Managers\ListManager;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

abstract class Controller extends BaseController
{
    /**
     * The Model class this controller manages.
     */
    protected $modelClass = null;

    /**
     * List all resources with pagination, filters, sorting, and search.
     */
    public function index(Request $request)
    {
        $model = app($this->modelClass);
        $model = $this->beforeList($request, $model);
        
        // Apply list management (filters, sorting, search, joins)
        $query = ListManager::apply($request, $model);
        
        // Apply beforeSearch hook
        $query = $this->beforeSearch($request, $query);
        
        // Paginate results
        $paginator = ListManager::paginate($query, $request);
        
        // Apply afterSearch hook
        $data = $this->afterSearch($request, $paginator);
        
        // Get extra data for response
        $extra = array_merge(
            $this->afterList($request, $data, $paginator->total()),
            ListManager::getExtraData($paginator)
        );
        
        return $this->sendResponse([
            'data' => $data,
            '__extraData' => $extra
        ]);
    }

    /**
     * Store a newly created resource.
     */
    public function store(Request $request)
    {
        $input = $this->beforeCreate($request);
        $data = app($this->modelClass)->create($input);
        $this->afterCreate($request, $data, $input);
        
        return $this->sendResponse($data, 'Creado con éxito', 201);
    }

    /**
     * Update the specified resource.
     */
    public function update(Request $request, $id)
    {
        $input = $this->beforeUpdate($request, $id);
        $data = app($this->modelClass)->findOrFail($id);
        $data->update($input);
        $this->afterUpdate($request, $data, $input, $id);
        
        return $this->sendResponse($data, 'Actualizado con éxito');
    }

    /**
     * Remove the specified resource.
     */
    public function destroy(Request $request, $id)
    {
        $this->beforeDelete($request, $id);
        $data = app($this->modelClass)->findOrFail($id);
        $data->delete();
        $this->afterDelete($request, $data, $id);
        
        return $this->sendResponse(true, 'Eliminado con éxito');
    }

    // --- List Hooks (Overridable) ---

    /**
     * Modify the model before list query is built.
     */
    public function beforeList(Request $request, $model) 
    { 
        return $model; 
    }

    /**
     * Modify the query before search is applied.
     */
    public function beforeSearch(Request $request, $query) 
    { 
        return $query; 
    }

    /**
     * Modify results after search is executed.
     */
    public function afterSearch(Request $request, $data) 
    { 
        return $data; 
    }

    /**
     * Modify response data after list is complete.
     */
    public function afterList(Request $request, $data, $total) 
    { 
        return ['total' => $total]; 
    }

    // --- CRUD Hooks (Overridable) ---

    /**
     * Modify input before creating resource.
     */
    public function beforeCreate(Request $request) 
    { 
        return $request->all(); 
    }

    /**
     * Execute after creating resource.
     */
    public function afterCreate(Request $request, $data, $input) 
    { 
        return true; 
    }

    /**
     * Modify input before updating resource.
     */
    public function beforeUpdate(Request $request, $id) 
    { 
        return $request->all(); 
    }

    /**
     * Execute after updating resource.
     */
    public function afterUpdate(Request $request, $data, $input, $id) 
    { 
        return true; 
    }

    /**
     * Execute before deleting resource.
     */
    public function beforeDelete(Request $request, $id) 
    { 
        return true; 
    }

    /**
     * Execute after deleting resource.
     */
    public function afterDelete(Request $request, $data, $id) 
    { 
        return true; 
    }
}

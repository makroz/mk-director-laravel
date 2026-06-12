<?php

namespace Mk\Director\Controllers;

use Mk\Director\Traits\CRUDSmart;

/**
 * SmartController - Controlador base que lee $mkConfig y ejecuta CRUD automático
 * 
 * Uso:
 *   class SurveyController extends SmartController
 *   {
 *       protected array $mkConfig = [
 *           'model' => Survey::class,
 *           'service' => SurveyService::class,
 *           'enumMap' => ['status' => SurveyStatus::class],
 *           'searchable' => ['title', 'description'],
 *       ];
 *   }
 */
class SmartController extends BaseController
{
    use CRUDSmart;

    /**
     * Configuración del módulo - debe definirse en el controller hijo
     */
    protected array $mkConfig = [];

    /**
     * Get the current module configuration.
     */
    public function getMkConfig(): array
    {
        return $this->mkConfig;
    }
}

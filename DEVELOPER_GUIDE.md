# 📖 Manual del Desarrollador — MK-Director (`mk-laravel`)

Bienvenido a la guía oficial de **MK-Director Core**, el motor de backend diseñado para acelerar el desarrollo de APIs robustas mediante una capa de abstracción potente sobre Laravel.

---

## 🏗️ 1. Arquitectura y Filosofía

MK-Director se basa en el principio de **Zero-Coupling** y **Configuración sobre Código**. El objetivo es que puedas definir el comportamiento de un módulo completo (CRUD, búsquedas, caché, plugins) simplemente configurando un arreglo en tu controlador.

### Flujo Estándar de Respuesta
Todas las respuestas de MK-Director siguen este formato:
```json
{
  "data": {
    "data": [...], // Colección de objetos o un objeto único
    "__extraData": {
       "total": 150,
       "perPage": 15,
       "page": 1,
       "plugin_verified": true, // Inyectado por plugins
       ...
    }
  },
  "message": "Operación exitosa",
  "status": 200,
  "execution_time": "0.02s" // (Solo en modo Debug)
}
```

---

## ⚙️ 2. Configuración (`mk_director.php`)

Después de publicar la configuración (`php artisan vendor:publish --tag=mk-config`), puedes ajustar el comportamiento global:

- **`debug`**: Habilita tiempos de ejecución y análisis de queries (EXPLAIN).
- **`list`**: Configura `default_per_page` y el `max_per_page`.
- **`features.auto_cache`**: Activa el **"Magic Cache"** global, el cual invalida automáticamente tags de caché al detectar escrituras (`INSERT`, `UPDATE`, `DELETE`) en las tablas correspondientes.

---

## ⚡ 3. Creando un Módulo con `SmartController`

La forma más rápida de crear un CRUD completo es extender `SmartController` y declarar la configuración del módulo en `$mkConfig`.

### Ejemplo de Controlador:
```php
namespace App\Modules\Surveys\Controllers;

use Mk\Director\Controllers\SmartController;
use App\Modules\Surveys\Models\Survey;
use App\Modules\Surveys\Services\SurveyService;
use App\Modules\Surveys\Resources\SurveyResource;

class SurveyController extends SmartController
{
    protected array $mkConfig = [
        'model'      => Survey::class,      // Modelo Eloquent
        'service'    => SurveyService::class, // (Opcional) Lógica de negocio (hooks)
        'resource'   => SurveyResource::class,// (Opcional) API Resource para transformar data
        'searchable' => ['title', 'description'], // Campos habilitados para búsqueda `q=`
        'with'       => ['category'],        // Eager loading fijo
        'features'   => [
            'auto_cache'       => true,      // Sobrescribe el global para este módulo
            'pagination_type'  => 'cursor',  // Options: length_aware, cursor
        ],
    ];
}
```

> 💡 **Cómo funciona**: `SmartController` ya incluye el trait `CRUDSmart`, que lee `$mkConfig` y ejecuta el CRUD completo. Los plugins (`MkAuditLoggerPlugin`, `MkMultiTenantPlugin`) detectan automáticamente los `SmartController` y hookan el ciclo de vida (audit log, multi-tenancy) sin código extra. El scaffolder `mk:module` genera exactamente este esqueleto.

### 3.1 Parámetros Disponibles en `$mkConfig`

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| **`model`** | `string` | **Requerido**. Nombre de clase FQCN del modelo Eloquent. |
| **`service`** | `string` | **Opcional**. Nombre de clase FQCN del servicio que implementa `MkModuleServiceInterface` para interceptar eventos. |
| **`resource`** | `string` | **Opcional**. Nombre de clase FQCN del API Resource de Laravel para dar formato a las respuestas. |
| **`dto`** | `string` | **Opcional**. DTO para validación estricta del payload de entrada. |
| **`searchable`** | `array` | **Opcional**. Lista de columnas de la tabla del modelo en las cuales buscar mediante el parámetro `q=`. |
| **`with`** | `array` | **Opcional**. Relaciones Eloquent fijas a cargar mediante Eager Loading. |
| **`withCount`** | `array` | **Opcional**. Contadores de relaciones Eloquent fijos a cargar mediante `withCount()`. |
| **`allowedIncludes`** | `array` | **Opcional**. Relaciones que el frontend puede solicitar dinámicamente mediante `include=rel1,rel2`. |
| **`allowedWithCount`** | `array` | **Opcional**. Contadores de relación que el frontend puede solicitar dinámicamente mediante `with_count=rel1`. |
| **`enumMap`** | `array` | **Opcional**. Mapa de `[campo => EnumClass]` para validar y castear automáticamente valores a Enums de PHP 8.1+. |
| **`plugins`** | `array` | **Opcional**. Lista de plugins locales para interceptar el ciclo del controlador. |
| **`cache_ttl`** | `int` | **Opcional**. Tiempo de vida del caché del controlador (segundos). Sobrescribe el valor global. |
| **`cache_tags`** | `array\|string` | **Opcional**. Tags de caché a usar. Por defecto es el nombre de la tabla del modelo. |
| **`features`** | `array` | **Opcional**. Toggles de features locales: `auto_cache` (bool), `pagination_type` (`'length_aware'` o `'cursor'`). |

---

## 🔍 4. ListManager: El Motor de Búsquedas (Guía para Frontend)

Tanto para **Next.js** como para **React Native**, el consumo de listas es estandarizado mediante parámetros URL:

### 4.1 Paginación
Controlada por `page` y `per_page` (o `cursor` en modo Cursor Pagination).

### 4.2 Filtrado Dinámico
Usa el parámetro `filter[columna][operador]=valor`.
- **Filtro exacto**: `/api/surveys?filter[status]=A`
- **Operadores**:
  - `neq`: Not Equal (ej: `filter[status][neq]=D`)
  - `gt` / `gte`: Greater than (ej: `filter[price][gt]=100`)
  - `lt` / `lte`: Less than
  - `in`: Lista de valores (ej: `filter[category_id][in]=1,2,3`)

### 4.3 Búsqueda Global (`q=`)
Realiza una búsqueda tipo "LIKE" en todos los campos definidos en la configuración `'searchable'` del controlador.
- Ejemplo: `/api/surveys?q=encuesta`

### 4.4 Ordenamiento (`sort`)
- **Ascendente**: `?sort=title`
- **Descendente**: `?sort=-title`
- **Múltiple**: `?sort=-created_at,title`

---

## 🔌 5. Sistema de Plugins (Extensibilidad)

Puedes interceptar cualquier flujo del controlador sin modificar el core.

### 5.1 Crear un Plugin:
Crea una clase que implemente `Mk\Director\Contracts\MkPluginInterface`.

```php
namespace App\MkPlugins;

use Mk\Director\Contracts\MkPluginInterface;

class AuditPlugin implements MkPluginInterface
{
    public function boot(): void { /* ... */ }
    
    public function beforeQuery($query, $request): void {
        // Ejemplo: Forzar filtrado por un tenant_id global
        // $query->where('tenant_id', Auth::user()->tenant_id);
    }

    public function beforeSave($request, array &$data, string $mode): void {
        if ($mode === 'create') {
            // Lógica solo para nuevos registros
        }
    }

    public function afterSave($model, $request, string $mode): void {
        // ...
    }

    public function beforeDelete($model, $request): void { }
    public function afterDelete($model, $request): void { }

    public function afterResponse(&$responseData): void {
        // Modificar el JSON final antes de enviarlo
        if (is_array($responseData) && isset($responseData['__extraData'])) {
            $responseData['__extraData']['audit_checked'] = true;
        }
    }
}
```

### 5.2 Registrarlo Globalmente (`config/mk_director.php`):
```php
'plugins' => [
    \App\MkPlugins\AuditPlugin::class,
],
```

### 5.3 Registrarlo Localmente (Solo en un Controlador):
Puedes habilitar plugins específicamente para un controlador añadiéndolos a su `$mkConfig`. Esto es ideal para validaciones o auditorías que solo aplican a un recurso.

```php
protected array $mkConfig = [
    'model'   => Survey::class,
    'plugins' => [
        \App\MkPlugins\SpecialValidationPlugin::class,
    ],
];
```

### 5.4 Plugins Disponibles en el Core:

#### `FileStoragePlugin`
Maneja automáticamente la subida de archivos y la conversión de rutas a URLs completas.

**Configuración en Controlador:**
```php
'plugins' => [
    \Mk\Director\Plugins\FileStoragePlugin::class,
],
'plugins_config' => [
    'file_storage' => [
        'fields'   => ['image', 'avatar'], // Campos que son archivos
        'disk'     => 'public',             // Disco de Laravel
        'path'     => 'surveys/images',     // Carpeta destino
        'auto_url' => true,                 // Convertir ruta a URL en la respuesta
    ]
]
```

---

---

## 🛡️ 7. Diagnóstico y Estándares de Calidad

MK-Director incluye un ecosistema de validación proactiva para evitar errores de configuración comunes.

### 7.1 El Comando `mk:status`
Este comando audita todos tus controladores `SmartController` y verifica:
- **Integridad de Clases**: Existencia de Modelos, Servicios y Enums configurados.
- **Base de Datos**: Verifica que los campos en `'searchable'` existan en la tabla física.
- **Plugins**: Valida que el modelo tenga los campos necesarios (`getRequirements()`).

**Uso:**
```bash
php artisan mk:status
```

### 7.2 Creación de Plugins con Requerimientos
Para que un plugin sea compatible con el sistema de diagnóstico, debe implementar `getRequirements()`:

```php
public function getRequirements(array $config): array {
    return [
        'fields' => $config['fields'] ?? [], // Campos requeridos en el modelo
        'config' => ['disk', 'path']         // Llaves requeridas en plugins_config
    ];
}
```

---

## 🚀 8. Integración con el Frontend (Guía Rápida)

MK-Director estandariza la comunicación mediante un protocolo de URL predefinido:

- **Búsqueda Global**: `?q=termino`
- **Filtros**: `?filter[campo][operador]=valor` (ej: `filter[status]=A`)
- **Ordenamiento**: `?sort=-created_at` (el prefijo `-` indica descendente)
- **Paginación**: `?page=2&per_page=15`

Toda respuesta exitosa (200 OK) garantiza la presencia de la llave `data` y, en colecciones, la llave `__extraData` con metadatos de paginación y telemetría.

---

## 💡 Consideraciones de Performance y Seguridad

1.  **`allowedIncludes`**: Siempre define qué relaciones puede pedir el frontend para evitar fugas de información.
2.  **`auto_cache`**: Úsalo para tablas con mucha lectura y poca escritura. MK-Director invalidará los tags de caché automáticamente.
3.  **`MK_DIRECTOR_DEBUG`**: Manténlo en `true` solo en local para ver el análisis de queries y tiempos de ejecución.

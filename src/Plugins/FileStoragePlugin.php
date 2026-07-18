<?php

declare(strict_types=1);

namespace Mk\Director\Plugins;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Mk\Director\Contracts\MkPluginInterface;
use Mk\Director\Managers\PluginManager;

/**
 * FileStoragePlugin — auto file uploads.
 *
 * **Hook**: `beforeSave` — corre ANTES del `Model::create` / `Model::update`.
 * El path se escribe en `$data[$columnName]` que Eloquent luego persiste en la
 * columna del modelo. NO se ejecuta después del insert (no es `afterCreate`).
 *
 * Si necesitás organizar archivos por ID de modelo (e.g.
 * `uploads/{id}/file.jpg`), ese patrón NO está soportado por este plugin —
 * YAGNI hasta que un consumer lo pida (FEEDBACK8 F8-B02 backlog deferred).
 *
 * **Config (R-PKG-045 D1)** — Mapeo explícito `request field → column`:
 *
 *   'plugins_config' => [
 *       'file_storage' => [
 *           'fields' => [
 *               // BC-safe (array plano): identity map
 *               'photo',  // request 'photo' → column 'photo'
 *               // NEW (asociativo): mapeo rename
 *               'avatar' => 'avatar_path',  // request 'avatar' → column 'avatar_path'
 *           ],
 *           'disk' => 'public',
 *           'path' => 'uploads/files',
 *           'auto_url' => true,
 *       ],
 *   ],
 *
 * **Auto-register (R-PKG-045 D2)**: el plugin se carga por default vía
 * `MkServiceProvider::registerPlugins()`. Opt-out vía config flag
 * `'features.file_storage_plugin' => false`.
 */
class FileStoragePlugin implements MkPluginInterface
{
    protected PluginManager $manager;

    /**
     * Archivos reemplazados en `beforeSave()` que se borran en `afterSave()`.
     *
     * @var array<int, array{0: string, 1: string}> pares `[disk, path]`
     */
    protected array $pendingDeletions = [];

    public function __construct(PluginManager $manager)
    {
        $this->manager = $manager;
    }

    public function boot(): void
    {
        // Preparation if needed
    }

    public function getRequirements(): array
    {
        $config = $this->manager->getConfigValue('plugins_config.file_storage', []);

        return [
            'required_config' => ['plugins_config.file_storage.fields'],
            'fields_added' => $config['fields'] ?? [],
        ];
    }

    public function beforeQuery(Builder $query, Request $request): void
    {
        // No query modifications needed
    }

    /**
     * Hook `beforeSave` — corre ANTES de `Model::create` / `Model::update`.
     * El path se escribe en `$data[$columnName]` para que Eloquent lo
     * persista al insertar/actualizar.
     *
     * **R-PKG-045 D1 — Mapeo explícito `request field → column`**:
     *   - Array plano (BC): `'fields' => ['photo']` se auto-normaliza como
     *     `['photo' => 'photo']` (identity map). Request field === column
     *     name, comportamiento legacy v1.x preservado.
     *   - Array asociativo (NEW): `'fields' => ['photo' => 'photo_path']`
     *     pinea el rename. Útil cuando el scaffolder genera columnas con
     *     sufijo `_path` (FEEDBACK A6 RETO: `--profile-fields=photo_path`).
     *   - Mixto: `'fields' => ['photo', 'avatar' => 'avatar_path']` es válido.
     */
    public function beforeSave(Request $request, array &$data, string $mode): void
    {
        $config = $this->manager->getConfigValue('plugins_config.file_storage', []);

        $fields = $config['fields'] ?? [];
        $disk = $config['disk'] ?? 'public';
        $path = $config['path'] ?? 'uploads/files';

        foreach ($fields as $requestField => $columnName) {
            // BC: array plano ['photo'] se auto-normaliza a ['photo' => 'photo']
            // (identity map). PHP foreach sobre array plano da keys integer
            // (0, 1, 2...) — is_int los detecta, y en ese caso el request
            // field ES el column name.
            if (is_int($requestField)) {
                $requestField = $columnName;
            }

            if ($request->hasFile($requestField)) {
                $file = $request->file($requestField);

                // Store file
                $storedPath = $file->store($path, $disk);

                // D1: escribir al column name (post-rename), no al request field name.
                $data[$columnName] = $storedPath;

                // F11-P03: `store()` genera un nombre aleatorio en cada upload,
                // así que un update deja el archivo anterior huérfano en disco.
                // Lo encolamos y recién lo borramos en `afterSave()`, cuando el
                // update ya está persistido: si la mutación falla, el archivo
                // viejo sigue siendo el vigente y borrarlo acá dejaría al
                // registro apuntando a la nada.
                if ($mode === 'update') {
                    $previousPath = $this->resolvePreviousPath($columnName);

                    if ($previousPath !== null && $previousPath !== $storedPath) {
                        $this->pendingDeletions[] = [$disk, $previousPath];
                    }
                }
            }
        }
    }

    /**
     * Path previo del campo, leído del modelo en contexto.
     *
     * Devuelve `null` si el caller no pineó el modelo (BC: callers viejos que
     * no llaman `setContextModel()` simplemente no borran nada) o si el campo
     * venía vacío.
     */
    protected function resolvePreviousPath(string $columnName): ?string
    {
        $model = $this->manager->getContextModel();

        if ($model === null) {
            return null;
        }

        $previous = data_get($model, $columnName);

        return is_string($previous) && $previous !== '' ? $previous : null;
    }

    /**
     * Borra los archivos reemplazados, ya con el update confirmado.
     *
     * Best-effort: si el archivo ya no está (borrado a mano, disco rotado,
     * dos updates concurrentes), `delete()` devuelve false y seguimos. Un
     * archivo huérfano es basura; una excepción acá tiraría abajo un update
     * que en los hechos salió bien.
     */
    public function afterSave($model, Request $request, string $mode): void
    {
        foreach ($this->pendingDeletions as [$disk, $path]) {
            try {
                Storage::disk($disk)->delete($path);
            } catch (\Throwable) {
                // no-op — ver docblock
            }
        }

        $this->pendingDeletions = [];
    }

    public function beforeDelete($model, Request $request): void
    {
        // Handle file deletion if needed
    }

    public function afterDelete($model, Request $request): void
    {
        // Handle file cleanup after deletion
    }

    public function afterResponse(&$responseData): void
    {
        // Convert internal paths to full URLs if config says so
        $config = $this->manager->getConfigValue('plugins_config.file_storage', []);

        if (! ($config['auto_url'] ?? true)) {
            return;
        }

        $fields = $config['fields'] ?? [];
        $disk = $config['disk'] ?? 'public';

        // Recursive URL conversion helper
        $this->convertPathsToUrls($responseData, $fields, $disk);
    }

    /**
     * Helper to recursively look for fields and convert paths to URLs.
     */
    protected function convertPathsToUrls(&$data, array $fields, string $disk): void
    {
        if (is_array($data) || is_object($data)) {
            foreach ($data as $key => &$value) {
                if (in_array($key, $fields) && is_string($value) && ! empty($value)) {
                    // Prepend storage URL
                    $value = Storage::disk($disk)->url($value);
                } elseif (is_array($value) || is_object($value)) {
                    $this->convertPathsToUrls($value, $fields, $disk);
                }
            }
        }
    }
}

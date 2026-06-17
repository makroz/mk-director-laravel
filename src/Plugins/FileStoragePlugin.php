<?php

declare(strict_types=1);

namespace Mk\Director\Plugins;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Mk\Director\Contracts\MkPluginInterface;
use Mk\Director\Managers\PluginManager;

/**
 * FileStoragePlugin
 * 
 * Intercepts request to handle file uploads automatically for specific fields.
 */
class FileStoragePlugin implements MkPluginInterface
{
    protected PluginManager $manager;

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

    public function beforeSave(Request $request, array &$data, string $mode): void
    {
        // Get config for this plugin from the current controller
        $config = $this->manager->getConfigValue('plugins_config.file_storage', []);
        
        $fields = $config['fields'] ?? [];
        $disk = $config['disk'] ?? 'public';
        $path = $config['path'] ?? 'uploads/files';

        foreach ($fields as $field) {
            if ($request->hasFile($field)) {
                $file = $request->file($field);
                
                // Store file
                $storedPath = $file->store($path, $disk);
                
                // Update data with the new path
                $data[$field] = $storedPath;
            }
        }
    }

    public function afterSave($model, Request $request, string $mode): void
    {
        // Cleaning temporary files if needed
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
        
        if (!($config['auto_url'] ?? true)) {
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
                if (in_array($key, $fields) && is_string($value) && !empty($value)) {
                    // Prepend storage URL
                    $value = Storage::disk($disk)->url($value);
                } else if (is_array($value) || is_object($value)) {
                    $this->convertPathsToUrls($value, $fields, $disk);
                }
            }
        }
    }
}

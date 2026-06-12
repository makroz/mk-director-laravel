# @mk/laravel

El motor de backend de MK-Director. Basado en Laravel 10+, ofrece una capa de abstracción potente para APIs CRUD.

> 📖 **[Guía Completa del Desarrollador](DEVELOPER_GUIDE.md)**: Instalación, Configuración, CRUD, ListManager y Plugins.

## Características Core
- **Model & Builder**: Soporte nativo para `cacheGet()`, `cacheFirst()` y `cacheFind()`.
- **Auto-Cache Plugin**: Flushing automático de tags de cache al detectar operaciones de escritura en la DB.
- **Magic CRUD Controller**: Implementa un ABM completo heredando de `Mk\Director\Controllers\Controller`.
- **List & Search Managers**: Parsing de strings complejos para búsquedas relacionales y joins dinámicos.

## Configuración
Publica la configuración:
```bash
php artisan vendor:publish --tag=mk-config
```

Habilita features en `config/mk_director.php`:
```php
'features' => [
    'auto_cache' => true,
    'dynamic_joins' => true,
],
```

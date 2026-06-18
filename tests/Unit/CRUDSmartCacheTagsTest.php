<?php
declare(strict_types=1);


namespace Mk\Director\Tests\Unit;

use Illuminate\Database\Eloquent\Model;

// --- Minimal stubs ---

class StubTableModel extends Model
{
    protected $table = 'stub_resources';
    protected $fillable = ['name'];
}

/**
 * Minimal controller stub that uses the CRUDSmart trait
 */
class StubCRUDController
{
    use \Mk\Director\Traits\CRUDSmart;

    // NOTE: $mkConfig is already declared by the CRUDSmart trait — do NOT re-declare here

    public function setConfig(array $config): void
    {
        $this->mkConfig = $config;
    }

    public function testGetCacheTags(): array
    {
        return $this->getCacheTags();
    }

    // Provide sendResponse stub to avoid abstract method errors
    protected function sendResponse($data, string $message = 'OK', int $status = 200) {}
    protected function sendError(string $message, int $status = 422) {}
}

// --- Tests ---

test('getCacheTags returns model table name by default', function () {
    $controller = new StubCRUDController();
    $controller->setConfig(['model' => StubTableModel::class]);

    // Before fix: this throws "Call to undefined method getModelClass()"
    $tags = $controller->testGetCacheTags();

    expect($tags)->toBe(['stub_resources']);
});

test('getCacheTags returns custom tags when configured', function () {
    $controller = new StubCRUDController();
    $controller->setConfig([
        'model'      => StubTableModel::class,
        'cache_tags' => ['custom_tag', 'other_tag'],
    ]);

    $tags = $controller->testGetCacheTags();

    expect($tags)->toBe(['custom_tag', 'other_tag']);
});

test('getCacheTags wraps single string tag in array', function () {
    $controller = new StubCRUDController();
    $controller->setConfig([
        'model'      => StubTableModel::class,
        'cache_tags' => 'single_tag',
    ]);

    $tags = $controller->testGetCacheTags();

    expect($tags)->toBe(['single_tag']);
});

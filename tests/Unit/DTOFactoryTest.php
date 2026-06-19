<?php
declare(strict_types=1);


namespace Mk\Director\Tests\Unit;

use Mk\Director\DTOs\MkDTO;
use Mk\Director\DTOs\DTOFactory;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Model;

// Test DTO

class TestDTO extends MkDTO
{
    public string $name;
    public string $email;
    public ?int $age;

    protected function rules(): array
    {
        return [
            'name' => ['string'],
            'email' => ['string'],
            'age' => ['int'],
        ];
    }
}

test('MkDTO creates from array correctly', function () {
    $data = [
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'age' => '30',
    ];

    $dto = TestDTO::fromArray($data);

    expect($dto->name)->toBe('John Doe');
    expect($dto->email)->toBe('john@example.com');
    expect($dto->age)->toBe(30);
});

test('MkDTO casts types correctly', function () {
    $data = [
        'name' => 'Jane',
        'email' => 'jane@test.com',
        'age' => '25',
    ];

    $dto = TestDTO::fromArray($data);

    expect($dto->age)->toBeInt();
    expect($dto->age)->toBe(25);
});

test('MkDTO converts back to array', function () {
    $data = [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'age' => '28',
    ];

    $dto = TestDTO::fromArray($data);
    $array = $dto->toArray();

    expect($array)->toHaveKey('name', 'Test User');
    expect($array)->toHaveKey('email', 'test@example.com');
    expect($array)->toHaveKey('age', 28);
});

class DTOStubModel extends Model
{
    protected $table = 'test_table';
    protected $fillable = ['name', 'email', 'age'];
}

test('DTOFactory creates DTO from request', function () {
    $request = Request::create('/test', 'POST', [
        'name' => 'Request User',
        'email' => 'request@test.com',
        'age' => '35',
    ]);

    $data = DTOFactory::makeFromRequest($request, DTOStubModel::class, TestDTO::class);

    expect($data)->toBeArray();
    expect($data['name'])->toBe('Request User');
    expect($data['age'])->toBe(35);
});

test('DTOFactory creates DTO from array', function () {
    $data = [
        'name' => 'Array User',
        'email' => 'array@test.com',
        'age' => '40',
    ];

    $result = DTOFactory::makeFromArray($data, DTOStubModel::class, TestDTO::class);

    expect($result)->toBeArray();
    expect($result['name'])->toBe('Array User');
    expect($result['age'])->toBe(40);
});

class TestReadonlyDTO extends MkDTO
{
    public readonly string $uuid;
    public string $name;
}

test('MkDTO handles readonly property hydration without re-assigning initialized properties', function () {
    $data = [
        'uuid' => '123e4567-e89b-12d3-a456-426614174000',
        'name' => 'John',
    ];

    $dto = TestReadonlyDTO::fromArray($data);
    expect($dto->uuid)->toBe('123e4567-e89b-12d3-a456-426614174000');
    expect($dto->name)->toBe('John');
});

test('MkDTO::detectEnums and DTOFactory::detectEnums find sibling Enums', function () {
    $tmpDir = sys_get_temp_dir() . '/mk-enum-detect-' . getmypid();
    $modelsDir = $tmpDir . '/Models';
    $enumsDir = $tmpDir . '/Enums';
    
    mkdir($modelsDir, 0o755, true);
    mkdir($enumsDir, 0o755, true);
    
    $modelFileContent = <<<'PHP'
<?php
namespace Mk\Director\Tests\Unit\Temp\Models;
class DummyModel extends \Illuminate\Database\Eloquent\Model {}
PHP;
    file_put_contents($modelsDir . '/DummyModel.php', $modelFileContent);
    require_once $modelsDir . '/DummyModel.php';
    
    $enumFileContent = <<<'PHP'
<?php
namespace Mk\Director\Tests\Unit\Temp\Enums;
enum DummyStatusEnum: string {
    case ACTIVE = 'active';
}
PHP;
    file_put_contents($enumsDir . '/DummyStatusEnum.php', $enumFileContent);
    require_once $enumsDir . '/DummyStatusEnum.php';
    
    $modelClass = 'Mk\Director\Tests\Unit\Temp\Models\DummyModel';
    $enums1 = MkDTO::detectEnums($modelClass);
    $enums2 = DTOFactory::detectEnums($modelClass);
    
    expect($enums1)->toBeArray();
    expect($enums1)->toHaveKey('dummy_status');
    expect($enums1['dummy_status'])->toBe('Mk\Director\Tests\Unit\Temp\Enums\DummyStatusEnum');
    expect($enums1)->toBe($enums2);
    
    unlink($modelsDir . '/DummyModel.php');
    unlink($enumsDir . '/DummyStatusEnum.php');
    rmdir($modelsDir);
    rmdir($enumsDir);
    rmdir($tmpDir);
});

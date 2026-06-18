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

test('MkDTO::detectEnums resolves namespace dynamically based on model namespace', function () {
    $enums = MkDTO::detectEnums(DTOStubModel::class);
    expect($enums)->toBeArray();
});

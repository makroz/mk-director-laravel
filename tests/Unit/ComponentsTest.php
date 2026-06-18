<?php
declare(strict_types=1);


namespace Mk\Director\Tests\Unit;

use Mk\Director\Models\Model;
use Mk\Director\Strategies\LikeSearchStrategy;
use Mk\Director\Strategies\ExactSearchStrategy;
use Mk\Director\Contracts\SearchStrategyInterface;

/**
 * Unit Tests for Package Components that don't require Laravel container
 * 
 * These tests can run in isolation without the full Laravel application context.
 */
test('LikeSearchStrategy implements SearchStrategyInterface', function () {
    $strategy = new LikeSearchStrategy();
    expect($strategy)->toBeInstanceOf(SearchStrategyInterface::class);
});

test('ExactSearchStrategy implements SearchStrategyInterface', function () {
    $strategy = new ExactSearchStrategy();
    expect($strategy)->toBeInstanceOf(SearchStrategyInterface::class);
});

test('MkModel can be instantiated', function () {
    $model = new class extends Model {
        protected $table = 'test';
    };
    expect($model)->toBeInstanceOf(Model::class);
});

test('MkModel returns apiResource property as null by default', function () {
    $model = new class extends Model {
        protected $table = 'test';
    };
    
    expect($model->apiResource)->toBeNull();
});

test('MkModel can set custom apiResource', function () {
    $model = new class extends Model {
        protected $table = 'test';
    };
    
    $model->apiResource = \stdClass::class;
    expect($model->apiResource)->toBe(\stdClass::class);
});

test('MkModel has newEloquentBuilder method', function () {
    $model = new class extends Model {
        protected $table = 'test';
    };
    
    expect(method_exists($model, 'newEloquentBuilder'))->toBeTrue();
});

test('MkModel can override getActivitylogOptions', function () {
    $model = new class extends Model {
        protected $table = 'test';
        
        public function getActivitylogOptions(): mixed
        {
            return ['key' => 'value'];
        }
    };
    
    $result = $model->getActivitylogOptions();
    expect($result)->toBe(['key' => 'value']);
});

test('MkModel getActivitylogOptions returns null by default', function () {
    $model = new class extends Model {
        protected $table = 'test';
    };
    
    $result = $model->getActivitylogOptions();
    expect($result)->toBeNull();
});

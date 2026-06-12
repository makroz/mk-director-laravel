<?php

namespace Mk\Director\Tests\Unit;

use Mk\Director\Contracts\MkPluginInterface;
use Mk\Director\Managers\PluginManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Mockery;

uses(\Mk\Director\Tests\TestCase::class);


test('PluginManager loads plugins from config and boots them', function () {
    $mockPlugin = new class implements MkPluginInterface {
        public bool $booted = false;
        public function boot(): void { $this->booted = true; }
        public function getRequirements(): array { return []; }
        public function beforeQuery(Builder $query, Request $request): void {}
        public function beforeSave(Request $request, array &$data, string $mode): void {}
        public function afterSave($model, Request $request, string $mode): void {}
        public function beforeDelete($model, Request $request): void {}
        public function afterDelete($model, Request $request): void {}
        public function afterResponse(&$responseData): void {}
    };

    config(['mk_director.plugins' => [get_class($mockPlugin)]]);
    app()->instance(get_class($mockPlugin), $mockPlugin);

    $manager = new PluginManager();
    expect($mockPlugin->booted)->toBeTrue();
});

test('PluginManager fires beforeQuery hook', function () {
    $mockPlugin = new class implements MkPluginInterface {
        public bool $called = false;
        public function boot(): void {}
        public function getRequirements(): array { return []; }
        public function beforeQuery(Builder $query, Request $request): void { $this->called = true; }
        public function beforeSave(Request $request, array &$data, string $mode): void {}
        public function afterSave($model, Request $request, string $mode): void {}
        public function beforeDelete($model, Request $request): void {}
        public function afterDelete($model, Request $request): void {}
        public function afterResponse(&$responseData): void {}
    };

    config(['mk_director.plugins' => [get_class($mockPlugin)]]);
    app()->instance(get_class($mockPlugin), $mockPlugin);

    $manager = new PluginManager();
    $manager->fireBeforeQuery(Mockery::mock(Builder::class), Request::create('/', 'GET'));

    expect($mockPlugin->called)->toBeTrue();
});

test('PluginManager fires beforeSave and modifies data', function () {
    $mockPlugin = new class implements MkPluginInterface {
        public function boot(): void {}
        public function getRequirements(): array { return []; }
        public function beforeQuery(Builder $query, Request $request): void {}
        public function beforeSave(Request $request, array &$data, string $mode): void { $data['modified'] = true; }
        public function afterSave($model, Request $request, string $mode): void {}
        public function beforeDelete($model, Request $request): void {}
        public function afterDelete($model, Request $request): void {}
        public function afterResponse(&$responseData): void {}
    };

    config(['mk_director.plugins' => [get_class($mockPlugin)]]);
    app()->instance(get_class($mockPlugin), $mockPlugin);

    $manager = new PluginManager();
    $data = ['original' => true];
    $manager->fireBeforeSave(Request::create('/', 'POST'), $data);

    expect($data)->toHaveKey('modified');
});

test('PluginManager fires afterSave hook', function () {
    $mockPlugin = new class implements MkPluginInterface {
        public bool $called = false;
        public function boot(): void {}
        public function getRequirements(): array { return []; }
        public function beforeQuery(Builder $query, Request $request): void {}
        public function beforeSave(Request $request, array &$data, string $mode): void {}
        public function afterSave($model, Request $request, string $mode): void { $this->called = true; }
        public function beforeDelete($model, Request $request): void {}
        public function afterDelete($model, Request $request): void {}
        public function afterResponse(&$responseData): void {}
    };

    config(['mk_director.plugins' => [get_class($mockPlugin)]]);
    app()->instance(get_class($mockPlugin), $mockPlugin);

    $manager = new PluginManager();
    $manager->fireAfterSave(new \stdClass(), Request::create('/', 'POST'));

    expect($mockPlugin->called)->toBeTrue();
});

test('PluginManager fires afterResponse hook and modifies response', function () {
    $mockPlugin = new class implements MkPluginInterface {
        public function boot(): void {}
        public function getRequirements(): array { return []; }
        public function beforeQuery(Builder $query, Request $request): void {}
        public function beforeSave(Request $request, array &$data, string $mode): void {}
        public function afterSave($model, Request $request, string $mode): void {}
        public function beforeDelete($model, Request $request): void {}
        public function afterDelete($model, Request $request): void {}
        public function afterResponse(&$responseData): void { $responseData['plugin_applied'] = true; }
    };

    config(['mk_director.plugins' => [get_class($mockPlugin)]]);
    app()->instance(get_class($mockPlugin), $mockPlugin);

    $manager = new PluginManager();
    $response = ['data' => []];
    $manager->fireAfterResponse($response);

    expect($response)->toHaveKey('plugin_applied');
});

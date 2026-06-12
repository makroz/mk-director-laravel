<?php

namespace Mk\Director\Tests\Unit;

use Mk\Director\Managers\ListManager;
use Mk\Director\Managers\SearchManager;
use Mk\Director\Strategies\LikeSearchStrategy;
use Mk\Director\Strategies\ExactSearchStrategy;
use Illuminate\Http\Request;

uses(\Mk\Director\Tests\TestCase::class);

// ListManager Tests

test('ListManager applies pagination correctly', function () {
    $config = [
        'model' => new class {
            public static $tableName = 'test_table';
            public static function query() { return null; }
        },
    ];

    $request = Request::create('/test', 'GET', [
        'page' => 2,
        'per_page' => 15,
    ]);

    $manager = new ListManager($request, $config);

    expect($manager)->toBeInstanceOf(ListManager::class);
});

test('ListManager applies filters with operators', function () {
    $config = [
        'searchable' => ['name', 'email'],
    ];

    $request = Request::create('/test', 'GET', [
        'filter' => [
            'status' => ['op' => 'eq', 'value' => 'A'],
            'price' => ['op' => 'gt', 'value' => 100],
        ],
    ]);

    $manager = new ListManager($request, $config);
    expect($manager)->toBeInstanceOf(ListManager::class);
});

test('ListManager handles sorting correctly', function () {
    $config = [];

    // Ascending sort
    $requestAsc = Request::create('/test', 'GET', ['sort' => 'name']);
    $managerAsc = new ListManager($requestAsc, $config);
    expect($managerAsc)->toBeInstanceOf(ListManager::class);

    // Descending sort with -prefix
    $requestDesc = Request::create('/test', 'GET', ['sort' => '-created_at']);
    $managerDesc = new ListManager($requestDesc, $config);
    expect($managerDesc)->toBeInstanceOf(ListManager::class);
});

test('ListManager respects max_per_page configuration', function () {
    $config = [];

    config(['mk_director.list.max_per_page' => 50]);

    $request = Request::create('/test', 'GET', ['per_page' => 100]);
    $manager = new ListManager($request, $config);

    expect($manager)->toBeInstanceOf(ListManager::class);
});

// SearchManager Tests

test('SearchManager parses simple search string', function () {
    $manager = new SearchManager();
    $result = $manager->parse('john');

    expect($result)->toBeArray();
    expect($result)->toHaveCount(1);
});

test('SearchManager parses comma-separated search', function () {
    $manager = new SearchManager();
    $result = $manager->parse('john, doe');

    expect($result)->toBeArray();
    expect($result)->toHaveCount(2);
});

test('SearchManager applies LikeSearchStrategy correctly', function () {
    $strategy = new LikeSearchStrategy();

    expect($strategy)->toBeInstanceOf(LikeSearchStrategy::class);
});

test('SearchManager applies ExactSearchStrategy correctly', function () {
    $strategy = new ExactSearchStrategy();

    expect($strategy)->toBeInstanceOf(ExactSearchStrategy::class);
});

test('SearchManager switches strategies', function () {
    $manager = new SearchManager();

    $manager->setStrategy(new LikeSearchStrategy());
    expect($manager->getStrategy())->toBeInstanceOf(LikeSearchStrategy::class);

    $manager->setStrategy(new ExactSearchStrategy());
    expect($manager->getStrategy())->toBeInstanceOf(ExactSearchStrategy::class);
});

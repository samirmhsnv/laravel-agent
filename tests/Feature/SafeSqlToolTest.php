<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SamirMhsnv\LaravelAgent\Exceptions\ToolException;
use SamirMhsnv\LaravelAgent\Exceptions\UnsafeQueryException;
use SamirMhsnv\LaravelAgent\Sql\SafeSqlTool;

beforeEach(function () {
    Schema::create('products', function ($table) {
        $table->id();
        $table->string('name');
        $table->integer('price');
    });

    Schema::create('secrets', function ($table) {
        $table->id();
        $table->string('value');
    });

    foreach (range(1, 30) as $i) {
        DB::table('products')->insert(['name' => "Product {$i}", 'price' => $i * 10]);
    }

    DB::table('secrets')->insert(['value' => 'hunter2']);

    config()->set('agent.sql.tables', ['products']);
    config()->set('agent.sql.max_rows', 10);
});

it('runs an allowed SELECT and returns rows', function () {
    $result = app(SafeSqlTool::class)->execute(['sql' => 'SELECT name, price FROM products WHERE price > 250 ORDER BY price']);

    expect($result['columns'])->toBe(['name', 'price'])
        ->and($result['row_count'])->toBe(5)
        ->and($result['truncated'])->toBeFalse()
        ->and($result['rows'][0])->toBe(['name' => 'Product 26', 'price' => 260]);
});

it('forces the row limit, even over a larger LIMIT in the query', function () {
    $result = app(SafeSqlTool::class)->execute(['sql' => 'SELECT * FROM products LIMIT 1000']);

    expect($result['row_count'])->toBe(10)
        ->and($result['truncated'])->toBeTrue();
});

it('keeps a smaller LIMIT from the query', function () {
    expect(app(SafeSqlTool::class)->execute(['sql' => 'SELECT * FROM products LIMIT 3'])['row_count'])->toBe(3);
});

it('refuses tables that are not allowlisted', function () {
    app(SafeSqlTool::class)->execute(['sql' => 'SELECT value FROM secrets']);
})->throws(UnsafeQueryException::class, 'table [secrets] is not allowed');

it('refuses writes and leaves the data untouched', function () {
    expect(fn () => app(SafeSqlTool::class)->execute(['sql' => 'DELETE FROM products']))
        ->toThrow(UnsafeQueryException::class);

    expect(DB::table('products')->count())->toBe(30);
});

it('reports database errors as tool errors the model can fix', function () {
    app(SafeSqlTool::class)->execute(['sql' => 'SELECT no_such_column FROM products']);
})->throws(ToolException::class, 'The database rejected the query');

it('runs on the configured connection', function () {
    config()->set('database.connections.reporting', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('agent.sql.connection', 'reporting');
    Schema::connection('reporting')->create('products', fn ($table) => $table->id());

    expect(app(SafeSqlTool::class)->execute(['sql' => 'SELECT COUNT(*) AS n FROM products'])['rows'][0]['n'])->toBe(0);
});

it('describes allowed tables and their columns to the model', function () {
    $description = app(SafeSqlTool::class)->description();

    expect($description)
        ->toContain('Only these tables can be queried: products.')
        ->toContain('capped at 10 rows')
        ->toContain('SQL dialect: sqlite')
        ->toContain('- products(id integer, name varchar, price integer)')
        ->not->toContain('secrets');
});

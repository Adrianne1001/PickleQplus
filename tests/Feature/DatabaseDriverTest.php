<?php

use Illuminate\Support\Facades\DB;

// Guards the MySQL CI job (and `composer test:mysql`): if the DB_* overrides are
// ever ignored, the suite would silently fall back to SQLite and prove nothing.
// REQUIRE_MYSQL is set only by that job, so the default SQLite run skips this.
it('runs on MySQL with InnoDB tables when REQUIRE_MYSQL is set', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');

    $nonInnoDb = DB::select(
        "select table_name from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE' and engine <> 'InnoDB'"
    );

    expect($nonInnoDb)->toBeEmpty();
})->skip(fn () => getenv('REQUIRE_MYSQL') !== '1', 'Only runs against MySQL (REQUIRE_MYSQL=1).');

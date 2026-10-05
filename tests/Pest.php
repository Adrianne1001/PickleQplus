<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the full Laravel application and get a fresh database
| per test. Unit tests stay framework-free (important for the pure-PHP
| rotation engine).
|
*/

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

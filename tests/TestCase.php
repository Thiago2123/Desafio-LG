<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['planta_a', 'planta_b'] as $conexao) {
            config([
                'database.connections.'.$conexao => [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                    'prefix' => '',
                    'foreign_key_constraints' => true,
                ],
            ]);

            Artisan::call('migrate', [
                '--database' => $conexao,
                '--force' => true,
            ]);
        }
    }
}

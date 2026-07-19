<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductionSimulationTest extends TestCase
{
    public function testaPreparacaoESimulacaoDasDuasPlantas()
    {
        $codigoPreparacao = Artisan::call('plants:setup', ['--fresh' => true]);

        $this->assertSame(0, $codigoPreparacao, Artisan::output());

        foreach (['planta_a', 'planta_b'] as $conexao) {
            $banco = DB::connection($conexao);

            $this->assertSame(5, $banco->table('products')->count());
            $this->assertSame(465, $banco->table('production_records')->count());
        }

        $codigoSimulacao = Artisan::call('production:simulate', [
            '--at' => '2026-02-01 06:00:00',
        ]);

        $this->assertSame(0, $codigoSimulacao, Artisan::output());

        foreach (['planta_a', 'planta_b'] as $conexao) {
            $registrosEmTempoReal = DB::connection($conexao)
                ->table('production_records')
                ->where('recorded_at', '>=', '2026-02-01 00:00:00')
                ->where('recorded_at', '<', '2026-02-02 00:00:00')
                ->count();

            $this->assertSame(5, $registrosEmTempoReal);
        }

        // Preenche o último minuto do dia e confirma que a próxima execução encerra.
        $codigoUltimoMinuto = Artisan::call('production:simulate', [
            '--at' => '2026-02-01 23:59:00',
        ]);
        $this->assertSame(0, $codigoUltimoMinuto, Artisan::output());

        $codigoEncerramento = Artisan::call('production:simulate');
        $this->assertSame(2, $codigoEncerramento, Artisan::output());
    }
}

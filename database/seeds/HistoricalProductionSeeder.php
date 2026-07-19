<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class HistoricalProductionSeeder extends Seeder
{
    /**
     * Cria o histórico reproduzível de janeiro: 31 dias x 3 turnos x 5 produtos.
     */
    public function run()
    {
        $conexao = DB::getDefaultConnection();
        $banco = DB::connection($conexao);
        $produtos = $banco->table('products')->get()->keyBy('slug');
        $registros = [];
        $agora = now();

        // Cada planta recebe dados diferentes, mas determinísticos a cada nova instalação.
        mt_srand($conexao === 'planta_b' ? 20260102 : 20260101);

        foreach (range(1, 31) as $dia) {
            foreach ([6, 14, 22] as $hora) {
                $dataDoRegistro = CarbonImmutable::create(2026, 1, $dia, $hora, 0, 0);

                foreach (config('production.products') as $configuracaoDoProduto) {
                    $meta = $configuracaoDoProduto['target_per_interval'];
                    $quantidadeProduzida = (int) round($meta * mt_rand(82, 108) / 100);
                    $taxaDeDefeitos = mt_rand(10, 75) / 10;
                    $quantidadeDefeituosa = min($quantidadeProduzida, (int) round($quantidadeProduzida * $taxaDeDefeitos / 100));

                    $registros[] = [
                        'product_id' => $produtos[$configuracaoDoProduto['slug']]->id,
                        'produced_quantity' => $quantidadeProduzida,
                        'defective_quantity' => $quantidadeDefeituosa,
                        'target_quantity' => $meta,
                        'recorded_at' => $dataDoRegistro->format('Y-m-d H:i:s'),
                        'created_at' => $agora,
                        'updated_at' => $agora,
                    ];
                }
            }
        }

        $banco->transaction(function () use ($banco, $registros) {
            $banco->table('production_records')
                ->where('recorded_at', '>=', '2026-01-01 00:00:00')
                ->where('recorded_at', '<', '2026-02-01 00:00:00')
                ->delete();

            // 100 linhas mantêm o lote abaixo do limite de placeholders do SQLite
            // usado nos testes e continuam eficientes no MySQL.
            foreach (array_chunk($registros, 100) as $lote) {
                $banco->table('production_records')->insert($lote);
            }
        });
    }
}

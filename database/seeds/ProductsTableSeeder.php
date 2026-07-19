<?php

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductsTableSeeder extends Seeder
{
    /** Cadastra o mesmo catálogo nas duas plantas, alterando o nome de cada linha. */
    public function run()
    {
        $conexao = DB::getDefaultConnection();
        $codigoDaPlanta = $conexao === 'planta_b' ? 'B' : 'A';
        $agora = now();

        foreach (config('production.products') as $indice => $produto) {
            DB::connection($conexao)->table('products')->updateOrInsert(
                ['slug' => $produto['slug']],
                [
                    'name' => $produto['name'],
                    'line_name' => sprintf('Linha %s-%02d', $codigoDaPlanta, $indice + 1),
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ]
            );
        }
    }
}

<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    public function testaConsolidacaoAntesDoCalculoDosPercentuais()
    {
        $this->inserirProdutoERegistro('planta_a', 'Linha A-01', 100, 4, 100);
        $this->inserirProdutoERegistro('planta_b', 'Linha B-01', 300, 18, 300);

        $resposta = $this->getJson('/api/dashboard?scope=all&date=2026-02-01');

        $resposta->assertOk();
        $dados = $resposta->json();
        $produto = $dados['products'][0];

        $this->assertSame(400, $produto['produced_quantity']);
        $this->assertSame(22, $produto['defective_quantity']);
        $this->assertSame(400, $produto['target_quantity']);
        $this->assertSame(5.5, $produto['defect_rate']);
        $this->assertSame(100, $produto['efficiency']);
        $this->assertTrue($produto['has_defect_alert']);
        $this->assertSame(1, $dados['totals']['alert_count']);
    }

    public function testaFiltroDeUmaPlanta()
    {
        $this->inserirProdutoERegistro('planta_a', 'Linha A-01', 100, 4, 125);
        $this->inserirProdutoERegistro('planta_b', 'Linha B-01', 300, 18, 300);

        $resposta = $this->getJson('/api/dashboard?scope=plant_a&date=2026-02-01');

        $resposta->assertOk();
        $produto = $resposta->json('products.0');

        $this->assertSame(100, $produto['produced_quantity']);
        $this->assertEquals(4.0, $produto['defect_rate']);
        $this->assertEquals(80.0, $produto['efficiency']);
        $this->assertFalse($produto['has_defect_alert']);
        $this->assertSame(['A' => 'Linha A-01'], $produto['lines']);
    }

    public function testaPercentuaisZeradosSemProducao()
    {
        $this->inserirProduto('planta_a', 'Linha A-01');

        $resposta = $this->getJson('/api/dashboard?scope=plant_a&date=2026-02-01');

        $resposta->assertOk();
        $produto = $resposta->json('products.0');

        $this->assertEquals(0.0, $produto['defect_rate']);
        $this->assertEquals(0.0, $produto['efficiency']);
        $this->assertFalse($produto['has_defect_alert']);
    }

    public function testaValidacaoDoEscopo()
    {
        $resposta = $this->getJson('/api/dashboard?scope=invalid&date=2026-02-01');

        $resposta->assertStatus(422);
        $resposta->assertJsonValidationErrors('scope');
    }

    public function testaAusenciaDeAlertaEmExatosCincoPorCento()
    {
        $this->inserirProdutoERegistro('planta_a', 'Linha A-01', 100, 5, 100);

        $resposta = $this->getJson('/api/dashboard?scope=plant_a&date=2026-02-01');

        $resposta->assertOk();
        $this->assertEquals(5.0, $resposta->json('products.0.defect_rate'));
        $this->assertFalse($resposta->json('products.0.has_defect_alert'));
    }

    public function testaIdentificacaoDePlantaIndisponivel()
    {
        config([
            'database.connections.planta_b.database' => database_path('missing-directory/planta-b.sqlite'),
        ]);
        DB::purge('planta_b');

        $resposta = $this->getJson('/api/dashboard?scope=all&date=2026-02-01');

        $resposta->assertStatus(503);
        $resposta->assertJson([
            'plant' => 'Planta B',
        ]);
    }

    private function inserirProdutoERegistro(
        string $conexao,
        string $nomeDaLinha,
        int $produzido,
        int $defeituoso,
        int $meta
    ): void {
        $produtoId = $this->inserirProduto($conexao, $nomeDaLinha);

        DB::connection($conexao)->table('production_records')->insert([
            'product_id' => $produtoId,
            'produced_quantity' => $produzido,
            'defective_quantity' => $defeituoso,
            'target_quantity' => $meta,
            'recorded_at' => '2026-02-01 10:00:00',
            'created_at' => '2026-02-01 10:00:00',
            'updated_at' => '2026-02-01 10:00:00',
        ]);
    }

    private function inserirProduto(string $conexao, string $nomeDaLinha): int
    {
        return DB::connection($conexao)->table('products')->insertGetId([
            'name' => 'Geladeira',
            'slug' => 'geladeira',
            'line_name' => $nomeDaLinha,
            'created_at' => '2026-02-01 06:00:00',
            'updated_at' => '2026-02-01 06:00:00',
        ]);
    }
}

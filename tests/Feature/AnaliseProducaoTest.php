<?php

namespace Tests\Feature;

use App\Services\AnaliseProducaoService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnaliseProducaoTest extends TestCase
{
    private $historicoHttp = [];

    protected function setUp(): void
    {
        parent::setUp();

        // A suíte valida nossa integração, mas não deve consumir o limite da aplicação ativa.
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function testaQueAIntegracaoDesabilitadaNaoImpedeODashboard()
    {
        config(['services.groq.key' => null]);

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Integração não configurada');

        $this->postJson('/api/analise-ia', [
            'scope' => 'all',
            'date' => '2026-02-01',
        ])->assertStatus(503)
            ->assertJson([
                'message' => 'A análise inteligente ainda não foi configurada neste ambiente.',
            ]);
    }

    public function testaGeracaoDeAnaliseEstruturadaPelaGroq()
    {
        $this->configurarGroq();
        $this->inserirProdutoERegistro();

        $this->simularRespostaGroq($this->respostaDaGroq());

        $resposta = $this->postJson('/api/analise-ia', [
            'scope' => 'plant_a',
            'date' => '2026-02-01',
        ]);

        $resposta->assertOk()->assertJson([
            'situacao' => 'atencao',
            'resumo' => 'A produção atingiu 80% da meta e a taxa de defeitos está acima do limite.',
            'provedor' => 'Groq',
            'modelo' => 'openai/gpt-oss-120b',
        ]);
        $this->assertCount(3, $resposta->json('destaques'));
        $this->assertCount(3, $resposta->json('pontos_atencao'));

        $this->assertCount(1, $this->historicoHttp);
        $requisicao = $this->historicoHttp[0]['request'];
        $corpo = json_decode((string) $requisicao->getBody(), true);

        $this->assertSame(
            'https://api.groq.com/openai/v1/chat/completions',
            (string) $requisicao->getUri()
        );
        $this->assertSame('Bearer chave-de-teste', $requisicao->getHeaderLine('Authorization'));
        $this->assertSame('openai/gpt-oss-120b', $corpo['model']);
        $this->assertTrue($corpo['response_format']['json_schema']['strict']);
        $this->assertStringContainsString('Geladeira', $corpo['messages'][1]['content']);

        $jsonDoPrompt = substr(
            $corpo['messages'][1]['content'],
            strlen('Analise os indicadores a seguir: ')
        );
        $dadosDoPrompt = json_decode($jsonDoPrompt, true);

        $this->assertSame('critica', $dadosDoPrompt['classificacao_calculada']);
        $this->assertSame(
            'acima_do_limite',
            $dadosDoPrompt['totais']['comparacoes_calculadas']['status_taxa_defeitos']
        );
        $this->assertSame(
            25,
            $dadosDoPrompt['totais']['comparacoes_calculadas']['quantidade_faltante_para_meta']
        );
    }

    public function testaCacheParaNaoConsumirACotaComOsMesmosDados()
    {
        $this->configurarGroq();
        $this->inserirProdutoERegistro();

        $this->simularRespostaGroq($this->respostaDaGroq());

        $parametros = [
            'scope' => 'plant_a',
            'date' => '2026-02-01',
        ];

        $this->postJson('/api/analise-ia', $parametros)->assertOk();
        $this->postJson('/api/analise-ia', $parametros)->assertOk();

        $this->assertCount(1, $this->historicoHttp);
    }

    public function testaMensagemClaraAoAtingirOLimiteDaGroq()
    {
        $this->configurarGroq();

        $this->simularRespostaGroq([
            'error' => ['message' => 'Rate limit reached'],
        ], 429);

        $this->postJson('/api/analise-ia', [
            'scope' => 'all',
            'date' => '2026-02-01',
        ])->assertStatus(429)
            ->assertJson([
                'message' => 'O limite temporário da Groq foi atingido. Aguarde um pouco e tente novamente.',
            ]);
    }

    public function testaRejeicaoDeRespostaExternaIncompleta()
    {
        $this->configurarGroq();

        $this->simularRespostaGroq([
            'choices' => [[
                'message' => [
                    'content' => json_encode(['resumo' => 'Resposta sem os demais campos.']),
                ],
            ]],
        ]);

        $this->postJson('/api/analise-ia', [
            'scope' => 'all',
            'date' => '2026-02-01',
        ])->assertStatus(502)
            ->assertJson([
                'message' => 'A análise recebida não passou pela validação.',
            ]);
    }

    private function configurarGroq(): void
    {
        Cache::flush();

        config([
            'services.groq.key' => 'chave-de-teste',
            'services.groq.endpoint' => 'https://api.groq.com/openai/v1/chat/completions',
            'services.groq.model' => 'openai/gpt-oss-120b',
            'services.groq.timeout_seconds' => 5,
            'services.groq.cache_seconds' => 300,
        ]);
    }

    /** Substitui a internet por uma resposta em memória e registra a requisição. */
    private function simularRespostaGroq(array $corpo, int $status = 200): void
    {
        $simulador = new MockHandler([
            new Response($status, ['Content-Type' => 'application/json'], json_encode($corpo)),
        ]);
        $pilha = HandlerStack::create($simulador);
        $pilha->push(Middleware::history($this->historicoHttp));

        $this->app->instance(
            AnaliseProducaoService::class,
            new AnaliseProducaoService(new Client(['handler' => $pilha]))
        );
    }

    private function respostaDaGroq(): array
    {
        return [
            'choices' => [[
                'message' => [
                    'content' => json_encode([
                        'situacao' => 'atencao',
                        'resumo' => 'A produção atingiu 80% da meta e a taxa de defeitos está acima do limite.',
                        'destaques' => [
                            'Foram produzidas 100 unidades.',
                            'O indicador foi consolidado.',
                            'A linha permaneceu em operação.',
                            'Este quarto item deve ser removido.',
                        ],
                        'pontos_atencao' => [
                            'A taxa de defeitos chegou a 6%.',
                            'A meta não foi atingida.',
                            'A linha requer verificação.',
                            'Este quarto item deve ser removido.',
                        ],
                        'acao_recomendada' => 'Verifique a etapa de qualidade da linha antes do próximo lote.',
                    ], JSON_UNESCAPED_UNICODE),
                ],
            ]],
        ];
    }

    private function inserirProdutoERegistro(): void
    {
        $produtoId = DB::connection('planta_a')->table('products')->insertGetId([
            'name' => 'Geladeira',
            'slug' => 'geladeira',
            'line_name' => 'Linha A-01',
            'created_at' => '2026-02-01 06:00:00',
            'updated_at' => '2026-02-01 06:00:00',
        ]);

        DB::connection('planta_a')->table('production_records')->insert([
            'product_id' => $produtoId,
            'produced_quantity' => 100,
            'defective_quantity' => 6,
            'target_quantity' => 125,
            'recorded_at' => '2026-02-01 10:00:00',
            'created_at' => '2026-02-01 10:00:00',
            'updated_at' => '2026-02-01 10:00:00',
        ]);
    }
}

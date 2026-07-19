<?php

namespace App\Services;

use App\Exceptions\AnaliseIaException;
use Carbon\CarbonImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

class AnaliseProducaoService
{
    private const VERSAO_PROMPT = '5';

    private $clienteHttp;

    public function __construct(?ClientInterface $clienteHttp = null)
    {
        // O parâmetro opcional permite substituir o cliente por um mock nos testes.
        $this->clienteHttp = $clienteHttp ?: new Client();
    }

    /**
     * Gera uma leitura gerencial dos indicadores usando a Groq.
     * O resultado fica em cache e só muda quando os dados de produção mudam.
     */
    public function gerarAnalise(array $metricas): array
    {
        if (! $this->estaConfigurado()) {
            throw new AnaliseIaException(
                'A análise inteligente ainda não foi configurada neste ambiente.',
                503
            );
        }

        $dadosParaAnalise = $this->prepararDados($metricas);
        // Modelo e versão entram na chave para uma mudança de prompt não reutilizar texto antigo.
        $conteudoDaChave = [
            'versao_prompt' => self::VERSAO_PROMPT,
            'modelo' => config('services.groq.model'),
            'dados' => $dadosParaAnalise,
        ];
        $chaveCache = 'analise-producao:'.hash('sha256', json_encode(
            $conteudoDaChave,
            JSON_UNESCAPED_UNICODE
        ));

        return Cache::remember(
            $chaveCache,
            (int) config('services.groq.cache_seconds'),
            function () use ($dadosParaAnalise, $metricas) {
                $analise = $this->consultarGroq($dadosParaAnalise);

                return array_merge($analise, [
                    'provedor' => 'Groq',
                    'modelo' => config('services.groq.model'),
                    'gerada_em' => CarbonImmutable::now(config('app.timezone'))->toIso8601String(),
                    'dados_ate' => $metricas['last_recorded_at'],
                ]);
            }
        );
    }

    public function estaConfigurado(): bool
    {
        return trim((string) config('services.groq.key')) !== '';
    }

    /**
     * Envia apenas indicadores consolidados. Credenciais e registros brutos dos
     * bancos nunca fazem parte do conteúdo enviado ao modelo.
     */
    private function prepararDados(array $metricas): array
    {
        $limite = (float) $metricas['alert_threshold'];
        $totais = $metricas['totals'];
        $comparacoesTotais = $this->calcularComparacoes(
            $totais['produced_quantity'],
            $totais['target_quantity'],
            $totais['defect_rate'],
            $limite,
            $totais['has_defect_alert']
        );

        if ($totais['has_defect_alert']) {
            $classificacao = 'critica';
        } elseif (
            $totais['alert_count'] > 0
            || ! $comparacoesTotais['meta_atingida']
            || $comparacoesTotais['taxa_proxima_do_limite']
        ) {
            $classificacao = 'atencao';
        } else {
            $classificacao = 'normal';
        }

        return [
            'escopo' => $metricas['scope'],
            'data' => $metricas['date'],
            'plantas' => $metricas['plants'],
            'limite_alerta_defeitos' => $limite,
            'classificacao_calculada' => $classificacao,
            'totais' => [
                'quantidade_produzida' => $totais['produced_quantity'],
                'quantidade_defeituosa' => $totais['defective_quantity'],
                'meta' => $totais['target_quantity'],
                'taxa_defeitos' => $totais['defect_rate'],
                'eficiencia' => $totais['efficiency'],
                'quantidade_alertas' => $totais['alert_count'],
                'comparacoes_calculadas' => $comparacoesTotais,
            ],
            'produtos' => array_map(function (array $produto) use ($limite) {
                return [
                    'nome' => $produto['name'],
                    'linha' => $produto['line_name'],
                    'quantidade_produzida' => $produto['produced_quantity'],
                    'quantidade_defeituosa' => $produto['defective_quantity'],
                    'meta' => $produto['target_quantity'],
                    'taxa_defeitos' => $produto['defect_rate'],
                    'eficiencia' => $produto['efficiency'],
                    'em_alerta' => $produto['has_defect_alert'],
                    'comparacoes_calculadas' => $this->calcularComparacoes(
                        $produto['produced_quantity'],
                        $produto['target_quantity'],
                        $produto['defect_rate'],
                        $limite,
                        $produto['has_defect_alert']
                    ),
                ];
            }, $metricas['products']),
        ];
    }

    /**
     * Entrega comparações prontas ao modelo para que a IA interprete os fatos,
     * sem precisar refazer subtrações ou decidir se uma taxa passou do limite.
     */
    private function calcularComparacoes(
        int $produzido,
        int $meta,
        float $taxaDefeitos,
        float $limite,
        bool $emAlerta
    ): array {
        $margemAteLimite = round($limite - $taxaDefeitos, 2);

        return [
            'meta_atingida' => $meta > 0 && $produzido >= $meta,
            'quantidade_faltante_para_meta' => max(0, $meta - $produzido),
            'status_taxa_defeitos' => $emAlerta ? 'acima_do_limite' : 'abaixo_ou_igual_ao_limite',
            'margem_ate_limite_pontos_percentuais' => $margemAteLimite,
            'taxa_proxima_do_limite' => ! $emAlerta
                && $margemAteLimite >= 0
                && $margemAteLimite <= 0.5,
        ];
    }

    /** Executa a chamada HTTP e transforma a resposta estruturada em um array seguro. */
    private function consultarGroq(array $dadosParaAnalise): array
    {
        try {
            $resposta = $this->clienteHttp->request(
                'POST',
                config('services.groq.endpoint'),
                [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => 'Bearer '.config('services.groq.key'),
                    ],
                    'timeout' => (int) config('services.groq.timeout_seconds'),
                    'connect_timeout' => 5,
                    'http_errors' => false,
                    'json' => [
                        'model' => config('services.groq.model'),
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => $this->instrucaoDoSistema(),
                            ],
                            [
                                'role' => 'user',
                                'content' => 'Analise os indicadores a seguir: '.json_encode(
                                    $dadosParaAnalise,
                                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                                ),
                            ],
                        ],
                        'reasoning_effort' => 'low',
                        'max_completion_tokens' => 900,
                        'response_format' => $this->formatoDaResposta(),
                    ],
                ]
            );
        } catch (GuzzleException $excecao) {
            throw new AnaliseIaException(
                'Não foi possível conectar ao serviço de análise inteligente.',
                503,
                $excecao
            );
        }

        $status = $resposta->getStatusCode();

        if ($status === 429) {
            throw new AnaliseIaException(
                'O limite temporário da Groq foi atingido. Aguarde um pouco e tente novamente.',
                429
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new AnaliseIaException(
                'A Groq não conseguiu gerar a análise neste momento.',
                502
            );
        }

        $corpo = json_decode((string) $resposta->getBody(), true);
        $conteudo = is_array($corpo)
            ? data_get($corpo, 'choices.0.message.content')
            : null;
        $analise = is_string($conteudo) ? json_decode($conteudo, true) : null;

        if (! is_array($analise)) {
            throw new AnaliseIaException('A Groq devolveu uma análise em formato inválido.', 502);
        }

        return $this->validarAnalise($analise);
    }

    /** Mantém o modelo preso aos números recebidos e evita diagnósticos inventados. */
    private function instrucaoDoSistema(): string
    {
        return implode(' ', [
            'Você é um analista de produção industrial.',
            'Responda em português do Brasil, de forma curta, clara e profissional.',
            'Use exclusivamente os indicadores fornecidos e não invente causas, sensores ou ocorrências.',
            'As comparações já foram calculadas pelo Laravel; não refaça nem contradiga esses campos.',
            'O campo situacao deve ser exatamente igual a classificacao_calculada.',
            'Nunca escreva perguntas, autocorreções ou expressões como "na verdade".',
            'Use vírgula como separador decimal nos textos em português, por exemplo 4,54%.',
            'Escreva "a taxa de defeitos foi X%"; nunca escreva "a produção ficou X% de taxa".',
            'Quando sugerir uma causa possível, apresente-a apenas como algo que deve ser verificado.',
            'Considere taxa de defeitos acima do limite informado como alerta.',
            'Use destaques somente para resultados positivos; meta não atingida pertence aos pontos de atenção.',
            'Se a taxa de defeitos estiver até 0,5 ponto percentual abaixo do limite, trate a proximidade como ponto de atenção.',
            'Não recomende manutenção preventiva, troca de máquina ou ação sobre sensores, pois esses dados não foram fornecidos.',
            'Não invente tendências, semanas futuras ou comparações que não estejam demonstradas pelos indicadores.',
            'Prefira ações verificáveis de inspeção da qualidade, revisão do processo ou acompanhamento da meta.',
            'Retorne no máximo três itens em destaques e três itens em pontos de atenção.',
            'Classifique a situação como normal, atencao ou critica.',
            'Retorne somente o JSON solicitado pelo schema.',
        ]);
    }

    /** O JSON Schema garante que o frontend sempre receba os mesmos campos. */
    private function formatoDaResposta(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'analise_producao',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'situacao' => [
                            'type' => 'string',
                            'description' => 'Copie exatamente o valor de classificacao_calculada.',
                            'enum' => ['normal', 'atencao', 'critica'],
                        ],
                        'resumo' => [
                            'type' => 'string',
                            'description' => 'Síntese objetiva, sem perguntas ou autocorreções.',
                        ],
                        'destaques' => [
                            'type' => 'array',
                            'description' => 'Somente fatos positivos. Nunca inclua meta não atingida.',
                            'items' => ['type' => 'string'],
                        ],
                        'pontos_atencao' => [
                            'type' => 'array',
                            'description' => 'Desvios, meta não atingida e proximidade do limite.',
                            'items' => ['type' => 'string'],
                        ],
                        'acao_recomendada' => [
                            'type' => 'string',
                            'description' => 'Ação verificável sustentada pelos indicadores fornecidos.',
                        ],
                    ],
                    'required' => [
                        'situacao',
                        'resumo',
                        'destaques',
                        'pontos_atencao',
                        'acao_recomendada',
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }

    /** Faz uma segunda validação no Laravel antes de confiar no conteúdo externo. */
    private function validarAnalise(array $analise): array
    {
        // Mantém o card conciso mesmo se o modelo produzir mais itens que o solicitado.
        foreach (['destaques', 'pontos_atencao'] as $campoLista) {
            if (isset($analise[$campoLista]) && is_array($analise[$campoLista])) {
                $analise[$campoLista] = array_slice($analise[$campoLista], 0, 3);
            }
        }

        $validador = Validator::make($analise, [
            'situacao' => 'required|in:normal,atencao,critica',
            'resumo' => 'required|string',
            'destaques' => 'required|array',
            'destaques.*' => 'string',
            'pontos_atencao' => 'required|array',
            'pontos_atencao.*' => 'string',
            'acao_recomendada' => 'required|string',
        ]);

        if ($validador->fails()) {
            throw new AnaliseIaException('A análise recebida não passou pela validação.', 502);
        }

        return $validador->validated();
    }
}

<?php

namespace App\Services;

use App\Exceptions\PlantDataUnavailableException;
use App\Models\Product;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DashboardService
{
    // Relaciona o nome técnico de cada conexão com os dados exibidos pela API.
    private const PLANTAS = [
        'planta_a' => [
            'scope' => 'plant_a',
            'code' => 'A',
            'name' => 'Planta A',
        ],
        'planta_b' => [
            'scope' => 'plant_b',
            'code' => 'B',
            'name' => 'Planta B',
        ],
    ];

    /**
     * Monta a resposta completa do dashboard para uma planta ou para o consolidado.
     * Esta é a função principal do serviço: consulta, consolida e calcula os indicadores.
     */
    public function calcularMetricas(string $escopo, CarbonImmutable $data): array
    {
        $conexoes = $this->conexoesDoEscopo($escopo);
        $inicio = $data->startOfDay();
        $fim = $inicio->addDay();
        $dadosDasPlantas = [];

        foreach ($conexoes as $conexao) {
            try {
                $dadosDasPlantas[] = $this->buscarDadosDaPlanta($conexao, $inicio, $fim);
            } catch (QueryException $excecao) {
                throw new PlantDataUnavailableException(
                    self::PLANTAS[$conexao]['name'],
                    $excecao
                );
            }
        }

        $produtos = $this->consolidarProdutos($dadosDasPlantas);
        $totais = $this->calcularTotais($produtos);
        $ultimoRegistroEm = $this->obterUltimoRegistro($dadosDasPlantas);

        return [
            'scope' => $escopo,
            'date' => $inicio->format('Y-m-d'),
            'generated_at' => CarbonImmutable::now(config('app.timezone'))->toIso8601String(),
            'last_recorded_at' => $ultimoRegistroEm
                ? CarbonImmutable::parse($ultimoRegistroEm, config('app.timezone'))->toIso8601String()
                : null,
            'alert_threshold' => (float) config('production.defect_alert_threshold'),
            'plants' => array_map(function (array $planta) {
                return [
                    'code' => $planta['code'],
                    'name' => $planta['name'],
                ];
            }, $dadosDasPlantas),
            'totals' => $totais,
            'products' => $produtos,
        ];
    }

    /**
     * Traduz o filtro recebido pela API para as conexões Laravel que serão consultadas.
     */
    private function conexoesDoEscopo(string $escopo): array
    {
        $escopos = [
            'plant_a' => ['planta_a'],
            'plant_b' => ['planta_b'],
            'all' => ['planta_a', 'planta_b'],
        ];

        if (! isset($escopos[$escopo])) {
            throw new InvalidArgumentException(sprintf('Escopo inválido: %s', $escopo));
        }

        return $escopos[$escopo];
    }

    /**
     * Busca catálogo e volumes de uma planta dentro do intervalo informado.
     * O catálogo usa Eloquent; os volumes usam SQL puro com agregação no banco.
     */
    private function buscarDadosDaPlanta(
        string $conexao,
        CarbonImmutable $inicio,
        CarbonImmutable $fim
    ): array {
        // Eloquent ORM: o catálogo exibido no dashboard vem do model Product.
        $produtos = Product::on($conexao)
            ->orderBy('id')
            ->get(['id', 'name', 'slug', 'line_name']);

        // SQL puro: o banco agrega os volumes antes de enviá-los para o PHP.
        $resultados = DB::connection($conexao)->select(
            'SELECT product_id,
                    SUM(produced_quantity) AS produced_quantity,
                    SUM(defective_quantity) AS defective_quantity,
                    SUM(target_quantity) AS target_quantity
               FROM production_records
              WHERE recorded_at >= ? AND recorded_at < ?
              GROUP BY product_id',
            [
                $inicio->format('Y-m-d H:i:s'),
                $fim->format('Y-m-d H:i:s'),
            ]
        );

        // Transforma o resultado do SQL em um mapa indexado pelo ID local do produto.
        $totaisPorProduto = [];

        foreach ($resultados as $resultado) {
            $totaisPorProduto[(int) $resultado->product_id] = [
                'produced_quantity' => (int) $resultado->produced_quantity,
                'defective_quantity' => (int) $resultado->defective_quantity,
                'target_quantity' => (int) $resultado->target_quantity,
            ];
        }

        $ultimoRegistroEm = DB::connection($conexao)
            ->table('production_records')
            ->where('recorded_at', '>=', $inicio->format('Y-m-d H:i:s'))
            ->where('recorded_at', '<', $fim->format('Y-m-d H:i:s'))
            ->max('recorded_at');

        return array_merge(self::PLANTAS[$conexao], [
            'connection' => $conexao,
            'last_recorded_at' => $ultimoRegistroEm,
            'products' => $produtos->map(function (Product $produto) use ($totaisPorProduto) {
                $quantidades = $totaisPorProduto[$produto->id] ?? [
                    'produced_quantity' => 0,
                    'defective_quantity' => 0,
                    'target_quantity' => 0,
                ];

                return array_merge([
                    'slug' => $produto->slug,
                    'name' => $produto->name,
                    'line_name' => $produto->line_name,
                ], $quantidades);
            })->all(),
        ]);
    }

    /**
     * Une produtos equivalentes pelo slug e soma seus valores absolutos.
     * Os percentuais só são calculados depois da soma para manter a ponderação correta.
     */
    private function consolidarProdutos(array $dadosDasPlantas): array
    {
        $consolidado = [];

        foreach ($dadosDasPlantas as $planta) {
            foreach ($planta['products'] as $produto) {

                // O slug é compartilhado entre as plantas; os IDs dos bancos não são.
                if (! isset($consolidado[$produto['slug']])) {
                    $consolidado[$produto['slug']] = [
                        'slug' => $produto['slug'],
                        'name' => $produto['name'],
                        'lines' => [],
                        'produced_quantity' => 0,
                        'defective_quantity' => 0,
                        'target_quantity' => 0,
                    ];
                }

                $consolidado[$produto['slug']]['lines'][$planta['code']] = $produto['line_name'];
                $consolidado[$produto['slug']]['produced_quantity'] += $produto['produced_quantity'];
                $consolidado[$produto['slug']]['defective_quantity'] += $produto['defective_quantity'];
                $consolidado[$produto['slug']]['target_quantity'] += $produto['target_quantity'];
            }
        }

        $produtos = array_map(function (array $produto) {
            $indicadores = $this->calcularIndicadores(
                $produto['produced_quantity'],
                $produto['defective_quantity'],
                $produto['target_quantity']
            );

            return array_merge($produto, [
                'line_name' => implode(' + ', array_values($produto['lines'])),
            ], $indicadores);
        }, array_values($consolidado));

        usort($produtos, function (array $primeiro, array $segundo) {
            return strcmp($primeiro['name'], $segundo['name']);
        });

        return $produtos;
    }

    /**
     * Soma todos os produtos para preencher os cartões superiores do dashboard.
     */
    private function calcularTotais(array $produtos): array
    {
        $totais = [
            'produced_quantity' => 0,
            'defective_quantity' => 0,
            'target_quantity' => 0,
        ];
        $quantidadeAlertas = 0;

        foreach ($produtos as $produto) {
            $totais['produced_quantity'] += $produto['produced_quantity'];
            $totais['defective_quantity'] += $produto['defective_quantity'];
            $totais['target_quantity'] += $produto['target_quantity'];
            $quantidadeAlertas += $produto['has_defect_alert'] ? 1 : 0;
        }

        return array_merge($totais, $this->calcularIndicadores(
            $totais['produced_quantity'],
            $totais['defective_quantity'],
            $totais['target_quantity']
        ), [
            'alert_count' => $quantidadeAlertas,
        ]);
    }

    /**
     * Calcula taxa de defeitos, eficiência e o estado do alerta de qualidade.
     */
    private function calcularIndicadores(int $produzido, int $defeituoso, int $meta): array
    {
        $taxaDefeitos = $produzido > 0 ? round($defeituoso / $produzido * 100, 2) : 0.0;
        $eficiencia = $meta > 0 ? round($produzido / $meta * 100, 2) : 0.0;

        return [
            'defect_rate' => $taxaDefeitos,
            'efficiency' => $eficiencia,
            'has_defect_alert' => $taxaDefeitos > (float) config('production.defect_alert_threshold'),
        ];
    }

    /**
     * Obtém o horário mais recente entre as plantas consultadas para informar
     * até qual instante os números exibidos estão atualizados.
     */
    private function obterUltimoRegistro(array $dadosDasPlantas): ?string
    {
        $datas = array_filter(array_column($dadosDasPlantas, 'last_recorded_at'));

        return $datas ? max($datas) : null;
    }
}

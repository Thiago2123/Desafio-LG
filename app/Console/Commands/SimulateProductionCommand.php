<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductionRecord;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SimulateProductionCommand extends Command
{
    // Código especial usado pelo container para diferenciar conclusão de uma falha.
    private const SIMULACAO_CONCLUIDA = 2;

    protected $signature = 'production:simulate
                            {--at= : Data e hora simulada no formato Y-m-d H:i:s}';

    protected $description = 'Gera um novo intervalo de produção para as duas plantas';

    /** Executa um novo intervalo simulado nas duas plantas. */
    public function handle()
    {
        if (! config('simulation.enabled')) {
            $this->comment('Simulação desativada por configuração.');

            return 0;
        }

        try {
            $dataDoRegistro = $this->resolverDataDoRegistro();

            if ($dataDoRegistro === null) {
                $this->comment('Simulação concluída: 01/02/2026 chegou ao fim.');

                return self::SIMULACAO_CONCLUIDA;
            }

            foreach (['planta_a', 'planta_b'] as $conexao) {
                $this->simularPlanta($conexao, $dataDoRegistro);
            }
        } catch (Throwable $excecao) {
            report($excecao);
            $this->error($excecao->getMessage());

            return 1;
        }

        $this->info(sprintf(
            'Produção simulada nas duas plantas em %s.',
            $dataDoRegistro->format('d/m/Y H:i:s')
        ));

        return 0;
    }

    /**
     * Usa a data informada em --at ou encontra o próximo minuto livre do dia simulado.
     */
    private function resolverDataDoRegistro(): ?CarbonImmutable
    {
        $fusoHorario = config('app.timezone');

        if ($this->option('at')) {
            return CarbonImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $this->option('at'),
                $fusoHorario
            );
        }

        $inicio = CarbonImmutable::parse(config('simulation.date').' 06:00:00', $fusoHorario);
        $fim = $inicio->startOfDay()->addDay();
        $ultimoHorario = null;

        foreach (['planta_a', 'planta_b'] as $conexao) {
            $valor = DB::connection($conexao)
                ->table('production_records')
                ->where('recorded_at', '>=', $inicio->startOfDay()->format('Y-m-d H:i:s'))
                ->where('recorded_at', '<', $fim->format('Y-m-d H:i:s'))
                ->max('recorded_at');

            if ($valor) {
                $horarioEncontrado = CarbonImmutable::parse($valor, $fusoHorario);
                $ultimoHorario = $ultimoHorario === null || $horarioEncontrado->greaterThan($ultimoHorario)
                    ? $horarioEncontrado
                    : $ultimoHorario;
            }
        }

        $proximoHorario = $ultimoHorario ? $ultimoHorario->addMinute() : $inicio;

        if ($proximoHorario->greaterThanOrEqualTo($fim)) {
            return null;
        }

        return $proximoHorario;
    }

    /**
     * Gera uma medição para todos os produtos de uma planta dentro de uma transação.
     */
    private function simularPlanta(string $conexao, CarbonImmutable $dataDoRegistro): void
    {
        $configuracaoDosProdutos = collect(config('production.products'))->keyBy('slug');

        DB::connection($conexao)->transaction(function () use (
            $conexao,
            $dataDoRegistro,
            $configuracaoDosProdutos
        ) {
            $produtos = Product::on($conexao)->orderBy('id')->get();

            if ($produtos->isEmpty()) {
                throw new \RuntimeException(sprintf(
                    'Nenhum produto cadastrado em %s. Execute plants:setup primeiro.',
                    $conexao
                ));
            }

            foreach ($produtos as $produto) {
                $meta = $configuracaoDosProdutos[$produto->slug]['target_per_interval'];
                $quantidadeProduzida = (int) round($meta * random_int(80, 112) / 100);
                $taxaDeDefeitos = random_int(10, 80) / 10;
                $quantidadeDefeituosa = min(
                    $quantidadeProduzida,
                    (int) round($quantidadeProduzida * $taxaDeDefeitos / 100)
                );

                ProductionRecord::on($conexao)->create([
                    'product_id' => $produto->id,
                    'produced_quantity' => $quantidadeProduzida,
                    'defective_quantity' => $quantidadeDefeituosa,
                    'target_quantity' => $meta,
                    'recorded_at' => $dataDoRegistro,
                ]);
            }
        });
    }
}

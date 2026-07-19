<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class PlantsSetupCommand extends Command
{
    protected $signature = 'plants:setup
                            {--fresh : Recria todas as tabelas antes de migrar}
                            {--no-seed : Não carrega os dados históricos}';

    protected $description = 'Aplica migrations e seeders nas bases independentes das duas plantas';

    /**
     * Aplica o mesmo schema e os dados iniciais em cada conexão, sem misturar as bases.
     */
    public function handle()
    {
        foreach (['planta_a', 'planta_b'] as $conexao) {
            $this->line('');
            $this->info(sprintf('Configurando %s...', $conexao));

            $comandoDeMigracao = $this->option('fresh') ? 'migrate:fresh' : 'migrate';
            $codigoDeSaida = Artisan::call($comandoDeMigracao, [
                '--database' => $conexao,
                '--force' => true,
            ]);
            $this->output->write(Artisan::output());

            if ($codigoDeSaida !== 0) {
                $this->error(sprintf('Falha ao migrar %s.', $conexao));

                return $codigoDeSaida;
            }

            if (! $this->option('no-seed')) {
                $codigoDeSaida = Artisan::call('db:seed', [
                    '--database' => $conexao,
                    '--force' => true,
                ]);
                $this->output->write(Artisan::output());

                if ($codigoDeSaida !== 0) {
                    $this->error(sprintf('Falha ao popular %s.', $conexao));

                    return $codigoDeSaida;
                }
            }
        }

        $this->line('');
        $this->info('As duas plantas estão prontas.');

        return 0;
    }
}

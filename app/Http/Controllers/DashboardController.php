<?php

namespace App\Http\Controllers;

class DashboardController extends Controller
{
    /**
     * Entrega somente a estrutura inicial da página e suas configurações.
     * Os dados de produção são carregados depois pelo JavaScript através da API.
     */
    public function __invoke()
    {
        return view('dashboard', [
            'dataSimulacao' => config('simulation.date'),
            'intervaloPolling' => config('dashboard.polling_interval_ms'),
            'iaConfigurada' => trim((string) config('services.groq.key')) !== '',
        ]);
    }
}

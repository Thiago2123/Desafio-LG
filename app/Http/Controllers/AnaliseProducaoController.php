<?php

namespace App\Http\Controllers;

use App\Exceptions\AnaliseIaException;
use App\Exceptions\PlantDataUnavailableException;
use App\Http\Requests\DashboardQueryRequest;
use App\Services\AnaliseProducaoService;
use App\Services\DashboardService;
use Carbon\CarbonImmutable;

class AnaliseProducaoController extends Controller
{
    /**
     * Reaproveita as métricas do dashboard e pede à Groq uma interpretação gerencial.
     * A rota é manual: ela não participa do polling de cinco segundos.
     */
    public function __invoke(
        DashboardQueryRequest $requisicao,
        DashboardService $servicoDashboard,
        AnaliseProducaoService $servicoAnalise
    ) {
        $dadosValidados = $requisicao->validated();

        try {
            $metricas = $servicoDashboard->calcularMetricas(
                $dadosValidados['scope'],
                CarbonImmutable::parse($dadosValidados['date'], config('app.timezone'))
            );

            return response()->json($servicoAnalise->gerarAnalise($metricas));
        } catch (PlantDataUnavailableException $excecao) {
            report($excecao);

            return response()->json([
                'message' => $excecao->getMessage(),
                'plant' => $excecao->planta(),
            ], 503);
        } catch (AnaliseIaException $excecao) {
            report($excecao);

            return response()->json([
                'message' => $excecao->getMessage(),
            ], $excecao->statusHttp());
        }
    }
}

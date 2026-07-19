<?php

namespace App\Http\Controllers;

use App\Exceptions\PlantDataUnavailableException;
use App\Http\Requests\DashboardQueryRequest;
use App\Services\DashboardService;
use Carbon\CarbonImmutable;

class DashboardApiController extends Controller
{
    /**
     * Recebe os filtros validados, chama a regra de negócio e devolve JSON.
     * O controller coordena a requisição, mas não executa cálculos ou SQL.
     */
    public function __invoke(
        DashboardQueryRequest $requisicao,
        DashboardService $servicoDashboard
    ) {
        $dadosValidados = $requisicao->validated();

        try {
            $metricas = $servicoDashboard->calcularMetricas(
                $dadosValidados['scope'],
                CarbonImmutable::parse($dadosValidados['date'], config('app.timezone'))
            );
        } catch (PlantDataUnavailableException $excecao) {
            report($excecao);

            // 503 indica que a API existe, mas uma fonte necessária está indisponível.
            return response()->json([
                'message' => $excecao->getMessage(),
                'plant' => $excecao->planta(),
            ], 503);
        }

        return response()->json($metricas);
    }
}

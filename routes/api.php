<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::get('/dashboard', 'DashboardApiController')->name('api.dashboard');

// O prefixo separa este contador do polling de GET /api/dashboard.
Route::post('/analise-ia', 'AnaliseProducaoController')
    ->middleware('throttle:5,1,analise-ia:')
    ->name('api.analise-ia');

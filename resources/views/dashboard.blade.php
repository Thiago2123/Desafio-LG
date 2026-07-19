<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#a50034">
    <meta name="description" content="Dashboard centralizado de produção das Plantas A e B.">
    <title>Dashboard - LG</title>
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
</head>
<body>
    <div id="dashboard-app" class="app-shell" 
        data-api-url="{{ route('api.dashboard') }}"
        data-analysis-url="{{ route('api.analise-ia') }}"
        data-ai-configured="{{ $iaConfigurada ? 'true' : 'false' }}"
        data-default-date="{{ $dataSimulacao }}"
        data-polling-interval="{{ $intervaloPolling }}"
    >
        <header class="topbar">
            <div class="brand" aria-label="LG Production Intelligence">
                <span class="brand-mark" aria-hidden="true">LG</span>
                <span class="brand-copy">
                    <strong>Dashboard Inteligente</strong>
                    <small>LG</small>
                </span>
            </div>

            <div class="live-indicator" id="live-indicator" aria-live="polite">
                <span class="live-dot" aria-hidden="true"></span>
                <span id="connection-label">Conectando</span>
            </div>
        </header>

        <main class="dashboard-container">
            <section class="hero" aria-labelledby="dashboard-title">
                <div>
                    <p class="eyebrow">OPERAÇÃO INDUSTRIAL</p>
                    <h1 id="dashboard-title">Dashboard Centralizado de Produção</h1>
                    <p class="hero-description">
                        Visão integrada das linhas, metas e qualidade das duas plantas.
                    </p>
                </div>

                <div class="update-panel">
                    <span class="update-label">Atualização da produção</span>
                    <strong id="last-recorded-at">Aguardando dados</strong>
                    <small id="last-request-at">Polling automático a cada 5 segundos</small>
                </div>
            </section>

            <section class="toolbar" aria-label="Filtros do dashboard">
                <div class="scope-filter">
                    <span class="filter-label">VISUALIZAÇÃO</span>
                    <div class="segmented-control" role="group" aria-label="Selecionar planta">
                        <button type="button" class="scope-button" data-scope="plant_a" aria-pressed="false">
                            Planta A
                        </button>
                        <button type="button" class="scope-button" data-scope="plant_b" aria-pressed="false">
                            Planta B
                        </button>
                        <button type="button" class="scope-button is-active" data-scope="all" aria-pressed="true">
                            Consolidado
                        </button>
                    </div>
                </div>

                <label class="date-filter">
                    <span class="filter-label">DATA DE REFERÊNCIA</span>
                    <input
                        type="date"
                        id="dashboard-date"
                        min="2026-01-01"
                        max="2026-02-01"
                        value="{{ $dataSimulacao }}"
                    >
                </label>

                <div class="period-note">
                    <span>Histórico</span>
                    <strong>Jan/2026</strong>
                    <span class="period-divider" aria-hidden="true"></span>
                    <span>Tempo real</span>
                    <strong>01/02/2026</strong>
                </div>
            </section>

            <section id="error-panel" class="message-panel error-panel" role="alert" hidden>
                <div>
                    <strong>Não foi possível atualizar os indicadores.</strong>
                    <p id="error-message">Verifique a conexão com as plantas e tente novamente.</p>
                </div>
                <button type="button" id="retry-button" class="secondary-button">Tentar novamente</button>
            </section>

            <section id="alert-panel" class="message-panel alert-panel" aria-live="polite" hidden>
                <span class="alert-symbol" aria-hidden="true">!</span>
                <div>
                    <strong id="alert-title">Atenção à taxa de defeitos</strong>
                    <p id="alert-description"></p>
                </div>
            </section>

            <section class="summary-grid" aria-label="Resumo dos indicadores">
                <article class="metric-card metric-primary">
                    <div class="metric-heading">
                        <span>PRODUÇÃO TOTAL</span>
                        <span class="metric-code">QTD</span>
                    </div>
                    <strong class="metric-value" id="total-produced">--</strong>
                    <p>itens produzidos no período</p>
                </article>

                <article class="metric-card">
                    <div class="metric-heading">
                        <span>ITENS DEFEITUOSOS</span>
                        <span class="metric-code">DEF</span>
                    </div>
                    <strong class="metric-value" id="total-defective">--</strong>
                    <p>itens fora do padrão de qualidade</p>
                </article>

                <article class="metric-card" id="defect-rate-card">
                    <div class="metric-heading">
                        <span>TAXA DE DEFEITOS</span>
                        <span class="metric-code">%</span>
                    </div>
                    <strong class="metric-value" id="total-defect-rate">--</strong>
                    <p>limite de alerta: acima de <strong id="alert-threshold">5%</strong></p>
                </article>

                <article class="metric-card">
                    <div class="metric-heading">
                        <span>EFICIÊNCIA GERAL</span>
                        <span class="metric-code">EFF</span>
                    </div>
                    <strong class="metric-value" id="total-efficiency">--</strong>
                    <div class="metric-progress" aria-hidden="true">
                        <span id="total-efficiency-bar"></span>
                    </div>
                    <p>produção realizada sobre a meta</p>
                </article>
            </section>

            <section class="ai-section" aria-labelledby="ai-title">
                <div class="ai-heading">
                    <div>
                        <div class="ai-kicker">
                            <span class="ai-badge">GROQ AI</span>
                            <span>Análise sob demanda</span>
                        </div>
                        <h2 id="ai-title">Análise inteligente da produção</h2>
                        <p>
                            Interpretação gerencial dos indicadores exibidos, gerada somente quando solicitada.
                        </p>
                    </div>

                    <button
                        type="button"
                        id="generate-ai-analysis"
                        class="ai-button"
                        @unless($iaConfigurada) disabled @endunless
                    >
                        <span class="ai-button-symbol" aria-hidden="true">✦</span>
                        <span id="ai-button-label">Gerar análise com IA</span>
                    </button>
                </div>

                <div id="ai-initial-state" class="ai-initial-state">
                    <strong id="ai-initial-title">
                        {{ $iaConfigurada ? 'Pronto para analisar os dados atuais' : 'Integração não configurada' }}
                    </strong>
                    <p id="ai-initial-description">
                        @if($iaConfigurada)
                            O clique envia somente os indicadores consolidados para a Groq. O polling não consome a cota da IA.
                        @else
                            Adicione GROQ_API_KEY ao arquivo .env do servidor para habilitar este recurso opcional.
                        @endif
                    </p>
                </div>

                <div id="ai-error-state" class="ai-error-state" role="alert" hidden>
                    <strong>Não foi possível gerar a análise.</strong>
                    <p id="ai-error-message"></p>
                </div>

                <div id="ai-result" class="ai-result" aria-live="polite" hidden>
                    <div class="ai-summary">
                        <div>
                            <span class="ai-result-label">DIAGNÓSTICO GERAL</span>
                            <span id="ai-situation" class="ai-situation">--</span>
                        </div>
                        <p id="ai-summary-text"></p>
                    </div>

                    <div class="ai-columns">
                        <article>
                            <h3>Destaques</h3>
                            <ul id="ai-highlights"></ul>
                        </article>
                        <article>
                            <h3>Pontos de atenção</h3>
                            <ul id="ai-attention-points"></ul>
                        </article>
                        <article class="ai-action-card">
                            <h3>Ação recomendada</h3>
                            <p id="ai-recommended-action"></p>
                        </article>
                    </div>

                    <small id="ai-metadata" class="ai-metadata"></small>
                </div>
            </section>

            <section class="production-section" aria-labelledby="production-title">
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">DETALHAMENTO</p>
                        <h2 id="production-title">Desempenho por produto</h2>
                    </div>
                    <div class="section-status">
                        <span id="product-count">0 produtos</span>
                        <span class="status-divider" aria-hidden="true"></span>
                        <span id="alert-count">0 alertas</span>
                    </div>
                </div>

                <div class="table-shell">
                    <table>
                        <caption class="sr-only">
                            Produção, defeitos, taxa de defeitos e eficiência por produto
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">Produto / linha</th>
                                <th scope="col" class="numeric-column">Produzido</th>
                                <th scope="col" class="numeric-column">Defeitos</th>
                                <th scope="col">Taxa de defeitos</th>
                                <th scope="col">Eficiência</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody id="product-table-body">
                            <tr class="loading-row">
                                <td colspan="6">Carregando indicadores das plantas...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <footer class="dashboard-footer">
                <p>Desafio Técnico · LG</p>
                <div class="architecture-tags" aria-label="Arquitetura da solução">
                    <span>Laravel 7</span>
                    <span>PHP 7.4</span>
                    <span>2× MySQL 8</span>
                    <span>Polling 5s</span>
                    <span>Groq AI</span>
                </div>
            </footer>
        </main>
    </div>

    <script src="{{ asset('js/dashboard.js') }}" defer></script>
</body>
</html>

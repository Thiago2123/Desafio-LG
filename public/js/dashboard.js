(function () {
    'use strict';

    var aplicacao = document.getElementById('dashboard-app');

    if (! aplicacao) {
        return;
    }

    var estado = {
        escopo: 'all',
        data: aplicacao.dataset.defaultDate,
        temporizador: null,
        requisicao: null,
        requisicaoAnalise: null,
        dadosAnalisadosAte: null,
        metadadosAnalise: '',
        jaCarregou: false
    };

    var urlDaApi = aplicacao.dataset.apiUrl;
    var urlDaAnalise = aplicacao.dataset.analysisUrl;
    var iaConfigurada = aplicacao.dataset.aiConfigured === 'true';
    var intervaloDeConsulta = Number(aplicacao.dataset.pollingInterval) || 5000;
    var formatadorDeNumero = new Intl.NumberFormat('pt-BR');
    var formatadorDePercentual = new Intl.NumberFormat('pt-BR', {
        minimumFractionDigits: 1,
        maximumFractionDigits: 2
    });

    var elementos = {
        botoesDeEscopo: Array.prototype.slice.call(document.querySelectorAll('[data-scope]')),
        campoData: document.getElementById('dashboard-date'),
        indicadorTempoReal: document.getElementById('live-indicator'),
        rotuloConexao: document.getElementById('connection-label'),
        ultimoRegistroEm: document.getElementById('last-recorded-at'),
        ultimaConsultaEm: document.getElementById('last-request-at'),
        painelErro: document.getElementById('error-panel'),
        mensagemErro: document.getElementById('error-message'),
        botaoTentarNovamente: document.getElementById('retry-button'),
        painelAlerta: document.getElementById('alert-panel'),
        descricaoAlerta: document.getElementById('alert-description'),
        totalProduzido: document.getElementById('total-produced'),
        totalDefeituoso: document.getElementById('total-defective'),
        taxaDefeitosTotal: document.getElementById('total-defect-rate'),
        eficienciaTotal: document.getElementById('total-efficiency'),
        barraEficienciaTotal: document.getElementById('total-efficiency-bar'),
        cartaoTaxaDefeitos: document.getElementById('defect-rate-card'),
        limiteAlerta: document.getElementById('alert-threshold'),
        quantidadeProdutos: document.getElementById('product-count'),
        quantidadeAlertas: document.getElementById('alert-count'),
        corpoTabela: document.getElementById('product-table-body'),
        botaoGerarAnalise: document.getElementById('generate-ai-analysis'),
        rotuloBotaoAnalise: document.getElementById('ai-button-label'),
        estadoInicialAnalise: document.getElementById('ai-initial-state'),
        tituloInicialAnalise: document.getElementById('ai-initial-title'),
        descricaoInicialAnalise: document.getElementById('ai-initial-description'),
        estadoErroAnalise: document.getElementById('ai-error-state'),
        mensagemErroAnalise: document.getElementById('ai-error-message'),
        resultadoAnalise: document.getElementById('ai-result'),
        situacaoAnalise: document.getElementById('ai-situation'),
        resumoAnalise: document.getElementById('ai-summary-text'),
        destaquesAnalise: document.getElementById('ai-highlights'),
        pontosAtencaoAnalise: document.getElementById('ai-attention-points'),
        acaoRecomendadaAnalise: document.getElementById('ai-recommended-action'),
        metadadosAnalise: document.getElementById('ai-metadata')
    };

    /** Consulta a API, trata falhas e agenda o próximo ciclo do polling. */
    function consultarDashboard() {
        if (document.hidden) {
            agendarProximaConsulta();
            return;
        }

        // Cancela uma consulta anterior para evitar respostas chegando fora de ordem.
        if (estado.requisicao) {
            estado.requisicao.abort();
        }

        estado.requisicao = new AbortController();
        definirEstadoDaConexao('carregando');

        var parametros = new URLSearchParams({
            scope: estado.escopo,
            date: estado.data
        });

        fetch(urlDaApi + '?' + parametros.toString(), {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            signal: estado.requisicao.signal,
            cache: 'no-store'
        })
            .then(function (resposta) {
                return resposta.json().catch(function () {
                    return {};
                }).then(function (dados) {
                    if (! resposta.ok) {
                        throw new Error(dados.message || 'Falha ao consultar o dashboard.');
                    }

                    return dados;
                });
            })
            .then(function (dados) {
                renderizarDashboard(dados);
                definirEstadoDaConexao('conectado');
                ocultarErro();
                estado.jaCarregou = true;
            })
            .catch(function (erro) {
                if (erro.name === 'AbortError') {
                    return;
                }

                definirEstadoDaConexao('desconectado');
                exibirErro(erro.message);
            })
            .finally(function () {
                estado.requisicao = null;
                agendarProximaConsulta();
            });
    }

    /** Agenda somente depois que a requisição atual termina, evitando sobreposição. */
    function agendarProximaConsulta() {
        window.clearTimeout(estado.temporizador);
        estado.temporizador = window.setTimeout(consultarDashboard, intervaloDeConsulta);
    }

    /** Distribui os dados recebidos entre cartões, alertas e tabela. */
    function renderizarDashboard(dados) {
        var totais = dados.totals;
        var produtos = dados.products || [];
        var produtosEmAlerta = produtos.filter(function (produto) {
            return produto.has_defect_alert;
        });

        elementos.totalProduzido.textContent = formatarNumero(totais.produced_quantity);
        elementos.totalDefeituoso.textContent = formatarNumero(totais.defective_quantity);
        elementos.taxaDefeitosTotal.textContent = formatarPercentual(totais.defect_rate);
        elementos.eficienciaTotal.textContent = formatarPercentual(totais.efficiency);
        elementos.barraEficienciaTotal.style.width = limitar(totais.efficiency, 0, 100) + '%';
        elementos.limiteAlerta.textContent = formatarPercentual(dados.alert_threshold);
        elementos.cartaoTaxaDefeitos.classList.toggle('has-alert', totais.has_defect_alert);

        elementos.quantidadeProdutos.textContent = pluralizar(produtos.length, 'produto', 'produtos');
        elementos.quantidadeAlertas.textContent = pluralizar(produtosEmAlerta.length, 'alerta', 'alertas');
        elementos.ultimoRegistroEm.textContent = dados.last_recorded_at
            ? formatarDataHora(dados.last_recorded_at)
            : 'Sem produção no período';
        elementos.ultimaConsultaEm.textContent = 'Dashboard consultado às ' + formatarHorario(dados.generated_at);

        renderizarAlertas(produtosEmAlerta, dados.alert_threshold);
        renderizarProdutos(produtos);
        atualizarValidadeDaAnalise(dados.last_recorded_at);
    }

    /** Exibe apenas os produtos cuja taxa ultrapassou o limite configurado. */
    function renderizarAlertas(produtos, limite) {
        if (! produtos.length) {
            elementos.painelAlerta.hidden = true;
            return;
        }

        var nomes = produtos.map(function (produto) {
            return produto.name + ' (' + formatarPercentual(produto.defect_rate) + ')';
        });

        elementos.descricaoAlerta.textContent =
            pluralizar(produtos.length, 'produto ultrapassou', 'produtos ultrapassaram') +
            ' o limite de ' + formatarPercentual(limite) + ': ' + nomes.join(', ') + '.';
        elementos.painelAlerta.hidden = false;
    }

    /** Monta as linhas da tabela a partir do array de produtos retornado pela API. */
    function renderizarProdutos(produtos) {
        if (! produtos.length) {
            elementos.corpoTabela.innerHTML =
                '<tr class="empty-row"><td colspan="6">Nenhum produto encontrado para o período.</td></tr>';
            return;
        }

        elementos.corpoTabela.innerHTML = produtos.map(function (produto) {
            var progressoDefeitos = limitar(produto.defect_rate / 10 * 100, 0, 100);
            var progressoEficiencia = limitar(produto.efficiency, 0, 100);
            var classeAlerta = produto.has_defect_alert ? ' has-alert' : '';
            var classeDefeito = produto.has_defect_alert ? ' is-danger' : '';
            var classeStatus = produto.has_defect_alert ? ' is-alert' : '';
            var rotuloStatus = produto.has_defect_alert ? 'Alerta' : 'Normal';

            return '<tr class="' + classeAlerta.trim() + '">' +
                '<td>' +
                    '<span class="product-name">' + escaparHtml(produto.name) + '</span>' +
                    '<span class="product-line">' + escaparHtml(produto.line_name) + '</span>' +
                '</td>' +
                '<td class="numeric-cell"><strong>' + formatarNumero(produto.produced_quantity) + '</strong></td>' +
                '<td class="numeric-cell">' + formatarNumero(produto.defective_quantity) + '</td>' +
                '<td class="rate-cell">' +
                    '<div class="rate-value defect-rate' + classeDefeito + '">' +
                        '<span>' + formatarPercentual(produto.defect_rate) + '</span>' +
                    '</div>' +
                    '<div class="rate-track" aria-hidden="true" style="--progress:' + progressoDefeitos + '%">' +
                        '<span></span>' +
                    '</div>' +
                '</td>' +
                '<td class="rate-cell">' +
                    '<div class="rate-value"><span>' + formatarPercentual(produto.efficiency) + '</span></div>' +
                    '<div class="rate-track" aria-hidden="true" style="--progress:' + progressoEficiencia + '%">' +
                        '<span></span>' +
                    '</div>' +
                '</td>' +
                '<td><span class="status-badge' + classeStatus + '">' + rotuloStatus + '</span></td>' +
            '</tr>';
        }).join('');
    }

    /** Atualiza o indicador visual de conexão no topo da página. */
    function definirEstadoDaConexao(estadoDaConexao) {
        elementos.indicadorTempoReal.classList.remove('is-online', 'is-offline');

        if (estadoDaConexao === 'conectado') {
            elementos.indicadorTempoReal.classList.add('is-online');
            elementos.rotuloConexao.textContent = 'Tempo real';
        } else if (estadoDaConexao === 'desconectado') {
            elementos.indicadorTempoReal.classList.add('is-offline');
            elementos.rotuloConexao.textContent = 'Sem conexão';
        } else {
            elementos.rotuloConexao.textContent = estado.jaCarregou ? 'Atualizando' : 'Conectando';
        }
    }

    function exibirErro(mensagem) {
        elementos.mensagemErro.textContent = mensagem;
        elementos.painelErro.hidden = false;
    }

    function ocultarErro() {
        elementos.painelErro.hidden = true;
    }

    /**
     * Solicita a análise apenas no clique. Ela não faz parte do polling para
     * preservar a cota gratuita e evitar recomendações repetidas.
     */
    function gerarAnaliseIa() {
        if (! iaConfigurada || estado.requisicaoAnalise) {
            return;
        }

        estado.requisicaoAnalise = new AbortController();
        elementos.botaoGerarAnalise.disabled = true;
        elementos.botaoGerarAnalise.classList.add('is-loading');
        elementos.rotuloBotaoAnalise.textContent = 'Analisando indicadores...';
        elementos.estadoInicialAnalise.hidden = false;
        elementos.tituloInicialAnalise.textContent = 'A Groq está interpretando os dados';
        elementos.descricaoInicialAnalise.textContent =
            'Produção, metas e qualidade estão sendo transformadas em uma leitura gerencial.';
        elementos.estadoErroAnalise.hidden = true;
        elementos.resultadoAnalise.hidden = true;

        fetch(urlDaAnalise, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                scope: estado.escopo,
                date: estado.data
            }),
            signal: estado.requisicaoAnalise.signal
        })
            .then(function (resposta) {
                return resposta.json().catch(function () {
                    return {};
                }).then(function (dados) {
                    if (! resposta.ok) {
                        throw new Error(dados.message || 'Falha ao gerar a análise inteligente.');
                    }

                    return dados;
                });
            })
            .then(renderizarAnaliseIa)
            .catch(function (erro) {
                if (erro.name === 'AbortError') {
                    return;
                }

                elementos.estadoInicialAnalise.hidden = true;
                elementos.resultadoAnalise.hidden = true;
                elementos.mensagemErroAnalise.textContent = erro.message;
                elementos.estadoErroAnalise.hidden = false;
            })
            .finally(function () {
                estado.requisicaoAnalise = null;
                elementos.botaoGerarAnalise.disabled = ! iaConfigurada;
                elementos.botaoGerarAnalise.classList.remove('is-loading');
                elementos.rotuloBotaoAnalise.textContent = elementos.resultadoAnalise.hidden
                    ? 'Gerar análise com IA'
                    : 'Gerar nova análise';
            });
    }

    /** Preenche o card somente com o JSON já validado pelo backend. */
    function renderizarAnaliseIa(analise) {
        var rotulos = {
            normal: 'Situação normal',
            atencao: 'Requer atenção',
            critica: 'Situação crítica'
        };

        elementos.situacaoAnalise.className = 'ai-situation is-' + analise.situacao;
        elementos.situacaoAnalise.textContent = rotulos[analise.situacao] || 'Analisado';
        elementos.resumoAnalise.textContent = analise.resumo;
        renderizarListaDaAnalise(elementos.destaquesAnalise, analise.destaques, 'Nenhum destaque informado.');
        renderizarListaDaAnalise(
            elementos.pontosAtencaoAnalise,
            analise.pontos_atencao,
            'Nenhum ponto de atenção identificado.'
        );
        elementos.acaoRecomendadaAnalise.textContent = analise.acao_recomendada;

        estado.dadosAnalisadosAte = analise.dados_ate;
        estado.metadadosAnalise = 'Gerada pela ' + analise.provedor +
            ' com ' + analise.modelo + ' em ' + formatarDataHora(analise.gerada_em) + '.';
        elementos.metadadosAnalise.textContent = estado.metadadosAnalise;
        elementos.resultadoAnalise.classList.remove('is-stale');
        elementos.estadoInicialAnalise.hidden = true;
        elementos.estadoErroAnalise.hidden = true;
        elementos.resultadoAnalise.hidden = false;
    }

    function renderizarListaDaAnalise(elemento, itens, mensagemVazia) {
        if (! itens || ! itens.length) {
            elemento.innerHTML = '<li>' + escaparHtml(mensagemVazia) + '</li>';
            return;
        }

        elemento.innerHTML = itens.map(function (item) {
            return '<li>' + escaparHtml(item) + '</li>';
        }).join('');
    }

    /** Avisa quando o polling já encontrou dados posteriores aos usados pela IA. */
    function atualizarValidadeDaAnalise(ultimoRegistroEm) {
        if (! estado.dadosAnalisadosAte || elementos.resultadoAnalise.hidden) {
            return;
        }

        var possuiNovosDados = ultimoRegistroEm !== estado.dadosAnalisadosAte;
        elementos.resultadoAnalise.classList.toggle('is-stale', possuiNovosDados);
        elementos.metadadosAnalise.textContent = estado.metadadosAnalise +
            (possuiNovosDados ? ' Há novos dados; gere outra análise para atualizá-la.' : '');
    }

    /** Limpa a análise ao trocar planta ou data, pois o contexto deixou de ser o mesmo. */
    function reiniciarAnaliseIa() {
        if (estado.requisicaoAnalise) {
            estado.requisicaoAnalise.abort();
        }

        estado.dadosAnalisadosAte = null;
        estado.metadadosAnalise = '';
        elementos.resultadoAnalise.hidden = true;
        elementos.resultadoAnalise.classList.remove('is-stale');
        elementos.estadoErroAnalise.hidden = true;
        elementos.estadoInicialAnalise.hidden = false;
        elementos.tituloInicialAnalise.textContent = iaConfigurada
            ? 'Pronto para analisar os dados atuais'
            : 'Integração não configurada';
        elementos.descricaoInicialAnalise.textContent = iaConfigurada
            ? 'O clique envia somente os indicadores consolidados para a Groq. O polling não consome a cota da IA.'
            : 'Adicione GROQ_API_KEY ao arquivo .env do servidor para habilitar este recurso opcional.';
        elementos.rotuloBotaoAnalise.textContent = 'Gerar análise com IA';
    }

    /** Troca entre Planta A, Planta B e consolidado e consulta novamente a API. */
    function selecionarEscopo(escopo) {
        estado.escopo = escopo;
        reiniciarAnaliseIa();

        elementos.botoesDeEscopo.forEach(function (botao) {
            var selecionado = botao.dataset.scope === escopo;
            botao.classList.toggle('is-active', selecionado);
            botao.setAttribute('aria-pressed', selecionado ? 'true' : 'false');
        });

        consultarDashboard();
    }

    function formatarNumero(valor) {
        return formatadorDeNumero.format(Number(valor) || 0);
    }

    function formatarPercentual(valor) {
        return formatadorDePercentual.format(Number(valor) || 0) + '%';
    }

    function formatarDataHora(valor) {
        return new Intl.DateTimeFormat('pt-BR', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        }).format(new Date(valor));
    }

    function formatarHorario(valor) {
        return new Intl.DateTimeFormat('pt-BR', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        }).format(new Date(valor));
    }

    function pluralizar(quantidade, singular, plural) {
        return quantidade + ' ' + (quantidade === 1 ? singular : plural);
    }

    function limitar(valor, minimo, maximo) {
        return Math.min(Math.max(Number(valor) || 0, minimo), maximo);
    }

    /** Evita que textos vindos da API sejam interpretados como marcação HTML. */
    function escaparHtml(valor) {
        return String(valor)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    elementos.botoesDeEscopo.forEach(function (botao) {
        botao.addEventListener('click', function () {
            selecionarEscopo(botao.dataset.scope);
        });
    });

    elementos.campoData.addEventListener('change', function (evento) {
        estado.data = evento.target.value || aplicacao.dataset.defaultDate;
        reiniciarAnaliseIa();
        consultarDashboard();
    });

    elementos.botaoTentarNovamente.addEventListener('click', consultarDashboard);
    elementos.botaoGerarAnalise.addEventListener('click', gerarAnaliseIa);

    document.addEventListener('visibilitychange', function () {
        if (! document.hidden) {
            consultarDashboard();
        }
    });

    consultarDashboard();
}());

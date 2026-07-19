# Arquitetura do Dashboard Centralizado de Produção

## 1. Objetivo desta decisão

Construir uma aplicação Laravel 7 que consulte duas fontes MySQL independentes,
normalize os resultados em uma camada de serviço e apresente indicadores da Planta A,
da Planta B ou das duas plantas consolidadas.

A solução precisa ser simples de demonstrar, reproduzível para qualquer um que quer executa-lo.

## 2. Premissas assumidas

- Cada planta possui seu próprio servidor MySQL e não se comunica com a outra.
- Somente a aplicação central conhece as duas conexões.
- As duas bases possuem o mesmo schema de banco, mas seus dados são independentes.
- Janeiro de 2026 representa o histórico.
- O relógio da simulação gera registros para 01/02/2026 (dia atual falço).
- Eficiência de produção = quantidade produzida / meta planejada * 100.
- Taxa de defeitos = quantidade defeituosa / quantidade produzida * 100.
- Uma taxa de defeitos estritamente maior que 5% gera uma mensagem de alerta.

## 3. Componentes

```text
Navegador
  |
  | HTML inicial + GET /api/dashboard a cada 5 segundos
  v
Nginx -> Laravel 7 / PHP 7.4
             |
             +----------------------------+
             |                            |
             v                            v
     DashboardService        AnaliseProducaoService
       |           |                    |
       v           v                    | somente no clique
  conexão      conexão                  v
  planta_a      planta_b             Groq API
       |           |
       v           v
   MySQL 8 A    MySQL 8 B
```

O navegador nunca acessa os bancos diretamente, assim diminui o processamento em tela. O `DashboardService` escolhe as conexões solicitadas, pelo botão na tela, executa as consultas e junta os resultados por produto.

## 4. Ambiente reproduzível

O repositório possui um Docker Compose próprio com cinco serviços principais:

1. `nginx`: recebe as requisições HTTP.
2. `app`: executa Laravel 7 em PHP-FPM 7.4.
3. `simulator`: executa o comando de simulação periodicamente sendo parametrizavel.
4. `mysql_planta_a`: primeiro banco MySQL 8.
5. `mysql_planta_b`: segundo banco MySQL 8.

A ideia inicial seria usar dois schemas no mesmo container, porem pelo anunciado, os bancos nao poderiam estar ligados um com o outro. Por isso deve ser separada então os containers

No ambiente local, o Nginx publica a aplicação na
porta configurada por `APP_PORT`; as portas dos bancos são expostas somente para
facilitar a avaliação com ferramentas como DBeaver ou MySQL Workbench.

## 5. Conexões Laravel

As conexões serão declaradas em `config/database.php`:

- `planta_a`
- `planta_b`

Cada uma possuirá variáveis próprias no `.env`:

```text
DB_PLANTA_A_HOST
DB_PLANTA_A_PORT
DB_PLANTA_A_DATABASE
DB_PLANTA_A_USERNAME
DB_PLANTA_A_PASSWORD

DB_PLANTA_B_HOST
DB_PLANTA_B_PORT
DB_PLANTA_B_DATABASE
DB_PLANTA_B_USERNAME
DB_PLANTA_B_PASSWORD
```

Isso permite apontar para containers, servidores físicos ou serviços remotos sem
alterar o código.

## 6. Modelo de dados de cada planta

### `products` (produtos)

|    Campo   |      Tipo      | Observação                                                         |
|------------|----------------|--------------------------------------------------------------------|
|    `id`    |     bigint     | Chave primária local ddo Banco                                     |
|   `name`   |     varchar    | Nome exibido  (alias)                                              |
|   `slug`   | varchar unique | Chave estável usada na consolidação (categoria)                    |
| `line_name`|     varchar    | Nome da linha de produção (em qual bd o produto está)              |
| timestamps |    datetime    | Auditoria básica (hora que entrou no banco e que foi feito update) |

Produtos iniciais: Geladeira, Monitor, Máquina de Lavar, TV e Ar-Condicionado.

### `production_records` (histórico de medições)

|        Campo         |     Tipo     | Observação                                |
|----------------------|--------------|-------------------------------------------| 
|        `id`          |    bigint    |       Chave primária                      |
|     `product_id`     |    bigint    |  Chave estrangeira da tabela products     |
|  `produced_quantity` | unsigned int |         Quantidade produzida              |
| `defective_quantity` | unsigned int |     Quantidade de defeituoso              |
|   `target_quantity`  | unsigned int |     Meta de produção planejada            |
|     `recorded_at`    |   datetime   | Instante simulado da medição (hora falsa) |
|       timestamps     |   datetime   |          Auditoria básica                 |

Deve ser criados índices em `recorded_at` e em `(product_id, recorded_at)`. 

```text
defective_quantity
Produzido: 115
Defeituoso: 4
taxa de defeitos = 4 / 115 × 100
```

```text
target_quantity
Meta: 120
Produzido: 115
eficiência = 115 / 120 × 100
```

## 7. Consultas exigidas pelo desafio

- **Eloquent ORM:** carregará o catálogo de produtos/linhas de cada planta.
- **SQL puro com `DB::select`:** somará produção, defeitos e metas no período.

A agregação ocorrerá no banco para transferir poucos dados. O PHP receberá somente
uma linha por produto e fará a consolidação entre plantas usando o `slug`.

Para a visão consolidada, somamos primeiro os valores absolutos:

```text
produzido consolidado = produzido A + produzido B
defeitos consolidados = defeitos A + defeitos B
meta consolidada = meta A + meta B
```

Depois recalculamos os percentuais. Não calculamos a média simples dos percentuais,
pois ela daria peso igual a plantas com volumes diferentes.

## 8. API e interface

### Página

`GET /dashboard`

Renderiza o Blade com estrutura, filtros e estado inicial.

### Dados

`GET /api/dashboard?scope=all&date=2026-02-01`

Valores aceitos para `scope`:

- `plant_a`
- `plant_b`
- `all`

Resposta resumida:

```json
{
  "scope": "all",
  "date": "2026-02-01",
  "generated_at": "2026-02-01T10:15:30-03:00",
  "totals": {},
  "products": []
}
```

O JavaScript consultará a API a cada cinco segundos. Alertas são derivados da taxa retornada e destacados quando `defect_rate > 5`.

### Análise inteligente de I.A 

`POST /api/analise-ia` recebe os mesmos filtros da consulta principal. O controller
reutiliza `DashboardService`, prepara os indicadores e chama
`AnaliseProducaoService`. A requisição externa acontece somente ao clicar no botão;
ela não participa do polling.

O Laravel envia totais e indicadores por produto.
O Groq devolve um JSON com situação, resumo, destaques, pontos de atenção e ação recomendada. 
O backend valida esse JSON novamente e mantém o resultado em cache. 
Se a chave não existir, a funcionalidade fica desabilitada sem afetar o restante do dashboard.

## 9. Simulação em tempo real

Um comando Artisan gerará pequenos lotes para ambas as plantas:

```text
php artisan production:simulate
```

O container `simulator` executará esse comando em um intervalo parametrizavel. Os
registros usarão a data simulada 01/02/2026 e horários crescentes. A simulação
poderá ser desativada por variável de ambiente.



## 10. Testes prioritários

- Cálculo de taxa de defeitos.
- Cálculo de eficiência.
- Tratamento de divisão por zero.
- Filtro `plant_a`, `plant_b` e `all`.
- Validação de `scope` e data.
- Geração de alerta somente acima de 5%.
- Indisponibilidade de uma planta com resposta de erro clara, sem ocultar a falha.
- Resposta estruturada da IA, cache e falhas externas sem chamadas reais nos testes.


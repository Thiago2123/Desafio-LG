# LG Production Dashboard

Dashboard centralizado desenvolvido para o desafio técnico de Desenvolvedor AX
Center da LG Electronics. A aplicação consulta duas plantas industriais com bancos
MySQL independentes e apresenta os resultados por planta ou de forma consolidada.

## Funcionalidades

- indicadores de produção, defeitos, meta e eficiência;
- filtros para Planta A, Planta B e visão consolidada;
- alerta de qualidade quando a taxa de defeitos ultrapassa 5%;
- histórico determinístico de janeiro de 2026;
- simulação em tempo real do dia 01/02/2026;
- atualização do dashboard por polling a cada cinco segundos;
- análise gerencial opcional com IA pela Groq;
- interface responsiva em Blade, CSS e JavaScript;
- testes automatizados das regras, APIs, bancos e integração com IA.

## Tecnologias

- PHP 7.4 e Laravel 7.30;
- dois servidores MySQL 8;
- Nginx;
- Docker Compose;
- Groq API com `openai/gpt-oss-120b`;
- PHPUnit.

## Arquitetura resumida

```text
Navegador -> Nginx -> Laravel
                         |-> MySQL Planta A
                         |-> MySQL Planta B
                         |-> Groq API (somente quando solicitada)
```

Os dois MySQL estão em redes internas separadas. Apenas a aplicação Laravel possui
acesso às duas redes e realiza a consolidação dos indicadores.

## Executar localmente com Docker

### Pré-requisitos

- Git;
- Docker Desktop ou Docker Engine;
- Docker Compose v2;
- portas `8080`, `3307` e `3308` disponíveis.

Não é necessário instalar PHP, Composer, Nginx ou MySQL diretamente na máquina.

### 1. Preparar o ambiente

Clone o repositório, entre na pasta do projeto e copie o arquivo .env de exemplo.

Linux ou macOS:

```bash
cp .env.example .env
```

PowerShell:

```powershell
Copy-Item .env.example .env
```

### 2. Instalar e preparar a aplicação

Execute na raiz do projeto:

```bash
docker compose build
docker compose run --rm app composer install --no-interaction --prefer-dist
docker compose run --rm app php artisan key:generate --force
docker compose run --rm app php artisan plants:setup --fresh
```

O comando `plants:setup` aplica as migrations e insere o catálogo e o histórico
nas duas plantas.

### 3. Iniciar os serviços

```bash
docker compose up -d
docker compose ps
```

Acesse [http://localhost:8080](http://localhost:8080).

Os bancos também podem ser consultados por ferramentas como DBeaver ou MySQL
Workbench:

| Planta |     Host    | Porta |   Banco    |
|--------|-------------|-------|------------|
| A      | `127.0.0.1` | 3307  | `planta_a` |
| B      | `127.0.0.1` | 3308  | `planta_b` |

Usuários e senhas estão no `.env`.

## Análise opcional com IA

Para testar a análise inteligente de I.A, ja está configurada um chave do groq, que expirará em 16/08/2026, caso necessário crie uma chave em [console.groq.com/keys](https://console.groq.com/keys) gratuito e configure no `.env`:

```dotenv
GROQ_API_KEY=gsk_sua_chave
GROQ_MODEL=openai/gpt-oss-120b
```

A análise da I.A é gerada somente quando o botão **Gerar análise com IA** é pressionado.
O polling não chama a Groq para polpar tokens.

## Simulação

O serviço `simulator` registra um novo minuto "falso" a cada dez segundos, para a simulação de dados do dia possa ser simulada. A simulação começa às 06:00 de 01/02/2026 e termina depois de registrar 23:59. O fim do container com estado `Exited (0)` representa conclusão normal; o dashboard e os bancos continuam disponíveis.

Para acompanhar os dados do simulator:

```bash
docker compose logs -f simulator
```

## Testes

```bash
docker compose run --rm app php vendor/bin/phpunit --colors=never
```

Resultado esperado:

```text
OK (14 tests, 62 assertions)
```

Os testes utilizam duas conexões SQLite independentes. A API da Groq é tambem
testada, portanto os testes não precisam de chave nem consomem cota externa.

## Regras principais de regra de negócio

```text
taxa de defeitos (%) = itens defeituosos / itens produzidos * 100
eficiência (%)       = itens produzidos /  meta de produção * 100
```

Foi criado no banco um campo chamado `meta`, para indicar uma meta de produção daquele iten, assim podendo saber se foi uma produção ruim ou boa.

No consolidado, primeiro são somadas as quantidades dos itens das duas plantas e somente depois os percentuais são calculados. Isso evita uma média incorreta entre
plantas com volumes diferentes.

Os otens são consultado com Eloquent. A agregação das plantas, defeitos e metas é
feita com SQL puro no MySQL, conforme solicitado no desafio.

## APIs

```http
GET /api/dashboard?scope=all&date=2026-02-01
POST /api/analise-ia
```

Escopos aceitos: `plant_a`, `plant_b` e `all`.

## Comandos úteis

```bash
# Parar preservando os dados
docker compose down

# Iniciar novamente
docker compose up -d

# Ver os últimos logs
docker compose logs --tail=100 app nginx simulator
```

Para apagar os volumes e recomeçar do zero, execute `docker compose down -v` e repita os comandos de preparação das plantas.

## Estrutura principal

```text
app/Console/Commands/       comandos de preparação e simulação
app/Http/Controllers/      página e APIs
app/Http/Requests/         validações
app/Models/                models
app/Services/              regras do dashboard e integração com a Groq (cérebro)
database/migrations/       tabelas das duas plantas
database/seeds/            dados dos bancos
public/css/                estilos do dashboard
public/js/                 renderização e logica do polling
resources/views/           página Blade (HTML)
tests/                     testes
```


## Demonstração

### Visão consolidada

Indicadores de produção, qualidade e eficiência das duas plantas atualizados em tempo real.

![Dashboard consolidado](docs/images/Consolidado.png)

### Análise De I.A

Interpretação gerencial gerada sob demanda pela Groq a partir dos indicadores calculados pelo Laravel.

![Análise inteligente da produção](docs/images/AnaliseIA.png)

### Detalhados

Detalhamento das quantidades, taxas de defeitos, eficiência e linhas de produção.

![Desempenho por produto](docs/images/Detalhado.png)


Documentação complementar:

- [`docs/01-arquitetura.md`](docs/01-arquitetura.md): decisões de arquitetura;

> PHP 7.4 e Laravel 7 foram mantidos para atender ao enunciado do desafio;

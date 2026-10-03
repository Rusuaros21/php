# Pré-conciliação contábil para o Domínio

Ferramenta externa ao Domínio Contabilidade que classifica as movimentações bancárias,
deixa o analista revisar só as exceções e (na fase 2) gera o arquivo de importação do Domínio.
O contexto completo, as regras de negócio e o andamento das etapas estão em [CLAUDE.md](CLAUDE.md).

## Requisitos

- Python 3.12
- PostgreSQL 16 (local ou via Docker)

## Instalação

```bash
cd conciliacao
python -m venv .venv
source .venv/bin/activate          # Windows: .venv\Scripts\activate
pip install -r requirements-dev.txt

cp .env.example .env               # ajuste as credenciais, se precisar
docker compose up -d               # sobe o PostgreSQL (opcional, se já tiver um local)

python manage.py migrate
python manage.py createsuperuser   # usuário para o admin
python manage.py runserver         # admin em http://localhost:8000/admin/
```

As variáveis do `.env` são lidas do ambiente. Exporte-as no terminal ou use
uma ferramenta como `direnv`. Os valores padrão já funcionam com o `docker-compose.yml`.

## Testes

```bash
pytest
```

O usuário do banco precisa da permissão `CREATEDB`, porque o pytest cria um banco de testes temporário.

## Estrutura

| Pasta        | Responsabilidade                                                |
|--------------|-----------------------------------------------------------------|
| `config/`    | Configurações do Django                                         |
| `empresas/`  | Empresas, contas bancárias e parâmetros de conciliação          |
| `importers/` | Leitura de extratos (OFX/CSV/Excel) e contas a pagar/receber    |
| `engine/`    | Normalização, regras, matching, score e duplicidade             |
| `exporters/` | Planilha de conferência e (fase 2) leiaute do Domínio           |
| `audit/`     | Registro das decisões                                           |
| `tests/`     | Testes. Só **dados fictícios** em `tests/fixtures/`             |

## LGPD

Nunca versione extratos ou planilhas reais. Use as pastas `dados/` e `saida/`, que são ignoradas pelo git.

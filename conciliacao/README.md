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

## Uso (até a etapa 2)

```bash
# 1. Cadastrar a empresa
python manage.py criar_empresa --razao-social "Supermercado X Ltda" --cnpj 11.222.333/0001-81 --codigo-dominio 101

# 2. Importar o extrato (no OFX a conta bancária vem do arquivo)
python manage.py importar_extrato dados/extrato_setembro.ofx --empresa 101

# Extrato em CSV/Excel: informe a conta
python manage.py importar_extrato dados/extrato.csv --empresa 101 --banco 748 --agencia 0101 --conta 12345-6

# 3. Importar o contas a pagar
python manage.py importar_titulos dados/contas_pagar.xlsx --empresa 101
```

Quando o arquivo usar nomes de coluna não reconhecidos, informe o mapeamento:

```bash
python manage.py importar_titulos dados/pagar.csv --empresa 101 \
    --mapa "parceiro_nome=Razão,numero_documento=NF,data_vencimento=Vcto,valor=Vlr Original"
```

Campos do extrato: `data`, `historico`, `valor` (ou `debito`/`credito`), `natureza` (D/C), `documento`, `fitid`.
Campos dos títulos: `parceiro_nome`, `parceiro_cnpj_cpf`, `numero_documento`, `data_emissao`,
`data_vencimento`, `data_pagamento`, `valor`, `valor_pago`, `historico`.

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

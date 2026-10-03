# Projeto: Pré-conciliação contábil para o Domínio

## Contexto

Ferramenta externa ao **Domínio Contabilidade** (Thomson Reuters) para automatizar a conciliação bancária e a preparação de lançamentos de um escritório de contabilidade. O escritório atende várias empresas; o caso piloto é uma rede de supermercados com centenas de fornecedores e ~1.500 movimentações bancárias/mês.

A ferramenta NÃO substitui o Domínio. Ela processa os dados antes, classifica as movimentações, deixa o analista revisar só as exceções e gera um arquivo no leiaute oficial de importação do Domínio.

Fluxo: extrato bancário + dados financeiros → importadores → motor de conciliação → revisão das exceções → arquivo no leiaute do Domínio → importação → validação pós-importação.

## Princípios

- O profissional revisa exceções; o sistema analisa tudo.
- Nenhuma decisão automática sem rastreabilidade: registrar regra usada, critério que gerou o match, score, usuário que aprovou, data/hora e alterações.
- Multiempresa desde o modelo de dados: cada empresa tem código no Domínio, plano de contas e regras próprias.
- Evitar lançamento em duplicidade: para cada tipo de movimentação, definir qual fonte gera o lançamento (a ferramenta ou outra rotina do Domínio).
- Dados financeiros de terceiros (LGPD): nunca versionar dados reais no repositório; usar dados fictícios ou mascarados em `tests/fixtures`.

## Classificações (saída do motor)

1. Conciliado automaticamente
2. Possível correspondência (precisa de aprovação)
3. Divergência (ex.: valor diferente)
4. Não identificado
5. Possível duplicidade

## Motor de conciliação

Matching em camadas, da regra mais rígida para a mais flexível:

1. Regras cadastradas da empresa (ex.: "TARIFA BANCÁRIA SICREDI" → conta de despesas bancárias). Regra específica de fornecedor vence regra genérica. Regras têm versão e aprovação.
2. Match exato: CNPJ/CPF + número do documento/nota + valor.
3. Match por valor + data (janela configurável) + mesmo fornecedor/cliente.
4. Match com tolerância (juros, multa, desconto, arredondamento), limite configurável.
5. Match N:1 e 1:N (pagamento em lote quitando várias notas; nota paga em parcelas).
6. Detecção de duplicidade (usar FITID do OFX e valor+data+histórico).

Score por critério (valor exato, CNPJ, nº do documento no histórico, proximidade da data); a soma define a faixa de classificação. Limites configuráveis por empresa.

Normalização antes do match: limpar históricos, extrair CNPJ/CPF e números de documento do texto do PIX/TED/boleto.

## Arquitetura

- `importers/`: um importador por fonte (OFX, CSV/Excel por banco, contas a pagar/receber, depois XML de NF-e, extrato de adquirente de cartão, Razão do Domínio), todos convertendo para um modelo interno único.
- `engine/`: normalização, regras, matching, score, duplicidade.
- `review/`: interface de revisão das exceções (fase 3; no MVP a saída é planilha).
- `exporters/`: arquivo no leiaute do Domínio + relatório de conferência.
- `audit/`: log de decisões.

Stack definida: Python 3.12, **Django 5.2**, **PostgreSQL** (desde o início, sem SQLite), pandas, openpyxl, pytest + pytest-django.

- O projeto Django fica em `conciliacao/` (a raiz do repositório contém outro projeto, em PHP).
- `config/`: settings do Django; credenciais do banco via variáveis de ambiente (ver `.env.example`).
- `empresas/`: empresa, conta bancária e parâmetros de conciliação (limites por empresa).
- OFX: parser próprio e tolerante (os OFX de bancos brasileiros costumam vir em SGML malformado; o `ofxparse` também não compila no ambiente).
- Admin do Django serve de cadastro provisório até a interface de revisão (fase 3).

## Fases

- **Fase 0 – Levantamento:** arquivos exportáveis do ambiente do cliente; campos disponíveis no extrato e no financeiro; como obter lançamentos contábeis; rotina do Domínio a usar; leiaute oficial de importação e campos obrigatórios; forma de validar a importação.
- **Fase 1 – MVP de medição (ATUAL):** uma empresa, um banco, um mês. Entrada: extrato OFX/CSV + contas a pagar (CSV/Excel). Saída: planilha Excel com cada movimentação classificada, score, critério do match e resumo por classificação. Sem importação no Domínio ainda. Objetivo: medir a taxa real de conciliação automática.
- **Fase 2 – Integração:** exportação no leiaute oficial do Domínio (obter o leiaute da versão usada pelo cliente; não supor campos) e validação pós-importação comparando Razão/Balancete exportado com o que foi gerado.
- **Fase 3 – Produto:** interface web de revisão, cadastro/aprovação de regras, multiempresa, auditoria completa.
- **Fase 4 – Ampliação:** adquirentes de cartão (relevante para supermercado: repasses líquidos, taxas, antecipações), CNAB de retorno, XML de NF-e, sugestão de novas regras a partir das aprovações.

## Pendências conhecidas

- Leiaute oficial de importação do Domínio: ainda não obtido.
- Extratos reais de cada banco usado pelos clientes: ainda não coletados (o conteúdo do histórico do PIX varia por banco).
- Formato do contas a pagar do cliente piloto: a confirmar.

## Andamento (execução por etapas)

Trabalhamos uma etapa por vez, com validação do usuário entre elas.

1. [x] Base do projeto: Django + PostgreSQL, apps, cadastro de empresa/conta bancária/parâmetros, testes.
2. [x] Importadores: OFX, extrato CSV/Excel, contas a pagar/receber CSV/Excel.
   - Colunas reconhecidas por sinônimos (`importers/leitores/tabela.py`); mapeamento manual com `--mapa` quando o arquivo do cliente usar outros nomes.
   - Mesmo arquivo não é importado duas vezes (hash SHA-256 por empresa).
   - Linhas de saldo do extrato CSV são ignoradas; qualquer linha inválida aborta a importação listando as linhas com erro.
   - Formatos genéricos: ajustar quando chegarem arquivos reais do cliente piloto.
3. [ ] Normalização: limpeza de histórico, extração de CNPJ/CPF e nº de documento.
4. [ ] Motor: regras, match exato, valor+data, tolerância, N:1/1:N, duplicidade, score.
5. [ ] Exportação da planilha Excel (movimentações classificadas + resumo).
6. [ ] Auditoria e comandos de linha de comando para o fluxo completo.

## Convenções

- Comunicação, documentação, mensagens de commit e interface sempre em português.

- Código e comentários podem ser em português; nomes de variáveis em português ou inglês, mas consistentes.
- Valores monetários com `Decimal`, nunca `float`.
- Datas em ISO internamente; aceitar dd/mm/aaaa na entrada.
- Toda regra de matching nova acompanha testes com casos fictícios.

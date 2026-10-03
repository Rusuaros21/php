# Dados de teste

Todos os arquivos desta pasta são **fictícios**: empresas, CNPJs, valores e
históricos foram inventados (os CNPJs têm dígitos verificadores válidos só
para exercitar a validação). Nunca coloque dados reais de clientes aqui (LGPD).

Cenário: supermercado fictício, Sicredi (748) ag. 0101 cc 12345-6, setembro/2026.

| Movimentação                                | Situação esperada no motor (etapa 4)        |
|---------------------------------------------|---------------------------------------------|
| PIX 1.520,00 Distribuidora Boa Vista NF 4521 | Match exato (CNPJ + NF + valor)             |
| Tarifa 35,90                                 | Regra cadastrada (despesa bancária)         |
| Tarifa 35,90 repetida com o mesmo FITID      | Possível duplicidade (só no OFX)            |
| Crédito Cielo 12.500,00                      | Não identificado (sem contas a receber)     |
| Boleto 980,45 Frigorífico (título 975,00)    | Match com tolerância (juros)                |
| TED 3.200,00 Laticínios (títulos 1.200 + 2.000) | Match N:1 (lote)                         |
| PIX 410,00 Hortifruti (sem CNPJ no histórico) | Match por valor + data + nome             |

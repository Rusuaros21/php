from datetime import date
from decimal import Decimal

import pytest
from openpyxl import Workbook

from importers.leitores.tabela import ler_extrato_tabela, ler_titulos
from importers.leitores.tipos import ErroLeitura


def escrever(tmp_path, nome, texto, encoding="utf-8"):
    caminho = tmp_path / nome
    caminho.write_bytes(texto.encode(encoding))
    return caminho


class TestExtratoTabela:
    def test_csv_com_coluna_dc_e_linhas_de_saldo(self, fixtures):
        extrato = ler_extrato_tabela(fixtures / "extrato_ficticio.csv")
        assert len(extrato.transacoes) == 6
        assert extrato.ignoradas == 2  # SALDO ANTERIOR e SALDO DO DIA
        primeira = extrato.transacoes[0]
        assert primeira.data == date(2026, 9, 1)
        assert primeira.valor == Decimal("-1520.00")
        assert primeira.documento == "4521"
        assert primeira.linha == 3
        assert extrato.transacoes[1].historico == "TARIFA BANCÁRIA SICREDI"
        assert extrato.transacoes[2].valor == Decimal("12500.00")

    def test_colunas_debito_e_credito_separadas(self, tmp_path):
        caminho = escrever(tmp_path, "e.csv", "Data,Descrição,Débito,Crédito\n"
                           "01/09/2026,TARIFA,\"35,90\",\n02/09/2026,DEPOSITO,,\"100,00\"\n")
        extrato = ler_extrato_tabela(caminho)
        assert [t.valor for t in extrato.transacoes] == [Decimal("-35.90"), Decimal("100.00")]

    def test_mapeamento_manual(self, tmp_path):
        caminho = escrever(tmp_path, "e.csv", "Dt Mov;Texto;Vlr\n01/09/2026;PIX;-10,00\n")
        with pytest.raises(ErroLeitura, match="Colunas obrigatórias"):
            ler_extrato_tabela(caminho)
        extrato = ler_extrato_tabela(caminho, {"data": "Dt Mov", "historico": "Texto", "valor": "Vlr"})
        assert extrato.transacoes[0].valor == Decimal("-10.00")

    def test_mapeamento_para_coluna_inexistente(self, tmp_path):
        caminho = escrever(tmp_path, "e.csv", "Data;Historico;Valor\n01/09/2026;PIX;-10,00\n")
        with pytest.raises(ErroLeitura, match="não existe no arquivo"):
            ler_extrato_tabela(caminho, {"valor": "Valor R$"})

    def test_erros_listam_todas_as_linhas(self, tmp_path):
        caminho = escrever(tmp_path, "e.csv", "Data;Historico;Valor\n"
                           "01/09/2026;OK;-10,00\n32/09/2026;DATA RUIM;-1,00\n02/09/2026;VALOR RUIM;abc\n")
        with pytest.raises(ErroLeitura) as erro:
            ler_extrato_tabela(caminho)
        assert "linha 3" in str(erro.value) and "linha 4" in str(erro.value)

    def test_excel(self, tmp_path):
        planilha = Workbook()
        aba = planilha.active
        aba.append(["Data", "Histórico", "Valor"])
        aba.append([date(2026, 9, 1), "PIX ENVIADO", -1520.0])
        aba.append([date(2026, 9, 2), "TARIFA", -35.9])
        caminho = tmp_path / "extrato.xlsx"
        planilha.save(caminho)
        extrato = ler_extrato_tabela(caminho)
        assert [t.valor for t in extrato.transacoes] == [Decimal("-1520.00"), Decimal("-35.90")]
        assert extrato.transacoes[0].data == date(2026, 9, 1)

    def test_extensao_nao_suportada(self, tmp_path):
        with pytest.raises(ErroLeitura, match="não suportad"):
            ler_extrato_tabela(escrever(tmp_path, "e.xls", "x"))


class TestTitulos:
    def test_contas_a_pagar_csv(self, fixtures):
        titulos = ler_titulos(fixtures / "contas_pagar_ficticio.csv")
        assert len(titulos) == 6
        primeiro = titulos[0]
        assert primeiro.parceiro_nome == "DISTRIBUIDORA BOA VISTA LTDA"
        assert primeiro.parceiro_cnpj_cpf == "22333444000181"
        assert primeiro.numero_documento == "4521"
        assert primeiro.data_emissao == date(2026, 8, 20)
        assert primeiro.data_vencimento == date(2026, 9, 1)
        assert primeiro.data_pagamento == date(2026, 9, 1)
        assert primeiro.valor == Decimal("1520.00")
        assert primeiro.valor_pago == Decimal("1520.00")
        assert titulos[1].data_pagamento is None and titulos[1].valor_pago is None

    def test_excel_com_cnpj_e_documento_numericos(self, tmp_path):
        planilha = Workbook()
        aba = planilha.active
        aba.append(["Razão Social", "CNPJ/CPF", "Nota Fiscal", "Vencimento", "Valor Original"])
        # CNPJ começando com zero, gravado como número no Excel.
        aba.append(["FORNECEDOR X", 1234567000189, 4521, "10/09/2026", 99.9])
        caminho = tmp_path / "pagar.xlsx"
        planilha.save(caminho)
        titulo = ler_titulos(caminho)[0]
        assert titulo.parceiro_cnpj_cpf == "01234567000189"
        assert titulo.numero_documento == "4521"
        assert titulo.valor == Decimal("99.90")

    def test_valor_negativo_ou_campos_vazios(self, tmp_path):
        caminho = escrever(tmp_path, "p.csv", "Fornecedor;Documento;Vencimento;Valor\n"
                           "X;1;10/09/2026;-5,00\n;2;10/09/2026;5,00\n")
        with pytest.raises(ErroLeitura) as erro:
            ler_titulos(caminho)
        assert "linha 2" in str(erro.value) and "linha 3" in str(erro.value)

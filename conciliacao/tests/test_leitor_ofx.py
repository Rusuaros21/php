from datetime import date
from decimal import Decimal

import pytest

from importers.leitores.ofx import ler_ofx, parece_ofx
from importers.leitores.tipos import ErroLeitura


def test_le_ofx_sgml_cp1252(fixtures):
    conteudo = (fixtures / "extrato_ficticio.ofx").read_bytes()
    assert parece_ofx(conteudo)
    extrato = ler_ofx(conteudo)

    assert (extrato.banco_codigo, extrato.agencia, extrato.conta) == ("0748", "0101", "12345-6")
    assert len(extrato.transacoes) == 7

    pix = extrato.transacoes[0]
    assert pix.data == date(2026, 9, 1)
    assert pix.valor == Decimal("-1520.00")
    assert pix.fitid == "202609010001"
    assert pix.documento == "4521"
    assert pix.tipo == "DEBIT"
    assert "22.333.444/0001-81" in pix.historico

    tarifa = extrato.transacoes[1]
    assert tarifa.valor == Decimal("-35.90")  # valor com vírgula no OFX
    assert tarifa.historico == "TARIFA BANCÁRIA SICREDI"  # acento em cp1252

    assert extrato.transacoes[2].valor == Decimal("12500.00")
    # NAME + MEMO são combinados no histórico
    assert extrato.transacoes[6].historico == "HORTIFRUTI CAMPO BELO PIX ENVIADO"


def test_le_ofx_xml_com_tags_fechadas():
    conteudo = b"""<?xml version="1.0" encoding="UTF-8"?>
<?OFX OFXHEADER="200" VERSION="220"?>
<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS>
<BANKACCTFROM><BANKID>001</BANKID><BRANCHID>1234</BRANCHID><ACCTID>99999</ACCTID></BANKACCTFROM>
<BANKTRANLIST>
<STMTTRN><TRNTYPE>DEBIT</TRNTYPE><DTPOSTED>20260915</DTPOSTED><TRNAMT>-10.00</TRNAMT><FITID>A1</FITID><MEMO>PAG &amp; CIA</MEMO></STMTTRN>
<STMTTRN><TRNTYPE>CREDIT</TRNTYPE><DTPOSTED>20260916</DTPOSTED><TRNAMT>20.5</TRNAMT><FITID>A2</FITID><MEMO>DEP\xc3\x93SITO</MEMO></STMTTRN>
</BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>"""
    extrato = ler_ofx(conteudo)
    assert extrato.conta == "99999"
    assert [t.valor for t in extrato.transacoes] == [Decimal("-10.00"), Decimal("20.50")]
    assert extrato.transacoes[0].historico == "PAG & CIA"
    assert extrato.transacoes[1].historico == "DEPÓSITO"


def test_arquivo_que_nao_e_ofx():
    with pytest.raises(ErroLeitura, match="não parece ser um OFX"):
        ler_ofx(b"Data;Valor\n01/09/2026;10,00")


def test_transacao_invalida_informa_qual():
    conteudo = b"<OFX><STMTTRN><DTPOSTED>20261340<TRNAMT>-1.00<MEMO>X</STMTTRN></OFX>"
    with pytest.raises(ErroLeitura, match="transação 1"):
        ler_ofx(conteudo)

from decimal import Decimal

import pytest
from django.core.management import call_command
from django.core.management.base import CommandError

from empresas.models import ContaBancaria
from importers.models import Importacao, MovimentacaoBancaria, TituloFinanceiro
from importers.servicos import (
    ArquivoJaImportado,
    ErroImportacao,
    importar_extrato,
    importar_titulos,
    localizar_ou_criar_conta,
)

pytestmark = pytest.mark.django_db


def test_importa_ofx_e_cria_conta(empresa, fixtures):
    importacao = importar_extrato(empresa, fixtures / "extrato_ficticio.ofx")
    assert importacao.tipo == Importacao.Tipo.EXTRATO_OFX
    assert importacao.total_registros == 7
    conta = importacao.conta_bancaria
    assert (conta.banco_codigo, conta.agencia, conta.conta) == ("748", "0101", "12345-6")
    assert MovimentacaoBancaria.objects.filter(conta_bancaria=conta).count() == 7
    assert sum(m.valor for m in MovimentacaoBancaria.objects.all()) == Decimal("6317.75")


def test_mesmo_arquivo_nao_e_importado_duas_vezes(empresa, fixtures):
    importar_extrato(empresa, fixtures / "extrato_ficticio.ofx")
    with pytest.raises(ArquivoJaImportado):
        importar_extrato(empresa, fixtures / "extrato_ficticio.ofx")
    assert MovimentacaoBancaria.objects.count() == 7


def test_ofx_reaproveita_conta_existente_ignorando_zeros(empresa, fixtures):
    conta = ContaBancaria.objects.create(empresa=empresa, banco_codigo="748", agencia="101", conta="123456")
    importacao = importar_extrato(empresa, fixtures / "extrato_ficticio.ofx")
    assert importacao.conta_bancaria == conta
    assert ContaBancaria.objects.count() == 1


def test_ofx_de_outra_conta_e_rejeitado(empresa, fixtures):
    outra = ContaBancaria.objects.create(empresa=empresa, banco_codigo="748", agencia="0101", conta="99999-9")
    with pytest.raises(ErroImportacao, match="12345-6"):
        importar_extrato(empresa, fixtures / "extrato_ficticio.ofx", conta_bancaria=outra)


def test_csv_exige_conta(empresa, fixtures):
    with pytest.raises(ErroImportacao, match="conta bancária"):
        importar_extrato(empresa, fixtures / "extrato_ficticio.csv")
    conta = localizar_ou_criar_conta(empresa, "748", "0101", "12345-6")
    importacao = importar_extrato(empresa, fixtures / "extrato_ficticio.csv", conta_bancaria=conta)
    assert importacao.tipo == Importacao.Tipo.EXTRATO_TABELA
    assert importacao.total_registros == 6
    assert importacao.registros_ignorados == 2


def test_importa_contas_a_pagar(empresa, fixtures):
    importacao = importar_titulos(empresa, fixtures / "contas_pagar_ficticio.csv")
    assert importacao.tipo == Importacao.Tipo.TITULOS_PAGAR
    titulos = TituloFinanceiro.objects.filter(empresa=empresa)
    assert titulos.count() == 6
    assert set(titulos.values_list("tipo", flat=True)) == {"pagar"}
    assert sum(t.valor for t in titulos) == Decimal("6755.00")


def test_comandos(empresa, fixtures, capsys):
    call_command("importar_extrato", str(fixtures / "extrato_ficticio.ofx"), "--empresa", "101")
    call_command("importar_titulos", str(fixtures / "contas_pagar_ficticio.csv"), "--empresa", "101")
    saida = capsys.readouterr().out
    assert "7 movimentação(ões)" in saida and "6 título(s)" in saida
    with pytest.raises(CommandError, match="já foi importado"):
        call_command("importar_extrato", str(fixtures / "extrato_ficticio.ofx"), "--empresa", "101")
    with pytest.raises(CommandError, match="não encontrada"):
        call_command("importar_titulos", "x.csv", "--empresa", "999")


def test_comando_criar_empresa(db, capsys):
    call_command("criar_empresa", "--razao-social", "Mercado Teste", "--cnpj", "22.333.444/0001-81",
                 "--codigo-dominio", "202")
    assert "Empresa cadastrada" in capsys.readouterr().out
    with pytest.raises(CommandError, match="CNPJ"):
        call_command("criar_empresa", "--razao-social", "X", "--cnpj", "123", "--codigo-dominio", "203")

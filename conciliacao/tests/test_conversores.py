from datetime import date, datetime
from decimal import Decimal

import pytest

from importers.conversores import ErroConversao, converter_data, converter_valor


@pytest.mark.parametrize(
    "bruto, esperado",
    [
        ("1.234,56", "1234.56"),
        ("-1.234,56", "-1234.56"),
        ("1.234,56 D", "-1234.56"),
        ("1.234,56 C", "1234.56"),
        ("1.234,56-", "-1234.56"),
        ("(1.234,56)", "-1234.56"),
        ("R$ 1.234,56", "1234.56"),
        ("1234.56", "1234.56"),
        ("1,234.56", "1234.56"),
        ("35,9", "35.90"),
        ("1.234", "1234.00"),  # ponto de milhar (convenção brasileira)
        ("1.234.567,00", "1234567.00"),
        ("0,005", "0.01"),  # arredondamento meio para cima
        (Decimal("10.5"), "10.50"),
        (1520, "1520.00"),
        (1520.1, "1520.10"),
        (0.1 + 0.2, "0.30"),  # float do Excel não vaza imprecisão
    ],
)
def test_converter_valor(bruto, esperado):
    resultado = converter_valor(bruto)
    assert isinstance(resultado, Decimal)
    assert resultado == Decimal(esperado)


@pytest.mark.parametrize("bruto", ["", "abc", "1,2,3", None, True, float("nan"), "12a,00"])
def test_converter_valor_invalido(bruto):
    with pytest.raises(ErroConversao):
        converter_valor(bruto)


@pytest.mark.parametrize(
    "bruto",
    ["01/09/2026", "1/9/2026", "01/09/26", "2026-09-01", "01-09-2026", "01.09.2026", "20260901",
     "2026-09-01 00:00:00", "01/09/2026 10:15", date(2026, 9, 1), datetime(2026, 9, 1, 8, 30)],
)
def test_converter_data(bruto):
    assert converter_data(bruto) == date(2026, 9, 1)


@pytest.mark.parametrize("bruto", ["", None, "31/02/2026", "data"])
def test_converter_data_invalida(bruto):
    with pytest.raises(ErroConversao):
        converter_data(bruto)

"""Conversão de valores monetários e datas vindos dos arquivos de entrada.

- Valores sempre em Decimal com 2 casas (nunca float).
- Datas aceitas em dd/mm/aaaa (padrão brasileiro) e ISO; internamente usamos date.
"""

import math
import re
from datetime import date, datetime
from decimal import ROUND_HALF_UP, Decimal, InvalidOperation

CENTAVO = Decimal("0.01")

FORMATOS_DATA = ("%d/%m/%Y", "%d/%m/%y", "%Y-%m-%d", "%d-%m-%Y", "%d.%m.%Y", "%Y%m%d")


class ErroConversao(ValueError):
    pass


def converter_valor(bruto) -> Decimal:
    """Converte um valor monetário para Decimal com 2 casas.

    Aceita números (int/float/Decimal, como vêm do Excel) e textos nos formatos
    usados por bancos brasileiros: "1.234,56", "-1.234,56", "1.234,56 D",
    "1.234,56-", "(1.234,56)", "R$ 1.234,56" e "1234.56".

    Atenção: com um único ponto e exatamente 3 dígitos depois dele ("1.234"),
    o ponto é tratado como separador de milhar (convenção brasileira).
    """
    if bruto is None or isinstance(bruto, bool):
        raise ErroConversao(f"Valor inválido: {bruto!r}")
    if isinstance(bruto, Decimal):
        return bruto.quantize(CENTAVO, rounding=ROUND_HALF_UP)
    if isinstance(bruto, int):
        return Decimal(bruto).quantize(CENTAVO)
    if isinstance(bruto, float):
        if math.isnan(bruto) or math.isinf(bruto):
            raise ErroConversao(f"Valor inválido: {bruto!r}")
        # str(float) devolve a menor representação exata (ex.: 0.1 -> "0.1").
        return Decimal(str(bruto)).quantize(CENTAVO, rounding=ROUND_HALF_UP)

    texto = str(bruto).strip().upper().replace("R$", "").replace(" ", "")
    original = texto
    if not texto:
        raise ErroConversao("Valor vazio")

    negativo = False
    if texto[-1] in "DC" and len(texto) > 1:
        negativo = texto[-1] == "D"
        texto = texto[:-1]
    if texto.startswith("(") and texto.endswith(")"):
        negativo, texto = True, texto[1:-1]
    if texto.startswith("-"):
        negativo, texto = True, texto[1:]
    elif texto.startswith("+"):
        texto = texto[1:]
    if texto.endswith("-"):
        negativo, texto = True, texto[:-1]

    if not re.fullmatch(r"\d[\d.,]*", texto):
        raise ErroConversao(f"Valor inválido: {original!r}")

    if "," in texto and "." in texto:
        if texto.rfind(",") > texto.rfind("."):
            texto = texto.replace(".", "").replace(",", ".")
        else:
            texto = texto.replace(",", "")
    elif "," in texto:
        if texto.count(",") > 1:
            raise ErroConversao(f"Valor inválido: {original!r}")
        texto = texto.replace(",", ".")
    elif texto.count(".") > 1 or (
        "." in texto and len(texto.rsplit(".", 1)[1]) == 3
    ):
        texto = texto.replace(".", "")

    try:
        valor = Decimal(texto).quantize(CENTAVO, rounding=ROUND_HALF_UP)
    except InvalidOperation as erro:
        raise ErroConversao(f"Valor inválido: {original!r}") from erro
    return -valor if negativo else valor


def converter_data(bruta) -> date:
    """Converte para date. Aceita date/datetime (Excel) e textos dd/mm/aaaa ou ISO."""
    if isinstance(bruta, datetime):
        return bruta.date()
    if isinstance(bruta, date):
        return bruta
    texto = str(bruta or "").strip()
    if not texto:
        raise ErroConversao("Data vazia")
    # Descarta horário ("2026-09-01 00:00:00", "01/09/2026 10:15").
    texto = texto.split(" ")[0].split("T")[0]
    for formato in FORMATOS_DATA:
        try:
            return datetime.strptime(texto, formato).date()
        except ValueError:
            continue
    raise ErroConversao(f"Data inválida: {bruta!r}")


def vazio(bruto) -> bool:
    """True para None, NaN do pandas e textos em branco."""
    if bruto is None:
        return True
    if isinstance(bruto, float) and math.isnan(bruto):
        return True
    try:
        import pandas as pd

        if bruto is pd.NaT:
            return True
    except ImportError:  # pragma: no cover
        pass
    return isinstance(bruto, str) and not bruto.strip()

"""Leitor de OFX tolerante.

Bancos brasileiros costumam gerar OFX 1.x em SGML com problemas: tags sem
fechamento, encoding cp1252, valores com vírgula, datas com fuso. Por isso
lemos com expressões regulares em vez de um parser XML estrito.
"""

import html
import re
from decimal import Decimal, InvalidOperation

from ..conversores import ErroConversao, converter_data, converter_valor
from .tipos import ErroLeitura, ExtratoLido, TransacaoLida

_RE_TRANSACAO = re.compile(
    r"<STMTTRN>(.*?)(?=</STMTTRN>|<STMTTRN>|</BANKTRANLIST>|$)", re.S | re.I
)


def _decodificar(conteudo: bytes) -> str:
    cabecalho = conteudo[:500].decode("ascii", errors="ignore").upper()
    candidatos = ["utf-8", "cp1252"]
    if "CHARSET:1252" in cabecalho or "ENCODING:USASCII" in cabecalho:
        candidatos = ["cp1252", "utf-8"]
    for encoding in candidatos:
        try:
            return conteudo.decode(encoding)
        except UnicodeDecodeError:
            continue
    return conteudo.decode("cp1252", errors="replace")


def _tag(bloco: str, nome: str) -> str:
    achado = re.search(rf"<{nome}>([^<\r\n]*)", bloco, re.I)
    return html.unescape(achado.group(1)).strip() if achado else ""


def _valor_ofx(texto: str) -> Decimal:
    # No OFX o ponto é o separador decimal; alguns bancos usam vírgula.
    if "," in texto:
        return converter_valor(texto)
    try:
        return converter_valor(Decimal(texto.strip()))
    except InvalidOperation as erro:
        raise ErroConversao(f"Valor inválido: {texto!r}") from erro


def ler_ofx(conteudo: bytes) -> ExtratoLido:
    texto = _decodificar(conteudo)
    inicio = texto.upper().find("<OFX>")
    if inicio < 0:
        raise ErroLeitura("Arquivo não parece ser um OFX (tag <OFX> não encontrada).")
    corpo = texto[inicio:]

    conta_bloco = re.search(r"<BANKACCTFROM>(.*?)(?:</BANKACCTFROM>|<BANKTRANLIST>)", corpo, re.S | re.I)
    conta_texto = conta_bloco.group(1) if conta_bloco else corpo

    transacoes: list[TransacaoLida] = []
    erros: list[str] = []
    for indice, bloco in enumerate(_RE_TRANSACAO.findall(corpo), start=1):
        nome = _tag(bloco, "NAME")
        memo = _tag(bloco, "MEMO")
        if nome and memo and nome.upper() not in memo.upper():
            historico = f"{nome} {memo}"
        else:
            historico = memo or nome
        try:
            data_bruta = _tag(bloco, "DTPOSTED")[:8]
            transacoes.append(
                TransacaoLida(
                    data=converter_data(data_bruta),
                    valor=_valor_ofx(_tag(bloco, "TRNAMT")),
                    historico=historico,
                    documento=_tag(bloco, "CHECKNUM") or _tag(bloco, "REFNUM"),
                    fitid=_tag(bloco, "FITID"),
                    tipo=_tag(bloco, "TRNTYPE").upper(),
                    linha=indice,
                )
            )
        except ErroConversao as erro:
            erros.append(f"transação {indice}: {erro}")

    if erros:
        raise ErroLeitura("OFX com transações inválidas:", erros)

    return ExtratoLido(
        transacoes=transacoes,
        banco_codigo=_tag(conta_texto, "BANKID"),
        agencia=_tag(conta_texto, "BRANCHID"),
        conta=_tag(conta_texto, "ACCTID"),
    )


def parece_ofx(conteudo: bytes) -> bool:
    inicio = conteudo[:2000].decode("ascii", errors="ignore").upper()
    return "OFXHEADER" in inicio or "<OFX>" in inicio

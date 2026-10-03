"""Leitura de planilhas (CSV/Excel) com reconhecimento de colunas.

Como cada banco e cada sistema financeiro exporta com nomes de coluna
diferentes, as colunas são localizadas por sinônimos. Quando o arquivo usa
nomes fora da lista, o usuário informa um mapeamento manual
(campo interno -> nome da coluna no arquivo).
"""

import csv
import io
import re
import unicodedata
from pathlib import Path

import pandas as pd

from ..conversores import ErroConversao, converter_data, converter_valor, vazio
from .tipos import ErroLeitura, ExtratoLido, TituloLido, TransacaoLida

SINONIMOS_EXTRATO = {
    "data": ["data", "data lancamento", "data movimento", "data mov", "dt lancamento", "dt movimento"],
    "historico": ["historico", "descricao", "lancamento", "memo", "detalhe", "detalhamento"],
    "valor": ["valor", "valor r$", "valor (r$)", "montante", "valor lancamento"],
    "documento": ["documento", "doc", "n documento", "numero documento", "nr documento", "num documento"],
    "fitid": ["fitid", "id transacao", "identificador"],
    "natureza": ["natureza", "tipo", "d/c", "dc", "debito/credito", "c/d"],
    "debito": ["debito", "saida", "saidas", "valor debito"],
    "credito": ["credito", "entrada", "entradas", "valor credito"],
}

SINONIMOS_TITULOS = {
    "parceiro_nome": ["fornecedor", "razao social", "nome", "parceiro", "cliente", "favorecido", "nome fornecedor"],
    "parceiro_cnpj_cpf": ["cnpj", "cpf", "cnpj/cpf", "cpf/cnpj", "cnpj cpf", "cnpj fornecedor", "documento fornecedor"],
    "numero_documento": ["documento", "numero documento", "n documento", "nr documento", "nota", "nf",
                         "numero nota", "nota fiscal", "titulo", "num titulo", "numero titulo", "duplicata"],
    "data_emissao": ["emissao", "data emissao", "dt emissao"],
    "data_vencimento": ["vencimento", "data vencimento", "dt vencimento", "vcto"],
    "data_pagamento": ["pagamento", "data pagamento", "dt pagamento", "baixa", "data baixa"],
    "valor": ["valor", "valor original", "valor titulo", "valor documento", "valor r$"],
    "valor_pago": ["valor pago", "valor baixa", "valor liquido pago"],
    "historico": ["historico", "observacao", "observacoes", "descricao"],
}

EXTENSOES_EXCEL = {".xlsx", ".xlsm"}
EXTENSOES_TEXTO = {".csv", ".txt"}


def normalizar_cabecalho(texto) -> str:
    texto = unicodedata.normalize("NFKD", str(texto)).encode("ascii", "ignore").decode()
    texto = re.sub(r"[^a-z0-9]+", " ", texto.lower())
    return texto.strip()


def ler_tabela(caminho: Path, separador: str | None = None) -> list[dict]:
    """Lê CSV/Excel e devolve uma lista de linhas {cabecalho_normalizado: valor}.
    Cada linha recebe a chave "_linha" com o número da linha no arquivo."""
    caminho = Path(caminho)
    extensao = caminho.suffix.lower()
    if extensao in EXTENSOES_EXCEL:
        df = pd.read_excel(caminho, dtype=object)
    elif extensao in EXTENSOES_TEXTO:
        df = _ler_csv(caminho.read_bytes(), separador)
    elif extensao == ".xls":
        raise ErroLeitura("Formato .xls (Excel antigo) não suportado; salve como .xlsx ou .csv.")
    else:
        raise ErroLeitura(f"Extensão não suportada: {extensao or '(sem extensão)'}")

    cabecalhos = [normalizar_cabecalho(c) for c in df.columns]
    if len(set(cabecalhos)) != len(cabecalhos):
        raise ErroLeitura(f"Colunas com nomes repetidos: {list(df.columns)}")

    linhas = []
    for posicao, registro in enumerate(df.itertuples(index=False), start=2):
        valores = list(registro)
        if all(vazio(v) for v in valores):
            continue
        linha = dict(zip(cabecalhos, valores))
        linha["_linha"] = posicao
        linhas.append(linha)
    return linhas


def _ler_csv(conteudo: bytes, separador: str | None) -> pd.DataFrame:
    for encoding in ("utf-8-sig", "cp1252"):
        try:
            texto = conteudo.decode(encoding)
            break
        except UnicodeDecodeError:
            continue
    if separador is None:
        try:
            separador = csv.Sniffer().sniff(texto[:5000], delimiters=";,\t|").delimiter
        except csv.Error:
            separador = ";"
    return pd.read_csv(
        io.StringIO(texto), sep=separador, dtype=str, keep_default_na=False, skipinitialspace=True
    )


def resolver_colunas(
    cabecalhos: list[str],
    sinonimos: dict[str, list[str]],
    mapeamento: dict[str, str] | None = None,
) -> dict[str, str]:
    """Devolve {campo_interno: cabecalho_no_arquivo}. O mapeamento manual tem prioridade."""
    disponiveis = set(cabecalhos)
    resolvido: dict[str, str] = {}
    for campo, coluna in (mapeamento or {}).items():
        if campo not in sinonimos:
            raise ErroLeitura(f"Campo desconhecido no mapeamento: {campo!r}. Válidos: {sorted(sinonimos)}")
        coluna_norm = normalizar_cabecalho(coluna)
        if coluna_norm not in disponiveis:
            raise ErroLeitura(f"Coluna {coluna!r} (mapeada para {campo!r}) não existe no arquivo.")
        resolvido[campo] = coluna_norm
    usados = set(resolvido.values())
    for campo, nomes in sinonimos.items():
        if campo in resolvido:
            continue
        for nome in nomes:
            nome_norm = normalizar_cabecalho(nome)
            if nome_norm in disponiveis and nome_norm not in usados:
                resolvido[campo] = nome_norm
                usados.add(nome_norm)
                break
    return resolvido


def _exigir(colunas: dict, obrigatorios: list[str], cabecalhos: list[str]) -> None:
    faltando = [c for c in obrigatorios if c not in colunas]
    if faltando:
        raise ErroLeitura(
            f"Colunas obrigatórias não encontradas: {faltando}. "
            f"Colunas do arquivo: {cabecalhos}. Use o mapeamento manual (--mapa)."
        )


def _texto(linha: dict, colunas: dict, campo: str) -> str:
    if campo not in colunas or vazio(linha.get(colunas[campo])):
        return ""
    valor = linha[colunas[campo]]
    if isinstance(valor, float) and valor.is_integer():
        valor = int(valor)  # nº de documento lido do Excel como 1234.0
    return str(valor).strip()


def _cabecalhos(linhas: list[dict]) -> list[str]:
    return [c for c in linhas[0] if c != "_linha"] if linhas else []


def ler_extrato_tabela(
    caminho: Path, mapeamento: dict[str, str] | None = None, separador: str | None = None
) -> ExtratoLido:
    linhas = ler_tabela(caminho, separador)
    if not linhas:
        raise ErroLeitura("Arquivo de extrato sem linhas.")
    cabecalhos = _cabecalhos(linhas)
    colunas = resolver_colunas(cabecalhos, SINONIMOS_EXTRATO, mapeamento)
    _exigir(colunas, ["data", "historico"], cabecalhos)
    if "valor" not in colunas and not ("debito" in colunas or "credito" in colunas):
        raise ErroLeitura(
            f"Coluna de valor não encontrada (valor, ou débito/crédito). Colunas do arquivo: {cabecalhos}."
        )

    transacoes, erros, ignoradas = [], [], 0
    for linha in linhas:
        numero = linha["_linha"]
        historico = _texto(linha, colunas, "historico")
        if normalizar_cabecalho(historico).startswith("saldo"):
            ignoradas += 1  # linhas de saldo não são movimentações
            continue
        try:
            valor = _valor_extrato(linha, colunas)
            if valor is None:
                ignoradas += 1
                continue
            transacoes.append(
                TransacaoLida(
                    data=converter_data(linha[colunas["data"]]),
                    valor=valor,
                    historico=historico,
                    documento=_texto(linha, colunas, "documento"),
                    fitid=_texto(linha, colunas, "fitid"),
                    linha=numero,
                )
            )
        except ErroConversao as erro:
            erros.append(f"linha {numero}: {erro}")

    if erros:
        raise ErroLeitura("Extrato com linhas inválidas:", erros)
    return ExtratoLido(transacoes=transacoes, ignoradas=ignoradas)


def _valor_extrato(linha: dict, colunas: dict):
    """Valor com sinal (negativo = débito). None se a linha não tiver valor."""
    if "valor" in colunas:
        bruto = linha.get(colunas["valor"])
        if vazio(bruto):
            return None
        valor = converter_valor(bruto)
        natureza = _texto(linha, colunas, "natureza").upper()
        if natureza.startswith("D"):
            return -abs(valor)
        if natureza.startswith("C"):
            return abs(valor)
        return valor
    debito = linha.get(colunas["debito"]) if "debito" in colunas else None
    credito = linha.get(colunas["credito"]) if "credito" in colunas else None
    if vazio(debito) and vazio(credito):
        return None
    total = abs(converter_valor(credito)) if not vazio(credito) else 0
    if not vazio(debito):
        total -= abs(converter_valor(debito))
    return converter_valor(total)


def ler_titulos(
    caminho: Path, mapeamento: dict[str, str] | None = None, separador: str | None = None
) -> list[TituloLido]:
    linhas = ler_tabela(caminho, separador)
    if not linhas:
        raise ErroLeitura("Arquivo de títulos sem linhas.")
    cabecalhos = _cabecalhos(linhas)
    colunas = resolver_colunas(cabecalhos, SINONIMOS_TITULOS, mapeamento)
    _exigir(colunas, ["parceiro_nome", "numero_documento", "data_vencimento", "valor"], cabecalhos)

    def data_opcional(linha, campo):
        if campo not in colunas or vazio(linha.get(colunas[campo])):
            return None
        return converter_data(linha[colunas[campo]])

    titulos, erros = [], []
    for linha in linhas:
        numero = linha["_linha"]
        try:
            valor = converter_valor(linha.get(colunas["valor"]))
            if valor <= 0:
                raise ErroConversao(f"valor do título deve ser positivo ({valor})")
            valor_pago = None
            if "valor_pago" in colunas and not vazio(linha.get(colunas["valor_pago"])):
                valor_pago = abs(converter_valor(linha[colunas["valor_pago"]]))
            nome = _texto(linha, colunas, "parceiro_nome")
            documento = _texto(linha, colunas, "numero_documento")
            if not nome or not documento:
                raise ErroConversao("fornecedor/cliente e nº do documento são obrigatórios")
            titulos.append(
                TituloLido(
                    parceiro_nome=nome,
                    parceiro_cnpj_cpf=_cnpj_cpf(_texto(linha, colunas, "parceiro_cnpj_cpf")),
                    numero_documento=documento,
                    data_emissao=data_opcional(linha, "data_emissao"),
                    data_vencimento=converter_data(linha.get(colunas["data_vencimento"])),
                    data_pagamento=data_opcional(linha, "data_pagamento"),
                    valor=valor,
                    valor_pago=valor_pago,
                    historico=_texto(linha, colunas, "historico"),
                    linha=numero,
                )
            )
        except ErroConversao as erro:
            erros.append(f"linha {numero}: {erro}")

    if erros:
        raise ErroLeitura("Arquivo de títulos com linhas inválidas:", erros)
    return titulos


def _cnpj_cpf(texto: str) -> str:
    """Somente dígitos, recompondo zeros à esquerda perdidos quando o Excel
    guarda o CNPJ/CPF como número (01234567000189 -> 1234567000189)."""
    digitos = re.sub(r"\D", "", texto)
    if not digitos:
        return ""
    if len(digitos) < 11:
        return digitos.zfill(11)
    if 11 < len(digitos) < 14:
        return digitos.zfill(14)
    return digitos

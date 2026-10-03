"""Gravação dos arquivos lidos no banco de dados."""

import hashlib
from pathlib import Path

from django.db import transaction

from empresas.models import ContaBancaria, Empresa
from empresas.validadores import somente_digitos

from .leitores.ofx import ler_ofx, parece_ofx
from .leitores.tabela import ler_extrato_tabela, ler_titulos
from .leitores.tipos import ErroLeitura
from .models import Importacao, MovimentacaoBancaria, TituloFinanceiro


class ErroImportacao(Exception):
    pass


class ArquivoJaImportado(ErroImportacao):
    pass


def _hash(conteudo: bytes) -> str:
    return hashlib.sha256(conteudo).hexdigest()


def _verificar_reimportacao(empresa: Empresa, hash_arquivo: str) -> None:
    anterior = Importacao.objects.filter(empresa=empresa, hash_sha256=hash_arquivo).first()
    if anterior:
        raise ArquivoJaImportado(
            f"Este arquivo já foi importado em {anterior.criada_em:%d/%m/%Y %H:%M} "
            f"(importação #{anterior.pk}, {anterior.nome_arquivo})."
        )


def _chave(texto: str) -> str:
    """Compara códigos de banco/agência/conta ignorando pontuação e zeros à esquerda."""
    return somente_digitos(texto).lstrip("0")


def localizar_ou_criar_conta(empresa: Empresa, banco: str, agencia: str, conta: str) -> ContaBancaria:
    if not (banco and conta):
        raise ErroImportacao("Informe banco e conta para localizar a conta bancária.")
    for existente in ContaBancaria.objects.filter(empresa=empresa):
        if (
            _chave(existente.banco_codigo) == _chave(banco)
            and _chave(existente.agencia) == _chave(agencia)
            and _chave(existente.conta) == _chave(conta)
        ):
            return existente
    return ContaBancaria.objects.create(
        empresa=empresa, banco_codigo=somente_digitos(banco).lstrip("0").zfill(3),
        agencia=agencia, conta=conta,
    )


def importar_extrato(
    empresa: Empresa,
    caminho: Path,
    *,
    conta_bancaria: ContaBancaria | None = None,
    usuario=None,
    mapeamento: dict[str, str] | None = None,
    separador: str | None = None,
) -> Importacao:
    """Importa um extrato OFX ou CSV/Excel.

    No OFX a conta vem do próprio arquivo; se `conta_bancaria` for informada,
    ela precisa coincidir. No CSV/Excel a conta bancária é obrigatória.
    """
    caminho = Path(caminho)
    conteudo = caminho.read_bytes()
    hash_arquivo = _hash(conteudo)
    _verificar_reimportacao(empresa, hash_arquivo)

    if caminho.suffix.lower() == ".ofx" or parece_ofx(conteudo):
        extrato = ler_ofx(conteudo)
        tipo = Importacao.Tipo.EXTRATO_OFX
        if extrato.conta:
            if conta_bancaria is None:
                conta_bancaria = localizar_ou_criar_conta(
                    empresa, extrato.banco_codigo, extrato.agencia, extrato.conta
                )
            elif _chave(conta_bancaria.conta) != _chave(extrato.conta):
                raise ErroImportacao(
                    f"O OFX é da conta {extrato.conta}, mas foi informada a conta {conta_bancaria.conta}."
                )
    else:
        extrato = ler_extrato_tabela(caminho, mapeamento, separador)
        tipo = Importacao.Tipo.EXTRATO_TABELA

    if conta_bancaria is None:
        raise ErroImportacao("Informe a conta bancária do extrato.")
    if conta_bancaria.empresa_id != empresa.pk:
        raise ErroImportacao("A conta bancária informada não pertence a esta empresa.")
    if not extrato.transacoes:
        raise ErroLeitura("O extrato não tem movimentações.")

    with transaction.atomic():
        importacao = Importacao.objects.create(
            empresa=empresa, conta_bancaria=conta_bancaria, tipo=tipo, nome_arquivo=caminho.name,
            hash_sha256=hash_arquivo, total_registros=len(extrato.transacoes),
            registros_ignorados=extrato.ignoradas, usuario=usuario,
        )
        MovimentacaoBancaria.objects.bulk_create(
            MovimentacaoBancaria(
                empresa=empresa, conta_bancaria=conta_bancaria, importacao=importacao,
                data=t.data, valor=t.valor, historico=t.historico[:500], documento=t.documento[:60],
                fitid=t.fitid[:255], tipo_transacao=t.tipo[:20], linha_origem=t.linha,
            )
            for t in extrato.transacoes
        )
    return importacao


def importar_titulos(
    empresa: Empresa,
    caminho: Path,
    *,
    tipo: str = TituloFinanceiro.Tipo.PAGAR,
    usuario=None,
    mapeamento: dict[str, str] | None = None,
    separador: str | None = None,
) -> Importacao:
    """Importa contas a pagar ou a receber de um CSV/Excel."""
    caminho = Path(caminho)
    conteudo = caminho.read_bytes()
    hash_arquivo = _hash(conteudo)
    _verificar_reimportacao(empresa, hash_arquivo)

    titulos = ler_titulos(caminho, mapeamento, separador)
    tipo_importacao = (
        Importacao.Tipo.TITULOS_PAGAR if tipo == TituloFinanceiro.Tipo.PAGAR else Importacao.Tipo.TITULOS_RECEBER
    )
    with transaction.atomic():
        importacao = Importacao.objects.create(
            empresa=empresa, tipo=tipo_importacao, nome_arquivo=caminho.name,
            hash_sha256=hash_arquivo, total_registros=len(titulos), usuario=usuario,
        )
        TituloFinanceiro.objects.bulk_create(
            TituloFinanceiro(
                empresa=empresa, importacao=importacao, tipo=tipo,
                parceiro_nome=t.parceiro_nome[:200], parceiro_cnpj_cpf=t.parceiro_cnpj_cpf[:14],
                numero_documento=t.numero_documento[:60], data_emissao=t.data_emissao,
                data_vencimento=t.data_vencimento, data_pagamento=t.data_pagamento,
                valor=t.valor, valor_pago=t.valor_pago, historico=t.historico[:500], linha_origem=t.linha,
            )
            for t in titulos
        )
    return importacao

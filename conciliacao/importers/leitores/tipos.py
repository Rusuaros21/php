from dataclasses import dataclass, field
from datetime import date
from decimal import Decimal


class ErroLeitura(Exception):
    """Arquivo ilegível ou com linhas inválidas. `erros` lista os problemas por linha."""

    def __init__(self, mensagem: str, erros: list[str] | None = None):
        self.erros = erros or []
        if self.erros:
            mensagem = mensagem + "\n" + "\n".join(f"  - {e}" for e in self.erros[:20])
            if len(self.erros) > 20:
                mensagem += f"\n  ... e mais {len(self.erros) - 20} erro(s)"
        super().__init__(mensagem)


@dataclass
class TransacaoLida:
    data: date
    valor: Decimal  # negativo = saída (débito), positivo = entrada (crédito)
    historico: str
    documento: str = ""
    fitid: str = ""
    tipo: str = ""
    linha: int | None = None


@dataclass
class ExtratoLido:
    transacoes: list[TransacaoLida]
    banco_codigo: str = ""
    agencia: str = ""
    conta: str = ""
    ignoradas: int = 0
    avisos: list[str] = field(default_factory=list)


@dataclass
class TituloLido:
    parceiro_nome: str
    numero_documento: str
    data_vencimento: date
    valor: Decimal
    parceiro_cnpj_cpf: str = ""
    data_emissao: date | None = None
    data_pagamento: date | None = None
    valor_pago: Decimal | None = None
    historico: str = ""
    linha: int | None = None

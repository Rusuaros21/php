from django.conf import settings
from django.db import models

from empresas.models import ContaBancaria, Empresa


class Importacao(models.Model):
    """Um arquivo importado. O hash impede importar o mesmo arquivo duas vezes."""

    class Tipo(models.TextChoices):
        EXTRATO_OFX = "extrato_ofx", "Extrato OFX"
        EXTRATO_TABELA = "extrato_tabela", "Extrato CSV/Excel"
        TITULOS_PAGAR = "titulos_pagar", "Contas a pagar"
        TITULOS_RECEBER = "titulos_receber", "Contas a receber"

    empresa = models.ForeignKey(Empresa, verbose_name="empresa", on_delete=models.PROTECT, related_name="importacoes")
    conta_bancaria = models.ForeignKey(
        ContaBancaria, verbose_name="conta bancária", on_delete=models.PROTECT,
        null=True, blank=True, related_name="importacoes",
    )
    tipo = models.CharField("tipo", max_length=20, choices=Tipo.choices)
    nome_arquivo = models.CharField("nome do arquivo", max_length=255)
    hash_sha256 = models.CharField("hash SHA-256", max_length=64)
    total_registros = models.PositiveIntegerField("registros importados", default=0)
    registros_ignorados = models.PositiveIntegerField("registros ignorados", default=0)
    usuario = models.ForeignKey(
        settings.AUTH_USER_MODEL, verbose_name="usuário", on_delete=models.PROTECT, null=True, blank=True,
    )
    criada_em = models.DateTimeField("importado em", auto_now_add=True)

    class Meta:
        verbose_name = "importação"
        verbose_name_plural = "importações"
        ordering = ["-criada_em"]
        constraints = [
            models.UniqueConstraint(fields=["empresa", "hash_sha256"], name="arquivo_importado_uma_vez_por_empresa")
        ]

    def __str__(self) -> str:
        return f"{self.get_tipo_display()} - {self.nome_arquivo}"


class MovimentacaoBancaria(models.Model):
    empresa = models.ForeignKey(Empresa, verbose_name="empresa", on_delete=models.PROTECT, related_name="movimentacoes")
    conta_bancaria = models.ForeignKey(
        ContaBancaria, verbose_name="conta bancária", on_delete=models.PROTECT, related_name="movimentacoes"
    )
    importacao = models.ForeignKey(
        Importacao, verbose_name="importação", on_delete=models.CASCADE, related_name="movimentacoes"
    )
    data = models.DateField("data")
    valor = models.DecimalField(
        "valor", max_digits=14, decimal_places=2, help_text="Negativo = saída (débito); positivo = entrada (crédito)."
    )
    historico = models.CharField("histórico", max_length=500)
    documento = models.CharField("documento", max_length=60, blank=True)
    fitid = models.CharField("FITID", max_length=255, blank=True, help_text="Identificador da transação no OFX.")
    tipo_transacao = models.CharField("tipo de transação", max_length=20, blank=True)
    linha_origem = models.PositiveIntegerField("linha/transação no arquivo", null=True, blank=True)

    class Meta:
        verbose_name = "movimentação bancária"
        verbose_name_plural = "movimentações bancárias"
        ordering = ["data", "id"]
        indexes = [
            models.Index(fields=["conta_bancaria", "data"]),
            models.Index(fields=["conta_bancaria", "fitid"]),
        ]

    def __str__(self) -> str:
        return f"{self.data:%d/%m/%Y} {self.valor} {self.historico[:40]}"


class TituloFinanceiro(models.Model):
    """Título do contas a pagar ou a receber."""

    class Tipo(models.TextChoices):
        PAGAR = "pagar", "A pagar"
        RECEBER = "receber", "A receber"

    empresa = models.ForeignKey(Empresa, verbose_name="empresa", on_delete=models.PROTECT, related_name="titulos")
    importacao = models.ForeignKey(Importacao, verbose_name="importação", on_delete=models.CASCADE, related_name="titulos")
    tipo = models.CharField("tipo", max_length=10, choices=Tipo.choices)
    parceiro_nome = models.CharField("fornecedor/cliente", max_length=200)
    parceiro_cnpj_cpf = models.CharField("CNPJ/CPF", max_length=14, blank=True)
    numero_documento = models.CharField("nº do documento", max_length=60)
    data_emissao = models.DateField("emissão", null=True, blank=True)
    data_vencimento = models.DateField("vencimento")
    data_pagamento = models.DateField("pagamento", null=True, blank=True)
    valor = models.DecimalField("valor", max_digits=14, decimal_places=2)
    valor_pago = models.DecimalField("valor pago", max_digits=14, decimal_places=2, null=True, blank=True)
    historico = models.CharField("histórico", max_length=500, blank=True)
    linha_origem = models.PositiveIntegerField("linha no arquivo", null=True, blank=True)

    class Meta:
        verbose_name = "título financeiro"
        verbose_name_plural = "títulos financeiros"
        ordering = ["data_vencimento", "id"]
        indexes = [
            models.Index(fields=["empresa", "tipo", "data_vencimento"]),
            models.Index(fields=["empresa", "parceiro_cnpj_cpf"]),
        ]

    def __str__(self) -> str:
        return f"{self.numero_documento} - {self.parceiro_nome} - {self.valor}"

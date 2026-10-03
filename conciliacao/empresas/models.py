from decimal import Decimal

from django.core.validators import MaxValueValidator, MinValueValidator
from django.db import models

from .validadores import somente_digitos, validar_cnpj


class Empresa(models.Model):
    """Empresa cliente do escritório. Cada empresa tem código no Domínio,
    plano de contas e regras próprias."""

    razao_social = models.CharField("razão social", max_length=200)
    nome_fantasia = models.CharField("nome fantasia", max_length=200, blank=True)
    cnpj = models.CharField("CNPJ", max_length=14, unique=True, validators=[validar_cnpj])
    codigo_dominio = models.CharField(
        "código no Domínio", max_length=20, unique=True,
        help_text="Código da empresa no Domínio Contabilidade.",
    )
    ativa = models.BooleanField("ativa", default=True)
    criada_em = models.DateTimeField("criada em", auto_now_add=True)
    atualizada_em = models.DateTimeField("atualizada em", auto_now=True)

    class Meta:
        verbose_name = "empresa"
        verbose_name_plural = "empresas"
        ordering = ["razao_social"]

    def __str__(self) -> str:
        return f"{self.codigo_dominio} - {self.nome_fantasia or self.razao_social}"

    def save(self, *args, **kwargs):
        self.cnpj = somente_digitos(self.cnpj)
        super().save(*args, **kwargs)


class ContaBancaria(models.Model):
    empresa = models.ForeignKey(
        Empresa, verbose_name="empresa", on_delete=models.PROTECT,
        related_name="contas_bancarias",
    )
    banco_codigo = models.CharField("código do banco", max_length=10)
    banco_nome = models.CharField("nome do banco", max_length=100, blank=True)
    agencia = models.CharField("agência", max_length=20)
    conta = models.CharField("conta", max_length=30)
    descricao = models.CharField("descrição", max_length=100, blank=True)
    conta_contabil_dominio = models.CharField(
        "conta contábil no Domínio", max_length=30, blank=True,
        help_text="Conta do plano de contas do Domínio que representa este banco.",
    )
    ativa = models.BooleanField("ativa", default=True)

    class Meta:
        verbose_name = "conta bancária"
        verbose_name_plural = "contas bancárias"
        constraints = [
            models.UniqueConstraint(
                fields=["empresa", "banco_codigo", "agencia", "conta"],
                name="conta_bancaria_unica_por_empresa",
            )
        ]

    def __str__(self) -> str:
        nome = self.banco_nome or self.banco_codigo
        return f"{nome} ag {self.agencia} cc {self.conta}"


class ParametrosConciliacao(models.Model):
    """Limites do motor de conciliação, configuráveis por empresa."""

    empresa = models.OneToOneField(
        Empresa, verbose_name="empresa", on_delete=models.CASCADE,
        related_name="parametros",
    )
    janela_dias = models.PositiveSmallIntegerField(
        "janela de datas (dias)", default=3,
        help_text="Diferença máxima de dias entre o banco e o título no match por valor + data.",
    )
    janela_dias_agrupamento = models.PositiveSmallIntegerField(
        "janela para agrupamentos (dias)", default=30,
        help_text="Janela usada nos matches N:1 (lote) e 1:N (parcelas).",
    )
    tolerancia_valor = models.DecimalField(
        "tolerância de valor (R$)", max_digits=12, decimal_places=2,
        default=Decimal("10.00"),
        help_text="Diferença máxima em reais (juros, multa, desconto, arredondamento).",
    )
    tolerancia_percentual = models.DecimalField(
        "tolerância percentual", max_digits=5, decimal_places=4,
        default=Decimal("0.0500"),
        validators=[MinValueValidator(Decimal("0")), MaxValueValidator(Decimal("1"))],
        help_text="Diferença máxima relativa ao valor do título (0,05 = 5%).",
    )
    score_conciliado = models.PositiveSmallIntegerField(
        "score mínimo para conciliação automática", default=80,
        validators=[MaxValueValidator(100)],
    )
    score_possivel = models.PositiveSmallIntegerField(
        "score mínimo para possível correspondência", default=50,
        validators=[MaxValueValidator(100)],
    )

    class Meta:
        verbose_name = "parâmetros de conciliação"
        verbose_name_plural = "parâmetros de conciliação"

    def __str__(self) -> str:
        return f"Parâmetros de {self.empresa}"

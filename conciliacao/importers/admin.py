from django.contrib import admin

from .models import Importacao, MovimentacaoBancaria, TituloFinanceiro


@admin.register(Importacao)
class ImportacaoAdmin(admin.ModelAdmin):
    list_display = ["criada_em", "empresa", "tipo", "nome_arquivo", "total_registros", "registros_ignorados", "usuario"]
    list_filter = ["tipo", "empresa"]
    readonly_fields = ["hash_sha256", "criada_em"]


@admin.register(MovimentacaoBancaria)
class MovimentacaoBancariaAdmin(admin.ModelAdmin):
    list_display = ["data", "valor", "historico", "documento", "fitid", "conta_bancaria"]
    list_filter = ["empresa", "conta_bancaria"]
    search_fields = ["historico", "documento", "fitid"]
    date_hierarchy = "data"


@admin.register(TituloFinanceiro)
class TituloFinanceiroAdmin(admin.ModelAdmin):
    list_display = ["numero_documento", "parceiro_nome", "parceiro_cnpj_cpf", "data_vencimento", "valor", "tipo"]
    list_filter = ["empresa", "tipo"]
    search_fields = ["numero_documento", "parceiro_nome", "parceiro_cnpj_cpf"]
    date_hierarchy = "data_vencimento"

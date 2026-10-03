from django.contrib import admin

from .models import ContaBancaria, Empresa, ParametrosConciliacao


class ContaBancariaInline(admin.TabularInline):
    model = ContaBancaria
    extra = 0


class ParametrosInline(admin.StackedInline):
    model = ParametrosConciliacao
    can_delete = False


@admin.register(Empresa)
class EmpresaAdmin(admin.ModelAdmin):
    list_display = ["codigo_dominio", "razao_social", "cnpj", "ativa"]
    list_filter = ["ativa"]
    search_fields = ["razao_social", "nome_fantasia", "cnpj", "codigo_dominio"]
    inlines = [ParametrosInline, ContaBancariaInline]


@admin.register(ContaBancaria)
class ContaBancariaAdmin(admin.ModelAdmin):
    list_display = ["empresa", "banco_codigo", "banco_nome", "agencia", "conta", "ativa"]
    list_filter = ["banco_codigo", "ativa"]

"""Funções compartilhadas pelos comandos de importação."""

from django.core.management.base import CommandError

from empresas.models import Empresa


def obter_empresa(codigo_dominio: str) -> Empresa:
    try:
        return Empresa.objects.get(codigo_dominio=codigo_dominio)
    except Empresa.DoesNotExist:
        raise CommandError(f"Empresa com código no Domínio {codigo_dominio!r} não encontrada.")


def interpretar_mapa(texto: str | None) -> dict[str, str] | None:
    """Converte "data=Dt Mov,valor=Vlr Lanc" em {"data": "Dt Mov", "valor": "Vlr Lanc"}."""
    if not texto:
        return None
    mapa = {}
    for par in texto.split(","):
        if "=" not in par:
            raise CommandError(f"Mapeamento inválido: {par!r}. Use campo=Coluna, separados por vírgula.")
        campo, coluna = par.split("=", 1)
        mapa[campo.strip()] = coluna.strip()
    return mapa

from django.core.exceptions import ValidationError
from django.core.management.base import BaseCommand, CommandError

from empresas.models import Empresa


class Command(BaseCommand):
    help = "Cadastra uma empresa cliente."

    def add_arguments(self, parser):
        parser.add_argument("--razao-social", required=True)
        parser.add_argument("--cnpj", required=True)
        parser.add_argument("--codigo-dominio", required=True, help="Código da empresa no Domínio.")
        parser.add_argument("--nome-fantasia", default="")

    def handle(self, *args, **opcoes):
        empresa = Empresa(
            razao_social=opcoes["razao_social"], cnpj=opcoes["cnpj"],
            codigo_dominio=opcoes["codigo_dominio"], nome_fantasia=opcoes["nome_fantasia"],
        )
        empresa.cnpj = "".join(c for c in empresa.cnpj if c.isdigit())
        try:
            empresa.full_clean()
        except ValidationError as erro:
            raise CommandError("; ".join(f"{k}: {' '.join(v)}" for k, v in erro.message_dict.items()))
        empresa.save()
        self.stdout.write(self.style.SUCCESS(f"Empresa cadastrada: {empresa} (id {empresa.pk})"))

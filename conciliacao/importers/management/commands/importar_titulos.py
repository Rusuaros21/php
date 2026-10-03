from django.core.management.base import BaseCommand, CommandError

from importers.leitores.tipos import ErroLeitura
from importers.management.comum import interpretar_mapa, obter_empresa
from importers.models import TituloFinanceiro
from importers.servicos import ErroImportacao, importar_titulos


class Command(BaseCommand):
    help = "Importa contas a pagar ou a receber (CSV ou Excel)."

    def add_arguments(self, parser):
        parser.add_argument("arquivo")
        parser.add_argument("--empresa", required=True, help="Código da empresa no Domínio.")
        parser.add_argument("--tipo", choices=TituloFinanceiro.Tipo.values, default=TituloFinanceiro.Tipo.PAGAR)
        parser.add_argument("--mapa", help='Mapeamento de colunas: "parceiro_nome=Razão,valor=Vlr Original".')
        parser.add_argument("--separador", help="Separador do CSV (detectado automaticamente se omitido).")

    def handle(self, *args, **opcoes):
        empresa = obter_empresa(opcoes["empresa"])
        try:
            importacao = importar_titulos(
                empresa, opcoes["arquivo"], tipo=opcoes["tipo"],
                mapeamento=interpretar_mapa(opcoes["mapa"]), separador=opcoes["separador"],
            )
        except (ErroImportacao, ErroLeitura, FileNotFoundError) as erro:
            raise CommandError(str(erro))
        self.stdout.write(self.style.SUCCESS(
            f"Importação #{importacao.pk}: {importacao.total_registros} título(s) {importacao.get_tipo_display().lower()}."
        ))

from django.core.management.base import BaseCommand, CommandError

from importers.leitores.tipos import ErroLeitura
from importers.management.comum import interpretar_mapa, obter_empresa
from importers.servicos import ErroImportacao, importar_extrato, localizar_ou_criar_conta


class Command(BaseCommand):
    help = (
        "Importa um extrato bancário (OFX, CSV ou Excel). No OFX a conta vem do arquivo; "
        "no CSV/Excel informe --banco, --agencia e --conta."
    )

    def add_arguments(self, parser):
        parser.add_argument("arquivo")
        parser.add_argument("--empresa", required=True, help="Código da empresa no Domínio.")
        parser.add_argument("--banco", help="Código do banco (ex.: 748).")
        parser.add_argument("--agencia", default="")
        parser.add_argument("--conta")
        parser.add_argument("--mapa", help='Mapeamento de colunas: "data=Dt Mov,valor=Vlr,historico=Descr".')
        parser.add_argument("--separador", help="Separador do CSV (detectado automaticamente se omitido).")

    def handle(self, *args, **opcoes):
        empresa = obter_empresa(opcoes["empresa"])
        try:
            conta = None
            if opcoes["banco"] or opcoes["conta"]:
                conta = localizar_ou_criar_conta(empresa, opcoes["banco"] or "", opcoes["agencia"], opcoes["conta"] or "")
            importacao = importar_extrato(
                empresa, opcoes["arquivo"], conta_bancaria=conta,
                mapeamento=interpretar_mapa(opcoes["mapa"]), separador=opcoes["separador"],
            )
        except (ErroImportacao, ErroLeitura, FileNotFoundError) as erro:
            raise CommandError(str(erro))
        self.stdout.write(self.style.SUCCESS(
            f"Importação #{importacao.pk}: {importacao.total_registros} movimentação(ões) na conta "
            f"{importacao.conta_bancaria}; {importacao.registros_ignorados} linha(s) ignorada(s)."
        ))

from pathlib import Path

import pytest

FIXTURES = Path(__file__).parent / "fixtures"


@pytest.fixture
def fixtures() -> Path:
    return FIXTURES


@pytest.fixture
def empresa(db):
    from empresas.models import Empresa

    return Empresa.objects.create(
        razao_social="Supermercado Ficticio Ltda", cnpj="11222333000181", codigo_dominio="101"
    )

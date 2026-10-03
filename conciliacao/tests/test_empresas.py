from decimal import Decimal

import pytest
from django.core.exceptions import ValidationError
from django.db import IntegrityError

from empresas.models import ContaBancaria, Empresa
from empresas.validadores import cnpj_valido, cpf_valido, validar_cnpj_cpf

# Documentos fictícios, com dígitos verificadores válidos.
CNPJ_FICTICIO = "11.222.333/0001-81"
CPF_FICTICIO = "123.456.789-09"


class TestValidadores:
    def test_cnpj_valido_formatado_e_sem_formatacao(self):
        assert cnpj_valido(CNPJ_FICTICIO)
        assert cnpj_valido("11222333000181")

    def test_cnpj_invalido(self):
        assert not cnpj_valido("11222333000182")
        assert not cnpj_valido("00000000000000")
        assert not cnpj_valido("123")
        assert not cnpj_valido(None)

    def test_cpf(self):
        assert cpf_valido(CPF_FICTICIO)
        assert not cpf_valido("12345678900")
        assert not cpf_valido("11111111111")

    def test_validar_cnpj_cpf(self):
        validar_cnpj_cpf(CNPJ_FICTICIO)
        validar_cnpj_cpf(CPF_FICTICIO)
        with pytest.raises(ValidationError):
            validar_cnpj_cpf("12345")


@pytest.mark.django_db
class TestEmpresa:
    def criar_empresa(self, **kwargs):
        dados = {
            "razao_social": "Supermercado Ficticio Ltda",
            "cnpj": CNPJ_FICTICIO,
            "codigo_dominio": "101",
        }
        dados.update(kwargs)
        return Empresa.objects.create(**dados)

    def test_cnpj_salvo_somente_com_digitos(self):
        empresa = self.criar_empresa()
        assert empresa.cnpj == "11222333000181"

    def test_parametros_padrao_criados_automaticamente(self):
        empresa = self.criar_empresa()
        parametros = empresa.parametros
        assert parametros.janela_dias == 3
        assert parametros.tolerancia_valor == Decimal("10.00")
        assert parametros.score_conciliado == 80
        assert parametros.score_possivel == 50

    def test_full_clean_rejeita_cnpj_invalido(self):
        empresa = Empresa(razao_social="X", cnpj="11222333000182", codigo_dominio="9")
        with pytest.raises(ValidationError):
            empresa.full_clean()

    def test_conta_bancaria_unica_por_empresa(self):
        empresa = self.criar_empresa()
        ContaBancaria.objects.create(
            empresa=empresa, banco_codigo="748", agencia="0101", conta="12345-6"
        )
        with pytest.raises(IntegrityError):
            ContaBancaria.objects.create(
                empresa=empresa, banco_codigo="748", agencia="0101", conta="12345-6"
            )

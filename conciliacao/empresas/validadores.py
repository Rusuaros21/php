"""Validação de CNPJ e CPF (dígitos verificadores)."""

import re

from django.core.exceptions import ValidationError


def somente_digitos(texto: str | None) -> str:
    return re.sub(r"\D", "", texto or "")


def _digito(numeros: str, pesos: list[int]) -> str:
    resto = sum(int(n) * p for n, p in zip(numeros, pesos)) % 11
    return "0" if resto < 2 else str(11 - resto)


def cnpj_valido(cnpj: str | None) -> bool:
    cnpj = somente_digitos(cnpj)
    if len(cnpj) != 14 or cnpj == cnpj[0] * 14:
        return False
    pesos1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]
    pesos2 = [6] + pesos1
    d1 = _digito(cnpj[:12], pesos1)
    d2 = _digito(cnpj[:12] + d1, pesos2)
    return cnpj[-2:] == d1 + d2


def cpf_valido(cpf: str | None) -> bool:
    cpf = somente_digitos(cpf)
    if len(cpf) != 11 or cpf == cpf[0] * 11:
        return False
    d1 = _digito(cpf[:9], list(range(10, 1, -1)))
    d2 = _digito(cpf[:9] + d1, list(range(11, 1, -1)))
    return cpf[-2:] == d1 + d2


def validar_cnpj(valor: str) -> None:
    if not cnpj_valido(valor):
        raise ValidationError("CNPJ inválido: %(valor)s", params={"valor": valor})


def validar_cnpj_cpf(valor: str) -> None:
    digitos = somente_digitos(valor)
    if len(digitos) == 14 and cnpj_valido(digitos):
        return
    if len(digitos) == 11 and cpf_valido(digitos):
        return
    raise ValidationError("CNPJ/CPF inválido: %(valor)s", params={"valor": valor})

from django.db.models.signals import post_save
from django.dispatch import receiver

from .models import Empresa, ParametrosConciliacao


@receiver(post_save, sender=Empresa)
def criar_parametros_padrao(sender, instance, created, **kwargs):
    """Toda empresa nova recebe os parâmetros de conciliação padrão."""
    if created:
        ParametrosConciliacao.objects.get_or_create(empresa=instance)

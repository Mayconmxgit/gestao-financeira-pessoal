<?php

namespace App\Modulos\Assinaturas\Dominio;

final class SituacoesAssinatura
{
    public const EM_ANDAMENTO = ['criando', 'pendente', 'ativa', 'periodo_graca', 'pausada'];

    private function __construct() {}
}

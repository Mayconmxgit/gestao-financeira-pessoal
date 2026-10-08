<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

final class RecuperacaoAcesso extends Mailable
{
    public function __construct(public readonly string $url) {}

    public function build(): self
    {
        return $this->subject('Recuperação de acesso Vida Financeira AI')
            ->text('emails.recuperacao_acesso', ['url' => $this->url]);
    }
}

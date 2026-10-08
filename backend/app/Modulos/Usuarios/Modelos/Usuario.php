<?php

namespace App\Modulos\Usuarios\Modelos;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Str;

final class Usuario extends Authenticatable
{
    protected $table = 'usuarios';

    public $timestamps = true;

    public const CREATED_AT = 'criado_em';

    public const UPDATED_AT = 'atualizado_em';

    protected $attributes = ['papel' => 'usuario', 'versao_token' => 1];

    protected $fillable = ['nome', 'email', 'senha'];

    protected $hidden = ['senha', 'versao_token'];

    public function getAuthPasswordName(): string
    {
        return 'senha';
    }

    protected static function booted(): void
    {
        self::creating(function (Usuario $usuario): void {
            $usuario->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['versao_token' => 'integer'];
    }
}

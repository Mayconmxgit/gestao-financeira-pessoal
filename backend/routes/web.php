<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['servico' => 'Vida Financeira AI']));

<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Necessário para `$this->authorize()` nas rotas sensíveis (anti-IDOR).
    use AuthorizesRequests;
}

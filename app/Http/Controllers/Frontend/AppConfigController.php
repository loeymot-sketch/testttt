<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Support\AppVersion;
use Illuminate\Http\JsonResponse;

/**
 * [STORES T-3.3.2 · 2026-10-01] Ce que l'application des stores doit savoir du serveur
 * avant de laisser commander : la version la plus ancienne encore acceptée, le message à
 * afficher sinon, et où se trouve la mise à jour. Public et en lecture seule.
 */
class AppConfigController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(AppVersion::configuration());
    }
}

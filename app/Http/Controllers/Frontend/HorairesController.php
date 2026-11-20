<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Services\Horaires\HorairesService;

/**
 * [HORAIRES 2026-11-05] Lecture PUBLIQUE des horaires (site et application) : ouvert maintenant, fin du service, prochaine
 * ouverture, fermeture exceptionnelle et son message, semaine. Aucune donnée client.
 */
class HorairesController extends Controller
{
    public function show(HorairesService $horaires)
    {
        return response(['status' => true, 'data' => $horaires->etatPublic()])
            ->header('Cache-Control', 'public, max-age=30');
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\HorairesRequest;
use App\Services\Horaires\HorairesService;

/** [HORAIRES 2026-11-05] Paramètres › Horaires : heures par jour + fermetures exceptionnelles. */
class HorairesController extends AdminController
{
    public function __construct(private readonly HorairesService $horaires)
    {
        parent::__construct();
        $this->middleware(['permission:settings'])->only('index', 'update');
    }

    public function index()
    {
        return response(['status' => true, 'data' => $this->horaires->etatPublic() + ['fermetures' => $this->horaires->fermetures()]]);
    }

    public function update(HorairesRequest $request)
    {
        $this->horaires->enregistrer($request->input('semaine', []), $request->input('fermetures', []));

        return response(['status' => true, 'data' => $this->horaires->etatPublic() + ['fermetures' => $this->horaires->fermetures()]]);
    }
}

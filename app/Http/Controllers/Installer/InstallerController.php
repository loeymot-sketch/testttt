<?php

namespace App\Http\Controllers\Installer;


use App\Http\Controllers\Controller;
use App\Services\InstallerPermissionCheckerService;
use App\Services\InstallerRequirementsCheckerService;
use App\Services\InstallerService;
use Dipokhalder\EnvEditor\EnvEditor;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Validator;

class InstallerController extends Controller
{
    public InstallerService $installerService;
    protected InstallerRequirementsCheckerService $installerRequirementsCheckerService;
    protected InstallerPermissionCheckerService $installerPermissionCheckerService;

    public function __construct(InstallerService $installerService, InstallerRequirementsCheckerService $installerRequirementsCheckerService, InstallerPermissionCheckerService $installerPermissionCheckerService)
    {
        $this->installerService                    = $installerService;
        $this->installerRequirementsCheckerService = $installerRequirementsCheckerService;
        $this->installerPermissionCheckerService   = $installerPermissionCheckerService;

        // [ULTRA-AUDIT V3 2026-07-02 — P0/P1] Garde « déjà installé ». BUG CRITIQUE de l'ancien code :
        // `Redirect::to(...)->send()` ENVOIE la réponse 302 mais NE STOPPE PAS l'exécution PHP (pas de
        // exit/throw) → le constructeur se terminait et la MÉTHODE demandée s'exécutait quand même sur
        // une app installée : `/install/database` reconfigurait la DB de prod, `/install/final-store`
        // réécrivait `.env` (APP_ENV=production…) — le tout NON AUTHENTIFIÉ.
        //
        // Correctif : garde en MIDDLEWARE de contrôleur (s'exécute au moment de la REQUÊTE, pas à
        // l'instanciation — un throw dans le constructeur casse le route-scan/tests). Retourner une
        // réponse depuis un middleware COURT-CIRCUITE le pipeline → la méthode n'est JAMAIS atteinte.
        $this->middleware(function ($request, $next) {
            if (file_exists(storage_path('installed'))) {
                return redirect(env('APP_URL') ?: '/');
            }
            return $next($request);
        });
    }

    public function index(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        return view('installer.welcome');
    }

    public function requirement(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        $phpSupportInfo = $this->installerRequirementsCheckerService->checkPHPversion(config('installer.core.minPhpVersion'));
        $requirements   = $this->installerRequirementsCheckerService->check(config('installer.requirements'));
        return view('installer.requirement', compact('requirements', 'phpSupportInfo'));
    }

    public function permission(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        $permissions = $this->installerPermissionCheckerService->check(config('installer.permissions'));
        return view('installer.permission', compact('permissions'));
    }

    public function license(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        return view('installer.license');
    }

    public function licenseStore(Request $request)
    {
        $rules     = config('installer.license.form.rules');
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return redirect(route('installer.license'))->withErrors($validator)->withInput();
        }

        try {
            $response = $this->installerService->licenseCodeChecker($request->all());
            if (isset($response->status) && $response->status) {
                $envService = new EnvEditor();
                $envService->addData([
                    'MIX_API_KEY' => $request->license_key,
                ]);
                return redirect(route('installer.site'));
            } else {
                return redirect(route('installer.license'))->withErrors(['global' => $response->message])->withInput();
            }
        } catch (Exception $e) {
            return redirect(route('installer.license'))->withErrors(['global' => $e->getMessage()])->withInput();
        }
    }

    public function site(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        return view('installer.site');
    }

    public function siteStore(Request $request): \Illuminate\Routing\Redirector|\Illuminate\Http\RedirectResponse|\Illuminate\Contracts\Foundation\Application
    {
        $rules     = config('installer.site.form.rules');
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            return redirect(route('installer.site'))->withErrors($validator)->withInput();
        }

        try {
            $this->installerService->siteSetup($request);
            return redirect(route('installer.database'));
        } catch (Exception $e) {
            return redirect(route('installer.site'))->withErrors($e->getMessage())->withInput();
        }
    }

    public function database(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        return view('installer.database');
    }

    public function databaseStore(Request $request): \Illuminate\Routing\Redirector|\Illuminate\Http\RedirectResponse|\Illuminate\Contracts\Foundation\Application
    {
        $rules     = config('installer.database.form.rules');
        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return redirect(route('installer.database'))->withErrors($validator)->withInput();
        }

        try {
            $response = $this->installerService->databaseSetup($request);
            if ($response) {
                return redirect(route('installer.final'));
            }
            return redirect(route('installer.database'))->withErrors(['global' => trans('installer.database.fail_message')])->withInput();
        } catch (Exception $e) {
            return redirect(route('installer.database'))->withErrors(['global' => $e->getMessage()])->withInput();
        }
    }

    public function final(): \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|\Illuminate\Contracts\Foundation\Application
    {
        return view('installer.final');
    }

    public function finalStore(): \Illuminate\Routing\Redirector|\Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse
    {
        try {
            $this->installerService->finalSetup();
            return redirect(env('APP_URL'));
        } catch (Exception $e) {
            return redirect(route('installer.site'))->withErrors(['global' => $e->getMessage()]);
        }
    }

}

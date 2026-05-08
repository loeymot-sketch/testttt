<?php

namespace App\Http\Controllers\Admin;

use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * [Wave Gamma G3 / V2-5] Kiosk theme admin surface.
 *
 * Cette ressource gère le slug du thème visuel actif (`Branch.active_kiosk_theme`)
 * appliqué côté kiosk via `:root[data-kiosk-theme="..."]` (cf. CSS
 * `resources/css/kiosk/themes/<slug>.css` et JS `kioskThemeManager.js`).
 *
 * - `show()`  : public-read léger consommé par les kiosks au boot. Ne
 *               révèle aucune donnée sensible (juste le slug ou
 *               `'standard'`). Pas de permission requise — les kiosks
 *               doivent pouvoir récupérer leur skin AVANT d'avoir un
 *               token sanctum si la séquence de boot diffère.
 * - `update()`: PATCH gated `permission:settings` (même gate que les
 *               surfaces `setting/*` admin et `kiosk-machine`), avec
 *               isolation de branche : un Admin scoped à branch X ne
 *               peut pas écraser le thème de branch Y. Audité via Log.
 *
 * Liste blanche : la validation `in:` doit rester synchrone avec
 * `SUPPORTED_THEMES` côté JS (`resources/js/services/kioskThemeManager.js`).
 */
class KioskThemeController extends AdminController
{
    /**
     * Liste blanche serveur des slugs autorisés. À garder synchrone avec
     * `SUPPORTED_THEMES` JS (`kioskThemeManager.js`). Tout ajout passe
     * d'abord par la création du fichier CSS dans `resources/css/kiosk/themes/`
     * et par les tests d'a11y obligatoires (cf. docs/KIOSK_THEMES.md).
     */
    public const SUPPORTED_THEMES = [
        'standard',
        'halloween',
        'christmas',
    ];

    public const DEFAULT_THEME = 'standard';

    public function __construct()
    {
        parent::__construct();
        $this->middleware(['permission:settings'])->only('update');
    }

    /**
     * GET /api/admin/kiosk-theme/{branchId}
     *
     * Public-read : retourne le slug du thème actif, ou 'standard' en
     * fallback (branche inconnue, slug vide, slug stale hors liste blanche).
     * Pas de 404 sur branche absente : les kiosks doivent toujours obtenir
     * un thème valide pour ne pas bloquer le boot.
     */
    public function show($branchId): JsonResponse
    {
        $branch = Branch::query()->find((int) $branchId);

        $theme = self::DEFAULT_THEME;
        if ($branch && is_string($branch->active_kiosk_theme) && $branch->active_kiosk_theme !== '') {
            $candidate = $branch->active_kiosk_theme;
            // Si la BDD contient un slug stale (ex. ancien thème supprimé),
            // ne pas l'exposer : le kiosk planterait sur une CSS absente.
            if (in_array($candidate, self::SUPPORTED_THEMES, true)) {
                $theme = $candidate;
            }
        }

        return response()->json([
            'active_theme' => $theme,
        ]);
    }

    /**
     * PATCH /api/admin/kiosk-theme/{branchId}
     *
     * Met à jour le slug actif pour une branche. Gated `permission:settings`
     * + isolation de branche : un Admin avec `branch_id` non-zéro ne peut
     * mettre à jour que sa propre branche (head-office = branch_id=0
     * peut modifier toutes les branches, conformément au pattern utilisé
     * dans `DeliveryPlatformController` et autres surfaces admin).
     */
    public function update(Request $request, $branchId): JsonResponse
    {
        $validated = $request->validate([
            'theme' => ['required', 'string', 'in:' . implode(',', self::SUPPORTED_THEMES)],
        ]);

        $targetBranchId = (int) $branchId;
        $userBranchId = (int) ($request->user()?->branch_id ?? 0);

        // Isolation de branche : seul un head-office Admin (branch_id=0) peut
        // modifier une autre branche. Un Admin local scoped reste cantonné
        // à sa propre branche, cohérent avec le BranchScope global.
        if ($userBranchId !== 0 && $userBranchId !== $targetBranchId) {
            return response()->json([
                'status'  => false,
                'message' => 'Branch scope denied.',
            ], 403);
        }

        $branch = Branch::query()->find($targetBranchId);
        if (!$branch) {
            return response()->json([
                'status'  => false,
                'message' => 'Branch not found.',
            ], 404);
        }

        $branch->active_kiosk_theme = $validated['theme'];
        $branch->save();

        Log::info('[KioskTheme] active theme changed', [
            'branch_id' => $targetBranchId,
            'theme'     => $validated['theme'],
            'user_id'   => $request->user()?->id,
        ]);

        return response()->json([
            'status'       => true,
            'active_theme' => $validated['theme'],
        ]);
    }
}

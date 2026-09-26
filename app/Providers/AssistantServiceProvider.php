<?php

namespace App\Providers;

use App\Services\Menu\Vision\MenuExtractionContract;
use App\Services\Menu\Vision\MockMenuExtractionService;
use Illuminate\Support\ServiceProvider;

/**
 * [ONB-04 2026-08-27] Le choix entre bouchon et implémentation réelle.
 *
 * Repris à l'identique du motif déjà en service pour la facture d'achat et le
 * ticket Uber : deux verrous, jamais un. Il faut `assistant.enabled` à vrai ET
 * une clé non vide pour qu'une implémentation réelle soit choisie. Un seul
 * drapeau finit toujours par être basculé par erreur.
 *
 * Aujourd'hui il n'existe AUCUNE implémentation réelle : elle attend le gate
 * propriétaire G-IA (fournisseur, clé, et surtout plafond de dépense — le projet
 * n'a aujourd'hui aucun compteur de coût). Le bouchon est donc la seule voie, et
 * c'est délibéré : toute la chaîne en aval se construit et se teste contre lui.
 *
 * Isolé dans son propre fournisseur pour ne PAS toucher AppServiceProvider, qui
 * porte les gardes de démarrage NF525.
 */
class AssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MenuExtractionContract::class, function ($app): MenuExtractionContract {
            $actif = (bool) config('assistant.enabled', false);
            $cle   = (string) config('services.openai.key', '');

            if ($actif && $cle !== '') {
                // [G-IA] Ici viendra l'implémentation réelle, une fois le gate tranché.
                // Tant qu'elle n'existe pas, on retombe explicitement sur le bouchon
                // plutôt que de lever une exception : un réglage mal mis ne doit pas
                // mettre l'application par terre, il doit rester sans effet.
                return $app->make(MockMenuExtractionService::class);
            }

            return $app->make(MockMenuExtractionService::class);
        });
    }
}

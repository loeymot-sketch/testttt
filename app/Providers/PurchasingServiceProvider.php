<?php

namespace App\Providers;

use App\Services\Purchasing\Vision\InvoiceVisionContract;
use App\Services\Purchasing\Vision\MockInvoiceVisionService;
use App\Services\Purchasing\Vision\OpenAiInvoiceVisionService;
use Illuminate\Support\ServiceProvider;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3b] Binding du lecteur de factures IA.
 *
 * Choisit l'implémentation de {@see InvoiceVisionContract} au runtime :
 *  - {@see OpenAiInvoiceVisionService} SI `services.openai.enabled` ET une clé
 *    non vide sont présentes (vision réelle) ;
 *  - sinon {@see MockInvoiceVisionService} — DÉFAUT (test/local sans clé).
 *
 * Conséquence voulue : aucun appel réseau tant que l'owner n'a pas activé +
 * fourni la clé. Le reste du flux (classification, endpoint, validation stock)
 * est identique quelle que soit l'impl → tout est testable end-to-end en mock.
 *
 * Domaine NEUF, ADDITIF, HORS NF525. Isolé dans son propre provider pour ne PAS
 * toucher AppServiceProvider (qui porte les boot-guards NF525).
 */
class PurchasingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(InvoiceVisionContract::class, function ($app): InvoiceVisionContract {
            $key = (string) config('services.openai.key', '');
            $enabled = (bool) config('services.openai.enabled', false);

            if ($enabled && $key !== '') {
                return $app->make(OpenAiInvoiceVisionService::class);
            }

            return $app->make(MockInvoiceVisionService::class);
        });
    }
}

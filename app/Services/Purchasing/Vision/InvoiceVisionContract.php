<?php

namespace App\Services\Purchasing\Vision;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3b] Contrat de LECTURE de facture.
 *
 * Un implémenteur reçoit le chemin d'une photo (facture / ticket) et rend les
 * lignes brutes lues, SANS aucune décision de cible stock (c'est le rôle de
 * InvoiceClassificationService). Deux implémentations réelles :
 *  - {@see MockInvoiceVisionService}   — DÉFAUT (test/local sans clé), fixture déterministe.
 *  - {@see OpenAiInvoiceVisionService} — derrière OPENAI_API_KEY (vision réelle).
 *
 * Le binding (PurchasingServiceProvider) choisit l'impl selon la présence de la
 * clé + `config('services.openai.enabled')`.
 *
 * Domaine NEUF, ADDITIF, HORS NF525 — aucune écriture fiscale.
 */
interface InvoiceVisionContract
{
    /**
     * Extrait les lignes d'une facture photographiée.
     *
     * @param  string  $photoPath  Chemin absolu (ou storage) de la photo.
     * @return array<int, array{raw_label:string, qty:float, unit:string, unit_price:float|null, tva_rate:float|null}>
     *         Liste de lignes normalisées. Peut être vide (fail-safe) mais jamais null.
     */
    public function extractLines(string $photoPath): array;
}

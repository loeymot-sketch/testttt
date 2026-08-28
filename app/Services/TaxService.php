<?php

namespace App\Services;

use Exception;
use App\Models\Tax;
use App\Enums\TaxType;
use App\Http\Requests\TaxRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;

class TaxService
{
    protected array $taxFilter = [
        'name',
        'code',
        'tax_rate',
        'type',
        'status'
    ];

    /**
     * @throws Exception
     */
    public function list(PaginateRequest $request)
    {
        try {
            $requests    = $request->all();
            $method      = $request->get('paginate', 0) == 1 ? 'paginate' : 'get';
            $methodValue = $request->get('paginate', 0) == 1 ? $request->get('per_page', 10) : '*';
            $orderColumn = $request->get('order_column') ?? 'id';
            $orderType   = $request->get('order_type') ?? 'desc';

            return Tax::where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->taxFilter)) {
                        $query->where($key, 'like', '%' . $request . '%');
                    }
                }
            })->orderBy($orderColumn, $orderType)->$method(
                $methodValue
            );
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function store(TaxRequest $request)
    {
        try {
            return Tax::create($request->validated() + ['type' => TaxType::PERCENTAGE]);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(TaxRequest $request, Tax $tax)
    {
        try {
            return tap($tax)->update($request->validated());
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function destroy(Tax $tax): void
    {
        try {
            /*
             * [ONB-02 2026-08-28 · FISCAL] Le garde-fou etait INVERSE, et les deux
             * branches supprimaient.
             *
             *   if (!blank($checkItem)) { $tax->delete(); }          // des articles ? on supprime
             *   else { SET FOREIGN_KEY_CHECKS=0; $tax->delete(); }   // aucun ? on coupe l'integrite
             *
             * Et `Tax::items()` filtre `status = ACTIVE` (`Tax.php:24`) : un article
             * DESACTIVE ou soft-supprime n'etait pas compte, alors qu'il conserve son
             * `tax_id` et que `items.tax_id` porte une vraie contrainte.
             *
             * Ce que ca produisait : desactiver un produit, supprimer sa taxe (geste
             * banal — le GOAL recense 47 taxes parasites a nettoyer), le reactiver.
             * `PricingService` fait alors `$taxes[0] ?? null` sur un `tax_id`
             * orphelin -> **0 % de TVA, sans alerte ni journal**. Le trou qu'ONB-02 a
             * ferme a la CREATION etait rouvert par la SUPPRESSION.
             *
             * `Tax` n'a pas de suppression douce (`Tax.php:9-13`) : l'effacement est
             * definitif. On REFUSE donc quand la taxe est encore referencee, au lieu
             * de casser l'integrite pour passer en force. Et on ne desactive JAMAIS
             * `FOREIGN_KEY_CHECKS` : la version d'avant ne le remettait meme pas dans
             * un `finally`, donc une exception laissait la connexion sans controle
             * d'integrite pour les requetes suivantes.
             */
            // On veut compter les articles de TOUTES les branches (une taxe est
            // globale) ET ceux qui sont soft-supprimes (ils gardent leur `tax_id`
            // et peuvent etre restaures). On nomme donc les deux intentions plutot
            // que d'eteindre tous les scopes en bloc.
            $referencants = \App\Models\Item::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->withTrashed()
                ->where('tax_id', $tax->id)
                ->count();

            if ($referencants > 0) {
                throw new Exception(
                    trans('all.message.taxe_encore_utilisee', ['n' => $referencants]),
                    422
                );
            }

            $tax->delete();
        } catch (Exception $exception) {
            Log::info(QueryExceptionLibrary::message($exception));
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function show(Tax $tax): Tax
    {
        try {
            return $tax;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
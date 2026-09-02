<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\WizardPageRequest;
use App\Http\Resources\WizardPageResource;
use App\Models\ItemCategory;
use App\Models\WizardPage;
use App\Services\Composer\WizardPageService;
use Illuminate\Http\Request;

/**
 * Bibliothèque de pages de wizard (« Choisis ton pain », « Suppléments »…), réutilisables par toutes
 * les catégories, ou copies privées d'une catégorie qui les a personnalisées.
 */
class WizardPageController extends AdminController
{
    public function __construct(private readonly WizardPageService $pages)
    {
        parent::__construct();
    }

    public function index(Request $request)
    {
        $categoryId = $request->integer('category_id') ?: null;

        return WizardPageResource::collection($this->pages->listFor($categoryId));
    }

    public function show(WizardPage $wizardPage)
    {
        $wizardPage->load(['choices', 'itemAttribute', 'ownerCategory'])->loadCount('steps');
        $wizardPage->usage = $this->pages->usage($wizardPage);

        return new WizardPageResource($wizardPage);
    }

    public function store(WizardPageRequest $request)
    {
        $owner = $request->integer('owner_category_id') ? ItemCategory::query()->findOrFail($request->integer('owner_category_id')) : null;

        return new WizardPageResource($this->pages->create($request->validated(), $owner));
    }

    public function update(WizardPageRequest $request, WizardPage $wizardPage)
    {
        return new WizardPageResource($this->pages->update($wizardPage, $request->validated()));
    }

    public function destroy(WizardPage $wizardPage)
    {
        $this->pages->delete($wizardPage);

        return response(['status' => true]);
    }

    public function duplicateForCategory(WizardPage $wizardPage, ItemCategory $category)
    {
        return new WizardPageResource($this->pages->duplicateForCategory($wizardPage, $category));
    }
}

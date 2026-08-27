<?php

namespace App\Services;


use Exception;
use App\Models\Language;
use Illuminate\Http\Request;
use App\Libraries\AppLibrary;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\LanguageRequest;
use App\Http\Requests\PaginateRequest;
use App\Libraries\QueryExceptionLibrary;
use Smartisan\Settings\Facades\Settings;
use App\Http\Requests\LanguageFileTextGetRequest;
use App\Http\Requests\LanguageFileTextStoreRequest;


class LanguageService
{
    protected $languageFilter = [
        'name',
        'code',
        'display_mode',
        'status',
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

            return Language::where(function ($query) use ($requests) {
                foreach ($requests as $key => $request) {
                    if (in_array($key, $this->languageFilter)) {
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
    public function store(LanguageRequest $request)
    {
        try {
            if (!file_exists(base_path("resources/js/languages/{$request->code}.json"))) {
                copy(base_path("resources/js/languages/en.json"), base_path("resources/js/languages/{$request->code}.json"));
            }

            if (!file_exists(base_path("lang/{$request->code}"))) {
                mkdir(base_path("lang/{$request->code}"), 0755);
                $files = scandir(base_path("lang/en"));
                if (count($files) > 2) {
                    foreach ($files as $file) {
                        if ($file != '.' && $file != '..') {
                            copy(base_path("lang/en/{$file}"), base_path("lang/{$request->code}/{$file}"));
                        }
                    }
                }
            }

            $language = Language::create($request->validated());
            if ($request->image) {
                $language->addMediaFromRequest('image')->toMediaCollection('language');
            }

            return $language;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function update(LanguageRequest $request, Language $language): Language
    {
        try {
            $language->update($request->validated());
            if ($request->image) {
                $language->clearMediaCollection('language');
                $language->addMediaFromRequest('image')->toMediaCollection('language');
            }
            return $language;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function destroy(Language $language): void
    {
        try {
            if (Settings::group('site')->get("site_default_language") != $language->id) {
                if (!env('DEMO') && $language->id !== 1) {
                    AppLibrary::deleteDir(base_path("lang/{$language->code}"));
                    if (file_exists(base_path("resources/js/languages/{$language->code}.json"))) {
                        unlink(base_path("resources/js/languages/{$language->code}.json"));
                    }
                }
                $language->delete();
            } else {
                throw new Exception("Default language not deletable", 422);
            }
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function show(Language $language): Language
    {
        try {
            return $language;
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }


    /**
     * @throws Exception
     */
    public function fileList(Language $language): \Vanilla\Support\Collection|\IlluminateAgnostic\Str\Support\Collection|\IlluminateAgnostic\Collection\Support\Collection|\IlluminateAgnostic\StrAgnostic\Str\Support\Collection|\IlluminateAgnostic\ArrAgnostic\Arr\Support\Collection|\Illuminate\Support\Collection|\IlluminateAgnostic\Arr\Support\Collection
    {
        try {
            $i     = 0;
            $array = [];

            if (file_exists(base_path("resources/js/languages/{$language->code}.json"))) {
                $array[$i] = (object)[
                    'path' => base_path("resources/js/languages/{$language->code}.json"),
                    'name' => "{$language->code}.json"
                ];
                $i++;
            }

            if (file_exists(base_path("lang/{$language->code}"))) {
                $files = scandir(base_path("lang/{$language->code}"));
                if (count($files) > 2) {
                    foreach ($files as $file) {
                        if ($file != '.' && $file != '..') {
                            $array[$i] = (object)[
                                'path' => base_path("lang/{$language->code}/{$file}"),
                                'name' => $file
                            ];
                            $i++;
                        }
                    }
                }
            }
            return collect($array);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }


    /**
     * [GOAL-L2-HEAL-01 2026-05-24] Phase L7-2-F-03 P0 — Stream-wrapper containment.
     *
     * Was: include($path) + fopen/file_get_contents/file_put_contents accepted
     * arbitrary stream wrappers (http://, php://, data://, file://, phar://)
     * — full RCE + SSRF + arbitrary file read/write gadget gated only by
     * permission:settings (cf. LanguageController.php:23-27).
     *
     * Containment (defence-in-depth):
     *   1. realpath() resolves the path — stream-wrapper URIs (http://, php://,
     *      data://, file://, phar://, ...) all return false because they are not
     *      local files on disk.
     *   2. The resolved real path MUST live under one of the two legitimate
     *      language directories: base_path('lang/') for PHP translation files,
     *      or base_path('resources/js/languages/') for the JS i18n JSON files.
     *      Defeats ../ traversal because realpath() canonicalises first.
     *   3. The resolved file extension MUST be .php or .json.
     *
     * Returns the resolved real path on success, throws RuntimeException on any
     * violation. Caller MUST use the returned path (NOT the original $path) for
     * the downstream include/read/write to avoid TOCTOU drift.
     *
     * @throws \RuntimeException
     */
    /**
     * [ONB-13 2026-08-28] Rend un littéral ÉCHAPPÉ par le langage du fichier cible,
     * guillemets compris — jamais une concaténation à la main.
     *
     * `var_export` et `json_encode` produisent l'un et l'autre une chaîne close et
     * échappée par les règles du langage : c'est ce qui neutralise la sortie de chaîne
     * (`"`), l'échappement (`\`) et, côté PHP, l'interpolation (`$`, `${`). Écrire
     * `"{$value}"` à la main, comme le faisait ce service, revient à faire ce travail
     * soi-même et à l'oublier.
     *
     * Les deux extensions possibles sont déjà bornées par `validateLangFilePath()`.
     */
    private function litteralPourFichier(string $resolvedPath, mixed $value): string
    {
        $texte = is_scalar($value) ? (string) $value : '';

        $estJson = strtolower((string) pathinfo($resolvedPath, PATHINFO_EXTENSION)) === 'json';

        if ($estJson) {
            $encode = json_encode($texte, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            // `json_encode` ne rend `false` que sur de l'UTF-8 invalide ; on refuse
            // plutôt que d'écrire un fichier de langue cassé.
            if ($encode === false) {
                throw new \RuntimeException('Traduction non encodable en JSON');
            }

            return $encode;
        }

        // PHP : littéral entre apostrophes, échappé par le langage lui-même.
        return var_export($texte, true);
    }

    private function validateLangFilePath(?string $path): string
    {
        if (!is_string($path) || $path === '') {
            throw new \RuntimeException('Invalid language file path');
        }

        $resolvedPath = realpath($path);
        if ($resolvedPath === false) {
            // realpath() returns false for stream wrappers (http://, php://,
            // data://, file://, phar://) AND for non-existent files.
            throw new \RuntimeException('Invalid language file path');
        }

        $allowedBases = array_filter([
            realpath(base_path('lang')),
            realpath(base_path('resources/js/languages')),
        ]);

        $under = false;
        foreach ($allowedBases as $base) {
            if (str_starts_with($resolvedPath, $base . DIRECTORY_SEPARATOR)) {
                $under = true;
                break;
            }
        }
        if (!$under) {
            throw new \RuntimeException('Language file path outside allowed directory');
        }

        $ext = pathinfo($resolvedPath, PATHINFO_EXTENSION);
        if (!in_array(strtolower($ext), ['php', 'json'], true)) {
            throw new \RuntimeException('Language file extension not allowed');
        }

        return $resolvedPath;
    }

    /**
     * @throws Exception
     */
    public function fileText(LanguageFileTextGetRequest $request)
    {
        try {
            // [GOAL-L2-HEAL-01 2026-05-24] Phase L7-2-F-03 P0: validate path
            // before include() — see validateLangFilePath() docblock.
            $resolvedPath = $this->validateLangFilePath($request->path);

            $explodeName = explode('.', $request->name);
            if ($explodeName > 0) {
                if ($explodeName[1] == 'json') {
                    // [GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13] include()
                    // on a .json file has no <?php tag, so PHP treats the raw
                    // JSON text as literal output and echoes it straight into
                    // the response body during the include() call itself —
                    // then, with no `return` here, this method (and the
                    // controller, which also didn't `return` this branch)
                    // implicitly returns null, so Laravel tries to send its
                    // own response on top of the already-echoed raw content.
                    // The resulting malformed response never resolves
                    // cleanly: confirmed live, the "Récupérer le contenu du
                    // fichier" button hangs indefinitely (no response event
                    // at all) whenever a .json language file is selected —
                    // which is *every* language's first/default file
                    // ({code}.json, always listed first by fileList()).
                    // .php files were never affected (return include(...)
                    // there correctly returns the file's `return [...]`
                    // array). Fixed by reading + decoding the JSON file
                    // properly instead of include()-ing it as if it were PHP.
                    $json = file_get_contents($resolvedPath);
                    return json_decode($json, true) ?? [];
                }

                return include($resolvedPath);
            }
        } catch (\RuntimeException $exception) {
            Log::info($exception->getMessage());
            throw new Exception($exception->getMessage(), 422);
        }
    }

    /**
     * @throws Exception
     */
    public function fileTextStore(LanguageFileTextStoreRequest $request): void
    {
        try {
            // [GOAL-L2-HEAL-01 2026-05-24] Phase L7-2-F-03 P0: validate path
            // before fopen/file_get_contents/file_put_contents — see
            // validateLangFilePath() docblock.
            $resolvedPath = $this->validateLangFilePath($request->x_language_file_path);

            $file        = fopen($resolvedPath, "rw");
            $fileContent = file_get_contents($resolvedPath);
            foreach ($request->all() as $key => $value) {
                if ($key != 'x_language_file_path' && $key != 'x_language_file_name') {
                    $key = str_replace('_', ' ', $key);
                    // [ONB-13 2026-08-28] La valeur était réinjectée VERBATIM entre
                    // guillemets doubles : `"{$value}"`. Un guillemet dans la valeur
                    // sortait de la chaîne, et un `$` y était interpolé — dans un
                    // fichier `<?php return [...]` que le traducteur inclut à chaque
                    // requête traduite. Le chemin était confiné et l'accès gardé par
                    // `permission:settings` ; le contenu, lui, ne l'était pas.
                    // On écrit désormais un littéral échappé par le langage cible.
                    $litteral = $this->litteralPourFichier($resolvedPath, $value);
                    if (strpos($fileContent, "'" . $key . "'") !== false) {
                        $fileContent = str_replace("'" . $key . "'", $litteral, $fileContent);
                    } elseif (strpos($fileContent, "\"{$key}\"") !== false) {
                        $fileContent = str_replace("\"{$key}\"", $litteral, $fileContent);
                    }
                }
            }

            file_put_contents($resolvedPath, $fileContent);
            fclose($file);
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DlcLabelProduct;
use App\Services\Labels\DlcVoiceInterpreter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * [ETIQUETTES 2026-10-19 · owner] Dictée vocale des étiquettes DLC : une phrase (ou son texte) devient une
 * liste de produits du catalogue, avec leur nombre d'étiquettes. Cette route ne fait QUE comprendre : elle
 * n'imprime rien, n'écrit rien au journal et ne garde pas l'audio. L'impression reste celle de l'écran
 * (« Qui ? », DLC du fabricant, produit d'origine…), déclenchée par un appui.
 *
 * Porte : celle des autres routes d'étiquettes (permission:pos-orders|pos|kitchen-display-system).
 * Domaine NEUF, ADDITIF, HORS NF525.
 */
class LabelVoiceController extends Controller
{
    /** En dessous, c'est un en-tête WAV (44 octets) et du silence : pas d'appel au modèle. */
    private const MIN_AUDIO_BYTES = 2000;

    public function __construct(private readonly DlcVoiceInterpreter $interpreter)
    {
        $this->middleware(['permission:pos-orders|pos|kitchen-display-system']);
    }

    public function interpret(Request $request): JsonResponse
    {
        $maxKb = max(64, (int) config('label_voice.max_kb', 4096));
        $request->validate([
            'audio' => ['required_without:transcript', 'nullable', 'file', 'mimes:wav,mp3,ogg,flac,aac,m4a', 'max:'.$maxKb],
            'transcript' => ['required_without:audio', 'nullable', 'string', 'max:1000'],
        ], [], ['audio' => 'enregistrement', 'transcript' => 'phrase']);

        // Le catalogue ACTIF de CE restaurant (BranchScope) : seul univers possible de la réponse.
        $products = DlcLabelProduct::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get();
        if ($products->isEmpty()) {
            return response()->json(['status' => 'no_catalogue', 'message' => 'Aucun produit à étiqueter : créez d’abord vos fiches.'], 422);
        }

        try {
            if ($request->hasFile('audio')) {
                $file = $request->file('audio');
                $format = match (strtolower((string) $file->guessExtension())) {
                    'mpga', 'mp3' => 'mp3',
                    'oga', 'ogg' => 'ogg',
                    'm4a', 'mp4' => 'm4a',
                    '' => 'wav',
                    default => strtolower((string) $file->guessExtension()),
                };
                $bytes = (string) file_get_contents($file->getRealPath());
                // Un enregistrement vide ou réduit à son en-tête : rien à écouter. On n'appelle PAS le modèle (coût, et un
                // modèle à qui l'on tend du silence peut « entendre » n'importe quoi).
                if (strlen($bytes) < self::MIN_AUDIO_BYTES) {
                    return $this->nothingHeard();
                }
                $result = $this->interpreter->fromAudio($bytes, $format, $products);
            } else {
                $result = $this->interpreter->fromText((string) $request->input('transcript'), $products);
            }
        } catch (RuntimeException $e) {
            $unavailable = ! $this->interpreter->available();

            return response()->json([
                'status' => $unavailable ? 'voice_unavailable' : 'voice_failed',
                'message' => $unavailable
                    ? 'La dictée vocale n’est pas activée sur ce restaurant.'
                    : 'Je n’ai pas pu analyser l’enregistrement : réessayez, ou choisissez les produits à la main.',
            ], $unavailable ? 503 : 502);
        }

        $nothing = $result['transcript'] === '' && $result['items'] === [];

        return response()->json([
            'data' => $result,
            'message' => $nothing ? 'Je n’ai rien entendu : rapprochez-vous de la tablette et réessayez.' : null,
        ]);
    }

    private function nothingHeard(): JsonResponse
    {
        return response()->json([
            'data' => ['driver' => 'none', 'transcript' => '', 'items' => [], 'unmatched' => []],
            'message' => 'Je n’ai rien entendu : rapprochez-vous de la tablette et réessayez.',
        ]);
    }
}

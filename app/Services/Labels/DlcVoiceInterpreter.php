<?php

namespace App\Services\Labels;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * [ETIQUETTES 2026-10-19 · owner] « Dis ce que tu veux étiqueter » : la voix devient une sélection de produits.
 *
 * Même discipline que {@see \App\Services\Uber\Vision\OpenAiUberTicketVisionService} :
 *  - le modèle PROPOSE, le serveur VÉRIFIE. Un identifiant que le modèle invente n'existe pas dans le
 *    catalogue du restaurant : il est jeté. Un nombre d'étiquettes absurde est ramené entre 1 et 20.
 *  - appel via `Http::` (simulable, aucun réseau en test), température 0, réponse JSON stricte ;
 *  - FAIL-CLOSED : une panne ne produit jamais de produits « devinés » à partir de rien. Avec de
 *    l'audio, elle lève {@see RuntimeException} (la tablette le dit) ; avec du texte, elle retombe sur
 *    le moteur local prudent {@see DlcVoiceLocalMatcher}.
 *
 * Rien n'est imprimé ici : le résultat REMPLIT la sélection de l'écran, et la personne appuie sur
 * « Imprimer ». Toutes les protections de l'impression (« Qui ? », DLC du fabricant, produit d'origine,
 * repas témoin, journal) restent donc celles de l'écran. L'audio n'est ni stocké ni journalisé.
 *
 * Domaine NEUF, ADDITIF, HORS NF525.
 */
class DlcVoiceInterpreter
{
    private const PROMPT = <<<'TXT'
Tu aides le personnel de cuisine d'un restaurant français (Le Cayenne) à imprimer des étiquettes de
DLC (date limite de consommation). La personne dicte (audio) ou écrit les produits à étiqueter.

Réponds en JSON STRICT, un objet unique :
{"transcript":string,"items":[{"product_id":number,"copies":number,"heard":string}],"unmatched":[string]}

Règles impératives :
- transcript = ce que tu as entendu, MOT POUR MOT, en français. Vide si rien d'intelligible.
- Une phrase cite souvent PLUSIEURS produits, parfois sans « et » ni virgule : « salade iceberg tomates
  oignons poivrons coupés » = quatre produits. Crée UN item par produit cité, jamais un seul pour la phrase.
- product_id = UNIQUEMENT un identifiant de la liste CATALOGUE ci-dessous. Prends la fiche la plus proche
  de ce qui est dit (« tomates » → la fiche de tomates la plus courante ; « boîte champignons » → la fiche
  de champignons en boîte). N'invente jamais d'identifiant, ne crée aucun produit.
- Si AUCUNE fiche ne convient, ne devine pas : mets les mots entendus dans "unmatched".
- Un même produit n'apparaît qu'une fois.
- copies = 1, sauf si un nombre d'étiquettes est dit pour CE produit (« deux étiquettes de tomates » → 2).
  Maximum 20.
- heard = les mots entendus pour ce produit.
- Ignore les mots de commande (« imprime », « aussi », « pour moi », « s'il te plaît »).
TXT;

    public function available(): bool
    {
        return (bool) config('label_voice.enabled', true) && (string) config('services.openai.key', '') !== '';
    }

    /**
     * @param  Collection<int, \App\Models\DlcLabelProduct>  $products
     * @return array{driver: string, transcript: string, items: list<array{product_id: int, name: string, copies: int, heard: string}>, unmatched: list<string>}
     *
     * @throws RuntimeException dictée indisponible ou réponse inexploitable (jamais de produits devinés)
     */
    public function fromAudio(string $audioBytes, string $format, Collection $products): array
    {
        if (! $this->available()) {
            throw new RuntimeException('Dictée vocale indisponible : clé absente ou fonction désactivée.');
        }

        return $this->ask($products, [
            ['type' => 'input_audio', 'input_audio' => ['data' => base64_encode($audioBytes), 'format' => $format]],
        ]);
    }

    /**
     * @param  Collection<int, \App\Models\DlcLabelProduct>  $products
     * @return array{driver: string, transcript: string, items: list<array{product_id: int, name: string, copies: int, heard: string}>, unmatched: list<string>}
     */
    public function fromText(string $text, Collection $products): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['driver' => 'none', 'transcript' => '', 'items' => [], 'unmatched' => []];
        }

        if ($this->available()) {
            try {
                return $this->ask($products, [['type' => 'text', 'text' => 'Phrase écrite : '.$text]]);
            } catch (RuntimeException $e) {
                Log::warning('[LabelVoice] modèle indisponible pour un texte, repli sur le moteur local', ['error' => $e->getMessage()]);
            }
        }

        $local = DlcVoiceLocalMatcher::match($text, $products);

        return ['driver' => 'local', 'transcript' => $text] + $local;
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     */
    private function ask(Collection $products, array $parts): array
    {
        $key = (string) config('services.openai.key', '');
        $model = (string) (config('label_voice.model') ?: config('services.openai.model', 'gpt-4o-mini'));

        $catalogue = $products->take((int) config('label_voice.max_catalogue', 600))
            ->map(fn ($p): string => (int) $p->id.' | '.preg_replace('/\s+/u', ' ', (string) $p->name))
            ->implode("\n");

        $response = Http::withToken($key)
            ->timeout((int) config('label_voice.timeout', 40))
            ->acceptJson()
            ->post(rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/').'/chat/completions', [
                'model' => $model,
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
                'messages' => [[
                    'role' => 'user',
                    'content' => array_merge(
                        [['type' => 'text', 'text' => self::PROMPT."\n\nCATALOGUE (identifiant | nom) :\n".$catalogue]],
                        $parts
                    ),
                ]],
            ]);

        if (! $response->successful()) {
            Log::warning('[LabelVoice] réponse non-2xx', ['status' => $response->status()]);
            throw new RuntimeException('Le service de dictée a répondu une erreur ('.$response->status().').');
        }

        $decoded = json_decode((string) $response->json('choices.0.message.content', ''), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Réponse du service de dictée illisible.');
        }

        return ['driver' => 'openai'] + self::normalize($decoded, $products);
    }

    /**
     * Le serveur ne croit pas le modèle sur parole : tout identifiant est vérifié contre le catalogue ACTIF du
     * restaurant, les doublons fusionnés (le plus grand nombre gagne), les nombres bornés.
     *
     * @param  array<string, mixed>  $raw
     * @param  Collection<int, \App\Models\DlcLabelProduct>  $products
     * @return array{transcript: string, items: list<array{product_id: int, name: string, copies: int, heard: string}>, unmatched: list<string>}
     */
    public static function normalize(array $raw, Collection $products): array
    {
        $byId = $products->keyBy(fn ($p): int => (int) $p->id);
        $max = max(1, (int) config('label_voice.max_copies', 20));
        $items = [];

        foreach ((array) ($raw['items'] ?? []) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = $row['product_id'] ?? null;
            if (! is_numeric($id) || ! $byId->has((int) $id)) {
                continue; // identifiant inventé ou d'un autre restaurant : jeté
            }
            $id = (int) $id;
            $copies = is_numeric($row['copies'] ?? null) ? (int) round((float) $row['copies']) : 1;
            $copies = min($max, max(1, $copies));
            $heard = mb_substr(trim((string) ($row['heard'] ?? '')), 0, 80);

            if (isset($items[$id])) {
                $items[$id]['copies'] = max($items[$id]['copies'], $copies);

                continue;
            }
            $items[$id] = ['product_id' => $id, 'name' => (string) $byId[$id]->name, 'copies' => $copies, 'heard' => $heard];
        }

        $unmatched = [];
        foreach ((array) ($raw['unmatched'] ?? []) as $u) {
            $u = mb_substr(trim((string) (is_scalar($u) ? $u : '')), 0, 60);
            if ($u !== '' && ! in_array($u, $unmatched, true) && count($unmatched) < 12) {
                $unmatched[] = $u;
            }
        }

        return [
            'transcript' => mb_substr(trim((string) ($raw['transcript'] ?? '')), 0, 600),
            'items' => array_values($items),
            'unmatched' => $unmatched,
        ];
    }
}

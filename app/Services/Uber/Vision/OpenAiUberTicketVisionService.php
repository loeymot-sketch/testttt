<?php

namespace App\Services\Uber\Vision;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * [UBER-PHOTO 2026-08-10 · owner] Lecture réelle du ticket Uber par un modèle de vision.
 *
 * Même famille que {@see \App\Services\Purchasing\Vision\OpenAiInvoiceVisionService} — même clé,
 * même config `services.openai`, même discipline : appel via `Http::` (donc entièrement simulable
 * par `Http::fake()`, aucun réseau en test), `temperature 0`, réponse contrainte en JSON.
 *
 * CE QUE LE PROMPT INTERDIT, ET POURQUOI
 * --------------------------------------
 * Un lecteur de ticket qui « complète » est bien pire qu'un lecteur qui laisse un trou : une
 * sauce inventée part en cuisine sans que personne ne s'en aperçoive, alors qu'un trou saute aux
 * yeux de l'humain qui valide. Le prompt exige donc de recopier ce qui est ÉCRIT, de laisser vide
 * ce qui est illisible, et de ne JAMAIS traduire un produit vers un autre nom que celui du ticket.
 * La mise en correspondance avec le catalogue se fait ensuite côté serveur, sur des règles
 * vérifiables — pas dans la tête du modèle.
 *
 * FAIL-CLOSED : sans clé, on lève une exception explicite. Le binding évite ce cas en amont
 * (doublure locale) ; c'est une seconde barrière si quelqu'un instancie cette classe directement.
 *
 * Domaine NEUF, ADDITIF, HORS NF525.
 */
class OpenAiUberTicketVisionService implements UberTicketVisionContract
{
    /**
     * Le ticket Uber est en FRANÇAIS et le restaurant est « Le Cayenne » : le prompt le dit, sinon
     * le modèle « corrige » les noms de produits vers des équivalents génériques.
     */
    private const PROMPT = <<<'TXT'
Tu lis le TICKET DE COMMANDE d'une plateforme de livraison (Uber Eats) photographié dans un
restaurant français. Plusieurs images peuvent composer UN SEUL ticket : lis-les dans l'ordre et
rends UNE seule commande, sans répéter une ligne vue sur deux photos qui se chevauchent.

Réponds en JSON STRICT, un objet unique de la forme :
{"customer_name":string,"display_id":string,"order_type":string,
 "items":[{"title":string,"quantity":number,"options":[string],"note":string}],
 "total":number}

Règles impératives :
- customer_name = le prénom/nom du CLIENT écrit sur la commande. Vide si absent.
- display_id = le numéro/code de commande affiché (ex. "#A1B2C"). Vide si absent.
- order_type = "delivery" si livraison, "pickup" si retrait au comptoir, sinon "".
- title = le nom du produit RECOPIÉ MOT POUR MOT depuis le ticket. Ne traduis pas, ne corrige
  pas, ne remplace pas par un produit approchant.
- quantity = le nombre d'exemplaires de cette ligne. ATTENTION : le ticket l'écrit EN TÊTE de la
  ligne produit, sous la forme « 2 x Cheese Burger ». Ce nombre va dans quantity, et le title ne
  le garde pas. L'oublier fait manquer un plat au client : c'est l'erreur la plus grave possible.
  Ne le confonds pas avec les « 1x » des lignes d'options en dessous, qui décrivent les choix.
  1 seulement si aucun nombre n'est écrit devant le produit.
- options = la liste des choix/suppléments/sauces/boissons imprimés SOUS le produit, un par
  entrée, recopiés tels quels.
- note = l'instruction libre du client pour cette ligne ("sans oignons"...). Vide si absente.
- total = le montant total du ticket en euros, ou omets le champ si tu ne le lis pas.
- N'INVENTE RIEN. Un champ illisible se laisse VIDE. Une ligne illisible se laisse hors du
  tableau plutôt que devinée.
TXT;

    public function driverName(): string
    {
        return 'openai';
    }

    public function readTicket(array $photoPaths): array
    {
        $key = (string) config('services.openai.key', '');

        if ($key === '') {
            throw new RuntimeException(
                'Lecture Uber indisponible : OPENAI_API_KEY absente. '
                .'Le binding doit retomber sur MockUberTicketVisionService en l\'absence de clé.'
            );
        }

        $images = [];
        foreach ($photoPaths as $path) {
            $encoded = $this->encodeImage((string) $path);
            if ($encoded !== null) {
                $images[] = ['type' => 'image_url', 'image_url' => ['url' => $encoded]];
            }
        }

        if ($images === []) {
            Log::warning('[UberTicketVision] aucune photo lisible, extraction abandonnée', ['paths' => $photoPaths]);

            return self::emptyTicket();
        }

        $response = Http::withToken($key)
            ->timeout((int) config('services.openai.timeout', 30))
            ->acceptJson()
            ->post(rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/').'/chat/completions', [
                'model' => (string) config('services.openai.model', 'gpt-4o-mini'),
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
                'messages' => [[
                    'role' => 'user',
                    'content' => array_merge([['type' => 'text', 'text' => self::PROMPT]], $images),
                ]],
            ]);

        if (! $response->successful()) {
            Log::warning('[UberTicketVision] réponse non-2xx', ['status' => $response->status()]);

            return self::emptyTicket();
        }

        return self::normalize(json_decode((string) $response->json('choices.0.message.content', ''), true));
    }

    /** Encode une photo en data-URI base64. Null si illisible — jamais d'exception ici. */
    private function encodeImage(string $photoPath): ?string
    {
        if (! is_file($photoPath) || ! is_readable($photoPath)) {
            return null;
        }

        $bytes = @file_get_contents($photoPath);
        if ($bytes === false) {
            return null;
        }

        $mime = function_exists('mime_content_type')
            ? (mime_content_type($photoPath) ?: 'image/jpeg')
            : 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    /**
     * Ramène TOUTE réponse — y compris une réponse mal formée — à la forme du contrat. Partagée
     * avec la doublure locale : les deux pilotes rendent rigoureusement la même structure, sinon
     * les tests en doublure ne prouveraient rien du chemin réel.
     *
     * @param  mixed  $decoded
     * @return array{customer_name: string, display_id: string, order_type: string, items: array<int, array{title: string, quantity: int, options: array<int,string>, note: string}>, total: float|null}
     */
    public static function normalize($decoded): array
    {
        if (! is_array($decoded)) {
            return self::emptyTicket();
        }

        $items = [];
        foreach ((array) ($decoded['items'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }
            $title = trim((string) ($line['title'] ?? $line['name'] ?? ''));
            if ($title === '') {
                continue; // une ligne sans nom n'est pas une ligne : on ne la devine pas.
            }
            $options = [];
            foreach ((array) ($line['options'] ?? []) as $opt) {
                $opt = trim((string) (is_array($opt) ? ($opt['title'] ?? $opt['name'] ?? '') : $opt));
                if ($opt !== '') {
                    $options[] = $opt;
                }
            }
            $quantity = max(1, (int) ($line['quantity'] ?? 1));

            // [QUANTITÉ EN TÊTE 2026-08-12] Filet de sécurité : le ticket Uber écrit la quantité
            // DEVANT le produit (« 2 x Cheese Burger »). Sur une vraie commande, la lecture a
            // retiré le « 2 x » du titre sans le reporter dans quantity — un burger manquait, et
            // rien à l'écran ne le signalait. On rattrape donc le préfixe s'il survit dans le
            // titre, sans jamais écraser une quantité déjà lue plus grande (pas de doublement
            // quand la lecture a fait son travail).
            if (preg_match('/^\s*(\d{1,2})\s*[x×]\s*(.+)$/iu', $title, $m)) {
                $quantity = max($quantity, (int) $m[1]);
                $title = trim($m[2]);
            }

            $items[] = [
                'title' => $title,
                'quantity' => $quantity,
                'options' => $options,
                'note' => trim((string) ($line['note'] ?? $line['special_instructions'] ?? '')),
            ];
        }

        $total = $decoded['total'] ?? null;

        return [
            'customer_name' => trim((string) ($decoded['customer_name'] ?? '')),
            'display_id' => trim((string) ($decoded['display_id'] ?? '')),
            'order_type' => strtolower(trim((string) ($decoded['order_type'] ?? ''))),
            'items' => $items,
            'total' => is_numeric($total) ? (float) $total : null,
        ];
    }

    /** @return array{customer_name: string, display_id: string, order_type: string, items: array<int,mixed>, total: null} */
    public static function emptyTicket(): array
    {
        return ['customer_name' => '', 'display_id' => '', 'order_type' => '', 'items' => [], 'total' => null];
    }
}

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
- Les titres de RUBRIQUE en capitales (PAIN, SAUCE, CRUDITÉS, BOISSON, SUPPLÉMENTS, VIANDE) ne
  sont PAS des produits : ils annoncent les options du produit écrit AU-DESSUS. Ne crée jamais
  une ligne d'items pour eux ; range ce qui les suit dans les options de ce produit.
- note = l'instruction libre du client pour cette ligne ("sans oignons"...). Vide si absente.
- total = le montant total du ticket en euros, ou omets le champ si tu ne le lis pas.
- N'INVENTE RIEN. Un champ illisible se laisse VIDE. Une ligne illisible se laisse hors du
  tableau plutôt que devinée.
TXT;

    /**
     * [RUBRIQUES 2026-08-12] Un en-tête de rubrique n'est pas un produit.
     *
     * Le ticket Uber range les choix sous des titres en capitales — PAIN, SAUCE, CRUDITÉS,
     * BOISSON, SUPPLÉMENTS. Sur une VRAIE commande (7B9F2, BOUDJEMA), la lecture en a fait des
     * LIGNES DE PRODUIT à part entière : la cuisine recevait « CRU | TO », « SUP + Chéddar » et
     * une ligne « Menu (Frites + Boisson) » née du seul mot « Boisson » — trois plats fantômes
     * pour une commande qui n'en comptait qu'un. Deux lectures du MÊME ticket ont donné des
     * structures différentes : la consigne ne suffit pas, il faut une garde déterministe.
     *
     * On replie donc la rubrique sur le produit qui la précède : son titre disparaît (il ne porte
     * aucune information), ses options rejoignent celles du produit — donc RIEN n'est perdu, ni
     * la sauce, ni la boisson, ni le supplément payé.
     *
     * Sans produit au-dessus, on ne replie pas : mieux vaut une ligne étrange et VISIBLE, que le
     * personnel corrigera à l'écran, qu'un choix client silencieusement supprimé.
     *
     * @param  list<array{title: string, quantity: int, options: list<string>, note: string}>  $items
     * @return list<array{title: string, quantity: int, options: list<string>, note: string}>
     */
    private static function replierLesRubriques(array $items): array
    {
        $sortie = [];

        foreach ($items as $item) {
            if (self::estIntituleDeRubrique((string) $item['title']) && $sortie !== []) {
                $dernier = count($sortie) - 1;
                foreach ($item['options'] as $o) {
                    $sortie[$dernier]['options'][] = $o;
                }
                if (($item['note'] ?? '') !== '') {
                    $sortie[$dernier]['note'] = trim($sortie[$dernier]['note'].' '.$item['note']);
                }

                continue;
            }

            $sortie[] = $item;
        }

        return $sortie;
    }

    /**
     * Une étiquette de rubrique SANS valeur — « CRUDITÉS » seul, pas « CRUDITÉS : Salade ».
     *
     * ⚠️ LA CASSE EST LE SEUL DISCRIMINANT FIABLE, et l'oublier coûte cher : « Pain » est à la
     * fois un titre de rubrique ET un vrai choix de la carte (Pain ou Galette). Une première
     * version filtrait sans regarder la casse : elle a effacé le pain d'un menu réel, mesuré sur
     * la commande E63F5 — la cuisine ne savait plus sur quoi servir le sandwich. Le ticket, lui,
     * imprime la rubrique en CAPITALES et la valeur en casse normale. On s'aligne sur le papier.
     */
    private static function estEtiquetteNue(string $texte): bool
    {
        $brut = trim($texte);

        // Une valeur (« Pain », « 1x Pain ») n'est jamais tout en capitales sur le ticket.
        if ($brut !== mb_strtoupper($brut, 'UTF-8')) {
            return false;
        }

        return self::estIntituleDeRubrique($brut);
    }

    /**
     * Les mots que le ticket Uber imprime pour ANNONCER des choix — jamais pour vendre un plat.
     *
     * Liste validée par l'owner le 2026-08-12 ; on n'y ajoute AUCUN mot de produit. « Frites »,
     * « Menu Enfant Nuggets », « Boisson Seule » sont de VRAIS articles de la carte : les y faire
     * entrer replierait une ligne payée sur la précédente, donc effacerait un plat vendu.
     */
    private const MOTS_RUBRIQUE = 'pains?|sauces?|crudites?|boissons?|supplements?|garnitures?|viandes?|accompagnements?|choix|options?|extras?';

    /**
     * [RUBRIQUES 2026-08-20 · owner] L'INTITULÉ d'une rubrique, DÉCORATIONS COMPRISES.
     *
     * La liste de mots n'était pas le problème : la NORMALISATION l'était. `sansAccents()` retire
     * la ponctuation mais laisse l'espace qui la précédait — « SAUCE : » devenait « sauce » suivi
     * d'une espace, que l'ancre `$` refusait, et « SAUCES (2) » devenait « sauces 2 ». Ces deux
     * formes, imprimées telles quelles par Uber, passaient donc AU TRAVERS des deux gardes de
     * 2026-08-12 : au niveau option elles ressortaient en supplément fantôme « + SAUCE », au
     * niveau ligne elles devenaient un PRODUIT que la carte ne peut évidemment pas reconnaître —
     * d'où le « ART » que l'owner voit sur chaque ticket scanné. Sa consigne est nette : ces
     * mots-là n'ont rien à faire sur le papier, seuls les codes techniques de la caisse comptent.
     *
     * L'élargissement se borne à ce qui se construit AVEC la même liste : un compte en fin
     * d'étiquette (« SAUCES (2) »), une tournure de choix (« CHOIX DE LA SAUCE »), une qualité
     * (« CRUDITÉS OFFERTES ») et la paire (« SAUCES ET CRUDITÉS »). Le mot de tête reste
     * obligatoirement un mot de {@see self::MOTS_RUBRIQUE} : « Boisson Seule » (article #3 de la
     * carte) ne matche pas, « seule » n'étant aucune de ces qualités.
     */
    private static function estIntituleDeRubrique(string $texte): bool
    {
        $n = trim((string) preg_replace('/\s+/', ' ', self::sansAccents(trim($texte))));
        // « SAUCES (2) » → « sauces 2 » → « sauces » : un compte n'est pas une valeur.
        $n = trim((string) preg_replace('/ \d+$/', '', $n));

        if ($n === '') {
            return false;
        }

        $mot = '(?:'.self::MOTS_RUBRIQUE.')';
        $tete = '(?:(?:choix|selection)(?: de(?: la| l| les)?| des| du)?\s+|(?:vos|votre|le|la|les)\s+)?';
        $queue = '(?:\s+(?:au choix|offerte?s?|incluse?s?|gratuite?s?|payante?s?|supplementaires?|obligatoires?|facultatives?|en plus))?';

        return (bool) preg_match('/^'.$tete.$mot.'(?:\s+et\s+'.$mot.')?'.$queue.'$/u', $n);
    }

    /** Minuscules ASCII : « CRUDITÉS » et « crudites » doivent se reconnaître. */
    private static function sansAccents(string $s): string
    {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);

        return is_string($t) ? preg_replace('/[^a-z0-9\s]/', '', mb_strtolower($t)) ?? $s : $s;
    }

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
                if ($opt === '') {
                    continue;
                }
                // [RUBRIQUES 2026-08-12] Un en-tête SEUL n'est pas un choix du client. Mesuré sur
                // la commande réelle E63F5 : la lecture rendait « PAIN », « SAUCE », « CRUDITÉS »,
                // « BOISSON » en plus de leurs valeurs, et la cuisine recevait « + SAUCE
                // + CRUDITÉS » — deux suppléments fantômes par ligne, à côté des vrais.
                // On ne jette QUE l'étiquette nue : « PAIN: Galette » porte une valeur, il reste.
                // Aucune information ne se perd, la valeur suit toujours sur la ligne d'après.
                if (self::estEtiquetteNue($opt)) {
                    continue;
                }
                $options[] = $opt;
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

        $items = self::replierLesRubriques($items);

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

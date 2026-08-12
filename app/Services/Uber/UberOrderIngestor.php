<?php

namespace App\Services\Uber;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * [UBER-PHOTO 2026-08-10] CRÉATION d'une commande de canal Uber — chemin UNIQUE.
 *
 * POURQUOI CE SERVICE EXISTE
 * --------------------------
 * Deux portes mènent désormais à la même commande : le webhook Uber (le jour où la plateforme
 * accordera l'accès production) et la PHOTO du ticket prise sur la tablette (qui fonctionne dès
 * aujourd'hui). Elles doivent créer la commande RIGOUREUSEMENT de la même façon.
 *
 * Ce code porte une année de corrections durement acquises — anti-doublon au niveau commande,
 * collision de numéro d'appel, ancrage d'un utilisateur technique, commande annulée avant sa
 * création, diffusion temps réel et décrément de stock. Les dupliquer pour le canal photo aurait
 * garanti que l'une des deux copies perde, tôt ou tard, l'une de ces protections. C'est très
 * exactement le défaut dominant relevé par les audits de ce projet : « un correctif appliqué à
 * une moitié du mécanisme, pas à sa jumelle ». Il n'y a donc qu'un seul mécanisme.
 *
 * NF525 : canal NON FISCALISÉ par défaut (`uber.fiscalize`), comme avant — Uber facture à part.
 * Aucune séquence fiscale n'est allouée ici, aucune chaîne d'audit n'est touchée.
 */
final class UberOrderIngestor
{
    /**
     * Crée la commande, ou retourne celle qui existe déjà.
     *
     * @param  array{display_id?:string, queue_number?:?string, items?:array<int,array>, total?:float, customer_name?:string, order_type?:string}  $mapped
     * @param  string  $dedupKey  clé d'idempotence AU NIVEAU COMMANDE, stockée dans `transaction_id`
     *                            (« uber:<id> » pour le webhook, « uber-photo:<sha256> » pour la photo)
     * @param  array{tombstone_key?:?string}  $opts
     * @return int|null  id de la commande, ou null si une annulation l'a précédée
     */
    public function ingest(array $mapped, string $dedupKey, array $opts = []): ?int
    {
        // Idempotence AU NIVEAU COMMANDE : deux signaux pour la MÊME commande ne créent qu'une
        // seule ligne. Sans branch-fence : l'appel peut venir d'un job sans utilisateur.
        $existing = Order::withoutGlobalScopes()->where('transaction_id', $dedupKey)->first();
        if ($existing) {
            return (int) $existing->id;
        }

        // Une annulation arrivée AVANT la création (pierre tombale) interdit de créer : sinon on
        // ressusciterait une commande annulée — stock décrémenté, cuisine mobilisée pour rien.
        $tombstone = $opts['tombstone_key'] ?? null;
        if (is_string($tombstone) && $tombstone !== '' && DB::table('webhook_events')
            ->where('provider', 'uber_eats')->where('webhook_id', $tombstone)->exists()) {
            Log::info('[UberOrderIngestor] création ignorée — annulée AVANT création', ['dedup' => $dedupKey]);

            return null;
        }

        $branchId = (int) config('uber.branch_id', 1);
        $userId = $this->uberSystemUserId($branchId);
        $baseQueue = (string) ($mapped['queue_number'] ?? '');

        // Le numéro d'appel n'est PAS unique par nature (il dérive d'un identifiant tronqué) alors
        // que la base porte un index UNIQUE (branche, date, numéro d'appel). Sans cette boucle, la
        // deuxième commande du jour qui tombe sur le même suffixe serait PERDUE. On désambiguïse
        // et on retente ; on ne relève qu'après épuisement.
        for ($attempt = 0; $attempt <= 5; $attempt++) {
            $queue = $attempt === 0 ? $baseQueue : ($baseQueue.'-'.$attempt);
            try {
                $order = DB::transaction(function () use ($mapped, $dedupKey, $branchId, $userId, $queue) {
                    $order = (new Order)->forceFill($this->orderAttributes($mapped, $dedupKey, $branchId, $userId, $queue));
                    $order->save();

                    foreach ((array) ($mapped['items'] ?? []) as $line) {
                        (new OrderItem)->forceFill([
                            'order_id' => $order->id,
                            'branch_id' => $branchId,
                            'item_id' => $line['item_id'],
                            'quantity' => $line['quantity'],
                            'price' => $line['unit_price'] ?? 0,
                            'discount' => 0,
                            'total_price' => $line['total'] ?? 0,
                            'composition_snapshot' => $line['composition_snapshot'] ?? null,
                            'instruction' => $line['instruction'] ?? null,
                        ])->save();
                    }

                    Log::info('[UberOrderIngestor] commande créée', [
                        'order_id' => $order->id, 'dedup' => $dedupKey, 'total' => $mapped['total'] ?? 0,
                    ]);

                    return $order;
                });
            } catch (QueryException $e) {
                if (! $this->isUniqueViolation($e)) {
                    throw $e; // erreur DB réelle → on ne l'avale pas.
                }
                // (a) Insert concurrent de la MÊME commande → dédup vrai, on la retourne.
                $existing = Order::withoutGlobalScope(BranchScope::class)->where('transaction_id', $dedupKey)->first();
                if ($existing) {
                    return (int) $existing->id;
                }
                // (b) AUTRE commande, même numéro d'appel → l'itération suivante désambiguïse.
                if ($attempt >= 5) {
                    throw $e;
                }

                continue;
            }

            // Diffusion temps réel + décrément stock/disponibilité + impression cuisine : c'est
            // cet événement qui branche la commande sur tout le reste du système. Le chemin
            // « déjà existante » retourne avant d'arriver ici → jamais diffusé deux fois.
            \App\Events\OrderCreated::dispatch($order);

            return (int) $order->id;
        }

        throw new \RuntimeException('Uber : résolution du numéro d\'appel impossible pour '.$dedupKey);
    }

    /** @return array<string, mixed> */
    private function orderAttributes(array $mapped, string $dedupKey, int $branchId, int $userId, string $queue): array
    {
        $total = (float) ($mapped['total'] ?? 0);
        $isPickup = str_contains(strtolower((string) ($mapped['order_type'] ?? '')), 'pickup');

        return [
            'branch_id' => $branchId,
            // `orders.user_id` est NOT NULL : sans cette ancre, AUCUNE commande Uber ne peut être
            // créée. L'ancre est un utilisateur technique sans aucun rôle.
            'user_id' => $userId,
            // Retrait au comptoir vs livraison : le ticket cuisine l'affiche, et l'emballage
            // n'est pas le même.
            'order_type' => $isPickup ? \App\Enums\OrderType::TAKEAWAY : \App\Enums\OrderType::DELIVERY,
            'source' => \App\Enums\Source::WEB, // canal API/web ; Uber se distingue par source_surface
            'source_surface' => 'uber_eats',
            'status' => \App\Enums\OrderStatus::ACCEPT, // visible cuisine immédiatement (déjà payée)
            'payment_status' => \App\Enums\PaymentStatus::PAID, // Uber prépaie
            // Commande IMMÉDIATE : sans ce champ, le défaut de la base la classe en PRÉCOMMANDE et
            // l'écran cuisine la range à demain.
            'is_advance_order' => \App\Enums\Ask::NO,
            // Date d'exploitation posée → l'index UNIQUE (branche, date, numéro) devient un vrai
            // verrou anti-doublon concurrent (sous MySQL, deux NULL sont distincts).
            'business_date' => now()->toDateString(),
            'total' => $total,
            'subtotal' => $total,
            'discount' => 0,
            'queue_number' => $queue,
            'order_serial_no' => (string) ($mapped['display_id'] ?? ''),
            'transaction_id' => $dedupKey,
            'order_datetime' => now(),
            // [UBER-PHOTO 2026-08-10 · owner « le nom du client »] Le nom lu sur le ticket est
            // reporté sur le canal que la cuisine et la caisse affichent déjà. Avant, il était
            // extrait puis JETÉ : le cuisinier voyait « U1234 » sans savoir pour qui il préparait.
            'pos_customer_name' => mb_substr(trim((string) ($mapped['customer_name'] ?? '')), 0, 60) ?: null,
        ];
    }

    /**
     * Violation d'unicité MySQL/SQLite ? (index UNIQUE (branche, date, numéro d'appel)).
     * SQLSTATE 23000 + code moteur 1062 (MySQL) / 19 ou 2067 (SQLite selon le driver de test).
     */
    public function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $sqlState === '23000'
            || in_array($driverCode, [1062, 19, 2067], true)
            || str_contains(strtolower($e->getMessage()), 'unique');
    }

    /** Utilisateur technique Uber (non privilégié, aucun rôle) — ancre pour `orders.user_id`. */
    public function uberSystemUserId(int $branchId): int
    {
        // Branch-fence uniquement : le scope de suppression douce reste actif pour ne pas
        // ressusciter une ligne effacée.
        $user = User::withoutGlobalScope(BranchScope::class)->firstOrCreate(
            ['username' => 'uber-eats-system'],
            [
                'name' => 'Uber Eats',
                'email' => 'uber-eats@system.local',
                'phone' => '0000000042',
                'password' => bcrypt(bin2hex(random_bytes(16))),
                'branch_id' => $branchId,
            ]
        );

        return (int) $user->id;
    }
}

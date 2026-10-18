<?php

namespace App\Services\Hygiene;

use App\Mail\HygieneAlertMail;
use App\Models\HygieneAlertDispatch;
use App\Models\HygieneAlertSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia P0 « alertes hors écran »] Prévient les responsables par e-mail quand un rappel
 * « à traiter maintenant » existe, SANS qu'ils aient ouvert l'écran.
 *
 * Ce qui est envoyé : les rappels de niveau `alert` calculés par {@see Reminders} (une seule vérité : même texte que l'accueil), un
 * seul e-mail récapitulatif par destinataire. Ce qui ne part JAMAIS : rien tant que le responsable n'a pas activé les alertes ;
 * rien hors de la plage d'envoi (pas à 3 h du matin) ; pas plus de `max_sends` messages par rappel et par jour, espacés de
 * `repeat_minutes` (relance tant que le rappel dure, jamais en rafale). Chaque envoi, réussi ou non, est écrit au journal.
 *
 * V1 : un seul restaurant (branche `config('hygiene.branch_id')`). Le canal e-mail est celui déjà configuré (SMTP) ; d'autres canaux
 * (SMS, messagerie) se brancheront ici sans toucher au calcul des rappels.
 */
final class AlertNotifier
{
    /** Les domaines qui peuvent lever un rappel URGENT. La traçabilité (« rien tracé depuis N jours ») reste un rappel sans urgence : à l'écran seulement. */
    public const DOMAINS = ['temperature', 'tasks', 'oil', 'dlc'];
    /** Panne de messagerie : on retente avec un délai qui double (10, 20, 40, 80 min, plafonné à 2 h) ; après ce nombre de tours sans succès, on renonce pour la journée. */
    private const MAX_FAILURE_ROUNDS = 8;
    /** Tolérance sur l'espacement des relances : un passage du planificateur à quelques secondes près ne décale pas une relance de 5 minutes. */
    private const REPEAT_TOLERANCE_SECONDS = 90;

    public function __construct(private readonly ?Reminders $reminders = null) {}

    public static function branchId(): int
    {
        return (int) config('hygiene.branch_id', 1);
    }

    /** Réglages de la branche (valeurs par défaut si rien n'a été enregistré). @return array<string,mixed> */
    public static function settings(?int $branch = null): array
    {
        $row = HygieneAlertSetting::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->where('branch_id', $branch ?? self::branchId())->first();
        $emails = is_array($row?->recipients) ? $row->recipients : [];

        return [
            'enabled' => (bool) ($row->enabled ?? false),
            'recipients' => array_values(array_filter($emails, fn ($e) => is_string($e) && filter_var($e, FILTER_VALIDATE_EMAIL))),
            'domains' => is_array($row?->domains) ? array_values(array_intersect(self::DOMAINS, $row->domains)) : self::DOMAINS,
            'send_from' => $row->send_from ?? '09:00',
            'send_until' => $row->send_until ?? '23:30',
            'repeat_minutes' => (int) ($row->repeat_minutes ?? 60),
            'max_sends' => (int) ($row->max_sends ?? 3),
        ];
    }

    /** Un canal e-mail réel est-il configuré ? (`log` et `array` n'envoient rien.) */
    public static function mailReady(): bool
    {
        return ! in_array((string) config('mail.default'), ['log', 'array', 'null', ''], true);
    }

    /**
     * Un passage (appelé toutes les 5 minutes par le planificateur).
     *
     * @return array{status:string, due:int, sent:int, failed:int}
     */
    public function run(?CarbonInterface $now = null): array
    {
        $now = $now ?? now();
        $branch = self::branchId();
        $out = fn (string $status, int $due = 0, int $sent = 0, int $failed = 0): array => compact('status', 'due', 'sent', 'failed');

        // Un seul passage à la fois : le planificateur ET un lancement à la main ne doivent jamais envoyer deux fois le même courriel.
        $lock = Cache::lock('hygiene:notify:'.$branch, 600);
        if (! $lock->get()) return $out('busy');
        try {
            return $this->runLocked($now, $branch, $out);
        } finally {
            $lock->release();
        }
    }

    private function runLocked(CarbonInterface $now, int $branch, \Closure $out): array
    {
        $s = self::settings($branch);
        if (! $s['enabled']) return $out('disabled');
        if ($s['recipients'] === []) return $out('no_recipients');
        if (! $this->insideWindow($now, $s['send_from'], $s['send_until'])) return $out('outside_window');

        $items = array_values(array_filter(
            ($this->reminders ?? new Reminders)->all($now),
            fn (array $i): bool => $i['level'] === 'alert' && in_array($i['domain'], $s['domains'], true)
        ));
        $due = array_values(array_filter($items, fn (array $i): bool => $this->isDue($branch, $i['key'], $now, $s)));
        if ($due === []) return $out('nothing_due');

        [$sent, $failed] = $this->deliver($branch, $due, $s['recipients'], $now, HygieneAlertDispatch::STATUS_SENT);

        return $out('done', count($due), $sent, $failed);
    }

    /** Message d'essai : part tout de suite vers les destinataires enregistrés (action volontaire du responsable). @return array{sent:int, failed:int} */
    public function sendTest(?CarbonInterface $now = null): array
    {
        $now = $now ?? now();
        $s = self::settings();
        $item = ['key' => 'test', 'domain' => 'temperature', 'level' => 'todo', 'text' => 'Ceci est un message de test : les alertes de Cayenne Hygiène arriveront à cette adresse.', 'route' => '/admin/hygiene'];
        [$sent, $failed] = $this->deliver(self::branchId(), [$item], $s['recipients'], $now, HygieneAlertDispatch::STATUS_TEST);

        return compact('sent', 'failed');
    }

    private function insideWindow(CarbonInterface $now, string $from, string $until): bool
    {
        $hm = $now->format('H:i');

        return $hm >= $from && $hm <= $until;
    }

    /**
     * Premier envoi du jour ; ensuite relance tous les `repeat_minutes` (à `REPEAT_TOLERANCE_SECONDS` près), au plus `max_sends` fois. Tant que RIEN n'est
     * parti (messagerie en panne), on retente avec un délai qui double, et on ne renonce qu'après `MAX_FAILURE_ROUNDS` tours.
     */
    private function isDue(int $branch, string $key, CarbonInterface $now, array $s): bool
    {
        $rows = HygieneAlertDispatch::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->where('branch_id', $branch)->whereDate('day', $now->toDateString())
            ->where('reminder_key', $key)->get(['status', 'sent_at', 'recipient', 'created_at']);
        $sentAt = $rows->where('status', HygieneAlertDispatch::STATUS_SENT)->pluck('sent_at')->filter()->map(fn ($d) => $d->timestamp)->unique()->sort()->values();

        if ($sentAt->isEmpty()) {
            $failed = $rows->where('status', HygieneAlertDispatch::STATUS_FAILED);
            if ($failed->isEmpty()) return true;
            $rounds = (int) ceil($failed->count() / max(1, count($s['recipients'])));
            if ($rounds >= self::MAX_FAILURE_ROUNDS) return false;
            $wait = min(120, 10 * (2 ** ($rounds - 1)));

            return $failed->max('created_at')->copy()->addMinutes($wait)->lte($now);
        }
        if ($sentAt->count() >= $s['max_sends']) return false;

        return $sentAt->last() <= $now->copy()->subMinutes($s['repeat_minutes'])->timestamp + self::REPEAT_TOLERANCE_SECONDS;
    }

    /**
     * Un e-mail récapitulatif par destinataire ; une ligne de journal par (rappel, destinataire).
     *
     * @param list<array<string,mixed>> $items
     * @param list<string> $recipients
     * @return array{0:int,1:int}
     */
    private function deliver(int $branch, array $items, array $recipients, CarbonInterface $now, string $okStatus): array
    {
        $sent = 0;
        $failed = 0;
        foreach ($recipients as $to) {
            $error = null;
            try {
                Mail::to($to)->send(new HygieneAlertMail($items, $now, $okStatus === HygieneAlertDispatch::STATUS_TEST));
            } catch (\Throwable $e) {
                $error = mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 190) ?: 'Envoi impossible';
            }
            $error === null ? $sent++ : $failed++;
            foreach ($items as $i) {
                HygieneAlertDispatch::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->create([
                    'branch_id' => $branch, 'day' => $now->toDateString(), 'reminder_key' => mb_substr($i['key'], 0, 80),
                    'domain' => $i['domain'], 'level' => $i['level'], 'text' => mb_substr($i['text'], 0, 300), 'channel' => 'mail',
                    'recipient' => mb_substr($to, 0, 190), 'status' => $error === null ? $okStatus : HygieneAlertDispatch::STATUS_FAILED,
                    'error' => $error, 'sent_at' => $error === null ? $now : null,
                ]);
            }
        }

        return [$sent, $failed];
    }
}

<?php

namespace App\Http\Controllers\Admin\Hygiene;

use App\Models\HygieneAlertDispatch;
use App\Models\HygieneAlertSetting;
use App\Services\Hygiene\AlertNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia P0] Réglage des alertes d'hygiène envoyées par e-mail aux responsables.
 *
 * Réservé à la permission `settings` (le responsable) EN PLUS de la porte du module : n'importe quel membre du personnel qui tient la
 * tablette ne choisit pas qui reçoit les alertes. Désactivé par défaut. Le journal des envois est en ajout seul.
 */
class AlertController extends HygieneController
{
    public const DOMAIN_LABELS = [
        'temperature' => 'Relevés de température',
        'tasks' => 'Nettoyage',
        'oil' => 'Huile de friture',
        'dlc' => 'DLC dépassées',
    ];

    public function __construct()
    {
        parent::__construct();

        $this->middleware(['permission:settings']);
    }

    public function show(Request $request): JsonResponse
    {
        $branch = AlertNotifier::branchId();
        $staff = $this->staffQuery($request)->whereNotNull('email')->where('email', '<>', '')->orderBy('name')->get(['id', 'name', 'email'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $this->cleanName($u->name), 'email' => $u->email])->values();
        $recent = HygieneAlertDispatch::withoutGlobalScopes()->where('branch_id', $branch)->orderByDesc('id')->limit(100)->get()
            ->map(fn (HygieneAlertDispatch $d) => [
                'id' => $d->id, 'at' => ($d->sent_at ?? $d->created_at)->toIso8601String(), 'recipient' => $d->recipient, 'status' => $d->status,
                'domain' => $d->domain, 'text' => $d->text, 'error' => $d->error,
            ])->values();

        return response()->json([
            'data' => ['settings' => AlertNotifier::settings($branch), 'staff' => $staff, 'recent' => $recent],
            'meta' => [
                'mail_ready' => AlertNotifier::mailReady(),
                'domains' => collect(self::DOMAIN_LABELS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            ] + $this->clock(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $hm = ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];
        $v = $request->validate([
            'enabled' => ['required', 'boolean'],
            'recipients' => ['present', 'array', 'max:10'],
            'recipients.*' => ['required', 'string', 'email:filter', 'max:190'],
            'domains' => ['required', 'array', 'min:1'],
            'domains.*' => [Rule::in(AlertNotifier::DOMAINS)],
            'send_from' => $hm,
            'send_until' => $hm,
            'repeat_minutes' => ['required', 'integer', 'between:15,240'],
            'max_sends' => ['required', 'integer', 'between:1,6'],
        ], [
            'recipients.*.email' => 'Cette adresse e-mail n\'est pas valide.',
            'recipients.max' => 'Dix destinataires au plus.',
            'domains.min' => 'Choisissez au moins un type d\'alerte.',
            'send_from.regex' => 'Heure de début illisible (exemple : 09:00).',
            'send_until.regex' => 'Heure de fin illisible (exemple : 23:30).',
        ]);

        $recipients = array_values(array_unique(array_map(fn ($e) => mb_strtolower(trim($e)), $v['recipients'])));
        if ($v['send_from'] >= $v['send_until']) {
            return $this->fail('send_until', 'L\'heure de fin doit être après l\'heure de début.');
        }
        if ($v['enabled'] && $recipients === []) {
            return $this->fail('recipients', 'Ajoutez au moins une adresse e-mail pour activer les alertes.');
        }
        if ($v['enabled'] && ! AlertNotifier::mailReady()) {
            return $this->fail('enabled', 'Aucune messagerie n\'est configurée sur ce serveur : les alertes ne partiraient pas. Le responsable technique doit d\'abord régler l\'envoi d\'e-mails.');
        }

        $branch = AlertNotifier::branchId();
        $row = HygieneAlertSetting::withoutGlobalScopes()->firstOrNew(['branch_id' => $branch]);
        $row->forceFill([
            'branch_id' => $branch, 'enabled' => $v['enabled'], 'recipients' => $recipients,
            'domains' => array_values(array_intersect(AlertNotifier::DOMAINS, $v['domains'])),
            'send_from' => $v['send_from'], 'send_until' => $v['send_until'],
            'repeat_minutes' => $v['repeat_minutes'], 'max_sends' => $v['max_sends'],
        ])->save();

        return response()->json(['data' => ['settings' => AlertNotifier::settings($branch)]]);
    }

    /** Envoie un message d'essai aux destinataires ENREGISTRÉS (action volontaire : le responsable voit l'e-mail arriver). */
    public function test(Request $request): JsonResponse
    {
        $s = AlertNotifier::settings();
        if ($s['recipients'] === []) {
            return $this->fail('recipients', 'Enregistrez d\'abord au moins une adresse e-mail.');
        }
        if (! AlertNotifier::mailReady()) {
            return $this->fail('enabled', 'Aucune messagerie n\'est configurée sur ce serveur.');
        }
        // un essai toutes les 30 secondes au plus : le bouton ne sert pas à inonder une boîte (les destinataires peuvent être externes)
        if (! \Illuminate\Support\Facades\Cache::add('hygiene:alert-test:'.AlertNotifier::branchId(), 1, 30)) {
            return $this->fail('recipients', 'Un essai vient de partir : patientez 30 secondes avant le suivant.', 429);
        }
        $r = app(AlertNotifier::class)->sendTest();

        return response()->json(['data' => $r], $r['failed'] > 0 && $r['sent'] === 0 ? 502 : 200);
    }

    private function fail(string $field, string $message, int $status = 422): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]]], $status);
    }
}

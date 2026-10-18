<?php

namespace App\Services\Hygiene;

use App\Models\HygieneOperatorPin;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia P1 « Qui ? sans code »] Codes personnels des employés et jetons de signature.
 *
 * Le code se vérifie UNE fois (au moment de choisir son nom sur la tablette) ; le serveur remet alors un JETON signé, valable
 * `hygiene.operator.token_minutes`, que la tablette joint aux écritures à la place du code : le code n'est jamais gardé sur la tablette,
 * pas même dans la file hors ligne. Un jeton ne vaut que pour UNE personne, UNE branche, et la fenêtre [émission, expiration] ; il meurt dès
 * que le code est changé ou retiré. Pour un relevé gardé hors ligne, c'est l'heure de la MESURE qui doit tomber dans cette fenêtre.
 *
 * Essais limités : `max_failures` codes faux de suite verrouillent la personne `lock_minutes` minutes (aucun indice sur la validité du code
 * pendant le verrou). Vérification sous verrou de ligne : des essais en parallèle ne contournent pas le plafond.
 */
final class OperatorPins
{
    public static function maxFailures(): int { return (int) config('hygiene.operator.max_failures', 5); }
    public static function lockMinutes(): int { return (int) config('hygiene.operator.lock_minutes', 10); }
    public static function tokenMinutes(): int { return (int) config('hygiene.operator.token_minutes', 10); }

    private function row(int $branch, int $userId): ?HygieneOperatorPin
    {
        return HygieneOperatorPin::withoutGlobalScopes()->where('branch_id', $branch)->where('user_id', $userId)->first();
    }

    public function has(int $branch, int $userId): bool
    {
        return $this->row($branch, $userId) !== null;
    }

    /** @return array<int,array{set_at:string, locked:bool}> user_id → état (jamais le code) */
    public function states(int $branch, ?CarbonInterface $now = null): array
    {
        $now = $now ?? now();

        return HygieneOperatorPin::withoutGlobalScopes()->where('branch_id', $branch)->get()
            ->mapWithKeys(fn (HygieneOperatorPin $r) => [$r->user_id => ['set_at' => $r->updated_at->toIso8601String(), 'locked' => $r->locked_until !== null && $r->locked_until->gt($now)]])
            ->all();
    }

    public function set(int $branch, int $userId, string $pin, ?int $by): void
    {
        $row = $this->row($branch, $userId) ?? new HygieneOperatorPin(['branch_id' => $branch, 'user_id' => $userId]);
        $row->forceFill(['branch_id' => $branch, 'pin_hash' => Hash::make($pin), 'failed_attempts' => 0, 'locked_until' => null, 'set_by' => $by])->save();
        $row->touch(); // un code redéfini à l'identique invalide quand même les jetons d'avant
    }

    public function remove(int $branch, int $userId): void
    {
        HygieneOperatorPin::withoutGlobalScopes()->where('branch_id', $branch)->where('user_id', $userId)->delete();
    }

    /**
     * @return array{result:string, remaining:int, locked_minutes:int, token?:string, expires_at?:string}
     *         result : ok | none (aucun code : libre) | invalid | locked
     */
    public function verify(int $branch, int $userId, string $pin, ?CarbonInterface $now = null): array
    {
        $now = $now ?? now();

        return DB::transaction(function () use ($branch, $userId, $pin, $now): array {
            $row = HygieneOperatorPin::withoutGlobalScopes()->where('branch_id', $branch)->where('user_id', $userId)->lockForUpdate()->first();
            if (! $row) return ['result' => 'none', 'remaining' => 0, 'locked_minutes' => 0];
            if ($row->locked_until !== null && $row->locked_until->gt($now)) {
                return ['result' => 'locked', 'remaining' => 0, 'locked_minutes' => (int) max(1, ceil($now->diffInSeconds($row->locked_until) / 60))];
            }
            if (Hash::check($pin, $row->pin_hash)) {
                $this->bookkeeping($row, ['failed_attempts' => 0, 'locked_until' => null]);
                $exp = $now->copy()->addMinutes(self::tokenMinutes());

                return ['result' => 'ok', 'remaining' => self::maxFailures(), 'locked_minutes' => 0, 'token' => $this->issue($branch, $userId, (int) $row->updated_at->timestamp, $now, $exp), 'expires_at' => $exp->toIso8601String()];
            }
            $failed = $row->failed_attempts + 1;
            if ($failed >= self::maxFailures()) {
                $this->bookkeeping($row, ['failed_attempts' => 0, 'locked_until' => $now->copy()->addMinutes(self::lockMinutes())]);

                return ['result' => 'locked', 'remaining' => 0, 'locked_minutes' => self::lockMinutes()];
            }
            $this->bookkeeping($row, ['failed_attempts' => $failed]);

            return ['result' => 'invalid', 'remaining' => self::maxFailures() - $failed, 'locked_minutes' => 0];
        });
    }

    /** Compte les essais SANS toucher updated_at : la date du code ne change que quand le code change (sinon le mauvais essai d'un tiers tuerait les jetons). */
    private function bookkeeping(HygieneOperatorPin $row, array $attrs): void
    {
        $row->timestamps = false;
        $row->forceFill($attrs)->save();
        $row->timestamps = true;
    }

    /** ok | invalid (forgé, autre personne, code changé depuis) | expired (le geste est hors de la fenêtre du jeton). */
    public function checkToken(int $branch, int $userId, string $token, CarbonInterface $at): string
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) return 'invalid';
        [$body, $sig] = $parts;
        if (! hash_equals($this->sign($body), $sig)) return 'invalid';
        $p = json_decode((string) base64_decode(strtr($body, '-_', '+/'), true), true);
        if (! is_array($p) || ($p['b'] ?? null) !== $branch || ($p['u'] ?? null) !== $userId) return 'invalid';
        $row = $this->row($branch, $userId);
        if (! $row || (int) $row->updated_at->timestamp !== (int) ($p['v'] ?? -1)) return 'invalid'; // code changé ou retiré depuis
        $t = $at->timestamp;

        return ($t >= ($p['i'] ?? PHP_INT_MAX) - 5 && $t <= ($p['e'] ?? 0)) ? 'ok' : 'expired';
    }

    private function issue(int $branch, int $userId, int $version, CarbonInterface $iat, CarbonInterface $exp): string
    {
        $body = rtrim(strtr(base64_encode(json_encode(['b' => $branch, 'u' => $userId, 'v' => $version, 'i' => $iat->timestamp, 'e' => $exp->timestamp])), '+/', '-_'), '=');

        return $body.'.'.$this->sign($body);
    }

    private function sign(string $body): string
    {
        return hash_hmac('sha256', $body, 'hygiene-operator|'.config('app.key'));
    }
}

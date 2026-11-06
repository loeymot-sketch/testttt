<?php

namespace App\Services\Horaires;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Smartisan\Settings\Facades\Settings;

/**
 * Horaires d'ouverture du restaurant — LA source unique (2026-11-05, demande propriétaire « ajoute le réglage des
 * horaires dans l'admin »).
 *
 * Avant : trois lecteurs de `kds.scheduled_window_open/close` (une seule plage 18:00 → 00:30, tous les jours, dans le
 * .env : commandes programmées `OrderRequest`, tournées de livraison `DeliveryWaveService`, « service ouvert » du site
 * `WaitEstimateService`) et « Ouvert 18h — 00h » écrit EN DUR dans l'application. Changer une heure ou fermer un soir
 * demandait une nouvelle version de l'application.
 *
 * Maintenant : réglage admin (Paramètres › Horaires), groupe de réglages `horaires` :
 *   - `semaine`    : pour chaque jour ISO (1 = lundi … 7 = dimanche) { ouvert, ouverture 'HH:MM', fermeture 'HH:MM' } ;
 *                    une fermeture ≤ ouverture ENJAMBE minuit (18:00 → 00:30 finit le lendemain à 00:30) ;
 *   - `fermetures` : fermetures exceptionnelles [{ du 'AAAA-MM-JJ', au 'AAAA-MM-JJ', message }] (dates de SERVICE).
 * Sans réglage enregistré : chaque jour ouvert de `kds.scheduled_window_open` à `kds.scheduled_window_close` —
 * exactement le comportement d'avant, rien ne change tant que le restaurant n'a rien saisi.
 *
 * Règle d'appartenance (identique aux trois lecteurs d'avant) : un instant est « en service » s'il est entre
 * l'ouverture et la fermeture INCLUSES d'un service — celui du jour, ou celui de la veille qui passe minuit.
 * Un service tombe s'il est ce jour-là dans une fermeture exceptionnelle.
 */
final class HorairesService
{
    public const GROUPE = 'horaires';

    public const JOURS = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

    /** @return array<int, array{ouvert: bool, ouverture: string, fermeture: string}> clés 1..7 (ISO) */
    public function semaine(): array
    {
        $defaut = [
            'ouvert'    => true,
            'ouverture' => self::hm((string) config('kds.scheduled_window_open', '18:00'), '18:00'),
            'fermeture' => self::hm((string) config('kds.scheduled_window_close', '00:30'), '00:30'),
        ];
        $brut = $this->lire('semaine');
        $out = [];
        foreach (array_keys(self::JOURS) as $iso) {
            $j = is_array($brut) && isset($brut[$iso]) && is_array($brut[$iso]) ? $brut[$iso] : (is_array($brut) && isset($brut[(string) $iso]) && is_array($brut[(string) $iso]) ? $brut[(string) $iso] : null);
            $out[$iso] = $j === null ? $defaut : [
                'ouvert'    => (bool) ($j['ouvert'] ?? true),
                'ouverture' => self::hm((string) ($j['ouverture'] ?? ''), $defaut['ouverture']),
                'fermeture' => self::hm((string) ($j['fermeture'] ?? ''), $defaut['fermeture']),
            ];
        }

        return $out;
    }

    /** @return list<array{du: string, au: string, message: string}> triées par date de début */
    public function fermetures(): array
    {
        $brut = $this->lire('fermetures');
        $out = [];
        foreach (is_array($brut) ? $brut : [] as $f) {
            if (! is_array($f)) {
                continue;
            }
            $du = self::date((string) ($f['du'] ?? ''));
            $au = self::date((string) ($f['au'] ?? '')) ?? $du;
            if ($du === null || $au === null) {
                continue;
            }
            if ($au < $du) {
                [$du, $au] = [$au, $du];
            }
            $out[] = ['du' => $du, 'au' => $au, 'message' => mb_substr(trim((string) ($f['message'] ?? '')), 0, 160)];
        }
        usort($out, fn ($a, $b) => strcmp($a['du'], $b['du']));

        return $out;
    }

    /** Fermeture exceptionnelle qui couvre ce jour de SERVICE (date 'Y-m-d'), ou null. */
    public function fermetureDu(string $jour): ?array
    {
        foreach ($this->fermetures() as $f) {
            if ($jour >= $f['du'] && $jour <= $f['au']) {
                return $f;
            }
        }

        return null;
    }

    /**
     * Le service qui contient cet instant, ou null.
     *
     * @return array{jour: string, debut: Carbon, fin: Carbon}|null
     */
    public function serviceA(CarbonInterface $t): ?array
    {
        $t = Carbon::instance($t)->setTimezone(config('app.timezone'));
        $semaine = $this->semaine();
        foreach ([$t->copy()->subDay(), $t->copy()] as $jour) {
            $s = $this->servicePour($jour, $semaine);
            if ($s && $t->gte($s['debut']) && $t->lte($s['fin'])) {
                return $s;
            }
        }

        return null;
    }

    public function estEnService(CarbonInterface $t): bool
    {
        return $this->serviceA($t) !== null;
    }

    /**
     * Prochaine ouverture STRICTEMENT après cet instant (jusqu'à 60 jours), ou null.
     *
     * @return array{jour: string, debut: Carbon, fin: Carbon}|null
     */
    public function prochaineOuverture(CarbonInterface $t): ?array
    {
        $t = Carbon::instance($t)->setTimezone(config('app.timezone'));
        $semaine = $this->semaine();
        for ($i = 0; $i <= 60; $i++) {
            $s = $this->servicePour($t->copy()->addDays($i), $semaine);
            if ($s && $s['debut']->gt($t)) {
                return $s;
            }
        }

        return null;
    }

    /** Ce que le site et l'application affichent (lecture publique). */
    public function etatPublic(?CarbonInterface $maintenant = null): array
    {
        $now = Carbon::instance($maintenant ?? now())->setTimezone(config('app.timezone'));
        $service = $this->serviceA($now);
        $prochaine = $service ? null : $this->prochaineOuverture($now);
        $fermetureDuJour = $service ? null : $this->fermetureDu($now->toDateString());
        $semaine = $this->semaine();
        $jours = [];
        foreach ($semaine as $iso => $j) {
            $jours[] = ['jour' => self::JOURS[$iso], 'iso' => $iso] + $j;
        }

        return [
            'fuseau'        => (string) config('app.timezone'),
            'maintenant'    => $now->toIso8601String(),
            'ouvert'        => $service !== null,
            'service'       => $service ? [
                'ouverture' => $service['debut']->format('H:i'),
                'fermeture' => $service['fin']->format('H:i'),
                'fin'       => $service['fin']->toIso8601String(),
                'minutes_avant_fermeture' => (int) max(0, $now->diffInMinutes($service['fin'], false)),
            ] : null,
            'prochaine_ouverture' => $prochaine ? [
                'date'    => $prochaine['jour'],
                'jour'    => self::JOURS[(int) Carbon::parse($prochaine['jour'])->isoWeekday()],
                'heure'   => $prochaine['debut']->format('H:i'),
                'debut'   => $prochaine['debut']->toIso8601String(),
                'aujourdhui' => $prochaine['jour'] === $now->toDateString(),
                'minutes' => (int) max(0, $now->diffInMinutes($prochaine['debut'], false)),
            ] : null,
            // Fermeture exceptionnelle EN COURS (aujourd'hui, hors service) : le message du restaurant.
            'fermeture_exceptionnelle' => $fermetureDuJour,
            'fermetures_a_venir' => array_values(array_filter($this->fermetures(), fn ($f) => $f['au'] >= $now->toDateString())),
            'semaine'       => $jours,
            'resume'        => $this->resume($semaine),
        ];
    }

    /** « Tous les jours 18h – 00h30 », « Du mardi au dimanche 18h – 00h », sinon jour par jour. */
    public function resume(?array $semaine = null): string
    {
        $semaine = $semaine ?? $this->semaine();
        $ouverts = array_filter($semaine, fn ($j) => $j['ouvert']);
        if ($ouverts === []) {
            return 'Fermé';
        }
        $plages = array_unique(array_map(fn ($j) => self::plage($j), $ouverts));
        if (count($plages) === 1) {
            $plage = reset($plages);
            if (count($ouverts) === 7) {
                return 'Tous les jours ' . $plage;
            }
            $isos = array_keys($ouverts);
            $consecutifs = max($isos) - min($isos) + 1 === count($isos);
            if ($consecutifs && count($isos) > 1) {
                return 'Du ' . self::JOURS[min($isos)] . ' au ' . self::JOURS[max($isos)] . ' ' . $plage;
            }
        }
        $morceaux = [];
        foreach ($semaine as $iso => $j) {
            $morceaux[] = ucfirst(self::JOURS[$iso]) . ' ' . ($j['ouvert'] ? self::plage($j) : 'fermé');
        }

        return implode(' · ', $morceaux);
    }

    /** Enregistre le réglage (déjà validé par HorairesRequest). */
    public function enregistrer(array $semaine, array $fermetures): void
    {
        $propre = [];
        foreach (array_keys(self::JOURS) as $iso) {
            $j = $semaine[$iso] ?? $semaine[(string) $iso] ?? [];
            $propre[$iso] = [
                'ouvert'    => (bool) ($j['ouvert'] ?? false),
                'ouverture' => self::hm((string) ($j['ouverture'] ?? ''), '18:00'),
                'fermeture' => self::hm((string) ($j['fermeture'] ?? ''), '00:30'),
            ];
        }
        $fs = [];
        foreach ($fermetures as $f) {
            $du = self::date((string) ($f['du'] ?? ''));
            $au = self::date((string) ($f['au'] ?? '')) ?? $du;
            if ($du !== null) {
                $fs[] = ['du' => $du, 'au' => $au, 'message' => mb_substr(trim((string) ($f['message'] ?? '')), 0, 160)];
            }
        }
        Settings::group(self::GROUPE)->set(['semaine' => json_encode($propre), 'fermetures' => json_encode(array_values($fs))]);
    }

    // ── interne ─────────────────────────────────────────────────────────────────────────────

    /** @return array{jour: string, debut: Carbon, fin: Carbon}|null */
    private function servicePour(CarbonInterface $jour, array $semaine): ?array
    {
        $date = Carbon::instance($jour)->setTimezone(config('app.timezone'))->startOfDay();
        $j = $semaine[(int) $date->isoWeekday()] ?? null;
        if (! $j || ! $j['ouvert'] || $this->fermetureDu($date->toDateString()) !== null) {
            return null;
        }
        [$ho, $mo] = array_map('intval', explode(':', $j['ouverture']));
        [$hf, $mf] = array_map('intval', explode(':', $j['fermeture']));
        $debut = $date->copy()->setTime($ho, $mo);
        $fin = $date->copy()->setTime($hf, $mf);
        if ($fin->lte($debut)) {
            $fin->addDay(); // la fermeture enjambe minuit
        }

        return ['jour' => $date->toDateString(), 'debut' => $debut, 'fin' => $fin];
    }

    private function lire(string $cle)
    {
        try {
            $v = Settings::group(self::GROUPE)->get($cle);
        } catch (\Throwable $e) {
            return null;
        }
        if (is_string($v)) {
            $d = json_decode($v, true);

            return is_array($d) ? $d : null;
        }

        return is_array($v) ? $v : null;
    }

    private static function plage(array $j): string
    {
        return self::heureLisible($j['ouverture']) . ' – ' . self::heureLisible($j['fermeture']);
    }

    /** 18:00 → 18h ; 00:30 → 00h30 */
    public static function heureLisible(string $hm): string
    {
        [$h, $m] = explode(':', $hm) + [1 => '00'];

        return $h . 'h' . ($m === '00' ? '' : $m);
    }

    private static function hm(string $v, string $defaut): string
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $defaut;
    }

    private static function date(string $v): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) !== false ? $v : null;
    }
}

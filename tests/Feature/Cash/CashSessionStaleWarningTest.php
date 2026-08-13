<?php

namespace Tests\Feature\Cash;

use App\Models\Branch;
use App\Models\CashDrawerSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * UNE SESSION DE CAISSE OUVERTE DEPUIS TROP LONGTEMPS DOIT LE DIRE.
 *
 * ── CE QUI A DÉCLENCHÉ CE BANC, MESURÉ EN PRODUCTION LE 2026-08-13 ───────────────────────────
 * Deux sessions de caisse ouvertes depuis **49 jours** et **36 jours**. Zéro session close depuis
 * l'installation. 237 mouvements de tiroir pour **3 818,30 €** accumulés dessous.
 *
 * ── POURQUOI C'EST PIRE QUE « PAS ENCORE CLÔTURÉ » ───────────────────────────────────────────
 * Une session de caisse existe pour comparer ce qu'on ATTEND dans le tiroir à ce qu'on y TROUVE.
 * Sur 49 jours, cette comparaison ne veut plus rien dire : l'écart d'un mardi se noie dans celui
 * d'un samedi, et un vol de 20 € disparaît dans le bruit de sept semaines. La fonction n'est pas
 * « en attente », elle est **devenue inutilisable** — et c'est arrivé en silence.
 *
 * ── CE QUE CE CORRECTIF FAIT, ET CE QU'IL NE FAIT PAS ────────────────────────────────────────
 * Il ne clôture rien tout seul : clôturer, c'est compter physiquement l'argent, et aucun logiciel
 * ne peut le faire à la place d'un humain. Une clôture automatique inventerait un montant, donc un
 * écart faux — pire que pas d'écart du tout.
 *
 * Il rend simplement l'ancienneté VISIBLE là où la caisse regarde déjà (`/cash-drawer/sessions/
 * current`, appelée à chaque ouverture d'écran). Un problème invisible ne se corrige jamais ; un
 * problème affiché finit par agacer quelqu'un, et c'est exactement ce qu'on veut.
 */
class CashSessionStaleWarningTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branche;

    private User $caissier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-'.str_repeat('c', 40));

        $this->branche = Branch::factory()->create();
        $this->caissier = User::factory()->create(['branch_id' => $this->branche->id, 'phone' => '0100000555']);
        $this->caissier->assignRole('POS Operator');
    }

    /* Nom explicite ET non colllisionnant : `session()` existe déjà sur le TestCase de
       Laravel, et le redéfinir en `private` fait échouer le chargement de la classe. */
    private function ouvrirDepuis(int $heures): CashDrawerSession
    {
        /* Insertion directe : ce modèle n'a pas de fabrique, et en écrire une pour un seul banc
           créerait une source de vérité de plus sur la forme d'une session de caisse. */
        $id = DB::table('cash_drawer_sessions')->insertGetId([
            'branch_id'         => $this->branche->id,
            'opened_by_user_id' => $this->caissier->id,
            'status'            => 'open',
            'opening_amount'    => 110.00,
            'opened_at'         => now()->subHours($heures),
            'created_at'        => now()->subHours($heures),
            'updated_at'        => now()->subHours($heures),
        ]);

        return CashDrawerSession::findOrFail($id);
    }

    private function lire(): array
    {
        return $this->actingAs($this->caissier, 'sanctum')
            ->getJson('/api/admin/pos/cash-drawer/sessions/current?branch_id='.$this->branche->id)
            ->json('data') ?? [];
    }

    /** Une session du jour ne dit rien : c'est le fonctionnement normal. */
    public function test_une_session_du_jour_ne_declenche_aucune_alerte(): void
    {
        $this->ouvrirDepuis(3);

        $d = $this->lire();

        /* ⚠️ MON ATTENTE ÉTAIT FAUSSE ICI, et le code avait raison : j'avais écrit 0, alors qu'une
           session de 3 heures doit évidemment annoncer 3. L'ancienneté est comptée TOUT LE TEMPS ;
           c'est le drapeau `stale` qui distingue le normal de l'anormal, pas le compteur. */
        $this->assertSame(3, (int) ($d['open_since_hours'] ?? -1),
            'l\'ancienneté doit être comptée même quand tout va bien');
        $this->assertFalse((bool) ($d['stale'] ?? true),
            'une session de 3 heures ne doit pas être signalée');
    }

    /**
     * LE CŒUR : au-delà du seuil, la session se signale. Le seuil vaut une journée de service —
     * une caisse qui traverse la nuit n'a pas été comptée, c'est le fait qu'on veut voir.
     */
    public function test_une_session_qui_a_passe_la_nuit_est_signalee(): void
    {
        $this->ouvrirDepuis(30);

        $d = $this->lire();

        $this->assertTrue((bool) ($d['stale'] ?? false),
            'une session ouverte depuis 30 heures ne se signale pas');
        $this->assertSame(30, (int) $d['open_since_hours']);
    }

    /**
     * ET LE CAS RÉEL DE LA PRODUCTION : 49 jours. Le banc le fige pour qu'on ne puisse plus
     * prétendre que « ça n'arrive pas ».
     */
    public function test_le_cas_reel_de_quarante_neuf_jours_est_signale(): void
    {
        $this->ouvrirDepuis(49 * 24);

        $d = $this->lire();

        $this->assertTrue((bool) ($d['stale'] ?? false));
        $this->assertSame(49 * 24, (int) $d['open_since_hours'],
            'l\'ancienneté annoncée ne correspond pas à la réalité');
    }

    /** Le seuil est réglable : une brasserie de nuit n'a pas le rythme d'un midi. */
    public function test_le_seuil_est_reglable(): void
    {
        Config::set('pos.cash_session_stale_hours', 72);
        $this->ouvrirDepuis(30);

        $this->assertFalse((bool) ($this->lire()['stale'] ?? true),
            'le seuil configuré n\'est pas respecté');
    }
}

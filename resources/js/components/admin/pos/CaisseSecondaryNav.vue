<template>
    <!-- [GOAL UX 2026-07-25] Navigation cohérente entre les pages secondaires de la caisse
         (Encaissement · Suivi · Historique · Écran client) + Retour caisse. Le caissier
         circule d'une page à l'autre sans passer par la sidebar admin. Miroir du toolbar
         déjà présent sur le tracker de suivi. -->
    <nav class="caisse-secondary-nav" :aria-label="$t('menu.encaissement') + ' — navigation'" data-testid="caisse-secondary-nav">
        <div class="csn-group">
            <router-link
                :to="{ name: 'admin.encaissement' }"
                class="csn-link"
                :class="{ active: current === 'encaissement' }"
                :aria-current="current === 'encaissement' ? 'page' : null"
                data-testid="csn-encaissement"
            >
                <span class="csn-ico" aria-hidden="true">💶</span>
                <span>{{ $t('menu.encaissement') }}</span>
            </router-link>
            <router-link
                :to="{ name: 'admin.pos-orders.tracker' }"
                class="csn-link"
                :class="{ active: current === 'suivi' }"
                :aria-current="current === 'suivi' ? 'page' : null"
                data-testid="csn-suivi"
            >
                <span class="csn-ico" aria-hidden="true">📋</span>
                <span>{{ $t('pos.tracker.title') }}</span>
            </router-link>
            <router-link
                :to="{ name: 'admin.historique.list' }"
                class="csn-link"
                :class="{ active: current === 'historique' }"
                :aria-current="current === 'historique' ? 'page' : null"
                data-testid="csn-historique"
            >
                <span class="csn-ico" aria-hidden="true">🗂️</span>
                <span>{{ $t('menu.historique') }}</span>
            </router-link>
            <router-link
                :to="{ name: 'admin.order-status-screen' }"
                target="_blank"
                rel="noopener"
                class="csn-link"
                data-testid="csn-oss"
            >
                <span class="csn-ico" aria-hidden="true">🖥️</span>
                <span>{{ $t('pos.tracker.customer_screen') }}</span>
            </router-link>
            <!-- [2026-08-13 · propriétaire : « accès admin caisse »] Les cinq écrans de la roue
                 existaient et fonctionnaient, mais AUCUN lien n'y menait : il fallait taper les
                 URL de mémoire. Un écran de service qu'on ne peut pas trouver n'existe pas.

                 C'est un `<a>` et non un `router-link` : la roue est une page Blade autonome,
                 hors de l'application Vue. Un `router-link` chercherait une route qui n'existe
                 pas dans le routeur et resterait muet.

                 `target="_blank"` : le caissier ne doit JAMAIS perdre sa caisse de vue pour
                 aller valider un tour. Il revient d'un geste, sa commande en cours intacte.

                 [2026-08-13, second passage] Le libellé était écrit en clair : `fr.json` portait
                 alors des modifications non committées d'une autre session, et y ajouter une clé
                 m'aurait obligé à committer son travail inachevé. Ce fichier est maintenant
                 propre, la clé est posée, et le libellé passe par `$t(...)` comme ses voisins. -->
            <a
                href="/admin/roue"
                target="_blank"
                rel="noopener"
                class="csn-link"
                data-testid="csn-roue"
                @click="ouvrirRoue"
            >
                <span class="csn-ico" aria-hidden="true">🎡</span>
                <span>{{ $t('menu.roue') }}</span>
            </a>
        </div>
        <router-link
            :to="{ name: 'admin.pos' }"
            class="csn-link csn-back"
            data-testid="csn-back-caisse"
        >
            <span class="csn-ico" aria-hidden="true">←</span>
            <span>{{ $t('pos.tracker.back_to_pos') }}</span>
        </router-link>
    </nav>
</template>

<script>
export default {
    name: 'CaisseSecondaryNav',
    props: {
        // 'encaissement' | 'suivi' | 'historique' — met en surbrillance la page courante.
        current: { type: String, default: '' },
    },
    methods: {
        /**
         * OUVRIR LA ROUE SANS RETAPER LE CODE DE LA MAISON.
         *
         * Le `href` du lien reste `/admin/roue` et ce n'est pas un détail : si JavaScript échoue,
         * si la méthode n'est pas montée, si un clic-milieu contourne le gestionnaire, le lien
         * mène quand même quelque part d'utile — la porte à code, qui marche toujours. On ne
         * remplace pas un chemin, on en ajoute un plus court.
         *
         * L'ONGLET EST OUVERT AVANT LE MOINDRE `await`. Un `window.open` appelé après une
         * promesse n'est plus rattaché au clic de l'utilisateur : le navigateur le bloque comme
         * une fenêtre surgissante. C'est le piège classique de ce motif, et il ne se voit qu'en
         * conditions réelles — jamais dans un test qui appelle la méthode directement.
         *
         * Pas de `noopener` dans l'appel : avec cette option, `window.open` rend `null` et il
         * devient impossible de poser l'adresse ensuite. On coupe donc le lien d'ouverture
         * APRÈS coup, ce qui donne la même protection.
         */
        async ouvrirRoue(evenement) {
            evenement.preventDefault();

            const onglet = window.open('about:blank', '_blank');
            if (!onglet) {
                // Fenêtre bloquée : on n'insiste pas, on navigue dans l'onglet courant plutôt que
                // de ne rien faire — un bouton qui ne fait rien est vécu comme une panne.
                window.location.href = '/admin/roue';
                return;
            }

            try {
                const { data } = await axios.post('/admin/wheel/screen-pass', { ecran: 'accueil' });
                onglet.location = (data && data.url) ? data.url : '/admin/roue';
            } catch (e) {
                // Compte sans droit caisse, réseau coupé, route absente : dans tous les cas la
                // porte à code reste ouverte. Jamais d'impasse.
                onglet.location = '/admin/roue';
            } finally {
                try { onglet.opener = null; } catch (e) { /* déjà navigué : sans conséquence */ }
            }
        },
    },
};
</script>

<style scoped>
.caisse-secondary-nav {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 16px;
}
.csn-group {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.csn-link {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 15px;
    border-radius: 999px;
    border: 1.5px solid #E5E5EF;
    background: #FFFFFF;
    color: #1B1B3A;
    font-weight: 700;
    font-size: 13px;
    line-height: 1;
    text-decoration: none;
    transition: border-color 0.15s ease, background 0.15s ease, color 0.15s ease, transform 0.08s ease;
    white-space: nowrap;
}
.csn-link:hover { border-color: #F4501E; color: #F4501E; }
.csn-link:active { transform: scale(0.97); }
.csn-link.active {
    background: #F4501E;
    border-color: #F4501E;
    color: #FFFFFF;
    box-shadow: 0 4px 12px rgba(244, 80, 30, 0.22);
}
.csn-link.csn-back {
    margin-left: auto;
    color: #6B6B85;
    border-style: dashed;
}
.csn-link.csn-back:hover { color: #F4501E; }
.csn-ico { font-size: 15px; }
@media (max-width: 680px) {
    .csn-link.csn-back { margin-left: 0; }
    .csn-link { flex: 1 1 auto; justify-content: center; }
}
</style>

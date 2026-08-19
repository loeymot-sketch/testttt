<!--
  [SIGNAL ANNULATION CUISINE 2026-08-19] Bandeau « RETIRER DU PASSE ».

  LE DÉFAUT RÉPARÉ. Quand la caisse annulait une commande DÉJÀ affichée en cuisine, la carte
  disparaissait au sondage suivant sans un mot : le cuisinier ne voyait rien partir et le plat
  restait sur le passe — il sortait au client suivant, ou finissait à la poubelle sans que
  personne ne le sache. Le board n'avait aucun canal pour dire « celle-là, retire-la ».

  Source : meta.recently_canceled de GET admin/kds-order, committée par le store
  kitchenDisplaySystemOrder → getter recentlyCanceled, rafraîchie par le poll KDS existant.
  Aucune requête HTTP en plus, et surtout AUCUN temps réel : la production tourne en
  BROADCAST_DRIVER=log, sans serveur de sockets — un bandeau qui dépendrait d'un événement
  poussé n'y apparaîtrait jamais.

  L'ACCUSÉ « VU » EST VOLONTAIREMENT LOCAL AU POSTE (localStorage). Ce n'est pas un pis-aller :
  un plat est physiquement sur UN passe, et chaque poste doit retirer LE SIEN. Un accusé
  partagé ferait disparaître l'alerte de l'écran du voisin qui n'a encore rien retiré. Le
  serveur borne de son côté la fenêtre d'affichage (config kds.canceled_notice_minutes).

  PIÈGES DU DÉPÔT ÉVITÉS ICI, volontairement :
   · pas de <Teleport> — le 2026-08-17, un Teleport dont l'enfant se montait APRÈS le mount
     initial a figé le board VIDE pour tout le service (TypeError dans moveTeleport, rejoué à
     chaque tick du chrono 1 s) ;
   · `overflow-x: hidden` DÉCLARÉ — non déclaré, il est recalculé en `auto` face à un
     `overflow-y` non-`visible` (CSS Overflow 3), ce qui a déjà produit un scroll horizontal ;
   · toute forme inattendue est neutralisée dans le computed → le bandeau ne peut pas faire
     tomber le board.

  i18n : libellés FR en dur, comme KdsScheduledBanner — V1 FR-locked (ADR-007).
-->
<template>
  <div
    v-if="visibleEntries.length > 0"
    class="kds-canceled-banner"
    role="alert"
    aria-live="assertive"
    data-testid="kds-canceled-banner"
  >
    <span class="kds-canceled-banner__icon" aria-hidden="true">🚫</span>
    <span class="kds-canceled-banner__label">
      ANNULÉE{{ visibleEntries.length > 1 ? 'S' : '' }} — RETIRER DU PASSE ({{ visibleEntries.length }})
    </span>
    <div class="kds-canceled-banner__list">
      <div
        v-for="(entry, idx) in visibleEntries"
        :key="entry.key"
        class="kds-canceled-banner__entry"
        :data-testid="`kds-canceled-entry-${idx}`"
      >
        <span class="kds-canceled-banner__serial keep-latin">{{ entry.serial }}</span>
        <span v-if="entry.items" class="kds-canceled-banner__items">{{ entry.items }}</span>
        <span v-if="entry.reason" class="kds-canceled-banner__reason">« {{ entry.reason }} »</span>
        <button
          type="button"
          class="kds-canceled-banner__ack"
          :data-testid="`kds-canceled-ack-${entry.id}`"
          :aria-label="`Confirmer le retrait de la commande ${entry.serial}`"
          @click="acknowledge(entry.id)"
        >Vu</button>
      </div>
    </div>
  </div>
</template>

<script>
export const ACK_STORAGE_KEY = 'kds.canceled_ack';

/**
 * Lecture défensive des accusés « Vu » du poste. localStorage peut être indisponible
 * (mode privé, quota, iframe sandboxée) ou contenir du JSON corrompu : dans TOUS ces cas
 * on rend {} — le bandeau réapparaît, ce qui est le repli SÛR. Un plat servi par erreur
 * coûte plus cher qu'une alerte affichée deux fois.
 * Exportée pour testabilité directe (vitest).
 */
export function readAcks(storage) {
    try {
        const raw = (storage || window.localStorage).getItem(ACK_STORAGE_KEY);
        if (!raw) return {};
        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch (e) {
        return {};
    }
}

/**
 * Écrit les accusés en ne gardant QUE les commandes encore servies par le serveur — sinon la
 * clé grossit indéfiniment sur un poste qui ne ferme jamais (l'écran cuisine tourne 7j/7).
 * Un échec d'écriture est avalé : il ne doit jamais empêcher le clic de répondre.
 */
export function writeAcks(acks, liveIds, storage) {
    const kept = {};
    (Array.isArray(liveIds) ? liveIds : []).forEach((id) => {
        if (Object.prototype.hasOwnProperty.call(acks, id)) kept[id] = acks[id];
    });
    try {
        (storage || window.localStorage).setItem(ACK_STORAGE_KEY, JSON.stringify(kept));
    } catch (e) {
        /* stockage indisponible — l'alerte réapparaîtra au prochain sondage, repli sûr */
    }
    return kept;
}

export default {
    name: 'KdsCanceledBanner',
    props: {
        // Entrées meta.recently_canceled — [{ id, order_serial_no, queue_number,
        // canceled_at, from_status, to_status, reason, items }]. Toute forme
        // inattendue est neutralisée ici.
        entries: { type: Array, default: () => [] },
    },
    data() {
        return { acks: readAcks() };
    },
    computed: {
        normalizedEntries() {
            const raw = Array.isArray(this.entries) ? this.entries : [];
            return raw
                .filter((e) => e && typeof e === 'object' && e.id !== null && e.id !== undefined)
                .map((e) => {
                    // Le numéro de file est ce que la cuisine a sous les yeux sur la carte ;
                    // le serial n'est qu'un repli quand la commande n'en a pas.
                    const queue = e.queue_number !== null && e.queue_number !== undefined && e.queue_number !== ''
                        ? String(e.queue_number)
                        : null;
                    const serialNo = e.order_serial_no !== null && e.order_serial_no !== undefined && e.order_serial_no !== ''
                        ? `#${e.order_serial_no}`
                        : `#${e.id}`;
                    return {
                        id: e.id,
                        key: `cancel-${e.id}`,
                        serial: queue ? `N°${queue}` : serialNo,
                        items: typeof e.items === 'string' ? e.items : '',
                        reason: typeof e.reason === 'string' && e.reason !== '' ? e.reason : '',
                        canceledAt: e.canceled_at || '',
                    };
                });
        },
        // Empreinte SCALAIRE de la liste servie (ids + horodatage) — c'est elle que le watcher
        // observe. Vue ne déclenche un watcher que si la valeur CHANGE : une chaîne le garantit,
        // un tableau/objet recalculé à chaque poll ne le garantit pas.
        entriesFingerprint() {
            return this.normalizedEntries.map((e) => `${e.id}@${e.canceledAt}`).join('|');
        },
        visibleEntries() {
            // Une entrée ré-annulée plus tard (nouveau canceled_at) redevient visible : l'accusé
            // porte sur CE retrait-là, pas sur le numéro de commande à vie.
            return this.normalizedEntries.filter((e) => this.acks[e.id] !== e.canceledAt);
        },
    },
    watch: {
        // Purge les accusés dont la commande n'est plus servie par le serveur (fenêtre écoulée).
        // Watcher sur une VALEUR SCALAIRE, jamais `deep` sur un getter : un watcher deep posé sur
        // un getter qui renvoie sa propre source ne se déclenche JAMAIS (Vue passe la même
        // référence en ancien et nouveau) — défaut déjà payé sur le panier caisse le 2026-08-19.
        entriesFingerprint() {
            this.acks = writeAcks(this.acks, this.normalizedEntries.map((e) => e.id));
        },
    },
    methods: {
        acknowledge(id) {
            const entry = this.normalizedEntries.find((e) => e.id === id);
            if (!entry) return;
            const next = { ...this.acks, [id]: entry.canceledAt };
            this.acks = writeAcks(next, this.normalizedEntries.map((e) => e.id));
        },
    },
};
</script>

<style scoped>
.kds-canceled-banner {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    min-height: 36px;
    padding: 6px 24px;
    background: #FEF2F2;
    color: #991B1B;
    border-bottom: 2px solid #FCA5A5;
    font-family: 'Inter', system-ui, sans-serif;
    /* Déclaré EXPLICITEMENT : non déclaré, `overflow-x` est recalculé en `auto` dès qu'un
       `overflow-y` non-`visible` s'applique (CSS Overflow 3) → scroll horizontal fantôme. */
    overflow-x: hidden;
    overflow-y: hidden;
}
.kds-canceled-banner__icon {
    flex: none;
    font-size: 15px;
    line-height: 1;
}
.kds-canceled-banner__label {
    flex: none;
    font-size: 13px;
    font-weight: 800;
    letter-spacing: 0.02em;
}
.kds-canceled-banner__list {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 12px;
    min-width: 0;
}
.kds-canceled-banner__entry {
    display: flex;
    align-items: center;
    gap: 6px;
    min-width: 0;
    padding: 2px 6px 2px 8px;
    background: #FFFFFF;
    border: 1px solid #FCA5A5;
    border-radius: 6px;
}
.kds-canceled-banner__serial {
    flex: none;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
    font-size: 13px;
    font-weight: 800;
}
.kds-canceled-banner__items {
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 320px;
}
.kds-canceled-banner__reason {
    font-size: 12px;
    font-style: italic;
    opacity: 0.85;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 220px;
}
.kds-canceled-banner__ack {
    flex: none;
    /* Cible tactile : un cuisinier a les mains prises, pas une souris. */
    min-width: 44px;
    min-height: 28px;
    padding: 2px 10px;
    border: 0;
    border-radius: 5px;
    background: #991B1B;
    color: #FFFFFF;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
}
.kds-canceled-banner__ack:hover { background: #7F1D1D; }
.kds-canceled-banner__ack:focus-visible { outline: 3px solid #1D4ED8; outline-offset: 2px; }
</style>

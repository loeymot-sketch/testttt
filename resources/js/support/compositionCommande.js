/**
 * [GOAL CAISSE CONTRÔLE 2026-09-02] Lecture d'une commande de caisse — règles PARTAGÉES.
 *
 * POURQUOI CE MODULE EXISTE
 * -------------------------
 * Ces règles vivaient comme `methods:` de `PosOrdersTrackerComponent.vue`. Le tiroir de contrôle
 * de la caisse (`PosControlDrawer.vue`) a besoin des MÊMES. Les recopier garantissait la divergence :
 * la troncature « +N » a déjà été corrigée DEUX fois — FIX-6/A-006 (la CSS coupait sans le dire, et
 * le texte complet se réfugiait dans un `title=` inatteignable au doigt) puis A-016 (le budget,
 * enfermé dans la méthode, n'était exercé par aucun test, si bien que le marqueur « +N » n'était
 * rendu sur AUCUNE des 10 captures d'audit). Un doublon aurait raté la seconde correction.
 *
 * Ce module ne fait que RENDRE. Il ne dérive rien, n'appelle rien, ne connaît ni Vue ni le réseau :
 * la composition arrive déjà réconciliée du serveur (`App\Support\Order\CompositionCompactor`,
 * forme compacte `{options:[{label,value,quantity?}], extras:[{name,quantity?}], addons:[…]}`),
 * qui est lui-même le port fidèle du normaliseur du ticket
 * (`resources/js/helpers/posReceiptBuilder.js`). Trois lecteurs, une seule vérité.
 *
 * i18n : ce module est PUR, il ne connaît pas `$t`. Tout libellé traduisible est passé en argument
 * (`libelleSupprime`, `libelleInstant`). Rendre une clé brute au caissier serait un défaut ; ne pas
 * savoir traduire ici en est la prévention.
 *
 * Banc : `tests/js/compositionCommandeModule.spec.js`.
 */

/**
 * Budget de la composition affichée sur une carte, en caractères.
 *
 * EXPORTÉ pour que les tests puissent exercer la branche de troncature au lieu de la deviner :
 * c'est parce que ce nombre était enfermé dans la méthode que le marqueur « +N » a pu n'être rendu
 * sur aucune capture sans que rien ne le signale (AUDIT-SUPERVISEUR 2026-08-25 · A-016).
 *
 * Valeur choisie pour ~2 lignes de 11 px dans une colonne de carte, volontairement généreuse : la
 * composition la plus riche relevée en audit (« Galette · Algerienne · Bien cuit · +2 Cheddar ·
 * +Salade », 54 caractères) passe ENTIÈRE.
 */
export const BUDGET_COMPO = 58;

/** Séparateur canonique entre morceaux de composition. La coupe tombe TOUJOURS dessus. */
export const SEPARATEUR_COMPO = ' · ';

/**
 * Nom du produit, avec repli.
 *
 * `item_name` est null quand l'article a été retiré du catalogue depuis la vente : sans repli la
 * carte affichait une ligne muette — une quantité, un vide, et un caissier incapable de dire ce que
 * le client tient dans la main.
 *
 * @param {object|null} ligne
 * @param {string} libelleSupprime  traduction de `label.deleted_item`, fournie par l'appelant
 */
export function nomProduit(ligne, libelleSupprime) {
    if (!ligne) return libelleSupprime;
    const nom = String(ligne.item_name || ligne.name || '').trim();
    return nom || libelleSupprime;
}

/**
 * Résumé d'UNE ligne : « Algérienne · Galette · +2 Cheddar ».
 *
 * Volontairement court — la carte doit rester lisible d'un coup d'œil ; le détail intégral vit
 * dans le panneau « Voir tout ». Une entrée sans valeur lisible est ÉCARTÉE plutôt que rendue en
 * « · » orphelin (même règle de rejet que le normaliseur du ticket).
 */
export function resumeComposition(ligne) {
    if (!ligne) return '';
    const morceaux = [];

    (ligne.options || []).forEach((o) => {
        const valeur = String((o && o.value) || '').trim();
        if (!valeur) return;
        morceaux.push(o.quantity > 1 ? `${valeur} ×${o.quantity}` : valeur);
    });
    (ligne.extras || []).forEach((e) => {
        const nom = String((e && e.name) || '').trim();
        if (!nom) return;
        morceaux.push(e.quantity > 1 ? `+${e.quantity} ${nom}` : `+${nom}`);
    });
    (ligne.addons || []).forEach((a) => {
        const nom = String((a && a.name) || '').trim();
        if (!nom) return;
        morceaux.push(a.quantity > 1 ? `+${a.quantity} ${nom}` : `+${nom}`);
    });

    return morceaux.join(SEPARATEUR_COMPO);
}

/**
 * La composition telle qu'elle est RÉELLEMENT affichable, et l'aveu de ce qui ne l'est pas.
 *
 * Pourquoi en JS et pas en CSS : `text-overflow: ellipsis` coupe sans que personne — ni le
 * composant, ni le caissier — ne sache qu'il manque quelque chose, et le texte complet se réfugie
 * alors dans un `title=`, c'est-à-dire nulle part sur une caisse tactile. Ici la coupe est mesurée,
 * tombe sur une frontière « · », et ce qui reste dehors est ANNONCÉ (« +2 ») par un marqueur tapable.
 *
 * @returns {{texte: string, tronque: boolean, restants: number}}
 */
export function compoAffichee(ligne, budget = BUDGET_COMPO) {
    const complet = resumeComposition(ligne);
    if (!complet || complet.length <= budget) {
        return { texte: complet, tronque: false, restants: 0 };
    }

    const morceaux = complet.split(SEPARATEUR_COMPO);
    const gardes = [];
    let longueur = 0;
    for (const m of morceaux) {
        const cout = gardes.length ? longueur + SEPARATEUR_COMPO.length + m.length : m.length;
        if (gardes.length && cout > budget) break;
        gardes.push(m);
        longueur = cout;
    }
    // Un premier morceau à lui seul plus long que le budget : on le garde quand même ENTIER plutôt
    // que de couper au milieu d'un mot — mieux vaut une ligne un peu longue qu'un « Algérie… » qui
    // ne veut rien dire.
    if (gardes.length === 0) gardes.push(morceaux[0]);

    return {
        texte: gardes.join(SEPARATEUR_COMPO),
        tronque: gardes.length < morceaux.length,
        restants: morceaux.length - gardes.length,
    };
}

/** Toutes les lignes de la commande, telles qu'expédiées par le serveur. */
export function lignesCompletes(commande) {
    return commande && Array.isArray(commande.order_items) ? commande.order_items : [];
}

/** Les 3 premières lignes — ce que porte une carte. */
export function itemsPreview(commande) {
    return lignesCompletes(commande).slice(0, 3);
}

/** Combien de lignes restent hors de l'aperçu. */
export function extraItemsCount(commande) {
    return Math.max(0, lignesCompletes(commande).length - 3);
}

/**
 * « Voir tout » n'apparaît que s'il y a vraiment quelque chose de plus à voir : plus de 3 lignes,
 * une personnalisation, ou une instruction. Un bouton qui n'ajoute rien est un bouton qui ment.
 */
export function aDuContenuAVoir(commande) {
    const lignes = lignesCompletes(commande);
    if (lignes.length > 3) return true;
    return lignes.some((l) => (
        (l.options || []).length > 0
        || (l.extras || []).length > 0
        || (l.addons || []).length > 0
        || (typeof l.instruction === 'string' && l.instruction.trim() !== '')
    ));
}

/** « 2× Cheddar, Salade » — liste nommée, quantité implicite à 1. */
export function listeNommee(liste) {
    return (Array.isArray(liste) ? liste : [])
        .map((e) => {
            const nom = String((e && e.name) || '').trim();
            if (!nom) return '';
            return e.quantity > 1 ? `${e.quantity}× ${nom}` : nom;
        })
        .filter(Boolean)
        .join(', ');
}

/**
 * Minutes écoulées depuis un horodatage ISO. Brique du rang cuisine et des seuils de retard.
 * `null` si la date est absente ou illisible — jamais 0, qui se lirait « à l'instant ».
 * Jamais négatif : l'horloge d'un poste peut être en avance sur le serveur.
 */
export function minutesEcoulees(iso, maintenant = Date.now()) {
    if (!iso) return null;
    const t = new Date(iso).getTime();
    if (!Number.isFinite(t)) return null;
    return Math.floor(Math.max(0, maintenant - t) / 60000);
}

/**
 * Âge court : « à l'instant », « 14 min », « 2h05 ». L'âge MESURÉ, jamais une attente prédite —
 * ce dépôt ne porte aucun modèle de débit cuisine, et annoncer une durée qu'on ne sait pas
 * calculer serait un mensonge au client.
 *
 * @param {string|null} iso
 * @param {string} libelleInstant  traduction de `pos.tracker.now`, fournie par l'appelant
 */
export function ageCourt(iso, libelleInstant, maintenant = Date.now()) {
    const mins = minutesEcoulees(iso, maintenant);
    if (mins === null) return '';
    if (mins < 1) return libelleInstant;
    if (mins < 60) return `${mins} min`;
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return `${h}h${m < 10 ? '0' + m : m}`;
}

/** Heure de commande en 24 h, locale FR : « 08:28 ». Vide si la date est absente ou illisible. */
export function heureCourte(iso) {
    if (!iso) return '';
    const t = new Date(iso).getTime();
    if (!Number.isFinite(t)) return '';
    try {
        return new Date(t).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    } catch (_) {
        return '';
    }
}

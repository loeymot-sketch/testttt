<template>
    <div class="col-12">
        <div class="db-card">
            <div class="db-card-header">
                <h3 class="db-card-title">Étiquettes DLC — hygiène</h3>
            </div>

            <div class="db-card-body">
                <LabelPrinterBar :info="printerInfo" :status="printerState.status" :supported="printerSupported"
                                 :detected-media="detectedMedia" @connect="connectPrinter" @adopt-media="adoptDetectedMedia" />

                <p v-if="pendingOffline.length" class="dlc-pending" role="status" data-testid="dlc-pending">
                    ⏳ {{ pendingOffline.length }} étiquette{{ pendingOffline.length > 1 ? "s" : "" }} imprimée{{ pendingOffline.length > 1 ? "s" : "" }} hors ligne
                    en attente du journal — {{ pendingOffline.length > 1 ? "elles partiront" : "elle partira" }} dès le retour du réseau (ou après reconnexion).
                    {{ pendingOffline.length > 1 ? "Elles figurent" : "Elle figure" }} dans « À surveiller » dès l'approche de la DLC.
                </p>
                <p v-if="loadError" class="dlc-alert" data-testid="dlc-error">{{ loadError }}</p>
                <p v-if="!loaded && !loadError" class="dlc-empty" role="status">Chargement des produits…</p>

                <!-- ── Aucun produit : on aide à démarrer ─────────────────────────────────── -->
                <div v-else-if="loaded && !activeProducts.length && !loadError" class="dlc-empty dlc-empty--cta" data-testid="dlc-empty">
                    <p>
                        <strong>Aucun produit pour l'instant.</strong> Créez vos fiches (oignons, tomates, olives, champignons…) : famille,
                        durées après ouverture ou décongélation, conservation, allergènes. Chaque appui imprimera ensuite l'étiquette.
                    </p>
                    <button v-if="recommended.missing" type="button" class="dlc-btn dlc-btn--primary" :disabled="installing"
                            data-testid="dlc-install-empty" @click="installRecommended(false)">
                        {{ installing ? "Installation…" : `Installer les ${recommended.total} produits recommandés` }}
                    </button>
                    <button type="button" class="dlc-btn" :class="{ 'dlc-btn--primary': !recommended.missing }" data-testid="dlc-add-first" @click="openEditor(null)">
                        ＋ Créer mon premier produit
                    </button>
                    <button type="button" class="dlc-btn" data-testid="dlc-import-open-empty" @click="importOpen = true">⬆ Importer un fichier (CSV)</button>
                    <div class="dlc-suggest" data-testid="dlc-suggest">
                        <span class="dlc-suggest-label">Ou partez d'un exemple (durées à adapter à votre plan de maîtrise sanitaire) :</span>
                        <button v-for="sg in suggestions" :key="sg.name" type="button" class="dlc-chip dlc-chip--wide"
                                :data-testid="`dlc-suggest-${sg.slug}`" @click="openEditor(sg)">
                            {{ sg.name }}
                        </button>
                    </div>
                </div>

                <template v-else-if="loaded">
                    <p v-if="justInstalled" class="dlc-note" role="status" data-testid="dlc-installed-note">
                        <strong>{{ justInstalled }} produits préconfigurés</strong> (légumes à couper, conserves ouvertes, viandes, marinades, fromages, sauces…),
                        avec leurs durées prudentes. Touchez un produit pour imprimer ; « Fiches produits » permet d'ajuster une durée à votre plan de maîtrise sanitaire.
                        <button type="button" class="dlc-btn dlc-btn--small" data-testid="dlc-installed-dismiss" @click="justInstalled = 0">OK</button>
                    </p>
                    <!-- ── Barre d'outils ─────────────────────────────────────────────────── -->
                    <div class="dlc-toolbar">
                        <!-- Qui fait les étiquettes ? Le nom (et le code, s'il y en a un) est vérifié par le serveur ; la personne est écrite sur la ligne du journal. -->
                        <div class="dlc-who" data-testid="dlc-who">
                            <HygieneOperatorPicker v-model="operatorId" v-model:token="operatorToken" @update:me="operatorMe = $event" />
                        </div>
                        <div class="dlc-search-row">
                            <label for="dlc-search" class="sr-only">Chercher un produit</label>
                            <input id="dlc-search" v-model="query" type="search" class="db-field-control" placeholder="🔍 Chercher un produit…"
                                   autocomplete="off" data-testid="dlc-search" />
                            <button v-if="query" type="button" class="dlc-btn dlc-btn--small" data-testid="dlc-search-clear" @click="query = ''">Effacer</button>
                        </div>
                        <button type="button" class="dlc-btn dlc-btn--primary" data-testid="dlc-new" @click="openEditor(null)">＋ Nouveau produit</button>
                        <button type="button" class="dlc-btn" :class="{ 'dlc-btn--attention': healthCount > 0 }" data-testid="dlc-health-open" @click="healthOpen = true">
                            Contrôle du catalogue<span v-if="healthCount" class="dlc-health-n" data-testid="dlc-health-count">{{ healthCount }}</span>
                        </button>
                        <!-- Étroit (portrait) : la liste « À surveiller » passe sous la grille ; l'alerte reste ici, en tête. -->
                        <button v-if="expiredCount" type="button" class="dlc-alert-chip" data-testid="dlc-watch-chip" @click="scrollToWatch">
                            ⚠ {{ expiredCount }} lot{{ expiredCount > 1 ? "s" : "" }} expiré{{ expiredCount > 1 ? "s" : "" }} — voir
                        </button>
                    </div>

                    <!-- en cherchant (famille « Tous »), les résultats passent juste sous la recherche : 3 rangées de familles les repoussaient hors de l'écran -->
                    <div v-show="!(query && !category)" class="dlc-cats" role="tablist" aria-label="Familles" data-testid="dlc-cats">
                        <button type="button" class="dlc-cat dlc-cat--fav" role="tab" :aria-selected="category === FAVORITES ? 'true' : 'false'" :class="{ 'is-on': category === FAVORITES }"
                                data-testid="dlc-cat-fav" @click="category = FAVORITES">★ Favoris <span class="dlc-cat-n" data-testid="dlc-fav-count">{{ favoriteCount }}</span></button>
                        <button type="button" class="dlc-cat" role="tab" :aria-selected="category === '' ? 'true' : 'false'" :class="{ 'is-on': category === '' }"
                                data-testid="dlc-cat-all" @click="category = ''">Tous <span class="dlc-cat-n">{{ activeProducts.length }}</span></button>
                        <button v-if="uncategorizedCount && categoryTabs.length" type="button" class="dlc-cat" role="tab"
                                :aria-selected="category === NO_FAMILY ? 'true' : 'false'" :class="{ 'is-on': category === NO_FAMILY }"
                                data-testid="dlc-cat-none" @click="category = NO_FAMILY">
                            <span class="dlc-dot" :style="{ background: colorOf('') }" aria-hidden="true"></span>Sans famille
                            <span class="dlc-cat-n">{{ uncategorizedCount }}</span>
                        </button>
                        <button v-for="c in categoryTabs" :key="c.name" type="button" class="dlc-cat" role="tab"
                                :aria-selected="category === c.name ? 'true' : 'false'" :class="{ 'is-on': category === c.name }"
                                :data-testid="`dlc-cat-${c.slug}`" @click="category = c.name">
                            <span class="dlc-dot" :style="{ background: colorOf(c.name) }" aria-hidden="true"></span>{{ c.name }}
                            <span class="dlc-cat-n">{{ c.count }}</span>
                        </button>

                    </div>

                    <div class="dlc-modes" role="group" aria-label="Mode d'impression">
                        <button type="button" class="dlc-mode" :class="{ 'is-on': !selectMode }" :aria-pressed="!selectMode ? 'true' : 'false'"
                                data-testid="dlc-mode-single" @click="setSelectMode(false)">Un appui = une étiquette</button>
                        <button type="button" class="dlc-mode" :class="{ 'is-on': selectMode }" :aria-pressed="selectMode ? 'true' : 'false'"
                                data-testid="dlc-mode-list" @click="setSelectMode(true)">☑ Sélection multiple</button>
                        <template v-if="selectMode">
                            <button type="button" class="dlc-btn dlc-btn--small" :disabled="!selectableVisible.length" data-testid="dlc-select-all"
                                    @click="selectAll">Tout sélectionner ({{ selectableVisible.length }})</button>
                            <button type="button" class="dlc-btn dlc-btn--small" :disabled="!selectionTotal" data-testid="dlc-select-none"
                                    @click="selection = {}">Aucun</button>
                            <label class="dlc-batch-action">
                                <span>Événement</span>
                                <select v-model="batchAction" class="db-field-control" data-testid="dlc-batch-action">
                                    <option value="">Celui de chaque produit</option>
                                    <option v-for="a in actionChoices" :key="a.key" :value="a.key">{{ a.label }}</option>
                                </select>
                            </label>
                        </template>
                    </div>

                    <p class="dlc-hint">
                        <template v-if="!selectMode">
                            Touchez un produit : l'étiquette sort aussitôt (événement et nombre habituels). <b>⋯</b> pour choisir
                            l'événement, le nombre ou saisir la DLC du fabricant.
                        </template>
                        <template v-else>
                            Touchez les produits préparés (chaque appui ajoute une étiquette), ou <b>Tout sélectionner</b>, puis
                            <b>Imprimer la sélection</b>.
                            <span v-if="apartCount" class="dlc-hint-apart" data-testid="dlc-apart-hint">
                                {{ apartCount }} produit{{ apartCount > 1 ? "s" : "" }} hors sélection (marqué{{ apartCount > 1 ? "s" : "" }} « À part ») : produit d'origine à indiquer, service d'un repas témoin à choisir,
                                fiche à compléter ou événement non prévu — touchez-le pour savoir quoi faire.
                            </span>
                        </template>
                    </p>

                    <div class="dlc-layout">
                        <aside v-if="watchItems.length" ref="watch" class="dlc-watch" data-testid="dlc-watch" aria-label="Étiquettes à surveiller">
                            <h4 class="dlc-h4">À surveiller</h4>
                            <ul>
                                <li v-for="w in watchItems" :key="w.key" :class="`is-${w.level}`" data-testid="dlc-watch-item">
                                    <!-- une seule ligne de gabarit : Vue supprime les espaces entre balises séparées par un saut de ligne -->
                                    <span class="dlc-watch-body"><span v-if="w.level === 'expired'" class="dlc-watch-icon" aria-hidden="true">⚠</span><strong>{{ w.name }}</strong> <span class="dlc-watch-n">({{ w.copies }} étiquette{{ w.copies > 1 ? "s" : "" }}<template v-if="w.lot"> · N° {{ w.lot }}</template>)</span><br />{{ w.text }}<template v-if="w.anomaly"><br /><span class="dlc-watch-anomaly" data-testid="dlc-watch-anomaly">{{ w.message }}</span></template><template v-if="w.unconfirmed"><br /><span class="dlc-watch-anomaly" data-testid="dlc-watch-unconfirmed">Impression non confirmée : vérifiez que l'étiquette est sortie.</span></template></span>
                                    <span v-if="w.pending" class="dlc-watch-pending" data-testid="dlc-watch-pending">⏳ hors ligne — journal en attente</span>
                                    <span v-else class="dlc-watch-actions">
                                        <button type="button" class="dlc-btn dlc-btn--small" :class="{ 'dlc-btn--danger': w.level === 'expired' }"
                                                :data-testid="`dlc-discard-${w.id}`" :disabled="discarding" @click="discard(w, 'discarded')">Jeté ✓</button>
                                        <button type="button" class="dlc-btn dlc-btn--small" :data-testid="`dlc-used-${w.id}`" :disabled="discarding"
                                                @click="discard(w, 'used')">{{ w.level === "expired" ? "Utilisé avant la DLC" : "Utilisé" }}</button>
                                    </span>
                                </li>
                            </ul>
                            <button v-if="watchMore" type="button" class="dlc-btn dlc-btn--small dlc-watch-more" data-testid="dlc-watch-more"
                                    @click="watchLimit = 300">+ {{ watchMore }} autre{{ watchMore > 1 ? "s" : "" }} lot{{ watchMore > 1 ? "s" : "" }} — tout afficher</button>
                        </aside>

                        <div ref="main" class="dlc-main">
                            <div v-if="query && !visibleProducts.length" class="dlc-empty dlc-empty--inline" data-testid="dlc-no-match">
                                <span>Aucun produit ne correspond à « {{ query }} ».</span>
                                <button v-if="recommended.missing" type="button" class="dlc-btn dlc-btn--small" :disabled="installing" data-testid="dlc-install-from-query"
                                        @click="installRecommended(false)">Ajouter les {{ recommended.missing }} produits recommandés</button>
                                <button type="button" class="dlc-btn dlc-btn--small" data-testid="dlc-add-from-query" @click="openEditor({ name: query })">Créer « {{ query }} »</button>
                            </div>
                            <p v-else-if="searchFuzzy" class="dlc-muted dlc-fuzzy-note" data-testid="dlc-fuzzy-note">Résultats proches de « {{ query }} ».</p>

                            <div v-if="category === FAVORITES && !favoriteCount" class="dlc-empty dlc-empty--inline" data-testid="dlc-fav-empty">
                                <span>Aucun favori pour l'instant. Touchez <b>☆</b> sur un produit que vous utilisez tout le temps : il sera ici, prêt à imprimer.</span>
                                <button type="button" class="dlc-btn dlc-btn--small" data-testid="dlc-fav-go-all" @click="category = ''">Voir tous les produits</button>
                            </div>
                            <div v-else-if="category === FAVORITES && !selectMode && !query" class="dlc-fav-bar">
                                <button type="button" class="dlc-btn dlc-btn--primary" :disabled="listBusy || !selectableVisible.length" data-testid="dlc-fav-prepare" @click="prepareFavorites">
                                    ☑ Préparer toute ma liste ({{ selectableVisible.length }})
                                </button>
                                <span class="dlc-muted">Sélectionne tous les favoris : il ne reste qu'à toucher « Imprimer la sélection ».</span>
                            </div>

                            <div class="dlc-grid" data-testid="dlc-grid">
                                <div v-for="p in visibleProducts" :key="p.id" class="dlc-cell">
                                    <button type="button" class="dlc-tile"
                                            :class="{ 'is-picked': selectMode && selection[p.id], 'is-expired': expiredNames.has(p.name), 'is-apart': selectMode && !selectable(p) }"
                                            :style="{ '--cat': colorOf(p.category) }" :aria-pressed="selectMode ? (selection[p.id] ? 'true' : 'false') : null"
                                            :disabled="listBusy" :data-testid="`dlc-product-${p.id}`" @click="onTile(p)">
                                        <span class="dlc-tile-top">
                                            <span v-if="selectMode" class="dlc-check" :class="{ 'is-on': selection[p.id] }" aria-hidden="true">{{ selection[p.id] ? "✓" : "" }}</span>
                                            <span class="dlc-tile-cat">{{ p.category || "Sans famille" }}</span>
                                            <span v-if="selectMode && selection[p.id]" class="dlc-tile-count" data-testid="dlc-tile-count">× {{ selection[p.id] }}</span>
                                            <span v-if="expiredNames.has(p.name)" class="dlc-tile-flag">⚠ Lot expiré</span>
                                            <span v-if="selectMode && !selectable(p)" class="dlc-tile-apart" data-testid="dlc-tile-apart">{{ apartReason(p) }}</span>
                                        </span>
                                        <span class="dlc-tile-name">{{ p.name }}</span>
                                        <span class="dlc-tile-life">{{ tileLife(p) }}</span>
                                        <span class="dlc-tile-meta">
                                            <span v-if="p.actions.length > 1">{{ p.actions.length }} événements</span>
                                            <span v-if="p.allergens.length">⚠ {{ p.allergens.length }} allergène{{ p.allergens.length > 1 ? "s" : "" }}</span>
                                            <span v-else-if="p.allergens_unreviewed" class="dlc-tile-review" data-testid="dlc-tile-review">Allergènes à renseigner</span>
                                            <span v-if="p.ask_original_dlc">DLC fabricant</span>
                                            <span v-if="p.needs_parent">Produit d'origine</span>
                                            <span v-if="ficheIncomplete(p)" class="dlc-tile-todo" data-testid="dlc-tile-todo">⚠ {{ ficheIncomplete(p) }}</span>
                                            <span v-if="p.default_copies > 1 && !selectMode">{{ p.default_copies }} par appui</span>
                                        </span>
                                        <span v-if="busyId === p.id" class="dlc-tile-busy">Impression…{{ queuedCount(p.id) ? ` (+${queuedCount(p.id)} en attente)` : "" }}</span>
                                        <span v-else-if="queuedCount(p.id)" class="dlc-tile-busy dlc-tile-busy--queued" data-testid="dlc-tile-queued">En attente ×{{ queuedCount(p.id) }}</span>
                                    </button>
                                    <button type="button" class="dlc-star" :class="{ 'is-on': p.is_favorite }" :aria-pressed="p.is_favorite ? 'true' : 'false'"
                                            :aria-label="p.is_favorite ? `Retirer des favoris : ${p.name}` : `Ajouter aux favoris : ${p.name}`" :disabled="Boolean(favoriteBusy[p.id])"
                                            :data-testid="`dlc-fav-${p.id}`" @click="toggleFavorite(p)">{{ p.is_favorite ? "★" : "☆" }}</button>
                                    <button v-if="selectMode && selection[p.id]" type="button" class="dlc-corner" :aria-label="`Retirer une étiquette ${p.name}`"
                                            :data-testid="`dlc-minus-${p.id}`" @click="minus(p)">−</button>
                                    <button v-else-if="!selectMode" type="button" class="dlc-corner dlc-corner--more" :aria-label="`Options d'impression : ${p.name}`"
                                            :data-testid="`dlc-more-${p.id}`" @click="openSheet(p)">⋯</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Sélection : prête à partir d'un seul appui. -->
                    <div v-if="basketShown" class="dlc-basket" data-testid="dlc-basket">
                        <span class="dlc-basket-text">
                            <strong data-testid="dlc-basket-total">{{ selectionTotal }} étiquette{{ selectionTotal > 1 ? "s" : "" }}</strong>
                            — {{ selectionProducts }} produit{{ selectionProducts > 1 ? "s" : "" }}
                        </span>
                        <button type="button" class="dlc-btn" :disabled="listBusy" data-testid="dlc-basket-clear" @click="selection = {}">Vider</button>
                        <button type="button" class="dlc-btn dlc-btn--primary" :disabled="listBusy || draining" data-testid="dlc-basket-print" @click="printSelection()">
                            {{ listBusy ? "Impression…" : "🖨 Imprimer la sélection" }}
                        </button>
                    </div>
                </template>

                <!-- ── Fiches produits ───────────────────────────────────────────────── -->
                <div v-if="products.length" class="dlc-section">
                    <button type="button" class="dlc-toggle" :aria-expanded="manage ? 'true' : 'false'" data-testid="dlc-manage-toggle" @click="manage = !manage">
                        {{ manage ? "▾" : "▸" }} Fiches produits ({{ products.length }})
                    </button>
                    <button type="button" class="dlc-btn dlc-btn--small dlc-import-btn" data-testid="dlc-import-open" @click="importOpen = true">⬆ Importer un fichier (CSV)</button>
                    <p v-if="manage && recommended.missing" class="dlc-muted">
                        <button type="button" class="dlc-btn dlc-btn--small" :disabled="installing" data-testid="dlc-install-more" @click="installRecommended(false)">
                            ＋ Ajouter les {{ recommended.missing }} produits recommandés manquants
                        </button>
                        Vos fiches existantes ne sont jamais modifiées.
                    </p>
                    <ul v-if="manage" class="dlc-list" data-testid="dlc-manage">
                        <li v-for="p in sortedProducts" :key="p.id" class="dlc-row" :class="{ 'is-off': !p.is_active }" :data-testid="`dlc-row-${p.id}`">
                            <span class="dlc-dot" :style="{ background: colorOf(p.category) }" aria-hidden="true"></span>
                            <span class="dlc-row-name">{{ p.name }}<small v-if="p.category"> · {{ p.category }}</small></span>
                            <span class="dlc-row-life">{{ p.actions.map((a) => `${actionLabel(a.key)} ${lifeOf(a)}`).join(" · ") }}</span>
                            <span v-if="p.duration_review === 'starting'" class="dlc-row-review" :data-testid="`dlc-row-review-${p.id}`">durées à valider</span>
                            <span v-else-if="p.duration_review === 'validated'" class="dlc-row-ok" :data-testid="`dlc-row-ok-${p.id}`">✓ validées</span>
                            <button type="button" class="dlc-btn dlc-btn--small" @click="toggleActive(p)">{{ p.is_active ? "Masquer" : "Réactiver" }}</button>
                            <button type="button" class="dlc-btn dlc-btn--small" :data-testid="`dlc-dup-${p.id}`" :aria-label="`Dupliquer ${p.name}`" @click="duplicate(p)">Dupliquer</button>
                            <button type="button" class="dlc-btn dlc-btn--small" :data-testid="`dlc-edit-${p.id}`" @click="openEditor(p)">Modifier</button>
                        </li>
                    </ul>
                </div>

                <!-- ── Historique ──────────────────────────────────────────────────────── -->
                <div class="dlc-section">
                    <h4 class="dlc-h4">Dernières étiquettes imprimées</h4>
                    <p v-if="!history.length" class="dlc-muted">Rien d'imprimé pour l'instant.</p>
                    <ul v-else class="dlc-history" data-testid="dlc-history">
                        <li v-for="h in history.slice(0, 20)" :key="h.id" class="dlc-history-row">
                            <span class="dlc-history-when">{{ short(h.created_at) }}</span>
                            <span class="dlc-history-name">{{ h.product_name }} ×{{ h.copies }}<small v-if="h.action"> · {{ actionLabel(h.action) }}</small></span>
                            <span class="dlc-history-dlc">DLC {{ short(h.expires_at) }}<small v-if="h.capped"> ({{ h.cap_source === "parent" ? "origine" : "fabricant" }})</small><small v-if="h.offline"> · hors ligne</small></span>
                            <span class="dlc-history-lot">{{ h.lot || "" }}<small v-if="h.offline && h.client_ref"> (réf. {{ offlineRefOf(h.client_ref) }})</small></span>
                            <span class="dlc-history-by">{{ h.by || "" }}</span>
                            <span class="dlc-history-state" :class="`is-${h.discarded_at ? 'discarded' : h.status}`">{{ h.discarded_at ? `${reasonText(h.discard_reason)} ${short(h.discarded_at)}${h.discarded_by ? ` par ${h.discarded_by}` : ""}` : stateText(h.status, h.created_at) }}{{ h.anomaly ? " · à vérifier" : "" }}{{ h.declared_new ? " · produit neuf déclaré" : "" }}</span>
                        </li>
                    </ul>
                </div>
            </div>
        </div>

        <!-- Résultat et « Annuler » : notices FIXES en bas d'écran — elles ne poussent jamais la grille (un appui pendant un
             saut de mise en page imprimerait le mauvais produit). -->
        <div class="dlc-toasts" :class="{ 'is-raised': basketShown }" :style="toastStyle" aria-live="polite">
            <div v-if="undo" class="dlc-undo" role="status" data-testid="dlc-undo">
                <span>{{ undo.text }}</span>
                <button type="button" class="dlc-btn dlc-btn--small" data-testid="dlc-undo-btn" @click="!toastGuarded() && undoDiscard()">Annuler</button>
            </div>
            <div v-if="last" class="dlc-last" :class="last.ok ? (last.warn ? 'is-warn' : 'is-ok') : 'is-ko'" data-testid="dlc-last" role="status">
                <div v-if="last.batch" class="dlc-last-text" data-testid="dlc-last-batch">
                    <strong>{{ last.ok ? "✓" : "⚠" }} {{ last.total }} étiquette{{ last.total > 1 ? "s" : "" }} imprimée{{ last.total > 1 ? "s" : "" }}
                        — {{ last.batch.filter((b) => b.ok).map((b) => `${b.name} ×${b.copies}`).join(", ") }}</strong>
                    <span v-if="last.failed.length" class="dlc-last-warn">Non imprimé : {{ last.failed.map((b) => `${b.name} (${b.message})`).join(" ; ") }}</span>
                    <span v-if="last.skipped.length" class="dlc-last-warn" data-testid="dlc-last-skipped">
                        Non imprimé, à faire un par un : {{ last.skipped.join(", ") }}
                    </span>
                    <span v-if="last.offline" class="dlc-last-warn">Hors ligne : inscrites au journal dès le retour du réseau.</span>
                </div>
                <div v-else class="dlc-last-text">
                    <strong>{{ last.ok ? "✓" : "⚠" }} {{ last.name }} — {{ last.copies }} étiquette{{ last.copies > 1 ? "s" : "" }}{{ last.actionLabel ? ` · ${last.actionLabel}` : "" }}</strong>
                    <span v-if="last.ok">{{ last.verb }} {{ last.opened }} · À consommer jusqu’au <b>{{ last.expires }}</b>{{ last.lot ? ` · N° ${last.lot}` : "" }}</span>
                    <span v-else>{{ last.message }}</span>
                    <span v-if="last.ok && last.capped" class="dlc-last-warn">{{ last.capSource === "parent" ? "DLC limitée à celle du produit d’origine." : "DLC limitée à celle du fabricant." }}</span>
                    <span v-if="last.ok && last.message" class="dlc-last-warn">{{ last.message }}</span>
                </div>
                <img v-if="last.preview" :src="last.preview" alt="Dernière étiquette" class="dlc-last-img" />
                <div class="dlc-last-actions">
                    <button v-if="last.ok && last.ids && last.ids.length" type="button" class="dlc-btn dlc-btn--small" data-testid="dlc-last-cancel"
                            :disabled="discarding" @click="cancelLast">Annuler cette impression</button>
                    <button type="button" class="dlc-btn dlc-btn--small dlc-btn--icon" aria-label="Fermer" data-testid="dlc-last-close" @click="closeLast">✕</button>
                </div>
            </div>
        </div>

        <DlcImportDialog v-if="importOpen" @close="importOpen = false" @imported="loadProducts" />
        <DlcCatalogueHealth v-if="healthOpen" :health="health" :products="products" :categories="knownCategories"
                            @close="healthOpen = false" @edit="editFromHealth" @changed="loadProducts" />
        <DlcProductEditor v-if="editing" :key="editorKey" :product="editing" :categories="knownCategories" :settings="settings"
                          :saving="saving" :error="formError" @save="saveProduct" @cancel="closeEditor" @delete="deleteProduct" />
        <DlcPrintSheet v-if="sheetProduct" :key="`${sheetProduct.id}-${sheetAction}`" :product="sheetProduct" :initial-action="sheetAction" :settings="settings" :server-offset-ms="serverOffsetMs" :busy="busyId === sheetProduct.id || queuedCount(sheetProduct.id) > 0"
                       :lots="lotRows.length ? lotRows : activeLots" :lots-partial="lotsError" :last-original="lastOriginalFor(sheetProduct.id)" :timezone="timezone"
                       @print="printFromSheet" @close="sheetProduct = null" @edit="editFromSheet" />

        <!-- Sélection contenant des produits « DLC du fabricant » : une seule étape, toutes les dates d'un coup (proposées
             d'après la dernière saisie de chaque produit), puis tout part ensemble. -->
        <div v-if="originals" ref="origOverlay" class="dlc-orig-overlay" data-testid="dlc-originals" @keydown.esc="originals = null" @keydown.tab="trapTab($event, $refs.origOverlay)">
            <div class="dlc-orig-panel" role="dialog" aria-modal="true" aria-label="DLC du fabricant">
                <header class="dlc-orig-head">
                    <h4>DLC du fabricant — {{ originals.length }} produit{{ originals.length > 1 ? "s" : "" }}</h4>
                    <button type="button" class="dlc-btn dlc-btn--small dlc-btn--icon" aria-label="Fermer" data-testid="dlc-originals-close" @click="originals = null">✕</button>
                </header>
                <p class="dlc-hint">Recopiez la date de l'emballage d'origine : la DLC imprimée ne la dépassera jamais.</p>
                <div v-for="o in originals" :key="o.id" class="dlc-orig-row">
                    <strong class="dlc-orig-name">{{ o.name }}</strong>
                    <input v-model="o.original_dlc" type="date" class="db-field-control" :min="todayIso" :aria-label="`DLC du fabricant : ${o.name}`"
                           :data-testid="`dlc-originals-date-${o.id}`" />
                    <input v-model="o.supplier_lot" type="text" maxlength="40" class="db-field-control dlc-orig-lot" placeholder="Lot (facultatif)"
                           :aria-label="`Lot du fabricant : ${o.name}`" :data-testid="`dlc-originals-lot-${o.id}`" />
                    <button v-if="o.last && o.last.original_dlc !== o.original_dlc" type="button" class="dlc-btn dlc-btn--small"
                            :data-testid="`dlc-originals-same-${o.id}`" @click="useSamePack(o)">
                        Même emballage ({{ o.last.original_dlc.split("-").reverse().join("/") }}{{ o.last.supplier_lot ? ` · lot ${o.last.supplier_lot}` : "" }})
                    </button>
                    <span v-if="o.original_dlc && o.original_dlc < todayIso" class="dlc-last-warn">Dépassée : ne pas utiliser.</span>
                </div>
                <footer class="dlc-orig-foot">
                    <button type="button" class="dlc-btn" @click="originals = null">Annuler</button>
                    <button type="button" class="dlc-btn dlc-btn--primary" :disabled="!originalsReady" data-testid="dlc-originals-print" @click="confirmOriginals">
                        🖨 Imprimer la sélection
                    </button>
                </footer>
            </div>
        </div>
    </div>
</template>

<script>
/**
 * [ETIQUETTES 2026-10-16 · owner « comme les concurrents (Komia…) : très configuré selon chaque produit, imprimer tout
 * ensemble ou un par un, date d'ouverture et date limite »] Étiquettes d'hygiène.
 *
 *   · un appui sur un produit = son étiquette, tout de suite (événement et nombre habituels du produit) ;
 *   · « ⋯ » = choisir l'événement (ouverture, décongélation…), le nombre, saisir la DLC du fabricant ;
 *   · « Sélection multiple » = cocher plusieurs produits (ou « Tout sélectionner », par famille) et tout imprimer ;
 *   · familles en onglets, recherche, plus utilisés en tête, « À surveiller » avec « Jeté » annulable.
 *
 * Les dates ne viennent PAS de la tablette : le serveur calcule événement et DLC avec son horloge (et la limite à la DLC du
 * fabricant ou du produit d'origine), les journalise avec un numéro et les renvoie ; le papier est identique au journal.
 * Réseau coupé : on imprime quand même avec l'heure du serveur connue au dernier échange — l'étiquette prime —, on le dit
 * sur l'étiquette, et la ligne de journal part dès le retour du réseau (file locale, sans doublon).
 *
 * Les appuis rapides sur plusieurs produits ne se perdent pas : ils attendent leur tour dans une file d'impression.
 */
import axios from "axios";
import alertService from "../../../services/alertService";
import labelPrinterMixin from "../../../helpers/labelPrinterMixin";
import LabelPrinterBar from "./LabelPrinterBar";
import DlcProductEditor from "./DlcProductEditor";
import DlcPrintSheet from "./DlcPrintSheet";
import DlcCatalogueHealth from "./DlcCatalogueHealth";
import DlcImportDialog from "./DlcImportDialog";
import HygieneOperatorPicker from "../hygiene/HygieneOperatorPicker";
import { DLC_ACTIONS, dlcLabelBlocks, formatDlcDate, previewExpiry, shelfLifeText, storageForAction, watchList, isValidThawedStorage } from "../../../helpers/dlcLabelBuilder";
import { searchProducts } from "../../../helpers/dlcSearch";
import { printBlocks, previewUrls } from "../../../helpers/labelPrintJob";
import { labelResultQueue, labelInflight } from "../../../helpers/labelResultQueue";

const PALETTE = ["#16a34a", "#dc2626", "#2563eb", "#d97706", "#7c3aed", "#0891b2", "#db2777", "#65a30d", "#ea580c", "#475569"];

function slug(v) {
    return String(v || "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
}

/** Deux appuis sur la même tuile à moins de ce délai = un rebond du doigt (ganté), pas deux étiquettes voulues. */
const DOUBLE_TAP_MS = 450;
/** Une notice qui vient d'apparaître ignore les appuis un court instant : le doigt visait ce qui était dessous. */
const TOAST_GUARD_MS = 600;
/** Écran laissé ouvert : l'heure avance (échéances, « expiré ») et la surveillance se relit (autres tablettes). */
const CLOCK_TICK_MS = 30000;
const WATCH_REFRESH_MS = 180000;
/** Une étiquette imprimée hors ligne attend son inscription au journal jusqu'à 7 jours (longue coupure). */
const OFFLINE_KEEP_MS = 7 * 24 * 3600 * 1000;
const NO_FAMILY = "__none__";
const FAVORITES = "__fav__";

function clientRef() {
    return `t${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

const SUGGESTIONS = [
    { name: "Oignons émincés", category: "Légumes & crudités", actions: [{ key: "prepared", value: 24, unit: "hours" }], storage: "0 à +4 °C", allergens: [] },
    { name: "Tomates tranchées", category: "Légumes & crudités", actions: [{ key: "prepared", value: 24, unit: "hours" }], storage: "0 à +4 °C", allergens: [] },
    { name: "Olives", category: "Épicerie", actions: [{ key: "opened", value: 3, unit: "days" }], storage: "0 à +4 °C", allergens: [] },
    { name: "Champignons", category: "Légumes & crudités", actions: [{ key: "opened", value: 24, unit: "hours" }], storage: "0 à +4 °C", allergens: [] },
    { name: "Poulet mariné", category: "Viandes & volailles", actions: [{ key: "thawed", value: 3, unit: "days" }, { key: "opened", value: 48, unit: "hours" }], storage: "0 à +3 °C", allergens: ["moutarde"] },
    { name: "Fromage râpé", category: "Fromages & produits laitiers", actions: [{ key: "opened", value: 3, unit: "days" }], storage: "0 à +4 °C", allergens: ["lait"], ask_original_dlc: true },
    { name: "Sauce blanche maison", category: "Sauces", actions: [{ key: "prepared", value: 48, unit: "hours" }], storage: "0 à +4 °C", allergens: ["lait", "oeufs"] },
].map((s) => ({ ...s, slug: slug(s.name) }));

export default {
    name: "LabelDlcComponent",
    components: { LabelPrinterBar, DlcProductEditor, DlcPrintSheet, DlcCatalogueHealth, DlcImportDialog, HygieneOperatorPicker },
    mixins: [labelPrinterMixin],
    data() {
        return {
            products: [],
            recommended: { total: 0, missing: 0, never_configured: false },
            // Contrôle du catalogue (serveur) : durées de départ à valider, allergènes à renseigner, fiches sans famille, fiches voisines
            health: { starting: [], allergens: [], no_category: [], neighbours: [] },
            healthOpen: false,
            importOpen: false,
            installing: false,
            justInstalled: 0,
            autoInstallTried: false,
            history: [],
            watchRows: [],
            lotRows: [],
            watchLimit: 30,
            nowMs: Date.now(),
            clockTimer: null,
            refreshTimer: null,
            visibilityHandler: null,
            toastStyle: {},
            toastShownAt: 0,
            originals: null,
            pendingOffline: [],
            origReturnFocus: null,
            lotsError: false,
            busyId: null,
            queue: [],
            draining: false,
            lastTapAt: {},
            lastTimer: null,
            onlineHandler: null,
            loaded: false,
            discarding: false,
            operatorId: null, operatorToken: null, operatorMe: null,
            favoriteBusy: {},
            undo: null,
            undoTimer: null,
            selectMode: false,
            selection: {},
            batchAction: "",
            listBusy: false,
            last: null,
            loadError: "",
            manage: false,
            editing: null,
            editorKey: 0,
            sheetProduct: null,
            sheetAction: "",
            formError: "",
            saving: false,
            serverOffsetMs: 0,
            query: "",
            category: "",
            timezone: "Europe/Paris",
        };
    },
    computed: {
        activeProducts() {
            return this.products.filter((p) => p.is_active);
        },
        sortedProducts() {
            return [...this.products].sort((a, b) => a.name.localeCompare(b.name, "fr"));
        },
        knownCategories() {
            return [...new Set(this.products.map((p) => p.category).filter(Boolean))];
        },
        NO_FAMILY() { return NO_FAMILY; },
        FAVORITES() { return FAVORITES; },
        favoriteCount() { return this.activeProducts.filter((p) => p.is_favorite).length; },
        healthCount() {
            const h = this.health;
            return h.starting.length + h.allergens.length + h.no_category.length + h.neighbours.length;
        },
        uncategorizedCount() { return this.activeProducts.filter((p) => !p.category).length; },
        todayIso() {
            return new Intl.DateTimeFormat("fr-CA", { timeZone: this.timezone }).format(new Date(this.nowMs + this.serverOffsetMs));
        },
        originalsReady() {
            return Boolean(this.originals && this.originals.every((o) => o.original_dlc && o.original_dlc >= this.todayIso));
        },
        categoryTabs() {
            const counts = new Map();
            this.activeProducts.forEach((p) => { if (p.category) counts.set(p.category, (counts.get(p.category) || 0) + 1); });
            return [...counts.entries()].sort((a, b) => a[0].localeCompare(b[0], "fr")).map(([name, count]) => ({ name, count, slug: slug(name) }));
        },
        actionChoices() {
            const keys = new Set();
            this.activeProducts.forEach((p) => p.actions.forEach((a) => keys.add(a.key)));
            return Object.values(DLC_ACTIONS).filter((a) => keys.has(a.key));
        },
        /** Produits dont un lot imprimé est échu : repérés directement sur leur tuile. */
        expiredNames() {
            return new Set(this.watchItems.filter((w) => w.level === "expired").map((w) => w.name));
        },
        expiredCount() {
            return this.watchItems.filter((w) => w.level === "expired").length;
        },
        /** Lots en cours (journal + surveillance, sans doublon) : origines possibles d'une préparation. */
        activeLots() {
            const byId = new Map();
            [...this.watchRows, ...this.history].forEach((h) => { if (!byId.has(h.id)) byId.set(h.id, h); });
            return [...byId.values()];
        },
        basketShown() {
            return this.selectMode && (this.selectionTotal > 0 || this.listBusy);
        },
        /** En sélection : les produits qu'on peut imprimer en lot (les autres s'impriment un par un). */
        selectableVisible() {
            return this.visibleProducts.filter((p) => this.selectable(p));
        },
        apartCount() {
            return this.visibleProducts.length - this.selectableVisible.length;
        },
        suggestions() { return SUGGESTIONS; },
        /** Famille, recherche ; les plus utilisés (30 jours) d'abord, puis l'ordre alphabétique. */
        visibleProducts() { return this.searchResult.items; },
        /** Recherche tolérante (accents, pluriel, fautes de frappe) : les correspondances exactes d'abord, les proches ensuite. */
        searchResult() {
            const inFamily = this.activeProducts
                .filter((p) => !this.category || (this.category === FAVORITES ? p.is_favorite : this.category === NO_FAMILY ? !p.category : p.category === this.category))
                .sort((a, b) => (b.uses_30d || 0) - (a.uses_30d || 0) || a.name.localeCompare(b.name, "fr"));
            return searchProducts(inFamily, this.query);
        },
        searchFuzzy() { return Boolean(this.query) && this.searchResult.fuzzy; },
        // `nowMs` avance toutes les 30 s : sans lui, l'écran laissé ouvert ne verrait jamais un lot devenir « expiré ».
        // Les étiquettes imprimées hors ligne pas encore journalisées y figurent aussi (sans « Jeté » : rien à acquitter
        // côté serveur tant qu'elles n'y sont pas).
        watch() {
            const pending = this.pendingOffline.map((q) => ({
                id: `q-${q.qid || q.at}`, dlc_product_id: q.body.product_id, product_name: q.body.product_name || "",
                copies: q.body.copies, expires_at: q.body.expires_at, status: "printed", lot: "", pending: true,
            }));
            return watchList([...this.watchRows, ...pending], this.nowMs + this.serverOffsetMs, this.watchLimit);
        },
        watchItems() { return this.watch.items; },
        watchMore() { return this.watch.more; },
        selectionTotal() {
            return Object.values(this.selection).reduce((n, c) => n + c, 0);
        },
        selectionProducts() {
            return Object.keys(this.selection).length;
        },
    },
    watch: {
        // La fenêtre « DLC du fabricant » prend le focus (clavier, lecteur d'écran) et le rend à la fermeture.
        originals(now, before) {
            if (now && !before) {
                this.origReturnFocus = document.activeElement;
                this.$nextTick(() => {
                    const first = this.$refs.origOverlay && this.$refs.origOverlay.querySelector("input");
                    if (first && first.focus) first.focus();
                });
            } else if (!now && before) {
                const el = this.origReturnFocus;
                this.origReturnFocus = null;
                // le bouton d'origine (« Imprimer la sélection ») est désactivé puis disparaît : le focus revient au mode
                // « Sélection multiple » (vérifié aussi à la fin de l'impression, voir focusAfterBatch)
                this.$nextTick(() => {
                    const usable = el && document.contains(el) && !el.disabled && el.offsetParent !== null;
                    const target = usable ? el : this.$el.querySelector('[data-testid="dlc-mode-list"]');
                    if (target && target.focus) target.focus();
                });
            }
        },
        // Un autre événement de lot : les produits qui ne le prévoient pas sortent de la sélection (jamais d'étiquette
        // « Entamé le » pour un produit qu'on vient de sortir du congélateur).
        batchAction() {
            const next = {};
            Object.entries(this.selection).forEach(([id, n]) => {
                const p = this.products.find((x) => String(x.id) === String(id));
                if (p && this.selectable(p)) next[id] = n;
            });
            this.selection = next;
        },
    },
    mounted() {
        labelInflight(axios).recover();
        this.loadProducts();
        this.syncOffline();
        // Réseau revenu : les étiquettes imprimées hors ligne partent au journal.
        this.onlineHandler = () => this.syncOffline();
        window.addEventListener("online", this.onlineHandler);
        // Tablette posée, écran ouvert entre deux services : l'heure avance, la surveillance se relit (lots imprimés
        // ailleurs, échéances franchies), et tout de suite quand l'écran redevient visible.
        this.clockTimer = setInterval(() => { this.nowMs = Date.now(); }, CLOCK_TICK_MS);
        this.refreshTimer = setInterval(() => this.syncOffline(), WATCH_REFRESH_MS);
        this.visibilityHandler = () => {
            if (document.visibilityState === "visible") { this.nowMs = Date.now(); this.syncOffline(); }
        };
        document.addEventListener("visibilitychange", this.visibilityHandler);
        window.addEventListener("focus", this.visibilityHandler);
    },
    beforeUnmount() {
        clearTimeout(this.undoTimer);
        clearTimeout(this.lastTimer);
        clearInterval(this.clockTimer);
        clearInterval(this.refreshTimer);
        if (this.onlineHandler) window.removeEventListener("online", this.onlineHandler);
        if (this.visibilityHandler) {
            document.removeEventListener("visibilitychange", this.visibilityHandler);
            window.removeEventListener("focus", this.visibilityHandler);
        }
    },
    methods: {
        norm(v) {
            return String(v || "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase().trim();
        },
        colorOf(category) {
            if (!category) return "#94a3b8";
            let h = 0;
            for (const ch of String(category)) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
            return PALETTE[h % PALETTE.length];
        },
        actionLabel(key) { return (DLC_ACTIONS[key] || DLC_ACTIONS.opened).label; },
        lifeOf(a) { return shelfLifeText(a.value, a.unit); },
        tileLife(p) {
            const a = p.actions[0];
            // espaces insécables : « +4 °C » ou « 2 jours » ne se coupent jamais en fin de ligne
            const nb = (t) => String(t || "").replace(/ (°C|jours?|h)\b/g, "\u00a0$1");
            // la conservation de l'événement par défaut (un produit décongelé n'est jamais affiché « ≤ −18 °C »)
            return [`${this.actionLabel(a.key)} · DLC +${nb(this.lifeOf(a))}`, nb(storageForAction(p.storage, a.key, p.thawed_storage))].filter(Boolean).join(" · ");
        },
        offlineRefOf(ref) { return String(ref || "").slice(-6).toUpperCase(); },
        /** Dernière DLC / lot du fabricant saisis pour ce produit, s'ils sont encore valables (même emballage entamé). */
        lastOriginalFor(productId) {
            // seulement une impression réellement sortie et pas annulée ni jetée (l'emballage jeté n'est plus « le même »)
            const rows = [...this.lotRows, ...this.history]
                .filter((h) => h.dlc_product_id === productId && h.original_dlc && h.original_dlc >= this.todayIso
                    && ["printed", "unconfirmed"].includes(h.status) && !["cancelled", "discarded"].includes(h.discard_reason))
                .sort((a, b) => b.id - a.id);
            return rows.length ? { original_dlc: rows[0].original_dlc, supplier_lot: rows[0].supplier_lot || "" } : null;
        },
        short(iso) {
            if (!iso) return "";
            const d = new Date(iso);
            if (Number.isNaN(d.getTime())) return "";
            try {
                return new Intl.DateTimeFormat("fr-FR", { timeZone: this.timezone, day: "2-digit", month: "2-digit", hour: "2-digit", minute: "2-digit" })
                    .format(d).replace(",", "");
            } catch (_) {
                return "";
            }
        },
        stateText(s, createdAt) {
            // réservée depuis plus de 10 min sans verdict : peut-être imprimée — « non confirmée », comme dans « À surveiller »
            if (s === "claimed" && createdAt && Date.now() + this.serverOffsetMs - Date.parse(createdAt) > 10 * 60000) return "non confirmée";
            return { printed: "imprimée", failed: "échec", unconfirmed: "non confirmée", claimed: "en cours" }[s] || s;
        },
        reasonText(r) {
            return { used: "utilisé", cancelled: "annulée" }[r] || "jeté";
        },
        /**
         * Imprimable en lot ? Les produits « DLC du fabricant » le sont (une étape commune demande leurs dates) ; restent
         * à part ceux qui exigent leur produit d'origine, une fiche sans conservation, ou un événement qu'ils ne prévoient pas.
         */
        /** Cet événement exige une saisie sur la fiche : l'origine du produit (fiche « fait à partir de… », congélation : jamais de recongélation) ou le service d'un repas témoin. */
        needsOrigin(p, actionKey) { const key = actionKey || (p.actions[0] && p.actions[0].key); return Boolean(p.needs_parent) || key === "frozen" || key === "witness"; }, // repas témoin : le service se choisit sur la fiche, jamais en un appui à l'aveugle
        selectable(p) {
            if (this.needsOrigin(p, this.batchAction) || this.ficheIncomplete(p)) return false;
            if (this.batchAction && !p.actions.some((a) => a.key === this.batchAction)) return false;
            return !(this.batchAction === "thawed" && !isValidThawedStorage(p.thawed_storage));
        },
        /** Le produit prévoit l'événement du lot, mais sa fiche ne permet pas de l'imprimer (décongélation sans conservation valide). */
        batchIncomplete(p) {
            return this.batchAction === "thawed" && p.actions.some((a) => a.key === "thawed") && !isValidThawedStorage(p.thawed_storage);
        },
        /** Ce qui manque à la fiche pour imprimer son événement par défaut ('' = rien). */
        ficheIncomplete(p) {
            if (!p.storage) return "Conservation à renseigner";
            if (p.actions[0] && p.actions[0].key === "thawed" && !isValidThawedStorage(p.thawed_storage)) return "Conservation après décongélation à renseigner";
            return "";
        },
        apartReason(p) {
            if (this.ficheIncomplete(p) || this.batchIncomplete(p)) return "À part (fiche à compléter)";
            if (this.needsOrigin(p, this.batchAction)) return (this.batchAction || (p.actions[0] && p.actions[0].key)) === "witness" ? "À part (service à choisir)" : "À part (produit d'origine)";
            return `Sans ${this.actionLabel(this.batchAction).toLowerCase()}`;
        },
        queuedCount(id) {
            return this.queue.filter((j, i) => j.product.id === id && !(i === 0 && this.busyId === id)).length;
        },
        scrollToWatch() {
            const el = this.$refs.watch;
            if (el && el.scrollIntoView) el.scrollIntoView({ block: "start", behavior: "smooth" });
        },
        apiMessage(e, fallback) {
            const data = e && e.response && e.response.data;
            if (data && data.errors) {
                const first = Object.values(data.errors)[0];
                if (Array.isArray(first) && first[0]) return first[0];
            }
            return (data && data.message) || fallback;
        },

        // ── chargement ─────────────────────────────────────────────────────────
        normalizeProduct(p) {
            const actions = Array.isArray(p.actions) && p.actions.length
                ? p.actions
                : [{ key: "opened", value: p.shelf_life_value, unit: p.shelf_life_unit }];
            return {
                ...p,
                actions,
                allergens: Array.isArray(p.allergens) ? p.allergens : [],
                default_copies: Number(p.default_copies) || 1,
                print_operator: p.print_operator === undefined ? true : Boolean(p.print_operator),
                ask_original_dlc: Boolean(p.ask_original_dlc),
                needs_parent: Boolean(p.needs_parent),
                no_allergens: Boolean(p.no_allergens),
                thawed_storage: p.thawed_storage || "",
            };
        },
        async loadProducts() {
            try {
                const { data } = await axios.get("admin/labels/products");
                this.products = (data.data || []).map(this.normalizeProduct);
                if (data.meta) {
                    if (data.meta.timezone) this.timezone = data.meta.timezone;
                    const server = Date.parse(data.meta.server_now);
                    if (Number.isFinite(server)) this.serverOffsetMs = server - Date.now();
                }
                // ★ Favoris n'est pas une famille : une modification de fiche ne doit jamais renvoyer la personne sur « Tous » sans explication
                if (this.category && this.category !== FAVORITES && !(this.category === NO_FAMILY && this.uncategorizedCount) && !this.categoryTabs.some((c) => c.name === this.category)) this.category = "";
                this.loadError = "";
                this.loaded = true;
                const hl = data.meta && data.meta.health;
                this.health = {
                    starting: (hl && hl.starting) || [], allergens: (hl && hl.allergens) || [],
                    no_category: (hl && hl.no_category) || [], neighbours: (hl && hl.neighbours) || [],
                };
                const rec = data.meta && data.meta.recommended;
                if (rec) this.recommended = { total: Number(rec.total) || 0, missing: Number(rec.missing) || 0, never_configured: Boolean(rec.never_configured) };
                // Premier lancement : le catalogue arrive tout seul, une seule fois (jamais après une suppression volontaire).
                if (this.recommended.never_configured && !this.autoInstallTried) {
                    this.autoInstallTried = true;
                    await this.installRecommended(true);
                }
            } catch (e) {
                this.loadError = "Liste des produits indisponible. Vérifiez la connexion puis rechargez la page.";
            }
        },
        /** Ajoute les produits recommandés absents. `auto` : premier lancement (ne fait rien si le restaurant a déjà configuré). */
        async installRecommended(auto) {
            if (this.installing) return;
            this.installing = true;
            try {
                const { data } = await axios.post("admin/labels/products/install-recommended", auto ? { only_if_empty: true } : {});
                const added = Number(data && data.data && data.data.added) || 0;
                if (added > 0) {
                    await this.loadProducts();
                    if (auto) this.justInstalled = added;
                    else alertService.success(`${added} produit${added > 1 ? "s" : ""} ajouté${added > 1 ? "s" : ""}.`);
                } else if (!auto) {
                    alertService.success("Tous les produits recommandés sont déjà dans votre liste.");
                }
            } catch (e) {
                alertService.error(this.apiMessage(e, "Installation impossible. Vérifiez la connexion puis réessayez."));
            } finally {
                this.installing = false;
            }
        },
        async loadHistory() {
            const [hist, watch] = await Promise.allSettled([
                axios.get("admin/labels/history", { params: { kind: "dlc" } }),
                axios.get("admin/labels/watch"),
            ]);
            // Réseau coupé : on garde ce qu'on avait (la surveillance ne se vide pas d'un coup).
            if (hist.status === "fulfilled") this.history = hist.value.data.data || [];
            if (watch.status === "fulfilled") {
                this.watchRows = watch.value.data.data || [];
                this.nowMs = Date.now();
                const meta = watch.value.data.meta;
                const server = meta && Date.parse(meta.server_now);
                if (Number.isFinite(server)) this.serverOffsetMs = server - Date.now();
            }
        },
        /** Rejoue la file locale (verdicts et étiquettes imprimées hors ligne), puis relit journal et surveillance. */
        async syncOffline() {
            try { await labelResultQueue(axios).flush(); } catch (_) { /* rejouée plus tard */ }
            this.refreshPending();
            await this.loadHistory();
        },
        /** Étiquettes imprimées hors ligne dont la ligne de journal attend le réseau (ou la reconnexion). */
        refreshPending() {
            try {
                this.pendingOffline = labelResultQueue(axios).items()
                    .filter((i) => /labels\/dlc\/offline$/.test(i.url) && i.body && i.body.status !== "failed");
            } catch (_) {
                this.pendingOffline = [];
            }
        },
        trapTab(e, root) {
            if (!root) return;
            const items = [...root.querySelectorAll("button:not([disabled]), input:not([disabled]), select")];
            if (!items.length) return;
            const first = items[0];
            const last = items[items.length - 1];
            if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        },
        useSamePack(o) {
            o.original_dlc = o.last.original_dlc;
            o.supplier_lot = o.last.supplier_lot || "";
        },

        // ── impression ─────────────────────────────────────────────────────────
        /** « Mes produits du jour » : sélectionne tous les favoris d'un appui ; l'impression se fait ensuite d'un seul appui sur le panier. */
        prepareFavorites() {
            this.setSelectMode(true);
            this.selectAll();
        },
        /** Étoile : change tout de suite à l'écran, puis le serveur confirme ; un échec remet l'étoile comme avant et le dit. */
        async toggleFavorite(p) {
            if (this.favoriteBusy[p.id]) return; // une requête par produit à la fois (A, B puis A de nouveau avant le retour : pas deux envois concurrents sur A)
            const want = !p.is_favorite;
            this.favoriteBusy = { ...this.favoriteBusy, [p.id]: true };
            p.is_favorite = want;
            // un produit retiré des favoris DISPARAÎT de la vue ★ : il ne doit pas rester dans la sélection, invisible, et s'imprimer
            if (!want && this.category === FAVORITES && this.selection[p.id]) {
                const { [p.id]: _gone, ...rest } = this.selection;
                this.selection = rest;
            }
            try {
                const { data } = await axios.put(`admin/labels/products/${p.id}/favorite`, { favorite: want });
                p.is_favorite = Boolean(data && data.data ? data.data.is_favorite : want);
            } catch (e) {
                p.is_favorite = !want;
                alertService.error("Favori non enregistré : vérifiez la connexion puis réessayez.");
            } finally {
                const { [p.id]: _done, ...rest } = this.favoriteBusy;
                this.favoriteBusy = rest;
            }
        },
        setSelectMode(on) {
            this.selectMode = on;
            this.selection = {};
        },
        /** Un appui : imprime tout de suite (ou ouvre la fiche si une saisie est obligatoire) ; en sélection, ajoute. */
        onTile(p) {
            if (this.selectMode) {
                if (!this.selectable(p)) {
                    // Produit à imprimer seul : on mène là où il faut agir, avec la raison.
                    if (this.ficheIncomplete(p) || this.batchIncomplete(p)) {
                        return this.openEditorFor(p, `${this.ficheIncomplete(p) || "Conservation après décongélation à renseigner"} : elle est imprimée sur l’étiquette.`);
                    }
                    if (this.needsOrigin(p, this.batchAction)) return this.openSheet(p, this.batchAction);
                    alertService.error(`« ${p.name} » ne prévoit pas l'événement « ${this.actionLabel(this.batchAction)} ».`);
                    return null;
                }
                const n = (this.selection[p.id] || 0) + (this.selection[p.id] ? 1 : p.default_copies);
                this.selection = { ...this.selection, [p.id]: Math.min(n, 20) };
                return null;
            }
            if (this.ficheIncomplete(p)) return this.openEditorFor(p, `${this.ficheIncomplete(p)} : elle est imprimée sur l’étiquette.`);
            // une saisie est obligatoire (DLC du fabricant, produit d'origine) : la fiche d'impression, jamais un tir à l'aveugle
            if (p.ask_original_dlc || this.needsOrigin(p)) return this.openSheet(p);
            const now = Date.now();
            if (now - (this.lastTapAt[p.id] || 0) < DOUBLE_TAP_MS) return null; // rebond du doigt
            this.lastTapAt = { ...this.lastTapAt, [p.id]: now };
            return this.printProduct(p, { copies: p.default_copies });
        },
        selectAll() {
            const next = { ...this.selection };
            this.selectableVisible.forEach((p) => { if (!next[p.id]) next[p.id] = p.default_copies; });
            this.selection = next;
        },
        minus(p) {
            const n = (this.selection[p.id] || 0) - 1;
            if (n <= 0) {
                const { [p.id]: _gone, ...rest } = this.selection;
                this.selection = rest;
            } else {
                this.selection = { ...this.selection, [p.id]: n };
            }
        },
        openSheet(p, action = "") {
            this.sheetAction = action || "";
            this.sheetProduct = p;
            this.loadLots();
        },
        /** Lots en cours, journal entier (origines possibles) — relus à chaque ouverture de fiche. */
        async loadLots() {
            try {
                const { data } = await axios.get("admin/labels/lots");
                this.lotRows = data.data || [];
                this.lotsError = false;
            } catch (_) {
                // on garde ce qu'on a (journal + surveillance), et la fiche le DIT : la liste des origines est partielle
                this.lotsError = true;
            }
        },
        openEditorFor(p, message) {
            this.openEditor(p);
            this.formError = message;
            return null;
        },
        editFromSheet(p) {
            this.sheetProduct = null;
            this.openEditor(p);
        },
        async printFromSheet(choice) {
            const p = this.sheetProduct;
            const result = await this.printProduct(p, choice);
            if (result && result.ok) this.sheetProduct = null;
        },
        /**
         * File d'impression : chaque appui prend son tour (l'imprimante ne fait qu'une chose à la fois). Rien n'est perdu ;
         * la tuile en attente le montre. @returns {Promise<{ok:boolean, message?:string}|null>}
         */
        printProduct(product, opts = {}) {
            if (this.listBusy) return Promise.resolve(null);
            return new Promise((resolve) => {
                this.queue.push({ product, opts, resolve });
                this.drain();
            });
        },
        async drain() {
            if (this.draining) return;
            this.draining = true;
            try {
                while (this.queue.length) {
                    const job = this.queue[0];
                    this.busyId = job.product.id;
                    let result = { ok: false, message: "Impression interrompue." };
                    try {
                        result = await this.printOne(job.product, job.opts);
                    } catch (e) {
                        result = { ok: false, message: (e && e.message) || "Impression interrompue." };
                    }
                    this.queue.shift();
                    job.resolve(result);
                    // Une panne d'imprimante : les appuis suivants ne partiraient pas mieux — on les rend, on le dit.
                    // (un refus du serveur ne concerne que CE produit : la file continue)
                    if (!result.ok && !result.refused && this.queue.length) {
                        const dropped = this.queue.splice(0);
                        dropped.forEach((j) => j.resolve({ ok: false, message: result.message }));
                        alertService.error(`${dropped.length} impression${dropped.length > 1 ? "s" : ""} en attente annulée${dropped.length > 1 ? "s" : ""} : ${result.message || "imprimante indisponible"}.`);
                    }
                }
            } finally {
                this.busyId = null;
                this.draining = false;
                this.loadHistory();
            }
        },
        /** Sélection : un travail d'impression par produit, dans l'ordre d'affichage ; on s'arrête à la première panne. */
        async printSelection(originalsById = null) {
            if (this.listBusy || this.draining || !this.selectionTotal) return;
            // Des produits « DLC du fabricant » dans la sélection : leurs dates d'abord, en une seule étape.
            if (!originalsById) {
                const needing = this.activeProducts.filter((p) => this.selection[p.id] && p.ask_original_dlc && this.selectable(p));
                if (needing.length) {
                    // jamais pré-remplies : la date d'un nouvel emballage se lit dessus ; « Même emballage » reprend la dernière
                    this.originals = needing.map((p) => ({ id: p.id, name: p.name, original_dlc: "", supplier_lot: "", last: this.lastOriginalFor(p.id) }));
                    return;
                }
            }
            this.listBusy = true;
            const batch = [];
            const skipped = [];
            try {
                const order = this.activeProducts.filter((x) => this.selection[x.id]);
                const rank = (p) => { const i = this.visibleProducts.indexOf(p); return i < 0 ? 1e6 : i; };
                order.sort((a, b) => rank(a) - rank(b));
                for (const p of order) {
                    const count = this.selection[p.id];
                    // la DLC du fabricant se saisit produit par produit ; un événement non prévu n'est JAMAIS remplacé par un autre
                    if (!this.selectable(p)) { skipped.push(p.name); continue; }
                    const orig = originalsById && originalsById[p.id];
                    const result = await this.printOne(p, {
                        copies: count, action: this.batchAction || null, quiet: true,
                        original_dlc: orig ? orig.original_dlc : undefined, supplier_lot: orig ? orig.supplier_lot : undefined,
                    });
                    batch.push({ name: p.name, copies: count, ok: result.ok, message: result.message, ids: result.ids || [], offline: result.offline });
                    if (!result.ok && (!result.refused || result.operatorRefused)) break; // panne d'imprimante OU refus de « Qui ? » : inutile d'enchaîner, la sélection reste pour réessayer
                    if (!result.ok) continue; // refusé (ce produit seulement) : il reste sélectionné, les autres partent
                    const { [p.id]: _done, ...rest } = this.selection;
                    this.selection = rest;
                }
                const failed = batch.filter((b) => !b.ok);
                this.showLast({
                    ok: failed.length === 0, warn: skipped.length > 0, batch, failed, skipped,
                    total: batch.filter((b) => b.ok).reduce((n, b) => n + b.copies, 0), preview: "",
                    ids: batch.filter((b) => b.ok).flatMap((b) => b.ids), offline: batch.some((b) => b.offline),
                });
            } finally {
                this.listBusy = false;
                this.loadHistory();
                this.focusAfterBatch();
            }
        },
        /** Fin d'une impression en lot : si le focus est tombé dans le vide (bouton disparu), il revient à « Sélection multiple ». */
        focusAfterBatch() {
            this.$nextTick(() => {
                const a = document.activeElement;
                if (a && a !== document.body && document.contains(a) && a.offsetParent !== null) return;
                const target = this.$el.querySelector && this.$el.querySelector('[data-testid="dlc-mode-list"]');
                if (target && target.focus) target.focus();
            });
        },
        confirmOriginals() {
            if (!this.originalsReady) return;
            const byId = Object.fromEntries(this.originals.map((o) => [o.id, { original_dlc: o.original_dlc, supplier_lot: String(o.supplier_lot || "").trim() || undefined }]));
            this.originals = null;
            this.printSelection(byId);
        },
        /**
         * Le résultat s'affiche en notice fixe (sans lui, on croit que rien ne s'est passé et on retape : doublon), posée
         * AU-DESSUS DE LA GRILLE — jamais sur les boutons « Jeté / Utilisé » de la surveillance. Un succès s'efface seul au
         * bout de 15 s ; un échec reste jusqu'à ce qu'on le ferme.
         */
        showLast(last) {
            this.placeToasts();
            this.last = last;
            this.toastShownAt = Date.now();
            clearTimeout(this.lastTimer);
            if (last.ok && !last.warn) this.lastTimer = setTimeout(() => { this.last = null; }, 15000);
        },
        placeToasts() {
            const el = this.$refs.main;
            if (!el || !el.getBoundingClientRect || typeof window === "undefined" || window.innerWidth < 1100) { this.toastStyle = {}; return; }
            const r = el.getBoundingClientRect();
            if (!r.width) { this.toastStyle = {}; return; }
            this.toastStyle = { left: `${Math.round(r.left)}px`, right: "auto", width: `${Math.round(Math.min(560, r.width))}px` };
        },
        toastGuarded() {
            return Date.now() - this.toastShownAt < TOAST_GUARD_MS;
        },
        closeLast() {
            clearTimeout(this.lastTimer);
            this.last = null;
        },
        /** Étiquette sortie par erreur : la ligne reste au journal, marquée « annulée », et quitte la surveillance. */
        async cancelLast() {
            if (this.toastGuarded()) return; // la notice vient d'apparaître sous le doigt
            if (!this.last || !this.last.ids || !this.last.ids.length) return;
            const name = this.last.batch ? "la sélection" : `« ${this.last.name} »`;
            await this.discard({ ids: this.last.ids, name }, "cancelled");
            this.closeLast();
        },
        /** Réserve les dates côté serveur, imprime, rend compte du résultat. @returns {Promise<{ok:boolean, message?:string}>} */
        async printOne(product, opts = {}) {
            const copies = Number(opts.copies) || product.default_copies || 1;
            let data = null;
            let offline = false;
            try {
                const res = await axios.post("admin/labels/dlc/print", {
                    product_id: product.id, copies, action: opts.action || undefined, original_dlc: opts.original_dlc || undefined,
                    supplier_lot: opts.supplier_lot || undefined, parent_log_id: opts.parent_log_id || undefined,
                    parent_none: opts.parent_none || undefined, meal_slot: opts.meal_slot || undefined,
                    operator_user_id: this.operatorId || undefined, operator_token: this.operatorToken || undefined,
                });
                data = res.data;
            } catch (e) {
                if (e && e.response && e.response.status && e.response.status < 500) {
                    const message = this.apiMessage(e, "Impression refusée.");
                    alertService.error(message);
                    // le refus vient de « Qui ? » (code manquant, expiré, bloqué) : il vaut pour TOUS les produits, inutile d'enchaîner le lot
                    const errs = e.response.data && e.response.data.errors ? Object.keys(e.response.data.errors) : [];
                    const operatorRefused = errs.some((k) => k.startsWith("operator_"));
                    return { ok: false, message, refused: true, operatorRefused }; // refus propre à CE produit : l'imprimante, elle, va bien
                }
                if (product.ask_original_dlc || opts.parent_log_id) {
                    const message = product.ask_original_dlc
                        ? "Hors ligne : la DLC du fabricant ne peut pas être vérifiée, réessayez quand le réseau revient."
                        : "Hors ligne : le produit d'origine ne peut pas être vérifié, réessayez quand le réseau revient.";
                    alertService.error(message);
                    return { ok: false, message, refused: true };
                }
                const action = opts.action ? product.actions.find((a) => a.key === opts.action) : product.actions[0];
                if (!action) {
                    const message = `« ${product.name} » ne prévoit pas cet événement.`;
                    alertService.error(message);
                    return { ok: false, message, refused: true };
                }
                // Réseau coupé : on imprime avec l'horloge du serveur connue au dernier échange ; le journal suivra.
                offline = true;
                const now = new Date(Date.now() + this.serverOffsetMs);
                const ref = clientRef();
                data = {
                    log_id: null, name: product.name, category: product.category, note: product.note,
                    storage: storageForAction(product.storage, action.key, product.thawed_storage), allergens: product.allergens,
                    no_allergens: product.no_allergens, action: action.key,
                    opened_at: now.toISOString(), expires_at: previewExpiry(action, now).toISOString(), copies,
                    by: null, lot: null, capped: false, offline: true, client_ref: ref, meal_slot: opts.meal_slot || null,
                };
            }

            const blocks = dlcLabelBlocks({
                name: data.name, category: data.category, note: data.note, action: data.action,
                openedAt: data.opened_at, expiresAt: data.expires_at, storage: data.storage, by: data.by, lot: data.lot,
                allergens: data.allergens, noAllergens: Boolean(data.no_allergens), capped: data.capped, capSource: data.cap_source,
                originalDlc: data.original_dlc, supplierLot: data.supplier_lot, parent: data.parent, offline: Boolean(data.offline),
                offlineRef: data.client_ref ? this.offlineRefOf(data.client_ref) : "", mealSlot: data.meal_slot || "",
            });
            const resultUrl = data.log_id ? `admin/labels/prints/${data.log_id}/result` : null;
            if (resultUrl) labelInflight(axios).begin(data.log_id, resultUrl, data.attempt || null);
            const result = await printBlocks(this.printerInstance(), Array.from({ length: data.copies }, () => blocks), this.settings);

            if (resultUrl) {
                // le verdict d'une étiquette DLC est gardé 7 jours en file : une longue coupure ne le perd jamais
                await labelResultQueue(axios).send(resultUrl, {
                    status: result.ok ? (result.unconfirmed ? "unconfirmed" : "printed") : "failed",
                    message: result.message ? String(result.message).slice(0, 290) : null,
                    printed_pages: result.ok ? data.copies : (result.pages || 0),
                    attempt: data.attempt || null,
                }, { maxAgeMs: OFFLINE_KEEP_MS });
                labelInflight(axios).end(data.log_id);
            } else if (offline) {
                // La ligne de journal partira dès le retour du réseau (file locale rejouée, `client_ref` : jamais de doublon).
                await labelResultQueue(axios).send("admin/labels/dlc/offline", {
                    client_ref: data.client_ref, product_id: product.id, product_name: product.name, action: data.action,
                    copies: data.copies, opened_at: data.opened_at, expires_at: data.expires_at, storage: data.storage || null,
                    // « produit neuf » déclaré pour une préparation : la déclaration suit l'étiquette au journal
                    parent_none: opts.parent_none || undefined, meal_slot: data.action === "witness" ? (opts.meal_slot || undefined) : undefined,
                    // la personne est écrite EN TOUTES LETTRES (« moi » = le compte qui a imprimé, pas celui qui sera connecté au rejeu)
                    operator_user_id: this.operatorId || this.operatorMe || undefined, operator_token: this.operatorToken || undefined,
                    status: result.ok ? (result.unconfirmed ? "unconfirmed" : "printed") : "failed",
                    message: result.message ? String(result.message).slice(0, 290) : null,
                }, { maxAgeMs: OFFLINE_KEEP_MS });
                this.refreshPending();
            }

            if (!opts.quiet) {
                let preview = "";
                try { preview = previewUrls([blocks], this.settings).urls[0] || ""; } catch (_) { preview = ""; }
                const warn = [offline ? "Hors ligne : étiquette imprimée ; elle sera inscrite au journal dès le retour du réseau." : "", result.ok && result.unconfirmed ? result.message : ""]
                    .filter(Boolean).join(" ");
                const action = DLC_ACTIONS[data.action] || DLC_ACTIONS.opened;
                this.showLast({
                    ok: result.ok, warn: Boolean(warn), name: data.name, copies: data.copies, actionLabel: action.label, verb: action.verb,
                    opened: formatDlcDate(data.opened_at, this.timezone), expires: formatDlcDate(data.expires_at, this.timezone),
                    lot: data.lot, capped: data.capped, capSource: data.cap_source, message: result.ok ? warn : result.message, preview,
                    ids: data.log_id ? [data.log_id] : [],
                });
            }
            if (!result.ok) alertService.error(result.message);
            return { ...result, ids: data.log_id ? [data.log_id] : [], offline };
        },

        // ── « Jeté » / « Utilisé » / « Annulée » ────────────────────────────────
        async discard(w, reason = "discarded") {
            this.discarding = true;
            this.placeToasts(); // la notice « Annuler » se pose sur la grille, jamais sur la liste qu'on vient de toucher
            try {
                await axios.post("admin/labels/prints/discard", { ids: w.ids, reason });
                await this.loadHistory();
                const what = String(w.name).startsWith("«") || String(w.name).startsWith("la ") ? w.name : `Lot « ${w.name} »`;
                const many = (w.ids || []).length > 1 || String(w.name).startsWith("la ");
                const text = { used: `${what} marqué utilisé.`, cancelled: `Impression de ${w.name} annulée : retirez ${many ? "les étiquettes" : "l'étiquette"}.` }[reason]
                    || `${what} marqué jeté.`;
                this.undo = { ids: w.ids, name: w.name, text };
                this.toastShownAt = Date.now();
                clearTimeout(this.undoTimer);
                this.undoTimer = setTimeout(() => { this.undo = null; }, 8000);
            } catch (e) {
                alertService.error(this.apiMessage(e, "Impossible d'enregistrer : réessayez."));
            } finally {
                this.discarding = false;
            }
        },
        async undoDiscard() {
            if (!this.undo) return;
            const { ids } = this.undo;
            this.undo = null;
            clearTimeout(this.undoTimer);
            try {
                await axios.post("admin/labels/prints/discard", { ids, restore: true });
                await this.loadHistory();
            } catch (e) {
                alertService.error(this.apiMessage(e, "Annulation impossible : réessayez."));
            }
        },

        // ── fiches produits ────────────────────────────────────────────────────
        openEditor(product) {
            this.formError = "";
            this.editorKey += 1;
            this.editing = product ? { ...product } : {};
        },
        /**
         * Dupliquer une fiche (« Précieux pour créer 20 produits proches ») : l'éditeur s'ouvre sur une COPIE au nom libre « X (copie) »,
         * rien n'est enregistré avant « Ajouter ». Une copie n'hérite ni de la validation des durées, ni de leur source, ni des allergènes
         * (« aucun des 14 » ne vaut que pour la recette d'origine) : c'est une fiche nouvelle, à valider et à renseigner.
         */
        duplicate(p) {
            const taken = new Set(this.products.map((x) => String(x.name).toLowerCase()));
            const base = String(p.name).slice(0, 100);
            let name = `${base} (copie)`;
            for (let n = 2; taken.has(name.toLowerCase()); n++) name = `${base} (copie ${n})`;
            // eslint-disable-next-line no-unused-vars
            const { id, duration_review, duration_source, durations_checked_at, durations_checked_by_name, uses_30d, sort_order, allergens, no_allergens, allergens_unreviewed, ...rest } = p;
            // une copie ne reprend PAS les allergènes : « aucun des 14 » est une affirmation sur UNE recette, pas sur celle qu'on va écrire
            this.openEditor({ ...rest, name, allergens: [], no_allergens: false });
        },
        editFromHealth(p) {
            this.healthOpen = false;
            this.openEditor(p);
        },
        closeEditor() {
            this.editing = null;
            this.formError = "";
        },
        async saveProduct(payload) {
            if (this.saving) return;
            this.formError = "";
            if (!String(payload.name || "").trim()) { this.formError = "Indiquez le nom du produit."; return; }
            if (!payload.actions.length) { this.formError = "Cochez au moins un événement (ouverture, décongélation…) avec sa durée."; return; }
            this.saving = true;
            try {
                if (payload.id) await axios.put(`admin/labels/products/${payload.id}`, payload);
                else await axios.post("admin/labels/products", payload);
                this.closeEditor();
                await this.loadProducts();
                alertService.success(`« ${payload.name.trim()} » enregistré.`);
            } catch (e) {
                this.formError = this.apiMessage(e, "Enregistrement impossible.");
            } finally {
                this.saving = false;
            }
        },
        async deleteProduct(id) {
            try {
                await axios.delete(`admin/labels/products/${id}`);
                this.closeEditor();
                await this.loadProducts();
            } catch (e) {
                this.formError = this.apiMessage(e, "Suppression impossible.");
            }
        },
        async toggleActive(p) {
            try {
                // masquer / réactiver ne touche que la visibilité (une fiche ancienne n'a rien à compléter pour être masquée)
                await axios.put(`admin/labels/products/${p.id}`, { name: p.name, is_active: !p.is_active });
                await this.loadProducts();
            } catch (e) {
                alertService.error(this.apiMessage(e, "Modification impossible."));
            }
        },
    },
};
</script>

<style scoped>
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
.dlc-alert { border: 1px solid #fca5a5; background: #fef2f2; color: #7f1d1d; border-radius: 8px; padding: 10px 12px; margin: 8px 0; }
.dlc-note { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; border-radius: 10px; padding: 10px 12px; margin: 0 0 12px; font-size: 15px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.dlc-tile-review { color: #b45309; font-weight: 700; }
.dlc-import-btn { margin: 0 0 8px 10px; }
.dlc-btn--attention { border-color: #fdba74; background: #fff7ed; color: #7c2d12; }
.dlc-health-n { display: inline-block; margin-left: 8px; min-width: 26px; padding: 1px 8px; border-radius: 999px; background: #D63D0B; color: #fff; font-size: 14px; font-weight: 800; text-align: center; }
/* téléphone : trois boutons sur UNE ligne (Masquer · Dupliquer · Modifier), la mention « durées à valider » sur la sienne */
@media (max-width: 599px) {
    .dlc-row > .dlc-btn--small { flex: 1 1 28%; padding: 0 6px; }
    .dlc-row-review, .dlc-row-ok { flex: 1 1 100%; }
}
.dlc-row-review { color: #b45309; font-weight: 700; font-size: 13px; }
.dlc-row-ok { color: #15803d; font-weight: 700; font-size: 13px; }
.dlc-empty { color: #475569; padding: 24px 8px; text-align: center; }
.dlc-empty--cta { text-align: left; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 12px; padding: 18px; }
.dlc-empty--inline { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; justify-content: center; }
.dlc-suggest { margin-top: 14px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.dlc-suggest-label { width: 100%; color: #475569; font-size: 14px; }
.dlc-muted { color: #64748b; }
.dlc-hint { color: #475569; font-size: 14px; margin: 8px 0 12px; }
.dlc-hint-apart { display: block; margin-top: 4px; color: #92400e; font-weight: 600; }
.dlc-alert-chip { min-height: 48px; padding: 0 16px; border-radius: 999px; border: 2px solid #dc2626; background: #fef2f2; color: #991b1b; font-weight: 800; font-size: 15px; cursor: pointer; }
@media (min-width: 1100px) { .dlc-alert-chip { display: none; } }

.dlc-toolbar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin: 4px 0 10px; }
.dlc-search-row { display: flex; gap: 8px; align-items: center; flex: 1 1 320px; }
.dlc-search-row .db-field-control { min-height: 52px; flex: 1 1 auto; font-size: 16px; }

/* Les familles passent à la ligne (9 avec le catalogue) : une rangée qui défile sans indice cachait Viandes, Sauces, Plats cuits… */
.dlc-cats { display: flex; flex-wrap: wrap; gap: 8px; padding: 2px 0 8px; }
.dlc-cat { flex: 0 0 auto; display: inline-flex; align-items: center; gap: 8px; min-height: 48px; padding: 0 16px; border-radius: 999px; border: 1px solid #cbd5e1; background: #fff; font-size: 15px; font-weight: 700; color: #334155; cursor: pointer; white-space: nowrap; }
.dlc-cat.is-on { background: #1a1a1a; border-color: #1a1a1a; color: #fff; }
.dlc-cat-n { background: #e2e8f0; color: #334155; border-radius: 999px; padding: 0 8px; font-size: 13px; }
.dlc-cat.is-on .dlc-cat-n { background: #fff; color: #1a1a1a; }
.dlc-dot { width: 12px; height: 12px; border-radius: 50%; display: inline-block; flex: 0 0 auto; }

.dlc-modes { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin: 6px 0 2px; }
.dlc-mode { min-height: 48px; padding: 0 16px; border-radius: 10px; border: 1px solid #cbd5e1; background: #fff; font-size: 15px; font-weight: 700; cursor: pointer; }
.dlc-mode.is-on { background: #1a1a1a; border-color: #1a1a1a; color: #fff; }
.dlc-batch-action { display: flex; align-items: center; gap: 8px; margin: 0; font-weight: 700; color: #334155; font-size: 14px; }
.dlc-batch-action .db-field-control { min-height: 48px; min-width: 220px; }

.dlc-layout { display: flex; flex-direction: column; gap: 12px; }
/* Étroit (portrait) : sous la grille — les produits restent en haut, l'alerte « lots expirés » est dans la barre d'outils. */
.dlc-watch { order: 2; background: #fffbeb; border: 1px solid #fcd34d; border-radius: 12px; padding: 10px 16px; margin: 0 0 6px; scroll-margin-top: 80px; }
@media (min-width: 1100px) {
    .dlc-layout { flex-direction: row; align-items: flex-start; }
    .dlc-main { flex: 1 1 auto; min-width: 0; }
    .dlc-watch { flex: 0 0 360px; position: sticky; top: 8px; max-height: calc(100vh - 24px); overflow-y: auto; }
}
.dlc-watch ul { list-style: none; margin: 0; padding: 0; }
.dlc-watch li { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; padding: 6px 0; font-size: 15px; }
.dlc-watch-actions { display: flex; gap: 6px; flex: 0 0 auto; margin-left: auto; }
.dlc-watch-more { margin: 6px 0 0; color: #92400e; }
.dlc-watch-anomaly { color: #9a3412; font-weight: 700; font-size: 13px; }
.dlc-watch-pending { margin-left: auto; color: #1e3a8a; font-weight: 700; font-size: 13px; }
.dlc-pending { border: 1px solid #bfdbfe; background: #eff6ff; color: #1e3a8a; border-radius: 10px; padding: 10px 12px; margin: 8px 0; font-weight: 600; }
.dlc-watch li.is-expired .dlc-watch-n { color: #7f1d1d; }
.dlc-tile-todo { background: #fef3c7 !important; color: #92400e; font-weight: 700; }
.dlc-history-anomaly { color: #9a3412; font-weight: 700; }
.dlc-orig-overlay { position: fixed; inset: 0; z-index: 1060; background: rgba(15, 23, 42, .55); display: flex; align-items: center; justify-content: center; padding: 12px; }
.dlc-orig-panel { background: #fff; border-radius: 16px; width: min(720px, 100%); max-height: calc(100vh - 24px); overflow-y: auto; padding: 14px 18px; box-shadow: 0 20px 60px rgba(0, 0, 0, .3); }
.dlc-orig-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.dlc-orig-head h4 { margin: 0; font-size: 20px; font-weight: 800; }
.dlc-orig-row { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; padding: 8px 0; border-bottom: 1px dashed #e2e8f0; }
.dlc-orig-row .db-field-control { min-height: 52px; max-width: 220px; font-size: 17px; }
.dlc-orig-name { flex: 1 1 180px; font-size: 17px; }
.dlc-orig-lot { max-width: 180px !important; }
.dlc-orig-foot { display: flex; gap: 10px; justify-content: flex-end; padding-top: 12px; }
.dlc-watch li.is-expired { background: #fee2e2; border-radius: 8px; padding: 6px 8px; margin: 2px -8px; color: #991b1b; font-weight: 700; }
.dlc-watch li.is-today { color: #92400e; }
.dlc-watch-icon { font-size: 18px; line-height: 1.2; margin-right: 6px; }
.dlc-watch-body { flex: 1 1 180px; min-width: 0; }
.dlc-watch-n { color: #64748b; font-weight: 400; }
.dlc-h4 { font-size: 16px; font-weight: 800; margin: 0 0 8px; color: #111827; }

.dlc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 12px; padding-bottom: 8px; }
.dlc-cell { position: relative; }
.dlc-who { display: flex; align-items: center; }
.dlc-star { position: absolute; right: 8px; top: 8px; width: 44px; height: 44px; border-radius: 12px; border: 2px solid #cbd5e1; background: #fff; color: #64748b; font-size: 24px; line-height: 1; cursor: pointer; z-index: 2; }
.dlc-star.is-on { border-color: #f59e0b; background: #fffbeb; color: #d97706; }
.dlc-star:disabled { opacity: .6; cursor: progress; }
.dlc-cat--fav { border-color: #f59e0b; color: #92400e; }
.dlc-cat--fav.is-on { background: #fffbeb; border-color: #d97706; color: #78350f; box-shadow: inset 0 0 0 1px #d97706; }
.dlc-fav-bar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 10px; }
.dlc-tile {
    position: relative; width: 100%; height: 100%; min-height: 128px; border-radius: 14px; border: 2px solid #e2e8f0; border-left: 8px solid var(--cat, #94a3b8);
    background: #fff; padding: 10px 12px 12px; display: flex; flex-direction: column; align-items: flex-start; gap: 4px; text-align: left; cursor: pointer;
}
.dlc-tile:active { background: #fff7f3; }
.dlc-tile:disabled { opacity: .6; cursor: progress; }
.dlc-tile.is-picked { border-color: #D63D0B; border-left-color: var(--cat, #94a3b8); background: #fff7f3; }
.dlc-tile.is-expired { border-color: #dc2626; border-left-color: #dc2626; background: #fef2f2; }
.dlc-tile.is-apart { background: #f8fafc; border-style: dashed; }
.dlc-tile-apart { background: #fef3c7; color: #92400e; border-radius: 999px; padding: 1px 10px; font-weight: 800; font-size: 12px; }
.dlc-tile-top { display: flex; align-items: center; gap: 6px; width: 100%; min-height: 24px; flex-wrap: wrap; padding-right: 48px; }
.dlc-check { width: 24px; height: 24px; border-radius: 6px; border: 2px solid #94a3b8; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; color: #fff; }
.dlc-check.is-on { background: #D63D0B; border-color: #D63D0B; }
.dlc-tile-cat { font-size: 12px; font-weight: 700; color: #475569; text-transform: uppercase; letter-spacing: .04em; flex: 1 1 auto; }
.dlc-tile-count { background: #D63D0B; color: #fff; border-radius: 999px; padding: 1px 10px; font-weight: 800; font-size: 14px; }
.dlc-tile-flag { background: #dc2626; color: #fff; border-radius: 999px; padding: 1px 10px; font-weight: 800; font-size: 12px; }
.dlc-tile-name { font-size: 20px; font-weight: 800; line-height: 1.15; color: #111827; padding-right: 44px; }
.dlc-tile-life { font-size: 14px; color: #334155; padding-right: 44px; }
.dlc-tile-meta { display: flex; flex-wrap: wrap; gap: 6px; font-size: 12px; color: #475569; padding-right: 44px; }
.dlc-tile-meta span { background: #f1f5f9; border-radius: 6px; padding: 1px 6px; }
.dlc-tile-busy { position: absolute; bottom: 8px; left: 12px; font-size: 12px; color: #1d4ed8; font-weight: 700; background: #dbeafe; border-radius: 6px; padding: 1px 6px; }
.dlc-tile-busy--queued { color: #334155; background: #e2e8f0; }
.dlc-corner { position: absolute; right: 8px; bottom: 8px; width: 48px; height: 48px; border-radius: 12px; border: 2px solid #D63D0B; background: #fff; color: #D63D0B; font-size: 26px; font-weight: 800; cursor: pointer; line-height: 1; }
.dlc-corner--more { border-color: #cbd5e1; color: #334155; font-size: 24px; }

.dlc-basket {
    position: sticky; bottom: 0; z-index: 2; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    background: #fff; border: 2px solid #D63D0B; border-radius: 12px; padding: 12px 16px; margin: 8px 0 16px;
    box-shadow: 0 -4px 14px rgba(15, 23, 42, .12);
}
.dlc-basket-text { flex: 1 1 200px; font-size: 16px; }

.dlc-toasts { position: fixed; right: 16px; bottom: 16px; z-index: 1040; display: flex; flex-direction: column; gap: 8px; width: min(560px, calc(100vw - 32px)); pointer-events: none; }
.dlc-toasts.is-raised { bottom: 104px; }
.dlc-toasts > * { pointer-events: auto; box-shadow: 0 10px 30px rgba(15, 23, 42, .25); }
.dlc-last { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; border-radius: 12px; padding: 12px 14px; }
.dlc-last.is-ok { background: #ecfdf5; border: 1px solid #a7f3d0; }
.dlc-last.is-warn { background: #fffbeb; border: 1px solid #fcd34d; }
.dlc-last.is-ko { background: #fef2f2; border: 1px solid #fca5a5; }
.dlc-last-text { display: flex; flex-direction: column; gap: 2px; font-size: 15px; flex: 1 1 240px; min-width: 0; }
.dlc-last-warn { color: #92400e; font-weight: 600; }
.dlc-last-img { width: 150px; max-width: 40%; border: 1px solid #94a3b8; image-rendering: pixelated; background: #fff; }
/* étroit (portrait) : notice compacte — l'image de l'étiquette est dans l'historique et l'aperçu ; on couvre moins de tuiles */
@media (max-width: 1099px) { .dlc-last-img { display: none; } .dlc-last { padding: 8px 10px; } .dlc-last-text { font-size: 14px; } }
.dlc-last-actions { display: flex; gap: 6px; align-items: center; margin-left: auto; }
.dlc-btn--icon { min-width: 48px; padding: 0; }
.dlc-undo { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; background: #1a1a1a; color: #fff; border-radius: 12px; padding: 8px 8px 8px 16px; }
.dlc-undo .dlc-btn { background: #fff; color: #1a1a1a; }
@media (max-width: 640px) { .dlc-toasts { right: 12px; left: 12px; width: auto; } }

.dlc-section { border-top: 1px solid #e2e8f0; padding-top: 14px; margin-top: 14px; }
.dlc-toggle { background: none; border: 0; font-size: 17px; font-weight: 800; cursor: pointer; padding: 8px 0; min-height: 48px; color: #111827; }
.dlc-chip { min-width: 52px; min-height: 48px; border-radius: 10px; border: 1px solid #cbd5e1; background: #fff; font-size: 16px; font-weight: 700; cursor: pointer; }
.dlc-chip--wide { padding: 0 14px; font-size: 15px; }
.dlc-btn { min-height: 52px; padding: 0 18px; border-radius: 10px; border: 1px solid #cbd5e1; background: #fff; font-size: 16px; font-weight: 700; cursor: pointer; }
.dlc-btn--primary { background: #D63D0B; border-color: #D63D0B; color: #fff; }
.dlc-btn--small { min-height: 48px; padding: 0 12px; font-size: 14px; }
.dlc-btn--danger { color: #b91c1c; border-color: #fca5a5; }
.dlc-btn:disabled { opacity: 1; background: #e2e8f0; border-color: #cbd5e1; color: #475569; cursor: not-allowed; }
.dlc-list { list-style: none; margin: 0; padding: 0; }
.dlc-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 8px 0; border-bottom: 1px dashed #e2e8f0; }
.dlc-row.is-off { opacity: .55; }
.dlc-row-name { flex: 1 1 220px; font-weight: 700; font-size: 16px; }
.dlc-row-name small { color: #64748b; font-weight: 400; }
.dlc-row-life { color: #475569; font-size: 14px; flex: 1 1 220px; }

.dlc-history { list-style: none; margin: 0; padding: 0; }
.dlc-history-row { display: flex; gap: 12px; flex-wrap: wrap; padding: 6px 0; border-bottom: 1px dashed #e2e8f0; font-size: 14px; align-items: baseline; }
.dlc-history-when { color: #64748b; font-variant-numeric: tabular-nums; min-width: 92px; }
.dlc-history-name { font-weight: 700; flex: 1 1 200px; }
.dlc-history-name small, .dlc-history-dlc small { color: #64748b; font-weight: 400; }
.dlc-history-dlc { color: #334155; }
.dlc-history-lot { color: #64748b; font-variant-numeric: tabular-nums; }
.dlc-history-by { color: #64748b; min-width: 60px; }
.dlc-history-state { font-weight: 700; flex: 0 0 auto; margin-left: auto; }
.dlc-history-state.is-printed { color: #166534; }
.dlc-history-state.is-failed { color: #991b1b; }
.dlc-history-state.is-unconfirmed { color: #92400e; }
.dlc-history-state.is-discarded { color: #475569; }
</style>

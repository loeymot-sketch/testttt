/* studio-iter2.jsx — Iteration 2 (points 6-12)
   6) Source picker create-on-the-fly · 7) Auto-save states
   8) Per-screen empty/loading/error · 9) Toast micro-system
   10) Permission matrix · 11) Stock resolve flow · 12) Search results
*/
const { Pill: Pill2, Chip: Chip2, Btn: Btn2, Switch: Switch2, Icon: Icon2, ProductThumb: ProductThumb2,
        STUDIO_BRANCHES: BR2, STUDIO_PRODUCTS: PR2 } = window;

/* ===== 6) SOURCE PICKER · CREATE-ON-THE-FLY =================================== */
const Iter6SourcePicker = ({ width = 1180, height = 700 }) => (
  <div className="studio" style={{
    width, height, background: "var(--studio-canvas)", padding: 18,
    display: "grid", gridTemplateColumns: "1fr 350px", gap: 14, position: "relative",
  }}>
    {/* StepEditor excerpt */}
    <div className="studio-card" style={{ padding: 18, display: "flex", flexDirection: "column", gap: 14 }}>
      <header>
        <span className="studio-eyebrow">Étape · sauce · source_ref</span>
        <h3 style={{ margin: "4px 0 0", fontSize: 16, fontWeight: 700 }}>Choix de la source</h3>
      </header>

      {/* Source type segmented (read-only here) */}
      <div style={{ display: "flex", gap: 0, padding: 3, background: "var(--cv1-surface-subtle)", borderRadius: 8, border: "1px solid var(--studio-line)", width: "fit-content" }}>
        {[
          { k: "item_attribute", label: "Attribut", active: true },
          { k: "extra_group",    label: "Groupe extras", active: false },
          { k: "addon",          label: "Addon",   active: false },
        ].map(t => (
          <button key={t.k} style={{
            padding: "6px 14px", fontSize: 12.5, fontWeight: 600, border: "none", cursor: "pointer",
            background: t.active ? "#fff" : "transparent",
            color: t.active ? "var(--studio-ink)" : "var(--studio-ink-mute)",
            borderRadius: 6, boxShadow: t.active ? "var(--studio-elev-1)" : "none",
          }}>{t.label}</button>
        ))}
      </div>

      {/* Picker dropdown — open */}
      <div style={{ position: "relative", maxWidth: 460 }}>
        <button style={{
          width: "100%", padding: "10px 12px",
          border: "2px solid var(--cv1-border-emphasis)", borderRadius: 10, background: "#fff",
          display: "flex", alignItems: "center", gap: 8, cursor: "pointer",
          textAlign: "left",
        }}>
          <Icon2 name="tag" size={14} />
          <span style={{ flex: 1, fontSize: 13, color: "var(--studio-ink-mute)" }}>Choisir un attribut produit…</span>
          <Icon2 name="chevron-d" size={14} />
        </button>

        <div style={{
          position: "absolute", top: "calc(100% + 6px)", left: 0, right: 0,
          background: "#fff", border: "1px solid var(--studio-line)", borderRadius: 10,
          boxShadow: "var(--studio-elev-2)", zIndex: 5,
          padding: 6, display: "flex", flexDirection: "column", gap: 2,
        }}>
          <div style={{ padding: "6px 8px", display: "flex", alignItems: "center", gap: 6, borderBottom: "1px solid var(--studio-line)", marginBottom: 4 }}>
            <Icon2 name="search" size={12} />
            <input className="studio-input" placeholder="Rechercher un attribut…" style={{ border: "none", padding: 0, height: 22, fontSize: 12.5 }} />
          </div>
          {[
            { name: "Sauce",        v: "Algérienne, Blanche, Harissa, Samouraï, BBQ, Curry", picked: true },
            { name: "Pain",         v: "Tortilla, Maison" },
            { name: "Cuisson",      v: "Saignante, À point, Bien cuite" },
            { name: "Salade",       v: "Iceberg, Roquette" },
          ].map(it => (
            <div key={it.name} style={{
              padding: "8px 10px", borderRadius: 7, cursor: "pointer",
              background: it.picked ? "var(--cv1-surface-subtle)" : "transparent",
              display: "flex", alignItems: "center", gap: 8,
            }}>
              <span style={{ width: 22, height: 22, borderRadius: 6, background: "var(--studio-src-attr-bg)", color: "var(--studio-src-attr-fg)", display: "flex", alignItems: "center", justifyContent: "center" }}>
                <Icon2 name="tag" size={11} />
              </span>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontSize: 12.5, fontWeight: 600 }}>{it.name}</div>
                <div style={{ fontSize: 10.5, color: "var(--studio-ink-mute)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{it.v}</div>
              </div>
              {it.picked && <Icon2 name="check" size={13} stroke={2.5} />}
            </div>
          ))}
          {/* Create-on-the-fly */}
          <div style={{ borderTop: "1px solid var(--studio-line)", marginTop: 4, paddingTop: 4 }}>
            <button style={{
              width: "100%", padding: "8px 10px", borderRadius: 7,
              background: "transparent", border: "1px dashed var(--cv1-border-emphasis)",
              color: "var(--cv1-border-emphasis)", fontWeight: 600, cursor: "pointer", fontSize: 12.5,
              display: "flex", alignItems: "center", gap: 8,
            }}>
              <Icon2 name="plus" size={13} />Nouveau… <span style={{ marginLeft: "auto", color: "var(--studio-ink-mute)", fontWeight: 500, fontSize: 11 }}>Crée à la volée</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    {/* Side-sheet: 350px slide-in */}
    <aside style={{
      background: "#fff", border: "1px solid var(--studio-line)", borderRadius: 12,
      boxShadow: "var(--studio-elev-2)", display: "flex", flexDirection: "column",
      overflow: "hidden", height: "fit-content", maxHeight: "100%",
    }}>
      <header style={{ padding: 14, borderBottom: "1px solid var(--studio-line)", display: "flex", alignItems: "center", gap: 10 }}>
        <span style={{ width: 30, height: 30, borderRadius: 8, background: "var(--studio-src-attr-bg)", color: "var(--studio-src-attr-fg)", display: "flex", alignItems: "center", justifyContent: "center" }}>
          <Icon2 name="tag" size={14} />
        </span>
        <div style={{ flex: 1 }}>
          <div className="studio-eyebrow">Création rapide</div>
          <h4 style={{ margin: "2px 0 0", fontSize: 14, fontWeight: 700 }}>Nouvel attribut</h4>
        </div>
        <button style={{ background: "transparent", border: "none", color: "var(--studio-ink-mute)", cursor: "pointer" }}>
          <Icon2 name="x" size={14} />
        </button>
      </header>

      <div style={{ padding: 14, display: "flex", flexDirection: "column", gap: 12, flex: 1, overflow: "auto" }}>
        <FormField label="Nom de l'attribut" hint="Visible seulement côté admin.">
          <input className="studio-input" defaultValue="Sauce" />
        </FormField>
        <FormField label="Valeurs" hint="Au moins 2 valeurs · ENTER pour valider chaque valeur.">
          <div style={{
            display: "flex", flexWrap: "wrap", gap: 4, padding: 6,
            border: "1px solid var(--studio-line-strong)", borderRadius: 8, minHeight: 40,
          }}>
            {["Algérienne", "Blanche", "Harissa", "Samouraï", "BBQ", "Curry"].map(v => (
              <span key={v} style={{
                background: "var(--studio-src-attr-bg)", color: "var(--studio-src-attr-fg)",
                fontSize: 11.5, fontWeight: 600, padding: "4px 8px", borderRadius: 999,
                display: "inline-flex", alignItems: "center", gap: 4,
              }}>{v}<Icon2 name="x" size={10} /></span>
            ))}
            <input style={{ flex: 1, minWidth: 80, border: "none", outline: "none", fontSize: 12, padding: "4px 6px" }} placeholder="Ajouter…" />
          </div>
        </FormField>

        {/* Variant: extra_group sample (collapsed below) */}
        <details style={{ marginTop: 4 }}>
          <summary style={{ fontSize: 11.5, color: "var(--studio-ink-mute)", cursor: "pointer", padding: "4px 0" }}>
            Variantes selon source_type (extra_group · addon)
          </summary>
          <div style={{ display: "flex", flexDirection: "column", gap: 8, marginTop: 8, fontSize: 11.5, color: "var(--studio-ink-soft)" }}>
            <div><strong>extra_group</strong> — nom du groupe + items <em>(label, prix HT, ordre)</em>.</div>
            <div><strong>addon</strong> — autocomplete sur catalogue + rôle (radio : <code>side</code> · <code>drink</code> · <code>upsell</code>).</div>
          </div>
        </details>
      </div>

      <footer style={{ padding: 12, borderTop: "1px solid var(--studio-line)", display: "flex", gap: 8 }}>
        <Btn2 size="sm" block>Annuler</Btn2>
        <Btn2 size="sm" variant="primary" block><Icon2 name="check" size={12} stroke={2.5} />Créer et sélectionner</Btn2>
      </footer>
    </aside>
  </div>
);

const FormField = ({ label, hint, children }) => (
  <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
    <label style={{ fontSize: 11.5, fontWeight: 600, color: "var(--studio-ink)" }}>{label}</label>
    {children}
    {hint && <span style={{ fontSize: 11, color: "var(--studio-ink-mute)" }}>{hint}</span>}
  </div>
);

/* ===== 7) AUTO-SAVE STATES ====================================================== */
const Iter7AutoSave = ({ width = 1180, height = 320 }) => (
  <div className="studio" style={{ width, height, background: "var(--studio-canvas)", padding: 18, display: "flex", flexDirection: "column", gap: 14 }}>
    <header>
      <span className="studio-eyebrow">TopBar Wizard · pill auto-sauvegarde</span>
      <h3 style={{ margin: "4px 0 0", fontSize: 16, fontWeight: 700 }}>5 états — transitions calmes</h3>
    </header>
    <div style={{ display: "grid", gridTemplateColumns: "repeat(5, 1fr)", gap: 12 }}>
      <SaveState tone="idle"    label="Idle"    text="Auto-sauvegardé il y a 4s"    icon={null}     desc="Affiché 2s après le dernier save · gris discret · pas d'icône." />
      <SaveState tone="saving"  label="Saving"  text="Sauvegarde…"                  icon="spinner"  desc="Pendant la requête PATCH (debounce 500ms)." />
      <SaveState tone="saved"   label="Saved"   text="Sauvegardé"                   icon="check-c"  desc="200ms d'auto-save · fade vers idle après 2s." />
      <SaveState tone="error"   label="Error"   text="Échec — Réessayer"            icon="alert"    desc="Cliquable · retry exponentiel (1, 2, 4, 8s, max 30s)." />
      <SaveState tone="offline" label="Offline" text="Hors ligne — modifs conservées" icon="wifi-off" desc="Buffer dans IndexedDB · sync au retour réseau." />
    </div>
    <div style={{
      padding: "10px 14px", borderRadius: 10, background: "var(--cv1-surface-subtle)",
      border: "1px solid var(--studio-line)", fontSize: 12, color: "var(--studio-ink-soft)",
      display: "flex", alignItems: "center", gap: 8,
    }}>
      <Icon2 name="info" size={13} />
      <span>Tous les états utilisent <code>aria-live="polite"</code>. <code>error</code> et <code>offline</code> passent en <code>role="alert"</code> à leur première apparition uniquement (pas de bavardage).</span>
    </div>
  </div>
);

const SaveState = ({ tone, label, text, icon, desc }) => {
  const tones = {
    idle:    { bg: "var(--cv1-surface-subtle)",     fg: "var(--studio-ink-mute)", border: "var(--studio-line)" },
    saving:  { bg: "#eef2ff",                       fg: "#3730a3",                border: "#c7d2fe" },
    saved:   { bg: "var(--studio-pill-active-bg)",  fg: "var(--studio-pill-active-fg)", border: "#bbf7d0" },
    error:   { bg: "var(--studio-pill-86-bg)",      fg: "var(--studio-pill-86-fg)",     border: "#fecdd3" },
    offline: { bg: "var(--studio-pill-draft-bg)",   fg: "var(--studio-pill-draft-fg)",  border: "#fde68a" },
  }[tone];
  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
      <span style={{ fontSize: 11, fontWeight: 700, color: "var(--studio-ink-mute)", textTransform: "uppercase", letterSpacing: "0.06em" }}>{label}</span>
      <div style={{
        padding: "6px 12px", borderRadius: 999, background: tones.bg, color: tones.fg,
        border: `1px solid ${tones.border}`, fontSize: 12, fontWeight: 600,
        display: "inline-flex", alignItems: "center", gap: 6, width: "fit-content",
        cursor: tone === "error" ? "pointer" : "default",
      }}>
        {icon === "spinner" && (
          <span style={{
            width: 11, height: 11, borderRadius: 999,
            border: "2px solid currentColor", borderTopColor: "transparent",
            animation: "studioSpin 0.8s linear infinite",
          }} />
        )}
        {icon === "check-c" && <Icon2 name="check-c" size={12} />}
        {icon === "alert" && <Icon2 name="alert" size={12} />}
        {icon === "wifi-off" && <Icon2 name="x-c" size={12} />}
        {text}
      </div>
      <span style={{ fontSize: 11, color: "var(--studio-ink-mute)", lineHeight: 1.5 }}>{desc}</span>
    </div>
  );
};

/* ===== 8) PER-SCREEN EMPTY / LOADING / ERROR =================================== */
const Iter8States = ({ width = 1180, height = 920 }) => (
  <div className="studio" style={{ width, height, background: "var(--studio-canvas)", padding: 18, display: "flex", flexDirection: "column", gap: 14, overflow: "hidden" }}>
    <header>
      <span className="studio-eyebrow">États par écran · 1er passage</span>
      <h3 style={{ margin: "4px 0 0", fontSize: 16, fontWeight: 700 }}>Empty · Loading · Error · No-permission</h3>
    </header>

    <div style={{ display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gridAutoRows: "1fr", gap: 12, flex: 1, minHeight: 0 }}>
      <StateCard tag="Screen 1" tone="empty" title="Aucune catégorie">
        <EmptyS1 />
      </StateCard>
      <StateCard tag="Screen 1" tone="loading" title="Chargement du catalogue">
        <LoadingS1 />
      </StateCard>
      <StateCard tag="Screen 1" tone="error" title="Erreur de chargement">
        <ErrorS1 />
      </StateCard>

      <StateCard tag="Screen 3" tone="empty" title="Aucune étape configurée">
        <EmptyS3 />
      </StateCard>
      <StateCard tag="Screen 5" tone="empty" title="Stock à jour">
        <EmptyS5 />
      </StateCard>
      <StateCard tag="Screen 1" tone="locked" title="No permission · catalog.publish">
        <NoPermS1 />
      </StateCard>
    </div>
  </div>
);

const StateCard = ({ tag, tone, title, children }) => {
  const tones = {
    empty:   { bg: "var(--cv1-surface-subtle)", fg: "var(--studio-ink-mute)" },
    loading: { bg: "#fff",                      fg: "var(--studio-ink-mute)" },
    error:   { bg: "var(--studio-pill-86-bg)",  fg: "var(--studio-pill-86-fg)" },
    locked:  { bg: "var(--studio-pill-draft-bg)", fg: "var(--studio-pill-draft-fg)" },
  }[tone];
  return (
    <div className="studio-card" style={{ padding: 0, overflow: "hidden", display: "flex", flexDirection: "column" }}>
      <div style={{ padding: "8px 12px", borderBottom: "1px solid var(--studio-line)", display: "flex", alignItems: "center", gap: 8 }}>
        <span style={{ background: tones.bg, color: tones.fg, padding: "2px 8px", borderRadius: 4, fontSize: 10, fontWeight: 700, textTransform: "uppercase", letterSpacing: "0.04em" }}>{tone}</span>
        <span style={{ fontSize: 11, color: "var(--studio-ink-mute)" }}>{tag}</span>
        <span style={{ flex: 1, fontSize: 12.5, fontWeight: 600, textAlign: "right" }}>{title}</span>
      </div>
      <div style={{ flex: 1, padding: 14, display: "flex", flexDirection: "column" }}>{children}</div>
    </div>
  );
};

const EmptyS1 = () => (
  <div style={{ flex: 1, display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", gap: 10, textAlign: "center" }}>
    <span style={{ width: 48, height: 48, borderRadius: 12, background: "var(--cv1-surface-subtle)", display: "flex", alignItems: "center", justifyContent: "center", color: "var(--studio-ink-mute)" }}>
      <Icon2 name="package" size={22} />
    </span>
    <div>
      <div style={{ fontSize: 13.5, fontWeight: 700 }}>Aucune catégorie</div>
      <div style={{ fontSize: 12, color: "var(--studio-ink-mute)", maxWidth: 220, marginTop: 2 }}>Crées-en une pour commencer à organiser tes produits.</div>
    </div>
    <Btn2 size="sm" variant="primary"><Icon2 name="plus" size={12} />Nouvelle catégorie</Btn2>
  </div>
);

const LoadingS1 = () => (
  <div style={{ flex: 1, display: "grid", gridTemplateColumns: "100px 1fr", gap: 10, minHeight: 0 }}>
    <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
      {Array.from({ length: 6 }).map((_, i) => (
        <div key={i} className="studio-skel" style={{ height: 28, borderRadius: 6 }} />
      ))}
    </div>
    <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gridAutoRows: "minmax(60px,1fr)", gap: 6, minHeight: 0 }}>
      {Array.from({ length: 6 }).map((_, i) => (
        <div key={i} className="studio-skel" style={{ borderRadius: 8 }} />
      ))}
    </div>
  </div>
);

const ErrorS1 = () => (
  <div style={{ flex: 1, display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", gap: 10, textAlign: "center" }}>
    <span style={{ width: 44, height: 44, borderRadius: 12, background: "#fff", color: "var(--studio-pill-86-fg)", display: "flex", alignItems: "center", justifyContent: "center", border: "1px solid #fecdd3" }}>
      <Icon2 name="alert" size={20} />
    </span>
    <div>
      <div style={{ fontSize: 13.5, fontWeight: 700 }}>Impossible de charger le catalogue</div>
      <div style={{ fontSize: 12, color: "var(--studio-ink-mute)", maxWidth: 240, marginTop: 2 }}>Code <code>NETWORK_TIMEOUT</code> · vérifie la filiale ou réessaie.</div>
    </div>
    <div style={{ display: "flex", gap: 6 }}>
      <Btn2 size="sm" variant="primary"><Icon2 name="refresh" size={12} />Réessayer</Btn2>
      <Btn2 size="sm">Changer de filiale</Btn2>
      <Btn2 size="sm">Ouvrir un ticket</Btn2>
    </div>
  </div>
);

const EmptyS3 = () => (
  <div style={{ flex: 1, display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", gap: 10, textAlign: "center" }}>
    <span style={{ width: 44, height: 44, borderRadius: 12, background: "var(--cv1-surface-subtle)", color: "var(--studio-ink-mute)", display: "flex", alignItems: "center", justifyContent: "center" }}>
      <Icon2 name="menu" size={20} />
    </span>
    <div>
      <div style={{ fontSize: 13.5, fontWeight: 700 }}>Aucune étape</div>
      <div style={{ fontSize: 12, color: "var(--studio-ink-mute)", maxWidth: 240, marginTop: 2 }}>Choisis un template ou ajoute une étape manuellement.</div>
    </div>
    <div style={{ display: "flex", gap: 6 }}>
      <Btn2 size="sm" variant="primary"><Icon2 name="package" size={12} />Choisir un template</Btn2>
      <Btn2 size="sm"><Icon2 name="plus" size={12} />Nouvelle étape</Btn2>
    </div>
  </div>
);

const EmptyS5 = () => (
  <div style={{ flex: 1, display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center", gap: 10, textAlign: "center" }}>
    <span style={{ width: 44, height: 44, borderRadius: 12, background: "var(--studio-pill-active-bg)", color: "var(--studio-pill-active-fg)", display: "flex", alignItems: "center", justifyContent: "center" }}>
      <Icon2 name="check-c" size={20} />
    </span>
    <div>
      <div style={{ fontSize: 13.5, fontWeight: 700 }}>Stock à jour</div>
      <div style={{ fontSize: 12, color: "var(--studio-ink-mute)", maxWidth: 240, marginTop: 2 }}>Aucune alerte sur les 4 filiales — dernière vérif il y a 2 min.</div>
    </div>
    <Btn2 size="sm"><Icon2 name="refresh" size={12} />Forcer un nouveau scan</Btn2>
  </div>
);

const NoPermS1 = () => (
  <div style={{ flex: 1, display: "flex", flexDirection: "column", gap: 8 }}>
    <div style={{ fontSize: 11.5, color: "var(--studio-ink-soft)" }}>Rôle actif : <strong>kitchen_manager</strong> · publication désactivée.</div>
    <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
      {[
        { label: "Publier les modifications", allowed: false, reason: "catalog.publish" },
        { label: "Supprimer le produit", allowed: false, reason: "catalog.delete" },
        { label: "Modifier le wizard", allowed: true },
        { label: "Toggle disponibilité", allowed: true },
      ].map(r => (
        <div key={r.label} style={{
          display: "flex", alignItems: "center", gap: 8, padding: "8px 10px",
          border: "1px solid var(--studio-line)", borderRadius: 8,
          background: "#fff", opacity: r.allowed ? 1 : 0.55,
        }}>
          <Icon2 name={r.allowed ? "check-c" : "lock"} size={13} />
          <span style={{ flex: 1, fontSize: 12, fontWeight: 600 }}>{r.label}</span>
          {!r.allowed && (
            <span style={{
              fontSize: 10.5, color: "var(--studio-pill-draft-fg)",
              background: "var(--studio-pill-draft-bg)",
              padding: "2px 6px", borderRadius: 4, fontWeight: 600,
            }} title={`Permission requise : ${r.reason}`}>{r.reason}</span>
          )}
        </div>
      ))}
    </div>
  </div>
);

/* ===== 9) TOAST MICRO-SYSTEM ==================================================== */
const Iter9Toasts = ({ width = 1180, height = 620 }) => (
  <div className="studio" style={{ width, height, background: "var(--studio-canvas)", padding: 18, display: "grid", gridTemplateColumns: "1fr 380px", gap: 14 }}>
    <div className="studio-card" style={{ padding: 18, position: "relative", overflow: "hidden", background: "linear-gradient(180deg, #f8fafc 0%, #eef0f4 100%)" }}>
      <span className="studio-eyebrow" style={{ position: "absolute", top: 14, left: 16 }}>Stack bottom-right · max 3 visibles</span>

      {/* Toast stack */}
      <div style={{ position: "absolute", bottom: 16, right: 16, display: "flex", flexDirection: "column-reverse", gap: 10, width: 360 }}>
        <ToastVariant tone="info" icon="info" title="Wizard sauvegardé" sub="Auto-save 14:32" countdown={2.6} undo />
        <ToastVariant tone="warning" icon="alert" title="Stock bas — Tortilla" sub="3 unités sur Filiale #2 · seuil 5" countdown={4.1} action="Voir le stock" />
        <ToastVariant tone="blocker" icon="alert" title="Publication impossible" sub="Conflit de version v3 → v5 · recharge nécessaire" persistent action="Recharger" />
      </div>
    </div>

    {/* Spec */}
    <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
      <div className="studio-card" style={{ padding: 14 }}>
        <span className="studio-eyebrow">Système · règles</span>
        <ul style={{ margin: "6px 0 0", paddingLeft: 18, fontSize: 12, color: "var(--studio-ink-soft)", lineHeight: 1.6 }}>
          <li><strong>Position</strong> : <code>fixed</code> bottom-right · 16px de marge.</li>
          <li><strong>Max 3</strong> visibles · les anciens passent en queue (FIFO).</li>
          <li><strong>Durées</strong> : info 4s · warning 6s · blocker 0 (manuel).</li>
          <li><strong>Undo timer</strong> : barre fine 0→100% · <em>hover</em> met en pause.</li>
          <li><strong>ARIA</strong> : container <code>role="status"</code> + <code>aria-live="polite"</code>.</li>
          <li><strong>Focus trap</strong> activé seulement quand le toast a une action critique (blocker).</li>
        </ul>
      </div>
      <div className="studio-card" style={{ padding: 14 }}>
        <span className="studio-eyebrow">Tokens</span>
        <div style={{ display: "flex", flexDirection: "column", gap: 4, marginTop: 6, fontSize: 11.5, color: "var(--studio-ink-soft)" }}>
          <div>info → <code>--cv1-status-info-bg / -border / -text</code></div>
          <div>warning → <code>--cv1-status-warning-*</code></div>
          <div>blocker → <code>--cv1-status-blocker-*</code></div>
        </div>
      </div>
    </div>
  </div>
);

const ToastVariant = ({ tone, icon, title, sub, countdown, undo, persistent, action }) => {
  const tones = {
    info:    { bg: "var(--cv1-surface-strong)", fg: "#fff", bar: "var(--cv1-border-emphasis)" },
    warning: { bg: "var(--cv1-status-warning-bg)", fg: "var(--cv1-status-warning-text)", border: "var(--cv1-status-warning-border)", bar: "#f59e0b" },
    blocker: { bg: "var(--cv1-status-blocker-bg)", fg: "var(--cv1-status-blocker-text)", border: "var(--cv1-status-blocker-border)", bar: "#e11d48" },
  }[tone];
  return (
    <div style={{
      background: tones.bg, color: tones.fg, borderRadius: 12,
      padding: "12px 14px", display: "flex", alignItems: "flex-start", gap: 10,
      boxShadow: "var(--studio-elev-2)", position: "relative", overflow: "hidden",
      border: tones.border ? `1px solid ${tones.border}` : "none",
    }}>
      <span style={{
        width: 28, height: 28, borderRadius: 8,
        background: tone === "info" ? "rgba(255,255,255,.1)" : "#fff",
        color: tone === "info" ? "#fff" : tones.fg,
        display: "flex", alignItems: "center", justifyContent: "center", flex: "0 0 auto",
      }}><Icon2 name={icon} size={14} /></span>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontSize: 13, fontWeight: 700 }}>{title}</div>
        <div style={{ fontSize: 11.5, opacity: tone === "info" ? 0.75 : 0.85, marginTop: 1 }}>{sub}</div>
        {(undo || action) && (
          <div style={{ marginTop: 8, display: "flex", gap: 6 }}>
            {undo && <button style={{ background: "transparent", color: "currentColor", border: "1px solid currentColor", borderRadius: 6, padding: "3px 10px", fontSize: 11.5, fontWeight: 600, cursor: "pointer" }}>Annuler</button>}
            {action && <button style={{ background: tone === "info" ? "rgba(255,255,255,.15)" : "currentColor", color: tone === "info" ? "#fff" : tones.bg, border: "none", borderRadius: 6, padding: "3px 10px", fontSize: 11.5, fontWeight: 700, cursor: "pointer" }}>{action}</button>}
          </div>
        )}
      </div>
      {!persistent && (
        <div style={{ position: "absolute", left: 0, bottom: 0, height: 2, width: `${(countdown / 5) * 100}%`, background: tones.bar }} />
      )}
    </div>
  );
};

/* ===== 10) PERMISSION MATRIX ==================================================== */
const Iter10Perms = ({ width = 1180, height = 460 }) => {
  const roles = ["super_admin", "branch_manager", "kitchen_manager"];
  const actions = [
    { k: "create_category",     label: "Créer une catégorie",     v: [1, 1, 0] },
    { k: "edit_category",       label: "Modifier une catégorie",  v: [1, 1, 0] },
    { k: "delete_category",     label: "Supprimer une catégorie", v: [1, 0, 0] },
    { k: "create_item",         label: "Créer un produit",        v: [1, 1, 0] },
    { k: "edit_item",           label: "Modifier un produit",     v: [1, 1, 1] },
    { k: "delete_item",         label: "Supprimer un produit",    v: [1, 0, 0] },
    { k: "edit_wizard",         label: "Éditer le wizard",        v: [1, 1, 1] },
    { k: "publish_wizard",      label: "Publier le wizard",       v: [1, 1, 0] },
    { k: "toggle_availability", label: "Toggle disponibilité",    v: [1, 1, 1] },
  ];
  return (
    <div className="studio" style={{ width, height, background: "var(--studio-canvas)", padding: 18, display: "flex", flexDirection: "column", gap: 14 }}>
      <header>
        <span className="studio-eyebrow">Réf. permissionChecker</span>
        <h3 style={{ margin: "4px 0 0", fontSize: 16, fontWeight: 700 }}>Matrice rôles × actions</h3>
      </header>
      <div className="studio-card" style={{ padding: 0, overflow: "hidden" }}>
        <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 12.5 }}>
          <thead>
            <tr style={{ background: "var(--cv1-surface-subtle)" }}>
              <th style={{ padding: "10px 14px", textAlign: "left", fontWeight: 700, color: "var(--studio-ink-soft)", borderBottom: "1px solid var(--studio-line)" }}>Action</th>
              {roles.map(r => (
                <th key={r} style={{ padding: "10px 14px", textAlign: "center", fontWeight: 700, color: "var(--studio-ink-soft)", borderBottom: "1px solid var(--studio-line)" }}>
                  <code style={{ fontSize: 11.5 }}>{r}</code>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {actions.map((a, i) => (
              <tr key={a.k} style={{ background: i % 2 ? "#fff" : "var(--cv1-surface-subtle)" }}>
                <td style={{ padding: "8px 14px", borderBottom: "1px solid var(--studio-line)" }}>
                  <div style={{ fontWeight: 600 }}>{a.label}</div>
                  <code style={{ fontSize: 10.5, color: "var(--studio-ink-mute)" }}>catalog.{a.k}</code>
                </td>
                {a.v.map((on, j) => (
                  <td key={j} style={{ padding: "8px 14px", textAlign: "center", borderBottom: "1px solid var(--studio-line)" }}>
                    {on ? (
                      <span style={{ width: 22, height: 22, borderRadius: 999, background: "var(--studio-pill-active-bg)", color: "var(--studio-pill-active-fg)", display: "inline-flex", alignItems: "center", justifyContent: "center" }}>
                        <Icon2 name="check" size={13} stroke={2.5} />
                      </span>
                    ) : (
                      <span style={{ width: 22, height: 22, borderRadius: 999, background: "var(--cv1-surface-subtle)", color: "var(--studio-ink-mute)", display: "inline-flex", alignItems: "center", justifyContent: "center" }}>
                        <Icon2 name="x" size={11} />
                      </span>
                    )}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
};

/* ===== 11) STOCK RESOLVE FLOW =================================================== */
const Iter11Resolve = ({ width = 420, height = 760 }) => (
  <div className="studio" style={{ width, height, background: "#fff", border: "1px solid var(--studio-line)", display: "flex", flexDirection: "column" }}>
    <header style={{ padding: 14, borderBottom: "1px solid var(--studio-line)" }}>
      <div className="studio-eyebrow">Santé du stock</div>
      <h2 style={{ margin: "4px 0 0", fontSize: 16, fontWeight: 700 }}>Résoudre un événement Auto-86</h2>
    </header>

    <div style={{ flex: 1, padding: 14, display: "flex", flexDirection: "column", gap: 10, overflow: "auto" }}>
      <ResolveCard
        productName="Le Méga"
        branch="Filiale #1 — Le Cay"
        ago="il y a 18 min"
        reason="Stock viande hachée à 0 (auto-86)"
      />
      <div style={{ padding: 12, border: "1px solid var(--studio-line)", borderRadius: 10, background: "var(--cv1-surface-subtle)", display: "flex", gap: 10, alignItems: "flex-start" }}>
        <ProductThumb2 kind="tacos-l" size={36} />
        <div style={{ flex: 1 }}>
          <div style={{ fontSize: 12.5, fontWeight: 600 }}>Le Brave</div>
          <div style={{ fontSize: 11, color: "var(--studio-ink-mute)" }}>Filiale #2 — Centre · auto-86 il y a 1h</div>
        </div>
        <Btn2 size="sm">Résoudre</Btn2>
      </div>
    </div>

    {/* Toast undo */}
    <div style={{ padding: 12, borderTop: "1px solid var(--studio-line)", background: "var(--cv1-surface-strong)", color: "#fff", display: "flex", alignItems: "center", gap: 10 }}>
      <span style={{ width: 24, height: 24, borderRadius: 6, background: "rgba(255,255,255,.12)", display: "flex", alignItems: "center", justifyContent: "center" }}>
        <Icon2 name="check" size={12} stroke={2.5} />
      </span>
      <div style={{ flex: 1 }}>
        <div style={{ fontSize: 12.5, fontWeight: 700 }}>Le Méga marqué disponible</div>
        <div style={{ fontSize: 11, opacity: 0.7 }}>Filiale #1 · resync POS + Kiosk · undo 5s</div>
      </div>
      <button style={{ background: "transparent", border: "1px solid rgba(255,255,255,.25)", color: "#fff", borderRadius: 6, padding: "3px 10px", fontSize: 11.5, fontWeight: 600, cursor: "pointer" }}>Annuler</button>
    </div>
  </div>
);

const ResolveCard = ({ productName, branch, ago, reason }) => (
  <div style={{ padding: 12, border: "1px solid var(--cv1-border-emphasis)", borderRadius: 10, background: "#eef2ff", display: "flex", flexDirection: "column", gap: 10 }}>
    <div style={{ display: "flex", gap: 10, alignItems: "flex-start" }}>
      <ProductThumb2 kind="tacos-xl" size={40} />
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontSize: 12.5, fontWeight: 700 }}>{productName}</div>
        <div style={{ fontSize: 11, color: "var(--studio-ink-mute)" }}>{branch} · auto-86 {ago}</div>
        <div style={{ fontSize: 11.5, color: "var(--studio-pill-86-fg)", marginTop: 4 }}>{reason}</div>
      </div>
    </div>
    {/* Inline confirm */}
    <div style={{ padding: 10, background: "#fff", borderRadius: 8, border: "1px solid var(--cv1-border-emphasis)", display: "flex", flexDirection: "column", gap: 8 }}>
      <div style={{ fontSize: 12.5, fontWeight: 600 }}>Marquer « {productName} » comme à nouveau disponible sur {branch.split(" — ")[0]} ?</div>
      <div style={{ fontSize: 11, color: "var(--studio-ink-mute)" }}>Le POS et la borne seront resync immédiatement. La cause root (stock à 0) reste à vérifier en cuisine.</div>
      <div style={{ display: "flex", gap: 6 }}>
        <Btn2 size="sm">Annuler</Btn2>
        <Btn2 size="sm" variant="primary"><Icon2 name="check" size={12} stroke={2.5} />Confirmer et resync</Btn2>
      </div>
    </div>
  </div>
);

/* ===== 12) SEARCH RESULTS ======================================================= */
const Iter12Search = ({ width = 1180, height = 480 }) => (
  <div className="studio" style={{ width, height, background: "var(--studio-canvas)", padding: 18, display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14 }}>
    <SearchPanel state="active" />
    <SearchPanel state="empty" />
  </div>
);

const SearchPanel = ({ state }) => (
  <div className="studio-card" style={{ padding: 14, display: "flex", flexDirection: "column", gap: 10 }}>
    <span className="studio-eyebrow">{state === "active" ? "Toolbar · live results" : "Toolbar · no-results"}</span>
    <div style={{ position: "relative" }}>
      <div style={{
        display: "flex", alignItems: "center", gap: 8, padding: "10px 12px",
        background: "#fff", border: "2px solid var(--cv1-border-emphasis)", borderRadius: 10,
      }}>
        <Icon2 name="search" size={14} />
        <input
          className="studio-input"
          style={{ border: "none", padding: 0, height: 22, flex: 1, fontSize: 13 }}
          defaultValue={state === "active" ? "tac" : "xyz123"}
        />
        <span style={{ fontSize: 11, color: "var(--studio-ink-mute)", background: "var(--cv1-surface-subtle)", padding: "2px 6px", borderRadius: 4 }}>Esc</span>
      </div>

      <div style={{
        position: "absolute", top: "calc(100% + 6px)", left: 0, right: 0,
        background: "#fff", border: "1px solid var(--studio-line)", borderRadius: 10,
        boxShadow: "var(--studio-elev-2)", zIndex: 5, padding: 6,
        display: "flex", flexDirection: "column", gap: 2, maxHeight: 320, overflow: "auto",
      }}>
        {state === "active" ? (
          <>
            {[
              { thumb: "tacos-m", name: "Tacos M (1 viande)", cat: "Nos Tacos", q: "tac" },
              { thumb: "tacos-l", name: "Tacos L (2 viandes)", cat: "Nos Tacos", q: "tac" },
              { thumb: "tacos-xl", name: "Le Méga · Tacos XL", cat: "Nos Tacos", q: "tac" },
              { thumb: "tacos-m", name: "Tactic Box", cat: "Promos · Mardi", q: "tac" },
              { thumb: "tacos-m", name: "Mini Tacos enfant", cat: "Menu enfant", q: "tac" },
            ].map((r, i) => (
              <div key={i} style={{
                padding: "8px 10px", borderRadius: 7, cursor: "pointer",
                background: i === 0 ? "var(--cv1-surface-subtle)" : "transparent",
                display: "flex", alignItems: "center", gap: 10,
              }}>
                <ProductThumb2 kind={r.thumb} size={28} />
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontSize: 12.5, fontWeight: 600 }}>
                    <Highlight text={r.name} q={r.q} />
                  </div>
                  <div style={{ fontSize: 10.5, color: "var(--studio-ink-mute)" }}>{r.cat}</div>
                </div>
                <Pill2 status="active">Actif</Pill2>
              </div>
            ))}
            <div style={{ borderTop: "1px solid var(--studio-line)", marginTop: 4, paddingTop: 4 }}>
              <button style={{ width: "100%", padding: "8px 10px", border: "none", background: "transparent", textAlign: "left", fontSize: 12, fontWeight: 600, color: "var(--cv1-border-emphasis)", cursor: "pointer", display: "flex", justifyContent: "space-between", alignItems: "center" }}>
                Voir les 14 résultats <Icon2 name="arrow-r" size={12} />
              </button>
            </div>
          </>
        ) : (
          <div style={{ padding: 22, textAlign: "center", display: "flex", flexDirection: "column", alignItems: "center", gap: 8 }}>
            <span style={{ width: 36, height: 36, borderRadius: 10, background: "var(--cv1-surface-subtle)", color: "var(--studio-ink-mute)", display: "flex", alignItems: "center", justifyContent: "center" }}>
              <Icon2 name="search" size={16} />
            </span>
            <div style={{ fontSize: 13, fontWeight: 700 }}>Aucun produit ne contient « xyz123 »</div>
            <div style={{ fontSize: 11.5, color: "var(--studio-ink-mute)", maxWidth: 280 }}>
              Vérifie l'orthographe ou retire le filtre catégorie « Promos · Mardi ».
            </div>
            <div style={{ display: "flex", gap: 6, marginTop: 4 }}>
              <Btn2 size="sm">Effacer la recherche</Btn2>
              <Btn2 size="sm">Retirer le filtre</Btn2>
            </div>
          </div>
        )}
      </div>
    </div>
    <div style={{ flex: 1 }} />
  </div>
);

const Highlight = ({ text, q }) => {
  const idx = text.toLowerCase().indexOf(q.toLowerCase());
  if (idx < 0) return text;
  return (
    <>
      {text.slice(0, idx)}
      <mark style={{ background: "#fef3c7", color: "var(--studio-pill-draft-fg)", padding: "0 2px", borderRadius: 2, fontWeight: 700 }}>{text.slice(idx, idx + q.length)}</mark>
      {text.slice(idx + q.length)}
    </>
  );
};

window.Iter6SourcePicker = Iter6SourcePicker;
window.Iter7AutoSave = Iter7AutoSave;
window.Iter8States = Iter8States;
window.Iter9Toasts = Iter9Toasts;
window.Iter10Perms = Iter10Perms;
window.Iter11Resolve = Iter11Resolve;
window.Iter12Search = Iter12Search;

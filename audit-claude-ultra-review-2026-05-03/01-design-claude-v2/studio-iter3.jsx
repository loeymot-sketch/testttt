/* studio-iter3.jsx — Iteration 3 (points 13-18)
   13) Keyboard shortcuts cheatsheet · 14) 1280px responsive
   15) RTL Arabic · 16) Onboarding tour · 17) Real photos guidance
   18) Micro-interactions + reduced-motion table
   + Iter3Readme — récap des 18 améliorations
*/
const { Pill: Pill3, Chip: Chip3, Btn: Btn3, Icon: Icon3, ProductThumb: ProductThumb3,
        STUDIO_PRODUCTS: PR3, STUDIO_CATEGORIES: CAT3 } = window;

/* ===== 13) KEYBOARD SHORTCUTS =================================================== */
const Iter13Shortcuts = ({ width = 1180, height = 720 }) => (
  <div className="studio" style={{
    width, height,
    background: "rgba(15,23,42,.55)",
    backdropFilter: "blur(8px)",
    padding: 24,
    display: "flex", alignItems: "center", justifyContent: "center", position: "relative",
  }}>
    {/* faux page in background */}
    <div style={{
      position: "absolute", inset: 16, background: "var(--studio-canvas)",
      borderRadius: 12, opacity: 0.35,
      backgroundImage:
        "linear-gradient(0deg, transparent 24px, rgba(15,23,42,.04) 25px), linear-gradient(90deg, transparent 24px, rgba(15,23,42,.04) 25px)",
      backgroundSize: "25px 25px",
    }} />

    {/* Cmd+/ hint pill (always visible) */}
    <div style={{
      position: "absolute", bottom: 18, right: 18,
      background: "var(--cv1-surface-strong)", color: "#fff",
      padding: "6px 10px", borderRadius: 999, fontSize: 11.5, fontWeight: 600,
      display: "flex", alignItems: "center", gap: 6,
    }}>
      Aide <Kbd dark>⌘</Kbd><Kbd dark>/</Kbd>
    </div>

    {/* Cheatsheet popup */}
    <div className="studio-card" style={{
      width: 760, maxHeight: "100%", overflow: "auto",
      borderRadius: 14, boxShadow: "var(--studio-elev-2)",
      background: "#fff", display: "flex", flexDirection: "column",
    }}>
      <header style={{ padding: "16px 20px", borderBottom: "1px solid var(--studio-line)", display: "flex", alignItems: "center", gap: 10 }}>
        <span style={{ width: 32, height: 32, borderRadius: 8, background: "var(--cv1-surface-strong)", color: "#fff", display: "flex", alignItems: "center", justifyContent: "center" }}>
          <Icon3 name="menu" size={16} />
        </span>
        <div style={{ flex: 1 }}>
          <div className="studio-eyebrow">Aide · raccourcis</div>
          <h3 style={{ margin: "2px 0 0", fontSize: 16, fontWeight: 700 }}>Raccourcis clavier</h3>
        </div>
        <span style={{ fontSize: 11, color: "var(--studio-ink-mute)" }}>Esc pour fermer</span>
      </header>

      <div style={{ padding: 20, display: "grid", gridTemplateColumns: "1fr 1fr", gap: 18 }}>
        <ShortcutGroup title="Global">
          <ShortcutRow keys={["⌘", "S"]} desc="Publier le brouillon" />
          <ShortcutRow keys={["⌘", "P"]} desc="Aperçu plein écran" />
          <ShortcutRow keys={["⌘", "/"]} desc="Ouvrir / fermer cette aide" />
          <ShortcutRow keys={["Esc"]} desc="Fermer modal · annuler drag" />
        </ShortcutGroup>
        <ShortcutGroup title="Liste catégories">
          <ShortcutRow keys={["↑", "↓"]} desc="Naviguer entre catégories" />
          <ShortcutRow keys={["Enter"]} desc="Sélectionner la catégorie" />
          <ShortcutRow keys={["N"]} desc="Nouvelle catégorie" />
        </ShortcutGroup>
        <ShortcutGroup title="Grille produits">
          <ShortcutRow keys={["↑", "↓", "←", "→"]} desc="Naviguer entre cards" />
          <ShortcutRow keys={["Enter"]} desc="Ouvrir l'inspecteur" />
          <ShortcutRow keys={["Space"]} desc="Toggle disponibilité" />
          <ShortcutRow keys={["⌘", "K"]} desc="Recherche globale" />
        </ShortcutGroup>
        <ShortcutGroup title="Wizard editor">
          <ShortcutRow keys={["J"]} desc="Étape suivante" />
          <ShortcutRow keys={["K"]} desc="Étape précédente" />
          <ShortcutRow keys={["D"]} desc="Dupliquer l'étape" />
          <ShortcutRow keys={["⌫"]} desc="Supprimer l'étape (avec confirm)" />
        </ShortcutGroup>
      </div>
    </div>
  </div>
);

const ShortcutGroup = ({ title, children }) => (
  <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
    <span className="studio-eyebrow">{title}</span>
    <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>{children}</div>
  </div>
);

const ShortcutRow = ({ keys, desc }) => (
  <div style={{ display: "flex", alignItems: "center", gap: 10, padding: "6px 4px", borderBottom: "1px dashed var(--studio-line)" }}>
    <div style={{ display: "flex", gap: 4, flex: "0 0 auto", minWidth: 86 }}>
      {keys.map((k, i) => <Kbd key={i}>{k}</Kbd>)}
    </div>
    <span style={{ flex: 1, fontSize: 12.5, color: "var(--studio-ink-soft)" }}>{desc}</span>
  </div>
);

const Kbd = ({ children, dark }) => (
  <span style={{
    minWidth: 22, height: 22, padding: "0 6px",
    background: dark ? "rgba(255,255,255,.12)" : "var(--cv1-surface-subtle)",
    color: dark ? "#fff" : "var(--studio-ink)",
    border: dark ? "1px solid rgba(255,255,255,.18)" : "1px solid var(--studio-line-strong)",
    borderRadius: 5, fontFamily: "var(--studio-font-mono)", fontSize: 11, fontWeight: 600,
    display: "inline-flex", alignItems: "center", justifyContent: "center",
    boxShadow: dark ? "none" : "0 1px 0 var(--studio-line-strong)",
  }}>{children}</span>
);

/* ===== 14) RESPONSIVE 1280px ==================================================== */
const Iter14Responsive1280 = ({ width = 1280, height = 800 }) => (
  <div className="studio" style={{
    width, height, background: "var(--studio-canvas)",
    display: "grid", gridTemplateColumns: "64px 1fr", overflow: "hidden",
    fontFamily: "var(--studio-font)",
  }}>
    {/* Collapsed rail */}
    <aside style={{ background: "#fff", borderRight: "1px solid var(--studio-line)", display: "flex", flexDirection: "column", padding: "10px 8px", gap: 6 }}>
      <button style={railBtn(true)}><Icon3 name="menu" size={16} /></button>
      <div style={{ height: 1, background: "var(--studio-line)", margin: "4px 0" }} />
      <button style={railBtn(false, true)}><Icon3 name="burger" size={16} /></button>
      <button style={railBtn(false)}><Icon3 name="menu" size={16} /></button>
      <button style={railBtn(false)}><Icon3 name="cup" size={16} /></button>
      <button style={railBtn(false)}><Icon3 name="cookie" size={16} /></button>
      <button style={railBtn(false)}><Icon3 name="tag" size={16} /></button>
      <button style={railBtn(false)}><Icon3 name="leaf" size={16} /></button>
      <div style={{ flex: 1 }} />
      <button style={railBtn(false)}><Icon3 name="settings" size={16} /></button>
    </aside>

    {/* Main */}
    <div style={{ display: "flex", flexDirection: "column", minWidth: 0 }}>
      {/* Topbar */}
      <header style={{
        height: 56, background: "#fff", borderBottom: "1px solid var(--studio-line)",
        padding: "0 16px", display: "flex", alignItems: "center", gap: 10,
      }}>
        <h1 style={{ margin: 0, fontSize: 15, fontWeight: 700 }}>Nos Tacos</h1>
        <span className="studio-pill">12 actifs</span>
        <div style={{ flex: 1 }} />
        <div style={{ display: "flex", alignItems: "center", gap: 6, padding: "6px 10px", border: "1px solid var(--studio-line-strong)", borderRadius: 8, background: "#fff", width: 200 }}>
          <Icon3 name="search" size={13} />
          <span style={{ fontSize: 12, color: "var(--studio-ink-mute)" }}>Rechercher…</span>
        </div>
        <Btn3 size="sm"><Icon3 name="store" size={12} />Filiale #1</Btn3>
        <Btn3 size="sm" variant="primary"><Icon3 name="publish" size={12} />Publier</Btn3>
      </header>

      {/* Toolbar with collapse trigger */}
      <div style={{ padding: "10px 16px", display: "flex", alignItems: "center", gap: 8, borderBottom: "1px solid var(--studio-line)", background: "#fff" }}>
        <Btn3 size="sm"><Icon3 name="plus" size={12} />Nouveau produit</Btn3>
        <Chip3>Tous</Chip3>
        <Chip3>Actifs</Chip3>
        <Chip3>Brouillons</Chip3>
        <div style={{ flex: 1 }} />
        <Btn3 size="sm">
          <Icon3 name="chevron-r" size={12} />Inspecteur
        </Btn3>
      </div>

      {/* Grid 3 columns */}
      <div style={{ flex: 1, padding: 16, display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gridAutoRows: "minmax(180px, 1fr)", gap: 12, overflow: "auto" }}>
        {[
          { kind: "tacos-m",  name: "Tacos M", price: "8,90 €", state: "active" },
          { kind: "tacos-l",  name: "Tacos L", price: "11,90 €", state: "active" },
          { kind: "tacos-xl", name: "Le Méga", price: "14,90 €", state: "86" },
          { kind: "tacos-m",  name: "Mini Tacos", price: "5,90 €", state: "draft" },
          { kind: "tacos-l",  name: "Tacos Box", price: "13,90 €", state: "active" },
          { kind: "tacos-xl", name: "Le Brave", price: "12,90 €", state: "active" },
        ].map((p, i) => (
          <div key={i} className="studio-card" style={{ padding: 12, display: "flex", flexDirection: "column", gap: 8 }}>
            <div style={{ width: "100%", aspectRatio: "4/3", borderRadius: 8, overflow: "hidden", display: "flex", alignItems: "center", justifyContent: "center", background: "var(--cv1-surface-subtle)" }}>
              <ProductThumb3 kind={p.kind} size={120} />
            </div>
            <div style={{ display: "flex", alignItems: "baseline", gap: 6 }}>
              <span style={{ flex: 1, fontSize: 13.5, fontWeight: 700 }}>{p.name}</span>
              <span style={{ fontSize: 12.5, fontWeight: 700 }}>{p.price}</span>
            </div>
            <div style={{ display: "flex", gap: 4 }}>
              <Pill3 status={p.state}>{p.state === "active" ? "Actif" : p.state === "86" ? "86" : "Brouillon"}</Pill3>
            </div>
          </div>
        ))}
      </div>

      {/* Hint */}
      <div style={{ padding: "8px 16px", borderTop: "1px solid var(--studio-line)", background: "#fff", fontSize: 11.5, color: "var(--studio-ink-mute)", display: "flex", alignItems: "center", gap: 8 }}>
        <Icon3 name="info" size={12} />
        À 1280px : rail collapsé en icônes (toggle ⌘B), inspecteur en overlay (toggle bouton), grille 3 colonnes au lieu de 4.
      </div>
    </div>

    {/* Inspector overlay (drawer) */}
    <aside style={{
      position: "absolute", top: 56, right: 0, bottom: 0, width: 360,
      background: "#fff", borderLeft: "1px solid var(--studio-line)",
      boxShadow: "var(--studio-elev-2)", padding: 14,
      display: "flex", flexDirection: "column", gap: 10,
    }}>
      <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
        <ProductThumb3 kind="tacos-l" size={36} />
        <div style={{ flex: 1, minWidth: 0 }}>
          <div className="studio-eyebrow">Inspecteur (overlay)</div>
          <div style={{ fontSize: 14, fontWeight: 700 }}>Tacos L</div>
        </div>
        <button style={{ background: "transparent", border: "none", color: "var(--studio-ink-mute)", cursor: "pointer" }}>
          <Icon3 name="x" size={14} />
        </button>
      </div>
      <div style={{ fontSize: 11.5, color: "var(--studio-ink-mute)" }}>
        À 1280px, l'inspecteur passe en overlay <code>fixed</code>. Esc le ferme. Le contenu de la grille reste centré.
      </div>
    </aside>
  </div>
);

const railBtn = (active, hint) => ({
  width: 48, height: 40, borderRadius: 8,
  background: active ? "var(--cv1-surface-strong)" : "transparent",
  color: active ? "#fff" : (hint ? "var(--cv1-border-emphasis)" : "var(--studio-ink-mute)"),
  border: hint ? "1.5px dashed var(--cv1-border-emphasis)" : "none",
  display: "flex", alignItems: "center", justifyContent: "center", cursor: "pointer",
});

/* ===== 15) RTL ARABIC =========================================================== */
const Iter15RTL = ({ width = 1280, height = 800 }) => (
  <div dir="rtl" lang="ar" className="studio" style={{
    width, height, background: "var(--studio-canvas)",
    display: "grid", gridTemplateColumns: "280px 1fr",
    fontFamily: "'Noto Sans Arabic', 'Inter', sans-serif",
  }}>
    {/* Categories rail (now on right) */}
    <aside style={{ background: "#fff", borderLeft: "1px solid var(--studio-line)", padding: 14, display: "flex", flexDirection: "column", gap: 6 }}>
      <span className="studio-eyebrow">الفئات</span>
      {[
        { ar: "الكل", n: 84, act: false },
        { ar: "تاكوس", n: 12, act: true },
        { ar: "البرغر", n: 18, act: false },
        { ar: "المشروبات", n: 22, act: false },
        { ar: "الحلويات", n: 9, act: false },
        { ar: "العروض", n: 6, act: false },
      ].map((c, i) => (
        <div key={i} style={{
          padding: "10px 12px", borderRadius: 8,
          display: "flex", alignItems: "center", gap: 8,
          background: c.act ? "var(--cv1-surface-strong)" : "transparent",
          color: c.act ? "#fff" : "var(--studio-ink)",
          fontWeight: c.act ? 700 : 500, cursor: "pointer", fontSize: 13.5,
        }}>
          <span style={{ flex: 1 }}>{c.ar}</span>
          <span style={{ fontSize: 11, opacity: 0.7 }}>{c.n}</span>
        </div>
      ))}
    </aside>

    <div style={{ display: "flex", flexDirection: "column", minWidth: 0 }}>
      <header style={{
        height: 56, background: "#fff", borderBottom: "1px solid var(--studio-line)",
        padding: "0 16px", display: "flex", alignItems: "center", gap: 10,
      }}>
        {/* Chevron flipped */}
        <button className="studio-btn studio-btn--ghost" style={{ padding: "0 8px" }}>
          <Icon3 name="chevron-r" size={14} />
          رجوع
        </button>
        <div style={{ width: 1, height: 24, background: "var(--studio-line)" }} />
        <h1 style={{ margin: 0, fontSize: 15, fontWeight: 700 }}>تاكوس</h1>
        <span className="studio-pill">12 منتج نشط</span>
        <div style={{ flex: 1 }} />
        <Btn3 size="sm"><Icon3 name="store" size={12} />فرع #1</Btn3>
        <Btn3 size="sm" variant="primary"><Icon3 name="publish" size={12} />نشر</Btn3>
      </header>

      <div style={{ padding: "10px 16px", display: "flex", alignItems: "center", gap: 8, borderBottom: "1px solid var(--studio-line)", background: "#fff" }}>
        <div style={{ display: "flex", alignItems: "center", gap: 6, padding: "6px 10px", border: "1px solid var(--studio-line-strong)", borderRadius: 8, background: "#fff", width: 220 }}>
          <Icon3 name="search" size={13} />
          <span style={{ fontSize: 12, color: "var(--studio-ink-mute)" }}>ابحث عن منتج…</span>
        </div>
        <Chip3>الكل</Chip3>
        <Chip3>نشط</Chip3>
        <Chip3>مسودة</Chip3>
      </div>

      <div style={{ flex: 1, padding: 16, display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gap: 12, overflow: "auto" }}>
        {[
          { kind: "tacos-m",  name: "تاكوس M", price: "8,90 €", state: "active" },
          { kind: "tacos-l",  name: "تاكوس L", price: "11,90 €", state: "active" },
          { kind: "tacos-xl", name: "الميغا",  price: "14,90 €", state: "86" },
          { kind: "tacos-m",  name: "ميني تاكوس", price: "5,90 €", state: "draft" },
          { kind: "tacos-l",  name: "بوكس تاكوس", price: "13,90 €", state: "active" },
          { kind: "tacos-xl", name: "البرايف", price: "12,90 €", state: "active" },
        ].map((p, i) => (
          <div key={i} className="studio-card" style={{ padding: 12, display: "flex", flexDirection: "column", gap: 8 }}>
            <div style={{ width: "100%", aspectRatio: "4/3", borderRadius: 8, overflow: "hidden", display: "flex", alignItems: "center", justifyContent: "center", background: "var(--cv1-surface-subtle)" }}>
              <ProductThumb3 kind={p.kind} size={120} />
            </div>
            <div style={{ display: "flex", alignItems: "baseline", gap: 6 }}>
              <span style={{ flex: 1, fontSize: 13.5, fontWeight: 700 }}>{p.name}</span>
              <span style={{ fontSize: 12.5, fontWeight: 700, fontFamily: "var(--studio-font)" }}>{p.price}</span>
            </div>
            {/* Channel chips MUST keep logical POS-then-Kiosk order */}
            <div style={{ display: "flex", gap: 4 }}>
              <Pill3 status={p.state}>{p.state === "active" ? "نشط" : p.state === "86" ? "86" : "مسودة"}</Pill3>
              <Chip3 channel="pos">POS</Chip3>
              <Chip3 channel="kiosk">Kiosk</Chip3>
            </div>
          </div>
        ))}
      </div>

      <div style={{ padding: "8px 16px", borderTop: "1px solid var(--studio-line)", background: "#fff", fontSize: 11.5, color: "var(--studio-ink-mute)", display: "flex", alignItems: "center", gap: 8 }}>
        <Icon3 name="info" size={12} />
        RTL : flex/grid s'inversent automatiquement. Chevrons retournés (chevron-r utilisé pour « retour »). Les codes monétaires et les chips POS/Kiosk gardent la lecture LTR via <code>direction: ltr</code> ciblé.
      </div>
    </div>
  </div>
);

/* ===== 16) ONBOARDING TOUR ====================================================== */
const Iter16Onboarding = ({ width = 1280, height = 800 }) => (
  <div className="studio" style={{
    width, height, background: "var(--studio-canvas)", position: "relative", overflow: "hidden",
  }}>
    {/* Mock page */}
    <div style={{
      position: "absolute", inset: 0,
      backgroundImage: "linear-gradient(180deg, #fff 0%, #fff 56px, var(--studio-canvas) 56px)",
    }} />
    <div style={{
      position: "absolute", top: 16, left: 16, fontSize: 14, fontWeight: 700,
    }}>Catalog Studio · Première visite</div>

    {/* Tooltips with attachment dots */}
    <Tooltip n={1} top={120} left={48} label="Bienvenue 👋" body="Voici tes catégories. Crée la première — tu pourras la renommer plus tard." />
    <Tooltip n={2} top={300} left={420} label="Ajoute un produit" body="Dans la catégorie, clique « + Nouveau produit » : prix TTC + photo et c'est en brouillon." />
    <Tooltip n={3} top={120} left={780} label="Configure le wizard" body="Choisis un template tacos/burger pour pré-câbler les 6 étapes en 5 secondes." />
    <Tooltip n={4} top={520} left={780} label="Publie" body="Le POS et la borne se mettent à jour en temps réel. Diff visible avant publication." />

    {/* Sticky checklist bottom-left */}
    <div className="studio-card" style={{
      position: "absolute", bottom: 18, left: 18, width: 320,
      padding: 14, boxShadow: "var(--studio-elev-2)",
      display: "flex", flexDirection: "column", gap: 10,
    }}>
      <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
        <span style={{
          width: 28, height: 28, borderRadius: 999,
          background: "conic-gradient(var(--cv1-border-emphasis) 0deg 90deg, var(--cv1-surface-subtle) 90deg 360deg)",
          display: "flex", alignItems: "center", justifyContent: "center",
        }}>
          <span style={{ width: 22, height: 22, borderRadius: 999, background: "#fff", display: "flex", alignItems: "center", justifyContent: "center", fontSize: 10.5, fontWeight: 700 }}>1/4</span>
        </span>
        <div style={{ flex: 1 }}>
          <div className="studio-eyebrow">Onboarding</div>
          <div style={{ fontSize: 13, fontWeight: 700 }}>Mets ta carte en ligne</div>
        </div>
        <button style={{ background: "transparent", border: "none", color: "var(--studio-ink-mute)", cursor: "pointer", fontSize: 11 }}>Ignorer</button>
      </div>
      {[
        { done: true,  label: "Créer une catégorie" },
        { done: false, label: "Ajouter un produit" },
        { done: false, label: "Configurer le wizard" },
        { done: false, label: "Publier la première version" },
      ].map((it, i) => (
        <div key={i} style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 12.5 }}>
          <span style={{
            width: 18, height: 18, borderRadius: 999,
            background: it.done ? "var(--studio-pill-active-bg)" : "var(--cv1-surface-subtle)",
            color: it.done ? "var(--studio-pill-active-fg)" : "var(--studio-ink-mute)",
            display: "flex", alignItems: "center", justifyContent: "center",
            border: `1.5px solid ${it.done ? "#bbf7d0" : "var(--studio-line-strong)"}`,
          }}>
            {it.done && <Icon3 name="check" size={11} stroke={3} />}
          </span>
          <span style={{ flex: 1, color: it.done ? "var(--studio-ink-mute)" : "var(--studio-ink)", textDecoration: it.done ? "line-through" : "none" }}>
            {it.label}
          </span>
        </div>
      ))}
    </div>
  </div>
);

const Tooltip = ({ n, top, left, label, body }) => (
  <div style={{ position: "absolute", top, left, zIndex: 5 }}>
    {/* attachment dot */}
    <span style={{
      position: "absolute", top: -10, left: -10,
      width: 22, height: 22, borderRadius: 999,
      background: "var(--cv1-border-emphasis)", color: "#fff",
      display: "flex", alignItems: "center", justifyContent: "center",
      fontSize: 11, fontWeight: 700,
      boxShadow: "0 0 0 4px rgba(37,99,235,.18)",
    }}>{n}</span>
    <div style={{
      width: 280, padding: 14, background: "#fff", borderRadius: 12,
      boxShadow: "var(--studio-elev-2)", border: "1px solid var(--studio-line)",
      display: "flex", flexDirection: "column", gap: 6,
    }}>
      <div style={{ fontSize: 13, fontWeight: 700 }}>{label}</div>
      <div style={{ fontSize: 12, color: "var(--studio-ink-soft)", lineHeight: 1.5 }}>{body}</div>
      <div style={{ display: "flex", gap: 6, marginTop: 4 }}>
        <button style={{ background: "transparent", border: "none", color: "var(--studio-ink-mute)", fontSize: 11.5, fontWeight: 600, cursor: "pointer", padding: 0 }}>Passer</button>
        <div style={{ flex: 1 }} />
        <Btn3 size="sm" variant="primary">{n === 4 ? "Terminer" : "Suivant"}</Btn3>
      </div>
    </div>
  </div>
);

/* ===== 17) REAL PHOTOS GUIDANCE ================================================ */
const Iter17Photos = ({ width = 1180, height = 560 }) => (
  <div className="studio" style={{ width, height, background: "var(--studio-canvas)", padding: 18, display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14 }}>
    {/* Specs panel */}
    <div className="studio-card" style={{ padding: 16, display: "flex", flexDirection: "column", gap: 10 }}>
      <header>
        <span className="studio-eyebrow">Doctrine photos produit</span>
        <h3 style={{ margin: "4px 0 0", fontSize: 16, fontWeight: 700 }}>Spec d'upload + ladder de fallback</h3>
      </header>
      <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 12.5 }}>
        <tbody>
          <tr><td style={tdK}>Ratio recommandé</td><td style={tdV}><code>4 : 3</code> (carrés tolérés mais cadrage automatique possible)</td></tr>
          <tr><td style={tdK}>Taille minimum</td><td style={tdV}><code>800 × 600 px</code> — sous ce seuil, warning à l'upload</td></tr>
          <tr><td style={tdK}>Taille maximum</td><td style={tdV}><code>4 Mo</code> — au-delà, erreur 413 + suggestion compresser</td></tr>
          <tr><td style={tdK}>Formats</td><td style={tdV}><code>PNG · JPG · WebP</code> · côté serveur, conversion auto en WebP</td></tr>
          <tr><td style={tdK}>Compression serveur</td><td style={tdV}>Sharp · 3 sizes générées (sm 320, md 640, lg 1280)</td></tr>
          <tr><td style={tdK}>Lazy load</td><td style={tdV}><code>IntersectionObserver</code> · <code>decode="async"</code> · placeholder LQIP</td></tr>
          <tr><td style={tdK}>Loading attr</td><td style={tdV}><code>loading="lazy"</code> partout sauf 1ère card visible</td></tr>
          <tr><td style={tdK}>A11y</td><td style={tdV}><code>alt</code> obligatoire à l'upload · empty alt = décor</td></tr>
        </tbody>
      </table>
    </div>

    {/* Fallback ladder */}
    <div className="studio-card" style={{ padding: 16, display: "flex", flexDirection: "column", gap: 12 }}>
      <span className="studio-eyebrow">Fallback ladder</span>
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 10 }}>
        <FallbackTier
          label="① Photo"
          desc="Photo originale uploadée"
          good
        >
          <div style={{ width: "100%", aspectRatio: "4/3", display: "flex", alignItems: "center", justifyContent: "center", background: "var(--cv1-surface-subtle)", borderRadius: 8 }}>
            <ProductThumb3 kind="tacos-l" size={120} />
          </div>
        </FallbackTier>
        <FallbackTier label="② SVG par catégorie" desc="Si photo absente : illustration par cat.">
          <div style={{
            width: "100%", aspectRatio: "4/3", display: "flex", alignItems: "center", justifyContent: "center",
            background: "linear-gradient(135deg, #fef3c7, #fde68a)", borderRadius: 8, color: "#92400e",
          }}>
            <Icon3 name="burger" size={56} />
          </div>
        </FallbackTier>
        <FallbackTier label="③ Initiale neutre" desc="Dernier recours : 1 lettre + neutre">
          <div style={{
            width: "100%", aspectRatio: "4/3", display: "flex", alignItems: "center", justifyContent: "center",
            background: "var(--studio-pill-neutral-bg)", borderRadius: 8,
            fontSize: 56, fontWeight: 800, color: "var(--studio-ink-mute)", letterSpacing: -2,
          }}>T</div>
        </FallbackTier>
      </div>
      <div style={{ fontSize: 11.5, color: "var(--studio-ink-mute)" }}>
        Ordre de chute : <code>image_url</code> → <code>category.fallback_svg</code> → <code>name[0].toUpperCase()</code>. Tout est rendu côté SSR pour éviter le flash.
      </div>
    </div>
  </div>
);

const tdK = { padding: "6px 8px", fontSize: 12, color: "var(--studio-ink-soft)", borderBottom: "1px solid var(--studio-line)", verticalAlign: "top", whiteSpace: "nowrap" };
const tdV = { padding: "6px 8px", fontSize: 12, fontWeight: 600, borderBottom: "1px solid var(--studio-line)" };

const FallbackTier = ({ label, desc, good, children }) => (
  <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
    {children}
    <div style={{ display: "flex", alignItems: "center", gap: 6 }}>
      {good ? <Pill3 status="active">Idéal</Pill3> : <Pill3>Fallback</Pill3>}
      <span style={{ fontSize: 12, fontWeight: 700 }}>{label}</span>
    </div>
    <span style={{ fontSize: 11, color: "var(--studio-ink-mute)" }}>{desc}</span>
  </div>
);

/* ===== 18) MICRO-INTERACTIONS =================================================== */
const Iter18Micro = ({ width = 1180, height = 540 }) => (
  <div className="studio" style={{ width, height, background: "var(--studio-canvas)", padding: 18, display: "flex", flexDirection: "column", gap: 14 }}>
    <header>
      <span className="studio-eyebrow">Micro-interactions</span>
      <h3 style={{ margin: "4px 0 0", fontSize: 16, fontWeight: 700 }}>À conserver vs neutralisées en <code>prefers-reduced-motion</code></h3>
    </header>
    <div className="studio-card" style={{ padding: 0, overflow: "hidden" }}>
      <table style={{ width: "100%", borderCollapse: "collapse", fontSize: 12.5 }}>
        <thead>
          <tr style={{ background: "var(--cv1-surface-subtle)" }}>
            <th style={th}>Interaction</th>
            <th style={th}>Comportement par défaut</th>
            <th style={th}>Reduced-motion</th>
            <th style={th}>Token / impl.</th>
          </tr>
        </thead>
        <tbody>
          {[
            ["Hover ProductCard",  "translateY(-1px) + ombre +1 (200ms)", "neutralisé · pas de translate, ombre fixe", "transition: transform/box-shadow"],
            ["Publish CTA :active","scale(0.98) (80ms)",                   "neutralisé · feedback uniquement bg",       "transform"],
            ["Drag drop final",    "snap immédiat sans easing pour précision", "gardé identique",                       "no transition on drop"],
            ["Skeleton shimmer",   "200% gradient sweep 1.4s loop",        "remplacé par fond statique",                "@keyframes studioShimmer"],
            ["Toast slide-in",     "translateY(8px) + fade 200ms",         "fade seul · pas de translate",              "cv1-motion-default"],
            ["Step picker swap",   "cross-fade 200ms",                     "swap immédiat",                             "transition: opacity"],
            ["Drag handle hover",  "curseur grab + scale 1.05",            "curseur seul",                              "cursor"],
            ["Auto-save check",    "fade vers idle après 2s",              "swap instantané après 2s",                  "setTimeout"],
          ].map((row, i) => (
            <tr key={i} style={{ background: i % 2 ? "#fff" : "var(--cv1-surface-subtle)" }}>
              <td style={td}><strong>{row[0]}</strong></td>
              <td style={td}>{row[1]}</td>
              <td style={td}><span style={{ color: "var(--studio-ink-soft)" }}>{row[2]}</span></td>
              <td style={td}><code>{row[3]}</code></td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
    <div style={{ padding: "10px 14px", borderRadius: 10, background: "var(--cv1-status-info-bg)", border: "1px solid var(--cv1-status-info-border)", color: "var(--cv1-status-info-text)", fontSize: 12, display: "flex", alignItems: "center", gap: 8 }}>
      <Icon3 name="info" size={13} />
      Implémentation : <code>tokens.css</code> contient <code>@media (prefers-reduced-motion: reduce)</code> qui force <code>transition-duration: 0.001ms</code> sur tout le conteneur <code>.studio</code>. Aucun JS dépendant des transitions ne peut casser.
    </div>
  </div>
);

const th = { padding: "10px 14px", textAlign: "left", fontWeight: 700, color: "var(--studio-ink-soft)", borderBottom: "1px solid var(--studio-line)", fontSize: 11, textTransform: "uppercase", letterSpacing: "0.04em" };
const td = { padding: "8px 14px", borderBottom: "1px solid var(--studio-line)", verticalAlign: "top" };

/* ===== Iter3 README · récap des 18 ============================================== */
const Iter3Readme = ({ width = 1240, height = 1100 }) => {
  const items = [
    { n: 1,  area: "Wizard editor", t: "Drag & drop reorder",        loc: "i1-drag",         vue: "ComposerStepListSidebar.vue" },
    { n: 2,  area: "Inspecteur",    t: "Branch overrides matrix",    loc: "i1-branch",       vue: "AvailabilityToggleComponent.vue" },
    { n: 3,  area: "Quick Create",  t: "Image upload — 5 états",     loc: "i1-upload",       vue: "<MediaUploaderComponent />" },
    { n: 4,  area: "Diff modal",    t: "Cas couverts + concret + conflict", loc: "i1-diff",  vue: "ComposerPublishDiffModal.vue" },
    { n: 5,  area: "Wizard topbar", t: "Conflict banner v(n)",       loc: "i1-conflict",     vue: "ProductComposerEditorComponent.vue" },
    { n: 6,  area: "Step editor",   t: "Source picker · création à la volée", loc: "i2-source-picker", vue: "<SourceCreateSheet />" },
    { n: 7,  area: "Wizard topbar", t: "Auto-save · 5 états",        loc: "i2-autosave",     vue: "AutoSavePill" },
    { n: 8,  area: "Tous écrans",   t: "Empty / Loading / Error / No-permission", loc: "i2-states", vue: "<StateRenderer />" },
    { n: 9,  area: "Global",        t: "Toast micro-system",         loc: "i2-toasts",       vue: "<ToastHost />" },
    { n: 10, area: "Permissions",   t: "Matrice 3 rôles × 9 actions", loc: "i2-perms",       vue: "permissionChecker.js" },
    { n: 11, area: "Stock",         t: "Resolve flow inline + undo", loc: "i2-resolve",      vue: "StockRuptureDashboardComponent.vue" },
    { n: 12, area: "Toolbar",       t: "Live search · highlight + no-results", loc: "i2-search", vue: "<CatalogSearchBox />" },
    { n: 13, area: "Global",        t: "Raccourcis clavier (⌘+/)",   loc: "i3-shortcuts",    vue: "<KeyboardShortcutsHelp />" },
    { n: 14, area: "Layout",        t: "Variant 1280px · rail + inspecteur collapsibles", loc: "i3-1280", vue: "CatalogStudioComponent.vue" },
    { n: 15, area: "i18n",          t: "RTL Arabe · direction inversée + chevrons flippés", loc: "i3-rtl", vue: "global CSS · dir attribute" },
    { n: 16, area: "Onboarding",    t: "Tour 4 tooltips + checklist sticky", loc: "i3-onboarding", vue: "<OnboardingTour />" },
    { n: 17, area: "Médias",        t: "Photos guidance + fallback ladder", loc: "i3-photos", vue: "ImageWithFallback.vue" },
    { n: 18, area: "Motion",        t: "Micro-interactions vs reduced-motion", loc: "i3-micro", vue: "tokens.css @media query" },
  ];
  return (
    <div className="studio" style={{
      width, height, background: "var(--studio-canvas)", padding: 28, overflow: "auto",
    }}>
      <header style={{ marginBottom: 16 }}>
        <span className="studio-eyebrow">Handoff · récap 18 améliorations</span>
        <h1 style={{ margin: "4px 0 0", fontSize: 24, fontWeight: 800, letterSpacing: -0.4 }}>
          Catalog Studio — README v2
        </h1>
        <p style={{ margin: "6px 0 0", color: "var(--studio-ink-soft)", fontSize: 13.5, maxWidth: 920 }}>
          Trois itérations livrées : <strong>Edge cases</strong> (1–5) · <strong>Robustness</strong> (6–12) · <strong>Polish</strong> (13–18).
          Chaque ligne pointe l'artboard correspondant et le composant Vue cible.
        </p>
      </header>

      <div style={{ display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gap: 10 }}>
        {items.map(it => (
          <div key={it.n} className="studio-card" style={{ padding: 12, display: "flex", flexDirection: "column", gap: 6 }}>
            <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
              <span style={{
                width: 26, height: 26, borderRadius: 7,
                background: it.n <= 5 ? "#eef2ff" : it.n <= 12 ? "#ecfeff" : "#fdf4ff",
                color: it.n <= 5 ? "#3730a3" : it.n <= 12 ? "#155e75" : "#86198f",
                fontSize: 12, fontWeight: 700,
                display: "flex", alignItems: "center", justifyContent: "center",
              }}>{it.n}</span>
              <span style={{ flex: 1, fontSize: 13, fontWeight: 700 }}>{it.t}</span>
            </div>
            <div style={{ fontSize: 11, color: "var(--studio-ink-mute)", display: "flex", gap: 6, alignItems: "center" }}>
              <span style={{ background: "var(--cv1-surface-subtle)", padding: "2px 6px", borderRadius: 4, fontWeight: 600 }}>{it.area}</span>
              <span>→</span>
              <code>{it.loc}</code>
            </div>
            <code style={{ fontSize: 10.5, color: "var(--studio-ink-mute)" }}>{it.vue}</code>
          </div>
        ))}
      </div>

      <div className="studio-card" style={{ padding: 16, marginTop: 16, display: "flex", flexDirection: "column", gap: 10 }}>
        <span className="studio-eyebrow">Sections du canvas (ordre de lecture conseillé)</span>
        <ol style={{ margin: 0, paddingLeft: 18, fontSize: 13, color: "var(--studio-ink-soft)", lineHeight: 1.7 }}>
          <li><strong>Overview</strong> — README initial · mapping écran → fichier .vue · tokens delta · a11y.</li>
          <li><strong>Screen 1–6</strong> — design hi-fi des 6 écrans + variantes wizard tacos.</li>
          <li><strong>Iter 1 · Edge cases</strong> — drag-reorder, branch overrides, image upload, diff cases, conflict banner.</li>
          <li><strong>Iter 2 · Robustness</strong> — création à la volée, auto-save, états par écran, toasts, perms, resolve, search.</li>
          <li><strong>Iter 3 · Polish</strong> — shortcuts, 1280, RTL, onboarding, photos, micro-motion + ce récap.</li>
          <li><strong>Components</strong> — atomes (cards, pills, switches) avec tous leurs états.</li>
        </ol>
      </div>
    </div>
  );
};

window.Iter13Shortcuts = Iter13Shortcuts;
window.Iter14Responsive1280 = Iter14Responsive1280;
window.Iter15RTL = Iter15RTL;
window.Iter16Onboarding = Iter16Onboarding;
window.Iter17Photos = Iter17Photos;
window.Iter18Micro = Iter18Micro;
window.Iter3Readme = Iter3Readme;

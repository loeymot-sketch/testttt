/* studio-screen1.jsx — Screen 1: Catalog Studio Home (CatalogStudioComponent.vue)
   Plus inline Quick Create (Screen 2 quick-create inline)
   Plus Stock Health side panel (Screen 5 inline + side)
*/

const Studio1 = ({ width = 1440, height = 900, state = "default" }) => {
  // state: "default" | "selected" | "quickcreate" | "loading" | "empty" | "error"
  const isLoading = state === "loading";
  const isEmpty   = state === "empty";
  const isError   = state === "error";
  const isQC      = state === "quickcreate";
  const selProduct = state === "selected" ? STUDIO_PRODUCTS[0] : null;

  return (
    <div className="studio" style={{
      width, height, background: "var(--studio-canvas)",
      display: "flex", flexDirection: "column",
      fontFamily: "var(--studio-font)",
      fontSize: 13,
    }}>
      <TopBar />
      <div style={{ display: "flex", flex: 1, minHeight: 0 }}>
        <LeftRail />
        <Main state={state} selProduct={selProduct} />
        <RightRail selProduct={selProduct} state={state} />
      </div>
      <BottomToast state={state} />
    </div>
  );
};

const TopBar = () => (
  <header style={{
    height: 56, background: "#fff",
    borderBottom: "1px solid var(--studio-line)",
    display: "flex", alignItems: "center", padding: "0 20px", gap: 16, flexShrink: 0,
  }}>
    {/* Logo */}
    <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
      <div style={{
        width: 28, height: 28, borderRadius: 8, background: "var(--fk-red)",
        display: "flex", alignItems: "center", justifyContent: "center",
        color: "#fff", fontWeight: 800, fontSize: 14, letterSpacing: -0.5,
      }}>FK</div>
      <span style={{ fontWeight: 700, fontSize: 14, color: "var(--studio-ink)" }}>FoodKing</span>
      <span style={{ color: "var(--studio-ink-mute)", fontSize: 13 }}>/</span>
      <span style={{ fontWeight: 600, fontSize: 13, color: "var(--studio-ink-soft)" }}>Catalog Studio</span>
    </div>
    <div style={{ flex: 1 }} />
    {/* Branch selector */}
    <div style={{
      display: "flex", alignItems: "center", gap: 8, padding: "6px 10px",
      borderRadius: 8, border: "1px solid var(--studio-line-strong)", background: "#fff",
      cursor: "pointer", fontSize: 12.5, fontWeight: 600,
    }}>
      <Icon name="store" size={14} />
      <span>Filiale #1 — Le Cay</span>
      <Icon name="chevron-d" size={14} />
    </div>
    {/* Unsaved drafts → publish */}
    <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
      <span className="studio-pill" data-status="draft"><span className="dot" />3 brouillons</span>
      <Btn variant="primary"><Icon name="publish" size={14} />Publier toutes les modifs</Btn>
    </div>
    <div style={{ width: 1, height: 24, background: "var(--studio-line)" }} />
    <div style={{
      width: 32, height: 32, borderRadius: 999,
      background: "linear-gradient(135deg,#0ea5e9,#6366f1)",
      display: "flex", alignItems: "center", justifyContent: "center",
      color: "#fff", fontWeight: 700, fontSize: 12,
    }}>YL</div>
  </header>
);

const LeftRail = () => (
  <aside style={{
    width: 280, background: "var(--studio-rail)",
    borderRight: "1px solid var(--studio-line)",
    display: "flex", flexDirection: "column", flexShrink: 0,
  }}>
    {/* Search */}
    <div style={{ padding: 16, borderBottom: "1px solid var(--studio-line)" }}>
      <div style={{ position: "relative" }}>
        <span style={{ position: "absolute", left: 10, top: 8, color: "var(--studio-ink-mute)" }}>
          <Icon name="search" size={14} />
        </span>
        <input className="studio-input" style={{ paddingLeft: 30, height: 32 }}
               placeholder="Rechercher une catégorie…" />
      </div>
    </div>

    {/* Categories header */}
    <div style={{
      padding: "12px 16px 8px", display: "flex", alignItems: "center",
      justifyContent: "space-between",
    }}>
      <span className="studio-eyebrow">Catégories</span>
      <button className="studio-btn studio-btn--sm studio-btn--ghost" style={{ color: "var(--cv1-border-emphasis)" }}>
        <Icon name="plus" size={12} />Nouvelle
      </button>
    </div>

    {/* Tree */}
    <nav style={{ flex: 1, overflowY: "auto", padding: "0 8px 12px" }}>
      <CatRow name="Toutes les catégories" count={68} all />
      {STUDIO_CATEGORIES.map(c => (
        <CatRow key={c.id} name={c.name} count={c.count} active={c.selected} hidden={c.hidden} />
      ))}
    </nav>

    {/* Footer link */}
    <div style={{ borderTop: "1px solid var(--studio-line)", padding: 12 }}>
      <a style={{
        display: "flex", alignItems: "center", gap: 8, padding: "6px 8px",
        color: "var(--studio-ink-mute)", fontSize: 12, fontWeight: 500,
        textDecoration: "none", borderRadius: 6,
      }}>
        <Icon name="settings" size={13} />
        Paramètres avancés
        <span style={{ marginLeft: "auto" }}><Icon name="arrow-r" size={12} /></span>
      </a>
    </div>
  </aside>
);

const CatRow = ({ name, count, active, all, hidden }) => (
  <div style={{
    display: "flex", alignItems: "center", gap: 6, padding: "6px 8px",
    borderRadius: 8,
    background: active ? "#eef2ff" : "transparent",
    color: active ? "#3730a3" : "var(--studio-ink)",
    fontWeight: active ? 600 : 500,
    fontSize: 13, cursor: "pointer", marginBottom: 1,
    position: "relative",
  }}>
    {!all && (
      <span style={{ color: "var(--studio-ink-mute)", opacity: 0.5, cursor: "grab" }}>
        <Icon name="drag" size={12} />
      </span>
    )}
    <span style={{ flex: 1, display: "flex", alignItems: "center", gap: 6 }}>
      {name}
      {hidden && <Icon name="eye-off" size={11} />}
    </span>
    <span style={{
      fontSize: 11, fontWeight: 600,
      color: active ? "#3730a3" : "var(--studio-ink-mute)",
      background: active ? "#e0e7ff" : "var(--studio-pill-neutral-bg)",
      padding: "1px 6px", borderRadius: 999, minWidth: 20, textAlign: "center",
    }}>{count}</span>
  </div>
);

const Main = ({ state, selProduct }) => {
  const isLoading = state === "loading";
  const isEmpty   = state === "empty";
  const isError   = state === "error";
  const isQC      = state === "quickcreate";

  return (
    <main style={{
      flex: 1, overflowY: "auto", padding: 20, minWidth: 0,
      display: "flex", flexDirection: "column", gap: 16,
    }}>
      {/* Header */}
      <div style={{ display: "flex", alignItems: "flex-end", justifyContent: "space-between", gap: 16 }}>
        <div>
          <span className="studio-eyebrow">Catégorie sélectionnée</span>
          <h1 style={{
            margin: "4px 0 0", fontSize: 22, fontWeight: 700, letterSpacing: -0.4,
            display: "flex", alignItems: "center", gap: 10,
          }}>
            Nos Tacos
            <span style={{ fontSize: 13, fontWeight: 500, color: "var(--studio-ink-mute)" }}>· 8 produits</span>
          </h1>
        </div>
        <Btn variant="brand"><Icon name="plus" size={14} />Nouveau produit</Btn>
      </div>

      {/* Toolbar */}
      <div style={{
        display: "flex", gap: 8, padding: "10px 12px",
        background: "#fff", border: "1px solid var(--studio-line)", borderRadius: 12,
      }}>
        <div style={{ position: "relative", flex: "0 0 280px" }}>
          <span style={{ position: "absolute", left: 10, top: 8, color: "var(--studio-ink-mute)" }}>
            <Icon name="search" size={14} />
          </span>
          <input className="studio-input" style={{ paddingLeft: 30 }}
                 placeholder="Rechercher un produit…" />
        </div>
        <FilterPill label="Statut" value="Tous" />
        <FilterPill label="Filiale" value="Filiale #1" icon="store" />
        <FilterPill label="Wizard" value="Tous" />
        <div style={{ flex: 1 }} />
        <Btn size="sm"><Icon name="sort" size={13} />Trier · A→Z</Btn>
      </div>

      {/* Body */}
      {isError ? <ErrorState /> :
       isEmpty ? <EmptyState /> :
       isLoading ? <LoadingGrid /> :
       <ProductGrid quickCreate={isQC} selectedId={selProduct?.id} />}
    </main>
  );
};

const FilterPill = ({ label, value, icon }) => (
  <button style={{
    display: "inline-flex", alignItems: "center", gap: 6,
    height: 32, padding: "0 10px", borderRadius: 8,
    border: "1px solid var(--studio-line-strong)", background: "#fff",
    fontSize: 12.5, color: "var(--studio-ink-soft)", cursor: "pointer",
  }}>
    {icon && <Icon name={icon} size={13} />}
    <span style={{ color: "var(--studio-ink-mute)", fontWeight: 500 }}>{label}</span>
    <span style={{ fontWeight: 600, color: "var(--studio-ink)" }}>{value}</span>
    <Icon name="chevron-d" size={12} />
  </button>
);

const ProductGrid = ({ quickCreate, selectedId }) => (
  <div style={{
    display: "grid",
    gridTemplateColumns: "repeat(auto-fill, minmax(196px, 1fr))",
    gap: 14,
  }}>
    {quickCreate ? <QuickCreateTile /> : <NewProductTile />}
    {STUDIO_PRODUCTS.map(p => (
      <ProductCard key={p.id} p={p} selected={p.id === selectedId} />
    ))}
  </div>
);

const NewProductTile = () => (
  <div style={{
    border: "1.5px dashed var(--studio-line-strong)", borderRadius: 14,
    background: "#fff", height: 248,
    display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center",
    gap: 8, color: "var(--studio-ink-mute)", cursor: "pointer",
  }}>
    <div style={{
      width: 36, height: 36, borderRadius: 999, background: "var(--studio-pill-neutral-bg)",
      display: "flex", alignItems: "center", justifyContent: "center",
      color: "var(--cv1-border-emphasis)",
    }}><Icon name="plus" size={18} /></div>
    <span style={{ fontWeight: 600, color: "var(--studio-ink)", fontSize: 13 }}>Nouveau produit</span>
    <span style={{ fontSize: 11, textAlign: "center", padding: "0 16px" }}>
      Crée en 30s · choisis un template
    </span>
  </div>
);

const QuickCreateTile = () => (
  <div style={{
    gridColumn: "span 2",
    border: "1px solid var(--cv1-border-emphasis)", borderRadius: 14,
    background: "#fff", height: 248,
    boxShadow: "0 0 0 3px rgba(37,99,235,0.12)",
    display: "flex", flexDirection: "column", padding: 14, gap: 10,
  }}>
    <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
      <div>
        <span className="studio-eyebrow" style={{ color: "var(--cv1-border-emphasis)" }}>Étape 1 / 2</span>
        <div style={{ fontSize: 14, fontWeight: 700, marginTop: 2 }}>Identité du produit</div>
      </div>
      <button style={{ background: "transparent", border: "none", cursor: "pointer", color: "var(--studio-ink-mute)" }}>
        <Icon name="x" size={16} />
      </button>
    </div>
    <div style={{ display: "grid", gridTemplateColumns: "80px 1fr", gap: 10, flex: 1 }}>
      <div style={{
        border: "1.5px dashed var(--studio-line-strong)", borderRadius: 10,
        display: "flex", alignItems: "center", justifyContent: "center",
        color: "var(--studio-ink-mute)", flexDirection: "column", gap: 4,
      }}>
        <Icon name="upload" size={18} />
        <span style={{ fontSize: 10 }}>Photo</span>
      </div>
      <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
        <input className="studio-input" placeholder="Nom du produit" defaultValue="Tacos M (1 viande)" />
        <div style={{ display: "flex", gap: 6 }}>
          <select className="studio-input" style={{ width: 130 }}><option>Nos Tacos</option></select>
          <input className="studio-input" placeholder="Description courte" />
        </div>
      </div>
    </div>
    <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
      <div style={{ display: "flex", gap: 4 }}>
        <span style={{ width: 24, height: 4, background: "var(--cv1-border-emphasis)", borderRadius: 2 }} />
        <span style={{ width: 24, height: 4, background: "var(--studio-line)", borderRadius: 2 }} />
      </div>
      <Btn variant="primary" size="sm">Continuer<Icon name="chevron-r" size={13} /></Btn>
    </div>
  </div>
);

const ProductCard = ({ p, selected }) => {
  const ringStyle = selected ? {
    borderColor: "var(--cv1-border-emphasis)",
    boxShadow: "0 0 0 3px rgba(37,99,235,0.16)",
  } : {};
  return (
    <article style={{
      background: "#fff", border: "1px solid var(--studio-line)", borderRadius: 14,
      overflow: "hidden", display: "flex", flexDirection: "column",
      ...ringStyle,
    }}>
      <div style={{
        height: 110, background: "linear-gradient(180deg,#fef3c7,#fde68a)",
        position: "relative", display: "flex", alignItems: "center", justifyContent: "center",
      }}>
        <ProductThumb kind={p.img} size={72} />
        <div style={{ position: "absolute", top: 8, left: 8 }}>
          <Pill status={p.status === "active" ? "active" : p.status === "draft" ? "draft" : "86"}>
            {p.status === "active" ? "Actif" : p.status === "draft" ? "Brouillon" : "86"}
          </Pill>
        </div>
        <div style={{ position: "absolute", top: 8, right: 8 }}>
          <button style={{
            width: 24, height: 24, borderRadius: 6, border: "none",
            background: "rgba(255,255,255,0.85)", cursor: "pointer",
            display: "flex", alignItems: "center", justifyContent: "center",
            color: "var(--studio-ink-soft)",
          }}>
            <Icon name="more" size={14} />
          </button>
        </div>
      </div>
      <div style={{ padding: 12, display: "flex", flexDirection: "column", gap: 6, flex: 1 }}>
        <div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", gap: 6 }}>
          <h4 style={{ margin: 0, fontSize: 13.5, fontWeight: 600, lineHeight: 1.25 }}>{p.name}</h4>
          <span style={{ fontSize: 13, fontWeight: 700 }}>{p.price}</span>
        </div>
        <div style={{ display: "flex", alignItems: "center", gap: 6, fontSize: 11 }}>
          <Chip>Wizard {p.wizard}</Chip>
          <span style={{ color: "var(--studio-ink-mute)" }}>· TTC</span>
        </div>
        {/* Stock inline */}
        <div style={{
          marginTop: "auto", display: "flex", alignItems: "center", gap: 8,
          padding: "8px 10px", borderRadius: 8,
          background: p.reason ? "var(--studio-pill-86-bg)" : "var(--cv1-surface-subtle)",
          border: `1px solid ${p.reason ? "var(--studio-pill-86-bg)" : "var(--studio-line)"}`,
        }}>
          <Switch on={!p.reason} reason={p.reason} />
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ fontSize: 11.5, fontWeight: 600, color: p.reason ? "var(--studio-pill-86-fg)" : "var(--studio-ink-soft)" }}>
              {p.reason ? "Indisponible" : "Disponible"}
            </div>
            <div style={{ fontSize: 10, color: "var(--studio-ink-mute)" }}>
              {p.reason ? "Stock épuisé · auto-86" : "Filiale #1"}
            </div>
          </div>
        </div>
      </div>
    </article>
  );
};

const RightRail = ({ selProduct, state }) => (
  <aside style={{
    width: 360, background: "#fff", borderLeft: "1px solid var(--studio-line)",
    flexShrink: 0, display: "flex", flexDirection: "column", overflowY: "auto",
  }}>
    {selProduct ? <ProductInspector p={selProduct} /> : <StockHealth />}
  </aside>
);

const StockHealth = () => (
  <div style={{ padding: 16, display: "flex", flexDirection: "column", gap: 14 }}>
    <div>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
        <h3 style={{ margin: 0, fontSize: 14, fontWeight: 700 }}>Santé du stock</h3>
        <Pill>Filiale #1</Pill>
      </div>
      <div style={{ marginTop: 4, fontSize: 12, color: "var(--studio-ink-mute)" }}>
        Dernière analyse il y a 8 min · 142 produits scannés
      </div>
    </div>

    {/* KPI row */}
    <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 8 }}>
      <KPI value="3" label="Auto-86 (24h)" tone="86" />
      <KPI value="7" label="Stock bas" tone="draft" />
      <KPI value="142" label="Scannés" tone="neutral" />
    </div>

    {/* Auto-86 */}
    <Section title="Auto-86 récents" action="Tout voir">
      {STOCK_AUTO86.map(e => (
        <div key={e.id} style={{
          padding: 10, borderRadius: 10, background: "var(--studio-pill-86-bg)",
          border: "1px solid #fecdd3", display: "flex", flexDirection: "column", gap: 6,
        }}>
          <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
            <span style={{ fontWeight: 600, fontSize: 12.5 }}>{e.product}</span>
            <span style={{ fontSize: 10.5, color: "var(--studio-pill-86-fg)" }}>{e.at}</span>
          </div>
          <div style={{ fontSize: 11.5, color: "var(--studio-pill-86-fg)" }}>
            {e.reason} · {e.branch}
          </div>
          <div style={{ display: "flex", gap: 6 }}>
            <Btn size="sm" variant="primary"><Icon name="check" size={12} />Résoudre</Btn>
            <Btn size="sm">Voir le produit</Btn>
          </div>
        </div>
      ))}
    </Section>

    {/* Low stock */}
    <Section title="Stock bas" action="Seuils">
      {STOCK_LOW.map(l => (
        <div key={l.id} style={{
          display: "flex", alignItems: "center", gap: 10, padding: "8px 10px",
          borderRadius: 8, background: "var(--cv1-surface-subtle)",
        }}>
          <div style={{ flex: 1 }}>
            <div style={{ fontWeight: 600, fontSize: 12.5 }}>{l.product}</div>
            <div style={{ fontSize: 11, color: "var(--studio-ink-mute)" }}>
              {l.qty}/{l.threshold} · {l.branch}
            </div>
          </div>
          <div style={{
            width: 60, height: 6, borderRadius: 999, background: "var(--studio-line)", overflow: "hidden",
          }}>
            <div style={{
              width: `${(l.qty / l.threshold) * 100}%`, height: "100%",
              background: l.qty / l.threshold < 0.5 ? "#ef4444" : "#f59e0b",
            }} />
          </div>
        </div>
      ))}
    </Section>

    <Btn block><Icon name="refresh" size={14} />Lancer un scan</Btn>
  </div>
);

const KPI = ({ value, label, tone }) => {
  const colors = {
    "86": { bg: "var(--studio-pill-86-bg)", fg: "var(--studio-pill-86-fg)" },
    draft: { bg: "var(--studio-pill-draft-bg)", fg: "var(--studio-pill-draft-fg)" },
    neutral: { bg: "var(--cv1-surface-subtle)", fg: "var(--studio-ink-soft)" },
  }[tone];
  return (
    <div style={{
      padding: "10px 12px", borderRadius: 10, background: colors.bg, color: colors.fg,
    }}>
      <div style={{ fontSize: 22, fontWeight: 700, lineHeight: 1 }}>{value}</div>
      <div style={{ fontSize: 11, marginTop: 4, fontWeight: 500 }}>{label}</div>
    </div>
  );
};

const Section = ({ title, action, children }) => (
  <div>
    <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 8 }}>
      <span className="studio-eyebrow">{title}</span>
      {action && <button style={{
        background: "transparent", border: "none", color: "var(--cv1-border-emphasis)",
        fontSize: 11, fontWeight: 600, cursor: "pointer",
      }}>{action}</button>}
    </div>
    <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>{children}</div>
  </div>
);

const ProductInspector = ({ p }) => (
  <div style={{ display: "flex", flexDirection: "column", height: "100%" }}>
    {/* Header */}
    <div style={{ padding: 16, borderBottom: "1px solid var(--studio-line)" }}>
      <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
        <ProductThumb kind={p.img} size={48} />
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ fontSize: 14, fontWeight: 700, lineHeight: 1.25 }}>{p.name}</div>
          <div style={{ fontSize: 11.5, color: "var(--studio-ink-mute)" }}>Nos Tacos · {p.price} TTC</div>
        </div>
        <button style={{ background: "transparent", border: "none", cursor: "pointer", color: "var(--studio-ink-mute)" }}>
          <Icon name="x" size={16} />
        </button>
      </div>
      {/* Tabs */}
      <div style={{ display: "flex", gap: 2, marginTop: 12, borderBottom: "none" }}>
        {["Détails", "Wizard", "Stock", "Canaux"].map((t, i) => (
          <button key={t} style={{
            padding: "6px 10px", border: "none", background: "transparent",
            fontSize: 12, fontWeight: 600,
            color: i === 1 ? "var(--cv1-border-emphasis)" : "var(--studio-ink-mute)",
            borderBottom: `2px solid ${i === 1 ? "var(--cv1-border-emphasis)" : "transparent"}`,
            marginBottom: -1, cursor: "pointer",
          }}>{t}</button>
        ))}
      </div>
    </div>
    {/* Wizard tab content */}
    <div style={{ flex: 1, overflowY: "auto", padding: 14, display: "flex", flexDirection: "column", gap: 10 }}>
      <div style={{
        padding: 10, borderRadius: 10, background: "var(--cv1-surface-subtle)",
        display: "flex", alignItems: "center", gap: 10,
      }}>
        <span className="studio-pill" data-status="active"><span className="dot" />Publié v3</span>
        <span style={{ fontSize: 11, color: "var(--studio-ink-mute)" }}>il y a 2 jours</span>
        <div style={{ flex: 1 }} />
        <Btn size="sm">Ouvrir l'éditeur<Icon name="arrow-r" size={12} /></Btn>
      </div>

      <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
        <span className="studio-eyebrow">Étapes (6)</span>
        {TACOS_STEPS.map(s => (
          <div key={s.id} style={{
            display: "flex", alignItems: "center", gap: 8, padding: "8px 10px",
            background: "#fff", border: "1px solid var(--studio-line)", borderRadius: 8,
          }}>
            <span style={{
              width: 18, height: 18, borderRadius: 999, background: "var(--cv1-surface-subtle)",
              display: "flex", alignItems: "center", justifyContent: "center",
              fontSize: 10.5, fontWeight: 700, color: "var(--studio-ink-soft)",
            }}>{s.position}</span>
            <span style={{ flex: 1, fontSize: 12.5, fontWeight: 500 }}>{s.label}</span>
            <Chip src={s.source_type}>{
              s.source_type === "item_attribute" ? "Attribut" :
              s.source_type === "extra_group" ? "Extra" : "Addon"
            }</Chip>
          </div>
        ))}
      </div>

      <div style={{ display: "flex", flexDirection: "column", gap: 4, marginTop: 6 }}>
        <span className="studio-eyebrow">Visibilité</span>
        <div style={{ display: "flex", gap: 6 }}>
          <Chip channel="pos">POS</Chip>
          <Chip channel="kiosk">Kiosk</Chip>
          <Chip>Web — désactivé</Chip>
        </div>
      </div>
    </div>
    {/* Footer actions */}
    <div style={{ padding: 12, borderTop: "1px solid var(--studio-line)", display: "flex", gap: 8 }}>
      <Btn block>Voir aperçu</Btn>
      <Btn block variant="primary"><Icon name="publish" size={13} />Publier</Btn>
    </div>
  </div>
);

const BottomToast = ({ state }) => {
  if (state === "loading" || state === "empty" || state === "error") return null;
  return (
    <div style={{ position: "absolute", bottom: 16, right: 16, zIndex: 5 }}>
      <div className="cv1-toast" style={{
        display: "flex", alignItems: "center", gap: 10, padding: "10px 14px",
        minWidth: 320, fontSize: 12.5,
      }}>
        <Icon name="check-c" size={16} />
        <div style={{ flex: 1 }}>
          <strong>"Le Méga" mis hors stock</strong>
          <div style={{ color: "var(--studio-ink-mute)", fontSize: 11, marginTop: 2 }}>
            Annulable pendant 5s
          </div>
        </div>
        <Btn size="sm">Annuler</Btn>
      </div>
    </div>
  );
};

const LoadingGrid = () => (
  <div style={{
    display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(196px, 1fr))", gap: 14,
  }}>
    {Array.from({ length: 8 }).map((_, i) => (
      <div key={i} style={{
        background: "#fff", border: "1px solid var(--studio-line)", borderRadius: 14,
        padding: 12, display: "flex", flexDirection: "column", gap: 10, height: 248,
      }}>
        <div className="skeleton" style={{ height: 100, borderRadius: 8 }} />
        <div className="skeleton" style={{ height: 14, width: "70%" }} />
        <div className="skeleton" style={{ height: 12, width: "40%" }} />
        <div style={{ flex: 1 }} />
        <div className="skeleton" style={{ height: 36, borderRadius: 8 }} />
      </div>
    ))}
  </div>
);

const EmptyState = () => (
  <div style={{
    border: "1.5px dashed var(--studio-line-strong)", borderRadius: 14,
    padding: "60px 20px", display: "flex", flexDirection: "column", alignItems: "center",
    gap: 10, textAlign: "center",
  }}>
    <div style={{
      width: 56, height: 56, borderRadius: 999, background: "var(--cv1-surface-subtle)",
      display: "flex", alignItems: "center", justifyContent: "center",
      color: "var(--studio-ink-mute)",
    }}>
      <Icon name="package" size={26} />
    </div>
    <h3 style={{ margin: 0, fontSize: 16, fontWeight: 700 }}>Aucun produit dans cette catégorie</h3>
    <p style={{ margin: 0, color: "var(--studio-ink-mute)", fontSize: 13, maxWidth: 360 }}>
      Crée ton premier produit en moins de 60 secondes — choisis un template, on s'occupe du reste.
    </p>
    <div style={{ display: "flex", gap: 8, marginTop: 6 }}>
      <Btn variant="brand"><Icon name="plus" size={13} />Nouveau produit</Btn>
      <Btn>Importer depuis CSV</Btn>
    </div>
  </div>
);

const ErrorState = () => (
  <div style={{
    border: "1px solid var(--studio-pill-86-bg)", background: "var(--studio-pill-86-bg)",
    borderRadius: 14, padding: 20, display: "flex", gap: 14,
  }}>
    <div style={{ color: "var(--studio-pill-86-fg)", flexShrink: 0 }}>
      <Icon name="alert" size={22} />
    </div>
    <div style={{ flex: 1 }}>
      <h3 style={{ margin: 0, fontSize: 14, fontWeight: 700, color: "var(--studio-pill-86-fg)" }}>
        Impossible de charger les produits
      </h3>
      <p style={{ margin: "4px 0 10px", fontSize: 12.5, color: "var(--studio-pill-86-fg)" }}>
        La filiale sélectionnée ne répond pas. Vérifie la connexion ou bascule sur une autre filiale.
      </p>
      <div style={{ display: "flex", gap: 6 }}>
        <Btn size="sm" variant="primary"><Icon name="refresh" size={12} />Réessayer</Btn>
        <Btn size="sm">Changer de filiale</Btn>
        <Btn size="sm" variant="ghost">Ouvrir un ticket</Btn>
      </div>
    </div>
  </div>
);

window.Studio1 = Studio1;

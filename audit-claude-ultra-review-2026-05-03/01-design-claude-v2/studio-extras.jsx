/* studio-extras.jsx — Screens 4, 5, 6 + Component Sheet */

/* ===== Screen 4: Publish Diff Modal (ComposerPublishDiffModal.vue) ===== */
const Studio4 = ({ width = 1200, height = 760 }) => (
  <div className="studio" style={{
    width, height, background: "rgba(15,23,42,0.55)",
    display: "flex", alignItems: "center", justifyContent: "center", padding: 24,
  }}>
    <div style={{
      width: "100%", maxWidth: 1080, maxHeight: "100%", background: "#fff",
      borderRadius: 16, display: "flex", flexDirection: "column", overflow: "hidden",
      boxShadow: "0 24px 48px rgba(15,23,42,0.28)",
    }}>
      {/* Modal header */}
      <header style={{
        padding: "16px 20px", borderBottom: "1px solid var(--studio-line)",
        display: "flex", alignItems: "center", gap: 12,
      }}>
        <span style={{
          width: 36, height: 36, borderRadius: 10, background: "#eef2ff",
          color: "var(--cv1-border-emphasis)",
          display: "flex", alignItems: "center", justifyContent: "center",
        }}><Icon name="git" size={18} /></span>
        <div style={{ flex: 1 }}>
          <div className="studio-eyebrow">Publication</div>
          <h2 style={{ margin: "2px 0 0", fontSize: 17, fontWeight: 700 }}>
            Tacos M (1 viande) — wizard v3 → v4
          </h2>
          <div style={{ fontSize: 12, color: "var(--studio-ink-mute)" }}>
            3 modifications · scope : toutes les filiales · auteur : Yann L.
          </div>
        </div>
        <button style={{ background: "transparent", border: "none", cursor: "pointer", color: "var(--studio-ink-mute)" }}>
          <Icon name="x" size={20} />
        </button>
      </header>

      {/* Toolbar */}
      <div style={{
        padding: "10px 20px", borderBottom: "1px solid var(--studio-line)",
        display: "flex", alignItems: "center", gap: 10, background: "var(--cv1-surface-subtle)",
      }}>
        <Pill status="active">Publié v3</Pill>
        <Icon name="arrow-r" size={14} />
        <Pill status="draft">Brouillon v4</Pill>
        <div style={{ flex: 1 }} />
        <span style={{ fontSize: 12, color: "var(--studio-ink-mute)" }}>Filtrer :</span>
        <Btn size="sm">Toutes</Btn>
        <Btn size="sm" style={{ background: "var(--studio-diff-add-bg)", color: "var(--studio-diff-add-fg)", borderColor: "#bbf7d0" }}>+ Ajouts</Btn>
        <Btn size="sm" style={{ background: "var(--studio-diff-rem-bg)", color: "var(--studio-diff-rem-fg)", borderColor: "#fecaca" }}>− Suppr.</Btn>
        <Btn size="sm" style={{ background: "var(--studio-diff-mod-bg)", color: "var(--studio-diff-mod-fg)", borderColor: "#fde68a" }}>~ Modifs</Btn>
      </div>

      {/* Diff body — side-by-side */}
      <div style={{ flex: 1, overflow: "auto", display: "grid", gridTemplateColumns: "1fr 1fr", borderBottom: "1px solid var(--studio-line)" }}>
        {/* Published v3 */}
        <div style={{ borderRight: "1px solid var(--studio-line)" }}>
          <DiffHeader label="Publié v3" sub="il y a 12 jours" tone="active" />
          <div style={{ padding: "8px 0" }}>
            <DiffRow tone="neutral" line={1} text='step_key: "taille" · min 1 max 1' />
            <DiffRow tone="neutral" line={2} text='step_key: "viande" · min 1 max 1' />
            <DiffRow tone="rem" line={3} text='step_key: "sauce" · min 1 max 1' />
            <DiffRow tone="neutral" line={4} text='step_key: "crudites" · min 0 max 6' />
            <DiffRow tone="neutral" line={5} text='— (aucune étape)' faded />
            <DiffRow tone="neutral" line={6} text='— (aucune étape)' faded />
          </div>
        </div>
        {/* Draft v4 */}
        <div>
          <DiffHeader label="Brouillon v4" sub="modifié il y a 4s" tone="draft" />
          <div style={{ padding: "8px 0" }}>
            <DiffRow tone="neutral" line={1} text='step_key: "taille" · min 1 max 1' />
            <DiffRow tone="neutral" line={2} text='step_key: "viande" · min 1 max 1' />
            <DiffRow tone="mod" line={3} text='step_key: "sauce" · min 1 max 2  (max 1 → 2)' />
            <DiffRow tone="neutral" line={4} text='step_key: "crudites" · min 0 max 6' />
            <DiffRow tone="add" line={5} text='step_key: "accompagnement" · addon · side · stockable' />
            <DiffRow tone="add" line={6} text='step_key: "boisson" · addon · drink · stockable' />
          </div>
        </div>
      </div>

      {/* Summary + confirm */}
      <div style={{ padding: "12px 20px", display: "flex", flexDirection: "column", gap: 10 }}>
        <div style={{ display: "flex", gap: 18, alignItems: "center" }}>
          <DiffStat tone="add" count={2} label="ajouts" />
          <DiffStat tone="rem" count={0} label="suppressions" />
          <DiffStat tone="mod" count={1} label="modifications" />
          <div style={{ flex: 1 }} />
          <span style={{ fontSize: 12, color: "var(--studio-ink-mute)" }}>
            <Icon name="info" size={12} /> La nouvelle version remplacera v3 sur tous les runtimes (POS · Kiosk).
          </span>
        </div>
        <div style={{
          padding: "10px 12px", border: "1px solid var(--cv1-status-warning-border)",
          background: "var(--cv1-status-warning-bg)", color: "var(--cv1-status-warning-text)",
          borderRadius: 10, fontSize: 12.5, display: "flex", gap: 8, alignItems: "flex-start",
        }}>
          <Icon name="alert" size={14} />
          <div>
            <strong>2 nouvelles étapes ont stockable_choices = true.</strong> L'auto-86 préventif sera activé sur les options "Frites" (4 unités) et "Coca 33cl" (12 unités) dès la publication.
          </div>
        </div>
      </div>

      {/* Footer */}
      <footer style={{
        padding: "12px 20px", borderTop: "1px solid var(--studio-line)",
        display: "flex", alignItems: "center", gap: 12, background: "#fafbfc",
      }}>
        <label style={{ display: "flex", alignItems: "center", gap: 8, cursor: "pointer", fontSize: 13, fontWeight: 500 }}>
          <span style={{
            width: 18, height: 18, borderRadius: 5, background: "var(--cv1-border-emphasis)",
            display: "flex", alignItems: "center", justifyContent: "center", color: "#fff",
          }}><Icon name="check" size={12} stroke={3} /></span>
          J'ai vérifié le diff et j'assume la publication.
        </label>
        <div style={{ flex: 1 }} />
        <Btn>Annuler</Btn>
        <Btn variant="brand"><Icon name="publish" size={14} />Publier v4 maintenant</Btn>
      </footer>
    </div>
  </div>
);

const DiffHeader = ({ label, sub, tone }) => (
  <div style={{
    padding: "10px 14px", borderBottom: "1px solid var(--studio-line)",
    display: "flex", alignItems: "center", gap: 8, background: "#fff",
    position: "sticky", top: 0,
  }}>
    <Pill status={tone}>{label}</Pill>
    <span style={{ fontSize: 11, color: "var(--studio-ink-mute)" }}>{sub}</span>
  </div>
);

const DiffRow = ({ tone, line, text, faded }) => {
  const tones = {
    add: { bg: "var(--studio-diff-add-bg)", fg: "var(--studio-diff-add-fg)", bar: "var(--studio-diff-add-bar)", marker: "+" },
    rem: { bg: "var(--studio-diff-rem-bg)", fg: "var(--studio-diff-rem-fg)", bar: "var(--studio-diff-rem-bar)", marker: "−" },
    mod: { bg: "var(--studio-diff-mod-bg)", fg: "var(--studio-diff-mod-fg)", bar: "var(--studio-diff-mod-bar)", marker: "~" },
    neutral: { bg: "transparent", fg: "var(--studio-ink-soft)", bar: "transparent", marker: " " },
  }[tone];
  return (
    <div style={{
      display: "flex", alignItems: "center", gap: 10, padding: "6px 14px",
      background: tones.bg, color: tones.fg,
      fontFamily: "var(--studio-font-mono)", fontSize: 12,
      opacity: faded ? 0.4 : 1,
      borderLeft: `3px solid ${tones.bar}`,
    }}>
      <span style={{ width: 16, textAlign: "right", color: "var(--studio-ink-mute)", fontSize: 11 }}>{line}</span>
      <span style={{ width: 12, fontWeight: 700 }}>{tones.marker}</span>
      <span style={{ flex: 1 }}>{text}</span>
    </div>
  );
};

const DiffStat = ({ tone, count, label }) => {
  const tones = {
    add: { bg: "var(--studio-diff-add-bg)", fg: "var(--studio-diff-add-fg)" },
    rem: { bg: "var(--studio-diff-rem-bg)", fg: "var(--studio-diff-rem-fg)" },
    mod: { bg: "var(--studio-diff-mod-bg)", fg: "var(--studio-diff-mod-fg)" },
  }[tone];
  return (
    <div style={{
      display: "flex", alignItems: "center", gap: 6, padding: "4px 10px",
      background: tones.bg, color: tones.fg, borderRadius: 6,
      fontSize: 12, fontWeight: 600,
    }}>
      <strong style={{ fontSize: 14 }}>{count}</strong>{label}
    </div>
  );
};

/* ===== Screen 5: Stock Health side panel (full) ===== */
const Studio5 = ({ width = 420, height = 900 }) => (
  <div className="studio" style={{
    width, height, background: "#fff", borderLeft: "1px solid var(--studio-line)",
    display: "flex", flexDirection: "column", overflow: "hidden",
  }}>
    <div style={{
      padding: 16, borderBottom: "1px solid var(--studio-line)",
      display: "flex", flexDirection: "column", gap: 8,
    }}>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
        <div>
          <div className="studio-eyebrow">Côté droit · contextuel</div>
          <h2 style={{ margin: "2px 0 0", fontSize: 16, fontWeight: 700 }}>Santé du stock</h2>
        </div>
        <button style={{ background: "transparent", border: "none", cursor: "pointer", color: "var(--studio-ink-mute)" }}>
          <Icon name="x" size={16} />
        </button>
      </div>
      <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
        <Pill><Icon name="store" size={11} />Filiale #1</Pill>
        <span style={{ fontSize: 11.5, color: "var(--studio-ink-mute)" }}>Mis à jour il y a 8 min</span>
      </div>
    </div>

    <div style={{ flex: 1, overflowY: "auto", padding: 16, display: "flex", flexDirection: "column", gap: 16 }}>
      {/* KPIs */}
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 8 }}>
        <KPI value="3" label="Auto-86 (24h)" tone="86" />
        <KPI value="7" label="Stock bas" tone="draft" />
        <KPI value="142" label="Scannés" tone="neutral" />
      </div>

      <Section title="Auto-86 (dernières 24h)">
        {STOCK_AUTO86.map(e => (
          <div key={e.id} style={{
            padding: 12, borderRadius: 12, background: "var(--studio-pill-86-bg)",
            border: "1px solid #fecdd3", display: "flex", flexDirection: "column", gap: 8,
          }}>
            <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
              <span style={{
                width: 28, height: 28, borderRadius: 8, background: "#fff",
                display: "flex", alignItems: "center", justifyContent: "center",
                color: "var(--studio-pill-86-fg)",
              }}><Icon name="alert" size={14} /></span>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontWeight: 700, fontSize: 13 }}>{e.product}</div>
                <div style={{ fontSize: 11, color: "var(--studio-pill-86-fg)" }}>{e.branch} · {e.at}</div>
              </div>
            </div>
            <div style={{ fontSize: 12, color: "var(--studio-pill-86-fg)", paddingLeft: 36 }}>
              {e.reason}
            </div>
            <div style={{ display: "flex", gap: 6, paddingLeft: 36 }}>
              <Btn size="sm" variant="primary"><Icon name="check" size={12} />Résoudre</Btn>
              <Btn size="sm">Voir produit</Btn>
            </div>
          </div>
        ))}
      </Section>

      <Section title="Stock bas">
        {STOCK_LOW.map(l => (
          <div key={l.id} style={{
            display: "flex", alignItems: "center", gap: 10, padding: 10,
            border: "1px solid var(--studio-line)", borderRadius: 10,
          }}>
            <ProductThumb kind={l.product.toLowerCase().replace(/[^a-z]/g, "") || "frites"} size={36} />
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ fontSize: 13, fontWeight: 600 }}>{l.product}</div>
              <div style={{ fontSize: 11, color: "var(--studio-ink-mute)" }}>
                {l.qty}/{l.threshold} unités · seuil bas
              </div>
            </div>
            <div style={{ width: 64, height: 6, borderRadius: 999, background: "var(--studio-line)", overflow: "hidden" }}>
              <div style={{
                width: `${(l.qty / l.threshold) * 100}%`, height: "100%",
                background: l.qty / l.threshold < 0.5 ? "#ef4444" : "#f59e0b",
              }} />
            </div>
          </div>
        ))}
      </Section>

      <Section title="Dernier scan" action="Configurer">
        <div style={{
          padding: 12, borderRadius: 12, background: "var(--cv1-surface-subtle)",
          display: "flex", flexDirection: "column", gap: 8,
        }}>
          <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
            <Icon name="clock" size={14} />
            <span style={{ fontSize: 12.5, fontWeight: 600 }}>03 mai 2026 · 14:32</span>
            <div style={{ flex: 1 }} />
            <Pill status="active">OK</Pill>
          </div>
          <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 8 }}>
            <ScanStat value="142" label="Produits scannés" />
            <ScanStat value="3" label="Auto-86 déclenchés" />
          </div>
          <Btn block><Icon name="refresh" size={13} />Lancer un scan maintenant</Btn>
        </div>
      </Section>
    </div>
  </div>
);

const Section = ({ title, action, children }) => (
  <div>
    <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 8 }}>
      <span className="studio-eyebrow">{title}</span>
      {action && <button style={{
        background: "transparent", border: "none", color: "var(--cv1-border-emphasis)",
        fontSize: 11, fontWeight: 600, cursor: "pointer",
      }}>{action}</button>}
    </div>
    <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>{children}</div>
  </div>
);

const KPI = ({ value, label, tone }) => {
  const colors = {
    "86": { bg: "var(--studio-pill-86-bg)", fg: "var(--studio-pill-86-fg)" },
    draft: { bg: "var(--studio-pill-draft-bg)", fg: "var(--studio-pill-draft-fg)" },
    neutral: { bg: "var(--cv1-surface-subtle)", fg: "var(--studio-ink-soft)" },
  }[tone];
  return (
    <div style={{ padding: 10, borderRadius: 10, background: colors.bg, color: colors.fg }}>
      <div style={{ fontSize: 22, fontWeight: 700, lineHeight: 1 }}>{value}</div>
      <div style={{ fontSize: 11, marginTop: 4, fontWeight: 500 }}>{label}</div>
    </div>
  );
};
const ScanStat = ({ value, label }) => (
  <div>
    <div style={{ fontSize: 16, fontWeight: 700 }}>{value}</div>
    <div style={{ fontSize: 11, color: "var(--studio-ink-mute)" }}>{label}</div>
  </div>
);

/* ===== Screen 6: States set (empty/loading/error per surface) ===== */
const Studio6 = ({ width = 1180, height = 600 }) => (
  <div className="studio" style={{
    width, height, background: "var(--studio-canvas)", padding: 20,
    display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 16,
  }}>
    <StateCard kind="empty"   title="Empty" />
    <StateCard kind="loading" title="Loading" />
    <StateCard kind="error"   title="Error" />
  </div>
);

const StateCard = ({ kind, title }) => (
  <div className="studio-card" style={{ padding: 16, display: "flex", flexDirection: "column", gap: 12 }}>
    <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
      <span className="studio-eyebrow">État · {title}</span>
      <Pill>{kind === "empty" ? "Aucune donnée" : kind === "loading" ? "Chargement" : "Erreur"}</Pill>
    </div>
    {kind === "empty" && (
      <div style={{
        flex: 1, border: "1.5px dashed var(--studio-line-strong)", borderRadius: 12,
        padding: "30px 16px", display: "flex", flexDirection: "column",
        alignItems: "center", gap: 10, textAlign: "center",
      }}>
        <div style={{
          width: 48, height: 48, borderRadius: 999, background: "var(--cv1-surface-subtle)",
          display: "flex", alignItems: "center", justifyContent: "center",
          color: "var(--studio-ink-mute)",
        }}><Icon name="package" size={22} /></div>
        <h4 style={{ margin: 0, fontSize: 14, fontWeight: 700 }}>Aucun produit</h4>
        <p style={{ margin: 0, fontSize: 12, color: "var(--studio-ink-mute)", maxWidth: 280 }}>
          Crée ton premier produit en 60s — choisis un template, ajoute une photo et un prix.
        </p>
        <Btn variant="brand" size="sm"><Icon name="plus" size={12} />Nouveau produit</Btn>
      </div>
    )}
    {kind === "loading" && (
      <div style={{ flex: 1, display: "flex", flexDirection: "column", gap: 8 }}>
        {Array.from({ length: 4 }).map((_, i) => (
          <div key={i} style={{
            display: "flex", gap: 10, padding: 10, border: "1px solid var(--studio-line)", borderRadius: 10,
          }}>
            <div className="skeleton" style={{ width: 40, height: 40, borderRadius: 8 }} />
            <div style={{ flex: 1, display: "flex", flexDirection: "column", gap: 6, justifyContent: "center" }}>
              <div className="skeleton" style={{ height: 12, width: "60%" }} />
              <div className="skeleton" style={{ height: 10, width: "40%" }} />
            </div>
            <div className="skeleton" style={{ width: 40, height: 22, borderRadius: 999, alignSelf: "center" }} />
          </div>
        ))}
        <div style={{ display: "flex", alignItems: "center", justifyContent: "center", gap: 6, fontSize: 11, color: "var(--studio-ink-mute)", marginTop: "auto" }}>
          <Icon name="refresh" size={12} />
          Chargement des produits…
        </div>
      </div>
    )}
    {kind === "error" && (
      <div style={{
        flex: 1, padding: 14, borderRadius: 12,
        background: "var(--studio-pill-86-bg)", border: "1px solid #fecdd3",
        display: "flex", flexDirection: "column", gap: 10,
      }}>
        <div style={{ display: "flex", gap: 10, alignItems: "flex-start" }}>
          <span style={{
            width: 36, height: 36, borderRadius: 10, background: "#fff",
            display: "flex", alignItems: "center", justifyContent: "center",
            color: "var(--studio-pill-86-fg)", flexShrink: 0,
          }}><Icon name="alert" size={18} /></span>
          <div>
            <h4 style={{ margin: 0, fontSize: 14, fontWeight: 700, color: "var(--studio-pill-86-fg)" }}>
              La filiale ne répond pas
            </h4>
            <p style={{ margin: "4px 0 0", fontSize: 12, color: "var(--studio-pill-86-fg)" }}>
              <code style={{ background: "#fff", padding: "1px 5px", borderRadius: 4, fontFamily: "var(--studio-font-mono)" }}>
                ECONNRESET · /api/admin/items?branch_id=1
              </code><br />
              Réessaie ou bascule sur une autre filiale pour continuer.
            </p>
          </div>
        </div>
        <div style={{ display: "flex", gap: 6, marginTop: "auto" }}>
          <Btn size="sm" variant="primary"><Icon name="refresh" size={12} />Réessayer</Btn>
          <Btn size="sm">Changer de filiale</Btn>
          <Btn size="sm" variant="ghost">Ouvrir un ticket</Btn>
        </div>
      </div>
    )}
  </div>
);

/* ===== Component sheet ===== */
const ComponentSheet = ({ width = 1240, height = 1340 }) => (
  <div className="studio" style={{
    width, height, background: "var(--studio-canvas)", padding: 24,
    display: "flex", flexDirection: "column", gap: 18, overflow: "hidden",
  }}>
    <header>
      <span className="studio-eyebrow">Composants · feuille de référence</span>
      <h1 style={{ margin: "4px 0 0", fontSize: 22, fontWeight: 700 }}>
        Atomes du Catalog Studio
      </h1>
      <p style={{ margin: "4px 0 0", color: "var(--studio-ink-mute)", fontSize: 13 }}>
        Chaque composant dans tous ses états · accessibilité ≥ AA · focus ring 3px (var(--cv1-focus-ring-color)).
      </p>
    </header>

    <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 14, overflow: "auto" }}>
      <CompCard title="ProductCard" file="ProductCard.vue · CatalogStudioComponent.vue">
        <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 10 }}>
          <ProductCardMini p={STUDIO_PRODUCTS[0]} label="default" />
          <ProductCardMini p={STUDIO_PRODUCTS[3]} label="draft" />
          <ProductCardMini p={STUDIO_PRODUCTS[4]} label="86" />
        </div>
      </CompCard>

      <CompCard title="StockToggle (AvailabilityToggleComponent.vue)" file="resources/js/components/admin/items/AvailabilityToggleComponent.vue">
        <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
          <ToggleStateRow on={true}  label="Disponible" sub="Filiale #1 · maj il y a 2 min" />
          <ToggleStateRow on={false} reason="out_of_stock"      label="Indisponible" sub="Stock épuisé · auto-86" />
          <ToggleStateRow on={false} reason="paused_by_manager" label="En pause"     sub="Pausé par Yann · 15:24" />
          <ToggleStateRow on={false} reason="scheduled_off"     label="Planifié OFF" sub="Reprend à 17:00" />
        </div>
      </CompCard>

      <CompCard title="WizardStepRow (ComposerStepListSidebar)" file="resources/js/components/admin/items/composer/ComposerStepListSidebar.vue">
        <div style={{ display: "flex", flexDirection: "column", gap: 4, padding: 8, background: "var(--cv1-surface-subtle)", borderRadius: 10 }}>
          <StepRowMini step={TACOS_STEPS[0]} state="default" />
          <StepRowMini step={TACOS_STEPS[1]} state="active" />
          <StepRowMini step={TACOS_STEPS[2]} state="hover" />
          <StepRowMini step={TACOS_STEPS[5]} state="error" />
        </div>
      </CompCard>

      <CompCard title="Pill / Chip / Source-type" file="cv1-tokens.css · studio additions">
        <div style={{ display: "flex", flexWrap: "wrap", gap: 6, alignItems: "center" }}>
          <Pill>Neutre</Pill>
          <Pill status="active">Actif</Pill>
          <Pill status="draft">Brouillon</Pill>
          <Pill status="86">86</Pill>
          <span style={{ width: 1, height: 18, background: "var(--studio-line)" }} />
          <Chip src="item_attribute">Attribut</Chip>
          <Chip src="extra_group">Extra</Chip>
          <Chip src="addon">Addon</Chip>
          <Chip src="fixed">Fixe</Chip>
          <span style={{ width: 1, height: 18, background: "var(--studio-line)" }} />
          <Chip channel="pos">POS</Chip>
          <Chip channel="kiosk">Kiosk</Chip>
        </div>
      </CompCard>

      <CompCard title="Btn" file="Studio shared">
        <div style={{ display: "flex", flexWrap: "wrap", gap: 8, alignItems: "center" }}>
          <Btn variant="primary"><Icon name="publish" size={13} />Publier</Btn>
          <Btn variant="brand"><Icon name="plus" size={13} />Nouveau</Btn>
          <Btn>Secondaire</Btn>
          <Btn variant="ghost">Ghost</Btn>
          <Btn disabled>Désactivé</Btn>
          <Btn size="sm">Petit</Btn>
          <Btn size="lg">Grand</Btn>
          <Btn style={{ outline: "3px solid var(--cv1-focus-ring-color)", outlineOffset: 2 }}>
            Focus
          </Btn>
        </div>
      </CompCard>

      <CompCard title="PublishBadge & DiffStat" file="ComposerPublishDiffModal.vue">
        <div style={{ display: "flex", flexWrap: "wrap", gap: 8, alignItems: "center" }}>
          <Pill status="active">Publié v3</Pill>
          <Pill status="draft">Brouillon v4</Pill>
          <Pill status="86">Conflit · v5 distante</Pill>
          <span style={{ width: 1, height: 18, background: "var(--studio-line)" }} />
          <DiffStat tone="add" count={2} label="ajouts" />
          <DiffStat tone="rem" count={1} label="suppr." />
          <DiffStat tone="mod" count={3} label="modifs" />
        </div>
      </CompCard>

      <CompCard title="VisibleOnSelector" file="StepEditorComponent.vue">
        <div style={{ maxWidth: 360 }}>
          <VisibleOnSelector visible={["pos", "kiosk"]} />
        </div>
      </CompCard>

      <CompCard title="HealthCard" file="StockRuptureDashboardComponent.vue">
        <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 8 }}>
          <KPI value="3"   label="Auto-86 (24h)" tone="86" />
          <KPI value="7"   label="Stock bas"     tone="draft" />
          <KPI value="142" label="Scannés"       tone="neutral" />
        </div>
      </CompCard>
    </div>
  </div>
);

const CompCard = ({ title, file, children }) => (
  <div className="studio-card" style={{ padding: 14, display: "flex", flexDirection: "column", gap: 10 }}>
    <div>
      <div style={{ fontSize: 13, fontWeight: 700 }}>{title}</div>
      <div style={{ fontSize: 11, color: "var(--studio-ink-mute)", fontFamily: "var(--studio-font-mono)" }}>{file}</div>
    </div>
    {children}
  </div>
);

const ProductCardMini = ({ p, label }) => (
  <div>
    <div style={{ fontSize: 10, color: "var(--studio-ink-mute)", marginBottom: 4, fontWeight: 600, letterSpacing: 0.06, textTransform: "uppercase" }}>{label}</div>
    <div style={{ background: "#fff", border: "1px solid var(--studio-line)", borderRadius: 12, overflow: "hidden" }}>
      <div style={{ height: 70, background: "linear-gradient(180deg,#fef3c7,#fde68a)", display: "flex", alignItems: "center", justifyContent: "center", position: "relative" }}>
        <ProductThumb kind={p.img} size={48} />
        <div style={{ position: "absolute", top: 6, left: 6 }}>
          <Pill status={p.status === "active" ? "active" : p.status === "draft" ? "draft" : "86"}>
            {p.status === "active" ? "Actif" : p.status === "draft" ? "Brouillon" : "86"}
          </Pill>
        </div>
      </div>
      <div style={{ padding: 8, display: "flex", flexDirection: "column", gap: 4 }}>
        <div style={{ fontSize: 12, fontWeight: 600, lineHeight: 1.2 }}>{p.name}</div>
        <div style={{ fontSize: 11, fontWeight: 700 }}>{p.price}</div>
      </div>
    </div>
  </div>
);

const ToggleStateRow = ({ on, reason, label, sub }) => (
  <div style={{
    display: "flex", alignItems: "center", gap: 10, padding: 10,
    border: "1px solid var(--studio-line)", borderRadius: 10,
    background: !on ? "var(--studio-pill-86-bg)" : "#fff",
  }}>
    <Switch on={on} reason={reason} />
    <div style={{ flex: 1 }}>
      <div style={{ fontSize: 12.5, fontWeight: 600, color: !on ? "var(--studio-pill-86-fg)" : "var(--studio-ink)" }}>
        {label}
      </div>
      <div style={{ fontSize: 11, color: "var(--studio-ink-mute)" }}>{sub}</div>
    </div>
    {!on && <Chip src="addon" style={{ background: "#fff" }}>{reason}</Chip>}
  </div>
);

const StepRowMini = ({ step, state }) => {
  const active = state === "active";
  const hover = state === "hover";
  const error = state === "error";
  const srcLabel = step.source_type === "item_attribute" ? "Attribut" : step.source_type === "extra_group" ? "Extra" : "Addon";
  return (
    <div style={{
      display: "flex", alignItems: "center", gap: 6, padding: "8px 10px", borderRadius: 8,
      background: active ? "#eef2ff" : hover ? "#f8fafc" : error ? "var(--studio-pill-86-bg)" : "#fff",
      border: `1px solid ${active ? "#c7d2fe" : error ? "#fecdd3" : "var(--studio-line)"}`,
    }}>
      <span style={{ color: "var(--studio-ink-mute)", opacity: 0.6 }}><Icon name="drag" size={12} /></span>
      <span style={{
        width: 18, height: 18, borderRadius: 999,
        background: active ? "var(--cv1-border-emphasis)" : "var(--cv1-surface-subtle)",
        color: active ? "#fff" : "var(--studio-ink-soft)",
        display: "flex", alignItems: "center", justifyContent: "center", fontSize: 10, fontWeight: 700,
      }}>{step.position}</span>
      <span style={{ flex: 1, fontSize: 12, fontWeight: 600 }}>{step.label}</span>
      <Chip src={step.source_type}>{srcLabel}</Chip>
      <span style={{ fontSize: 10, color: "var(--studio-ink-mute)" }}>· {state}</span>
    </div>
  );
};

window.Studio4 = Studio4;
window.Studio5 = Studio5;
window.Studio6 = Studio6;
window.ComponentSheet = ComponentSheet;

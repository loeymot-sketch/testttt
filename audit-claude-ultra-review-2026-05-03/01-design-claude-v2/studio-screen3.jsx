/* studio-screen3.jsx — Screen 3: Wizard Editor (ProductComposerEditorComponent.vue)
   3 columns: steps list · step editor · live preview (POS top, Kiosk bottom).
   The previews mirror the actual runtime captures, not generic phone frames.
*/

const Studio3 = ({ width = 1440, height = 900, focusedStepId = 2 }) => {
  const focused = TACOS_STEPS.find(s => s.id === focusedStepId) || TACOS_STEPS[1];
  return (
    <div className="studio" style={{
      width, height, background: "var(--studio-canvas)",
      display: "flex", flexDirection: "column",
    }}>
      <ComposerHeader />
      <div style={{ flex: 1, display: "grid", gridTemplateColumns: "300px minmax(0,1fr) 420px", gap: 12, padding: 12, minHeight: 0 }}>
        <StepsList focusedStepId={focused.id} />
        <StepEditor step={focused} />
        <LivePreview focused={focused} />
      </div>
    </div>
  );
};

const ComposerHeader = () => (
  <header style={{
    background: "#fff", borderBottom: "1px solid var(--studio-line)",
    padding: "10px 16px", display: "flex", alignItems: "center", gap: 14,
  }}>
    {/* Breadcrumb */}
    <button className="studio-btn studio-btn--ghost" style={{ padding: "0 8px" }}>
      <Icon name="chevron-l" size={14} />
      Retour
    </button>
    <div style={{ width: 1, height: 24, background: "var(--studio-line)" }} />
    <ProductThumb kind="tacos-m" size={36} />
    <div style={{ minWidth: 0 }}>
      <div style={{ display: "flex", alignItems: "center", gap: 6, fontSize: 11, color: "var(--studio-ink-mute)", fontWeight: 500 }}>
        <span>Catalog Studio</span>
        <Icon name="chevron-r" size={11} />
        <span>Nos Tacos</span>
        <Icon name="chevron-r" size={11} />
        <span style={{ color: "var(--studio-ink)" }}>Tacos M (1 viande)</span>
      </div>
      <h1 style={{ margin: 0, fontSize: 16, fontWeight: 700, letterSpacing: -0.2 }}>
        Éditeur de wizard
      </h1>
    </div>
    <div style={{ flex: 1 }} />
    <span className="studio-pill" data-status="draft"><span className="dot" />Brouillon v4 · 3 modifs</span>
    <span className="studio-pill"><Icon name="clock" size={11} />Auto-sauvegardé il y a 4s</span>
    <div style={{ width: 1, height: 24, background: "var(--studio-line)" }} />
    {/* Branch scope */}
    <div style={{
      display: "flex", alignItems: "center", gap: 6, padding: "5px 10px",
      borderRadius: 8, border: "1px solid var(--studio-line-strong)", fontSize: 12, fontWeight: 600,
    }}>
      <Icon name="store" size={12} />
      Toutes les filiales
      <Icon name="chevron-d" size={12} />
    </div>
    <Btn>Aperçu plein écran</Btn>
    <Btn variant="primary"><Icon name="publish" size={13} />Publier v4…</Btn>
  </header>
);

const StepsList = ({ focusedStepId }) => (
  <aside className="studio-card" style={{ display: "flex", flexDirection: "column", overflow: "hidden" }}>
    {/* Header */}
    <div style={{ padding: 12, borderBottom: "1px solid var(--studio-line)" }}>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 8 }}>
        <span className="studio-eyebrow">Étapes du wizard</span>
        <span style={{ fontSize: 11, color: "var(--studio-ink-mute)", fontWeight: 600 }}>{TACOS_STEPS.length} pages</span>
      </div>
      <Btn block size="sm"><Icon name="copy" size={12} />Choisir un template…</Btn>
    </div>
    {/* List */}
    <div style={{ flex: 1, overflowY: "auto", padding: "8px 8px 4px", display: "flex", flexDirection: "column", gap: 4 }}>
      {TACOS_STEPS.map(s => (
        <StepRow key={s.id} step={s} active={s.id === focusedStepId} />
      ))}
    </div>
    {/* Footer */}
    <div style={{ padding: 8, borderTop: "1px solid var(--studio-line)" }}>
      <Btn block size="sm" variant="ghost" style={{ color: "var(--cv1-border-emphasis)", border: "1px dashed var(--studio-line-strong)" }}>
        <Icon name="plus" size={12} />Ajouter une étape
      </Btn>
    </div>
  </aside>
);

const StepRow = ({ step, active }) => {
  const srcLabel = step.source_type === "item_attribute" ? "Attribut" :
                   step.source_type === "extra_group" ? "Extra" : "Addon";
  return (
    <div style={{
      display: "flex", alignItems: "center", gap: 6, padding: "8px 8px",
      borderRadius: 8,
      background: active ? "#eef2ff" : "transparent",
      border: `1px solid ${active ? "#c7d2fe" : "transparent"}`,
      cursor: "pointer", position: "relative",
    }}>
      <span style={{ color: "var(--studio-ink-mute)", opacity: 0.6, cursor: "grab" }}>
        <Icon name="drag" size={12} />
      </span>
      <span style={{
        width: 20, height: 20, borderRadius: 999,
        background: active ? "var(--cv1-border-emphasis)" : "var(--cv1-surface-subtle)",
        color: active ? "#fff" : "var(--studio-ink-soft)",
        display: "flex", alignItems: "center", justifyContent: "center",
        fontSize: 11, fontWeight: 700,
      }}>{step.position}</span>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{
          fontSize: 12.5, fontWeight: 600,
          color: active ? "#3730a3" : "var(--studio-ink)",
          whiteSpace: "nowrap", textOverflow: "ellipsis", overflow: "hidden",
        }}>{step.label}</div>
        <div style={{ display: "flex", gap: 3, marginTop: 3, alignItems: "center", flexWrap: "wrap" }}>
          <Chip src={step.source_type}>{srcLabel}</Chip>
          {step.visible_on.includes("pos") && <Chip channel="pos">POS</Chip>}
          {step.visible_on.includes("kiosk") && <Chip channel="kiosk">Kiosk</Chip>}
          {step.addon_role && <Chip>{step.addon_role}</Chip>}
          {step.stockable_choices && (
            <span style={{ color: "#f59e0b", display: "inline-flex", alignItems: "center" }} title="Stock-tracked">
              <Icon name="alert" size={11} />
            </span>
          )}
        </div>
      </div>
      <span style={{
        fontSize: 10, color: "var(--studio-ink-mute)", fontWeight: 600,
        padding: "1px 5px", borderRadius: 4, background: "var(--cv1-surface-subtle)",
      }}>{step.min_select}–{step.max_select}</span>
    </div>
  );
};

const StepEditor = ({ step }) => (
  <section className="studio-card" style={{ display: "flex", flexDirection: "column", overflow: "hidden" }}>
    {/* Editor header */}
    <div style={{
      padding: "12px 16px", borderBottom: "1px solid var(--studio-line)",
      display: "flex", alignItems: "center", gap: 10,
    }}>
      <span style={{
        width: 26, height: 26, borderRadius: 999, background: "var(--cv1-border-emphasis)",
        color: "#fff", display: "flex", alignItems: "center", justifyContent: "center",
        fontSize: 13, fontWeight: 700,
      }}>{step.position}</span>
      <div style={{ flex: 1 }}>
        <div className="studio-eyebrow">Étape · {step.step_key}</div>
        <h2 style={{ margin: "2px 0 0", fontSize: 16, fontWeight: 700 }}>{step.label}</h2>
      </div>
      <Btn size="sm"><Icon name="copy" size={12} />Dupliquer</Btn>
      <Btn size="sm" variant="ghost" style={{ color: "var(--studio-pill-86-fg)" }}>
        <Icon name="trash" size={12} />Supprimer
      </Btn>
    </div>

    <div style={{ flex: 1, overflowY: "auto", padding: "16px 18px", display: "flex", flexDirection: "column", gap: 16 }}>
      {/* Identity */}
      <FieldRow>
        <Field label="Libellé affiché" hint="Vu par les clients sur le kiosk et les caissiers sur le POS.">
          <input className="studio-input" defaultValue={step.label} />
        </Field>
        <Field label="Step key" hint="Identifiant technique. Slug, immuable une fois publié.">
          <input className="studio-input" defaultValue={step.step_key} style={{ fontFamily: "var(--studio-font-mono)", fontSize: 12 }} />
        </Field>
      </FieldRow>

      {/* Source type — segmented + picker */}
      <Field label="Source des choix">
        <SegSourceType current={step.source_type} />
        <div style={{ marginTop: 10 }}>
          <SourcePicker step={step} />
        </div>
      </Field>

      {/* Min / max sliders */}
      <FieldRow>
        <Field label="Sélection minimale">
          <RangePair value={step.min_select} max={6} />
        </Field>
        <Field label="Sélection maximale">
          <RangePair value={step.max_select} max={6} accent />
        </Field>
      </FieldRow>

      {/* Visibility + flags */}
      <FieldRow>
        <Field label="Visible sur" hint="Une étape masquée sur un canal n'apparaît pas dans son wizard.">
          <VisibleOnSelector visible={step.visible_on} />
        </Field>
        <Field label="Comportement">
          <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
            <ToggleRow on={step.allow_repeat} label="Autoriser doublons" hint="Le client peut choisir 2× la même viande." />
            <ToggleRow on={step.stockable_choices} label="Suivi de stock" hint="Auto-86 si une option est en rupture." warning />
            <ToggleRow on={step.is_active} label="Étape active" hint="Désactiver = invisible runtime." />
          </div>
        </Field>
      </FieldRow>

      {/* Addon role (only if source_type=addon) */}
      {step.source_type === "addon" && (
        <Field label="Rôle de l'addon" hint="Permet aux runtimes de regrouper les addons par catégorie sémantique.">
          <AddonRoleRadio current={step.addon_role} />
        </Field>
      )}
    </div>
  </section>
);

const FieldRow = ({ children }) => (
  <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 16 }}>{children}</div>
);

const Field = ({ label, hint, children }) => (
  <label style={{ display: "flex", flexDirection: "column", gap: 6 }}>
    <span style={{ fontSize: 12, fontWeight: 600, color: "var(--studio-ink)" }}>{label}</span>
    {hint && <span style={{ fontSize: 11, color: "var(--studio-ink-mute)", marginTop: -3 }}>{hint}</span>}
    {children}
  </label>
);

const SegSourceType = ({ current }) => {
  const opts = [
    { id: "item_attribute", label: "Attribut produit", icon: "tag" },
    { id: "extra_group",    label: "Groupe d'extras",  icon: "leaf" },
    { id: "addon",          label: "Addon",            icon: "package" },
  ];
  return (
    <div style={{
      display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 4,
      padding: 4, background: "var(--cv1-surface-subtle)",
      border: "1px solid var(--studio-line)", borderRadius: 10,
    }}>
      {opts.map(o => {
        const active = o.id === current;
        return (
          <button key={o.id} style={{
            display: "flex", alignItems: "center", justifyContent: "center", gap: 6,
            padding: "8px 10px", borderRadius: 7, border: "none",
            background: active ? "#fff" : "transparent",
            color: active ? "var(--studio-ink)" : "var(--studio-ink-mute)",
            fontSize: 12, fontWeight: 600, cursor: "pointer",
            boxShadow: active ? "0 1px 2px rgba(15,23,42,0.08)" : "none",
          }}>
            <Icon name={o.icon} size={13} />{o.label}
          </button>
        );
      })}
    </div>
  );
};

const SourcePicker = ({ step }) => {
  const isAttr = step.source_type === "item_attribute";
  const isExtra = step.source_type === "extra_group";
  const isAddon = step.source_type === "addon";
  const label = isAttr ? `Attribut "${step.source_ref}"` :
                isExtra ? `Groupe "${step.source_ref}"` :
                          `Addon "${step.source_ref}"`;
  return (
    <div style={{
      display: "flex", alignItems: "center", gap: 10, padding: "10px 12px",
      border: "1px solid var(--studio-line-strong)", borderRadius: 10, background: "#fff",
    }}>
      <span style={{
        width: 32, height: 32, borderRadius: 8,
        background: "var(--studio-src-attr-bg)", color: "var(--studio-src-attr-fg)",
        display: "flex", alignItems: "center", justifyContent: "center",
      }}><Icon name={isAttr ? "tag" : isExtra ? "leaf" : "package"} size={15} /></span>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontSize: 13, fontWeight: 600 }}>{label}</div>
        <div style={{ fontSize: 11, color: "var(--studio-ink-mute)" }}>
          {step.options.length} options · {step.options.slice(0, 4).join(" · ")}{step.options.length > 4 ? " · …" : ""}
        </div>
      </div>
      <Btn size="sm">Modifier</Btn>
      <Btn size="sm" variant="ghost" style={{ color: "var(--cv1-border-emphasis)" }}>
        <Icon name="plus" size={12} />Nouvelle option
      </Btn>
    </div>
  );
};

const RangePair = ({ value, max, accent }) => (
  <div style={{ display: "flex", alignItems: "center", gap: 12 }}>
    <div style={{ flex: 1, position: "relative", height: 24, display: "flex", alignItems: "center" }}>
      <div style={{ width: "100%", height: 4, borderRadius: 999, background: "var(--studio-line)" }} />
      <div style={{
        position: "absolute", left: 0, top: "50%", transform: "translateY(-50%)",
        width: `${(value / max) * 100}%`, height: 4, borderRadius: 999,
        background: accent ? "var(--fk-red)" : "var(--cv1-border-emphasis)",
      }} />
      <div style={{
        position: "absolute", left: `calc(${(value / max) * 100}% - 8px)`, top: "50%", transform: "translateY(-50%)",
        width: 16, height: 16, borderRadius: 999, background: "#fff",
        border: `2px solid ${accent ? "var(--fk-red)" : "var(--cv1-border-emphasis)"}`,
        boxShadow: "0 1px 3px rgba(0,0,0,0.15)",
      }} />
    </div>
    <input type="number" value={value} readOnly className="studio-input" style={{
      width: 56, textAlign: "center", fontWeight: 600,
    }} />
  </div>
);

const VisibleOnSelector = ({ visible }) => (
  <div style={{ display: "flex", gap: 6 }}>
    {[
      { id: "pos",   label: "POS",   icon: "monitor", desc: "1 page dense, vitesse caisse" },
      { id: "kiosk", label: "Kiosk", icon: "tablet",  desc: "Multi-pages, parcours client" },
    ].map(c => {
      const on = visible.includes(c.id);
      return (
        <button key={c.id} style={{
          flex: 1, display: "flex", alignItems: "center", gap: 8,
          padding: "8px 10px", borderRadius: 10, cursor: "pointer",
          border: `1px solid ${on ? (c.id === "kiosk" ? "var(--fk-red)" : "var(--cv1-surface-strong)") : "var(--studio-line-strong)"}`,
          background: on ? (c.id === "kiosk" ? "var(--fk-red-soft)" : "var(--cv1-surface-subtle)") : "#fff",
          textAlign: "left",
        }}>
          <Icon name={c.icon} size={14} />
          <div style={{ flex: 1 }}>
            <div style={{ fontSize: 12, fontWeight: 700 }}>{c.label}</div>
            <div style={{ fontSize: 10, color: "var(--studio-ink-mute)" }}>{c.desc}</div>
          </div>
          <span style={{
            width: 14, height: 14, borderRadius: 999,
            border: `1.5px solid ${on ? (c.id === "kiosk" ? "var(--fk-red)" : "var(--cv1-surface-strong)") : "var(--studio-line-strong)"}`,
            background: on ? (c.id === "kiosk" ? "var(--fk-red)" : "var(--cv1-surface-strong)") : "transparent",
            display: "flex", alignItems: "center", justifyContent: "center",
            color: "#fff",
          }}>{on && <Icon name="check" size={9} stroke={3} />}</span>
        </button>
      );
    })}
  </div>
);

const ToggleRow = ({ on, label, hint, warning }) => (
  <div style={{
    display: "flex", alignItems: "center", gap: 10, padding: "8px 10px",
    border: "1px solid var(--studio-line)", borderRadius: 8, background: "#fff",
  }}>
    <Switch on={on} />
    <div style={{ flex: 1, minWidth: 0 }}>
      <div style={{ fontSize: 12, fontWeight: 600, display: "flex", alignItems: "center", gap: 5 }}>
        {label}
        {warning && on && <Icon name="alert" size={11} />}
      </div>
      <div style={{ fontSize: 10.5, color: "var(--studio-ink-mute)" }}>{hint}</div>
    </div>
  </div>
);

const AddonRoleRadio = ({ current }) => {
  const roles = [
    { id: "drink",          label: "Boisson",  icon: "cup" },
    { id: "side",           label: "Acc.",     icon: "fries" },
    { id: "dessert",        label: "Dessert",  icon: "cookie" },
    { id: "menu_component", label: "Plat",     icon: "burger" },
    { id: "upsell",         label: "Upsell",   icon: "trend" },
  ];
  return (
    <div style={{ display: "grid", gridTemplateColumns: "repeat(5, 1fr)", gap: 6 }}>
      {roles.map(r => {
        const active = r.id === current;
        return (
          <button key={r.id} style={{
            display: "flex", flexDirection: "column", alignItems: "center", gap: 4,
            padding: "8px 4px", borderRadius: 10, cursor: "pointer",
            border: `1px solid ${active ? "var(--cv1-border-emphasis)" : "var(--studio-line-strong)"}`,
            background: active ? "#eef2ff" : "#fff",
            color: active ? "#3730a3" : "var(--studio-ink-soft)",
          }}>
            <Icon name={r.icon} size={16} />
            <span style={{ fontSize: 10.5, fontWeight: 600 }}>{r.label}</span>
          </button>
        );
      })}
    </div>
  );
};

/* =========================================================================
   LIVE PREVIEW (right column) — POS top, Kiosk bottom.
   Both renders are stylistic but faithful to the actual runtime captures:
   POS = dense single-page table-like wizard, Kiosk = multi-page with stepper
   dots, beige bg, big rounded option cards.
   ========================================================================= */

const LivePreview = ({ focused }) => (
  <aside style={{
    display: "flex", flexDirection: "column", gap: 10,
    overflow: "hidden", minHeight: 0,
  }}>
    {/* Preview header */}
    <div className="studio-card" style={{ padding: "10px 12px", display: "flex", alignItems: "center", gap: 8 }}>
      <span className="studio-eyebrow">Aperçu live</span>
      <div style={{ flex: 1 }} />
      <span style={{ fontSize: 11, color: "var(--studio-ink-mute)" }}>Synchro 500ms</span>
      <span style={{
        width: 6, height: 6, borderRadius: 999, background: "#10b981",
        boxShadow: "0 0 0 3px rgba(16,185,129,0.2)",
      }} />
    </div>

    {/* POS preview */}
    <div className="studio-card" style={{ padding: 10, display: "flex", flexDirection: "column", gap: 8, flex: "0 0 auto" }}>
      <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
        <Chip channel="pos">POS · 1 page</Chip>
        <span style={{ fontSize: 11, color: "var(--studio-ink-mute)" }}>Caissier · vitesse</span>
        <div style={{ flex: 1 }} />
        <button style={{ background: "transparent", border: "none", cursor: "pointer", color: "var(--studio-ink-mute)" }}>
          <Icon name="refresh" size={13} />
        </button>
      </div>
      <PosPreview focusedKey={focused.step_key} />
    </div>

    {/* Kiosk preview */}
    <div className="studio-card" style={{ padding: 10, display: "flex", flexDirection: "column", gap: 8, flex: 1, minHeight: 0 }}>
      <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
        <Chip channel="kiosk">Kiosk · multi-pages</Chip>
        <span style={{ fontSize: 11, color: "var(--studio-ink-mute)" }}>Borne client</span>
        <div style={{ flex: 1 }} />
        <span style={{ fontSize: 10.5, color: "var(--studio-ink-mute)", fontWeight: 600 }}>
          Page {focused.position} / {TACOS_STEPS.length}
        </span>
      </div>
      <KioskPreview focused={focused} />
    </div>
  </aside>
);

/* ===== POS preview — mirrors POS_V5_AFTER capture (red header, dense grid) ===== */
const PosPreview = ({ focusedKey }) => (
  <div style={{
    background: "#fff", border: "1px solid var(--studio-line)", borderRadius: 10,
    overflow: "hidden", fontFamily: "var(--studio-font)",
  }}>
    {/* Red brand bar (POS header) */}
    <div style={{
      background: "linear-gradient(180deg, #2a0809, #5a0d12)", color: "#fff",
      padding: "10px 12px", display: "flex", alignItems: "center", gap: 8,
    }}>
      <ProductThumb kind="tacos-m" size={32} round />
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontSize: 9, fontWeight: 700, letterSpacing: "0.12em", opacity: 0.85, textTransform: "uppercase" }}>
          Composition rapide
        </div>
        <div style={{ fontSize: 13, fontWeight: 700, lineHeight: 1.1 }}>Tacos M (1 viande)</div>
      </div>
      <span style={{
        background: "var(--fk-red)", color: "#fff", padding: "3px 8px",
        borderRadius: 999, fontSize: 11, fontWeight: 700,
      }}>6,50 €</span>
    </div>

    {/* Single-page wizard rows — all 6 steps stacked, dense */}
    <div style={{ padding: 8, display: "flex", flexDirection: "column", gap: 6, background: "#fff" }}>
      {TACOS_STEPS.map(s => (
        <PosStepRow key={s.id} step={s} highlight={s.step_key === focusedKey} />
      ))}
    </div>

    {/* Footer / total */}
    <div style={{
      borderTop: "1px solid var(--studio-line)", padding: "8px 12px",
      display: "flex", alignItems: "center", gap: 8, background: "#fafbfc",
    }}>
      <span style={{ fontSize: 11, color: "var(--studio-ink-mute)" }}>0 / 6 étapes complètes</span>
      <div style={{ flex: 1, height: 4, background: "var(--studio-line)", borderRadius: 999, overflow: "hidden" }}>
        <div style={{ width: "16%", height: "100%", background: "var(--fk-red)" }} />
      </div>
      <span style={{ fontSize: 13, fontWeight: 700 }}>6,50 €</span>
      <button style={{
        background: "var(--fk-red)", color: "#fff", border: "none", borderRadius: 6,
        padding: "5px 10px", fontSize: 11, fontWeight: 700, cursor: "pointer",
      }}>Ajouter</button>
    </div>
  </div>
);

const PosStepRow = ({ step, highlight }) => {
  const visible = step.visible_on.includes("pos");
  return (
    <div style={{
      display: "flex", alignItems: "center", gap: 8, padding: "6px 8px", borderRadius: 6,
      background: highlight ? "#fff7e6" : "transparent",
      border: highlight ? "1px solid #fcd34d" : "1px solid transparent",
      opacity: visible ? 1 : 0.4,
    }}>
      <span style={{
        width: 16, height: 16, borderRadius: 999,
        background: highlight ? "var(--fk-red)" : "var(--cv1-surface-subtle)",
        color: highlight ? "#fff" : "var(--studio-ink-soft)",
        display: "flex", alignItems: "center", justifyContent: "center",
        fontSize: 9, fontWeight: 700, flexShrink: 0,
      }}>{step.position}</span>
      <span style={{ fontSize: 11, fontWeight: 600, width: 90, flexShrink: 0, color: "var(--studio-ink)" }}>
        {step.label.replace("Choisis ", "").replace("ta ", "").replace("ton ", "").replace("tes ", "").replace("la ", "")}
      </span>
      <div style={{ flex: 1, display: "flex", gap: 3, overflow: "hidden", flexWrap: "nowrap" }}>
        {step.options.slice(0, 4).map(o => (
          <span key={o} style={{
            fontSize: 10, padding: "2px 6px", borderRadius: 4,
            background: "#fff", border: "1px solid var(--studio-line)",
            whiteSpace: "nowrap", color: "var(--studio-ink-soft)",
          }}>{o}</span>
        ))}
        {step.options.length > 4 && (
          <span style={{ fontSize: 10, color: "var(--studio-ink-mute)", padding: "2px 4px" }}>
            +{step.options.length - 4}
          </span>
        )}
      </div>
      <span style={{
        fontSize: 9.5, color: "var(--studio-ink-mute)", fontWeight: 600,
        padding: "1px 4px", borderRadius: 3, background: "var(--cv1-surface-subtle)",
      }}>{step.min_select}–{step.max_select}</span>
    </div>
  );
};

/* ===== Kiosk preview — mirrors kiosk-wizard-live-composition capture =====
   Beige bg, dark header strip with stepper avatars, pagination dots, big
   rounded option cards with photo + CHOISIR pill, sticky footer.
*/
const KioskPreview = ({ focused }) => {
  if (!focused.visible_on.includes("kiosk")) {
    return (
      <div style={{
        flex: 1, borderRadius: 12, background: "#fafafa",
        border: "1px dashed var(--studio-line-strong)",
        display: "flex", flexDirection: "column", alignItems: "center", justifyContent: "center",
        gap: 6, padding: 16, color: "var(--studio-ink-mute)",
      }}>
        <Icon name="eye-off" size={20} />
        <div style={{ fontSize: 12, fontWeight: 600 }}>Étape masquée sur Kiosk</div>
        <div style={{ fontSize: 10.5, textAlign: "center", maxWidth: 220 }}>
          Active "Kiosk" dans Visible sur pour voir l'aperçu.
        </div>
      </div>
    );
  }
  const kioskSteps = TACOS_STEPS.filter(s => s.visible_on.includes("kiosk"));
  const idx = kioskSteps.findIndex(s => s.id === focused.id);
  return (
    <div style={{
      flex: 1, minHeight: 0, borderRadius: 14, overflow: "hidden",
      background: "var(--fk-bg)", border: "1px solid var(--fk-border-strong)",
      display: "flex", flexDirection: "column",
      fontFamily: "var(--studio-font)",
    }}>
      {/* Top bar */}
      <div style={{
        background: "#fff", padding: "10px 12px",
        borderBottom: "1px solid var(--fk-border-strong)",
        display: "flex", flexDirection: "column", gap: 8,
      }}>
        <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
          <span style={{
            width: 22, height: 22, borderRadius: 999, background: "#1a1a1a", color: "#fff",
            display: "flex", alignItems: "center", justifyContent: "center", fontSize: 11,
          }}>☀</span>
          <div style={{ flex: 1, textAlign: "center" }}>
            <div style={{ fontSize: 8, fontWeight: 700, letterSpacing: "0.18em", color: "var(--fk-text-mute)" }}>
              VOUS COMPOSEZ
            </div>
            <div style={{ fontSize: 13, fontWeight: 800, letterSpacing: 0.2 }}>TACOS M (1 VIANDE)</div>
          </div>
          <span style={{
            width: 22, height: 22, borderRadius: 999, border: "1px solid var(--fk-border-strong)", background: "#fff",
            display: "flex", alignItems: "center", justifyContent: "center", color: "var(--fk-text-soft)",
          }}><Icon name="x" size={12} /></span>
        </div>

        {/* Step avatars row */}
        <div style={{ display: "flex", gap: 6, justifyContent: "center", alignItems: "flex-start" }}>
          {kioskSteps.map((s, i) => (
            <div key={s.id} style={{
              display: "flex", flexDirection: "column", alignItems: "center", gap: 3,
              flex: 1, maxWidth: 50,
            }}>
              <div style={{
                width: 32, height: 32, borderRadius: 999,
                background: i === idx ? "var(--fk-red)" : "var(--fk-surface-alt)",
                border: i === idx ? `2px solid var(--fk-red-dark)` : "1px solid var(--fk-border-strong)",
                display: "flex", alignItems: "center", justifyContent: "center",
                color: i === idx ? "#fff" : "var(--fk-text-mute)",
                position: "relative", overflow: "hidden",
              }}>
                {i < idx ? <Icon name="check" size={13} stroke={3} /> :
                 i === idx ? <span style={{ fontSize: 11, fontWeight: 800 }}>{s.position}</span> :
                 <Icon name="plus" size={11} />}
              </div>
              <span style={{
                fontSize: 7.5, fontWeight: 800, letterSpacing: "0.08em", textTransform: "uppercase",
                textAlign: "center", color: i === idx ? "var(--fk-red)" : "var(--fk-text-mute)",
                lineHeight: 1.1,
              }}>{s.label.replace("Choisis ", "").replace("ta ", "").replace("ton ", "").replace("tes ", "").replace("la ", "")}</span>
            </div>
          ))}
        </div>

        {/* Pagination dashes */}
        <div style={{ display: "flex", gap: 3, padding: "0 4px" }}>
          {kioskSteps.map((s, i) => (
            <div key={s.id} style={{
              flex: 1, height: 3, borderRadius: 999,
              background: i <= idx ? "var(--fk-red)" : "var(--fk-surface-alt)",
              opacity: i === idx ? 1 : (i < idx ? 0.6 : 1),
            }} />
          ))}
        </div>
      </div>

      {/* Body */}
      <div style={{ flex: 1, padding: 12, display: "flex", flexDirection: "column", gap: 10, overflow: "hidden" }}>
        <div style={{ textAlign: "center" }}>
          <div style={{
            fontSize: 14, fontWeight: 800, letterSpacing: 0.2,
            color: "var(--fk-text)", textTransform: "uppercase",
          }}>{focused.label.toUpperCase()}</div>
          <div style={{
            display: "inline-flex", marginTop: 4, padding: "2px 10px",
            borderRadius: 999, background: "var(--fk-surface-alt)",
            border: "1px solid var(--fk-border-strong)",
            fontSize: 10, fontWeight: 700, color: "var(--fk-text-soft)",
          }}>0 / {focused.max_select}</div>
        </div>

        <div style={{
          padding: "6px 10px", borderRadius: 999, background: "#fff",
          border: "1px solid var(--fk-border-strong)",
          fontSize: 9.5, color: "var(--fk-text-soft)", textAlign: "center", fontWeight: 500,
        }}>
          {kioskHint(focused)}
        </div>

        {/* Option cards */}
        <div style={{
          flex: 1, display: "grid", gridTemplateColumns: "1fr 1fr", gap: 6, overflow: "auto",
        }}>
          {focused.options.slice(0, 4).map((o, i) => (
            <KioskOptionCard key={o} name={o} idx={i} kind={kindFor(focused.step_key, o)} />
          ))}
        </div>
      </div>

      {/* Sticky footer */}
      <div style={{
        borderTop: "1px solid var(--fk-border-strong)", background: "#fff",
        padding: "8px 10px", display: "flex", alignItems: "center", gap: 6,
      }}>
        <button style={{
          fontSize: 9, fontWeight: 800, letterSpacing: "0.08em", textTransform: "uppercase",
          padding: "6px 8px", border: "none", background: "transparent", color: "var(--fk-red)", cursor: "pointer",
        }}>ABANDONNER</button>
        <button style={{
          fontSize: 9, fontWeight: 800, letterSpacing: "0.08em", textTransform: "uppercase",
          padding: "6px 8px", border: "none", borderRadius: 6, background: "var(--fk-surface-alt)",
          color: "var(--fk-text-soft)",
        }}>PRÉCÉDENT</button>
        <div style={{ flex: 1 }} />
        <button style={{
          fontSize: 10, fontWeight: 800, letterSpacing: "0.06em", textTransform: "uppercase",
          padding: "8px 14px", border: "none", borderRadius: 8, background: "var(--fk-red-soft)",
          color: "var(--fk-red)", cursor: "pointer",
        }}>SUIVANT</button>
        <span style={{
          padding: "8px 10px", borderRadius: 8, background: "var(--fk-red-soft)",
          color: "var(--fk-red)", fontSize: 10.5, fontWeight: 800,
        }}>Total 6,50 €</span>
      </div>
    </div>
  );
};

const KioskOptionCard = ({ name, idx, kind }) => (
  <div style={{
    background: "#fff", border: "1px solid var(--fk-border-strong)", borderRadius: 12,
    padding: 8, display: "flex", alignItems: "center", gap: 8,
  }}>
    <ProductThumb kind={kind} size={36} />
    <div style={{ flex: 1, minWidth: 0 }}>
      <div style={{
        fontSize: 11, fontWeight: 800, color: "var(--fk-text)",
        textTransform: "uppercase", letterSpacing: 0.3,
        whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis",
      }}>{name}</div>
      <div style={{
        marginTop: 4, display: "inline-flex", padding: "3px 10px",
        borderRadius: 999, background: "var(--fk-red-soft)",
        color: "var(--fk-red)", fontSize: 8.5, fontWeight: 800, letterSpacing: "0.1em",
      }}>CHOISIR</div>
    </div>
  </div>
);

function kindFor(stepKey, option) {
  if (stepKey === "viande") {
    if (option === "Merguez") return "merguez";
    if (option === "Kefta") return "kefta";
    if (option === "Mexicain") return "mexicain";
    if (option === "Cordon Bleu") return "cordon";
    return "merguez";
  }
  if (stepKey === "boisson") return "coca";
  if (stepKey === "accompagnement") return "frites";
  if (stepKey === "crudites") return "salade";
  return "tacos-m";
}

function kioskHint(step) {
  const hints = {
    taille:        "Choisis la taille de ton tacos.",
    viande:        "Votre tacos comprend 1 viande : touchez celle que vous voulez.",
    sauce:         "Vous pouvez choisir jusqu'à 2 sauces.",
    crudites:      "Ajoutez les crudités que vous souhaitez (jusqu'à 6).",
    accompagnement:"Choisis ton accompagnement (optionnel).",
    boisson:       "Une boisson pour accompagner ?",
  };
  return hints[step.step_key] || "Faites votre choix.";
}

window.Studio3 = Studio3;

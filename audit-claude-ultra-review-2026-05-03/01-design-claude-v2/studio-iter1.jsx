/* studio-iter1.jsx — Iteration 1 (points 1-5)
   1) Drag & drop reorder · 2) Branch overrides · 3) Image upload states
   4) Diff modal — cases & conflict · 5) Conflict banner (Wizard top)
*/
const { Pill, Chip, Btn, Switch, Icon, ProductThumb, TACOS_STEPS } = window;

/* ===== 1) DRAG & DROP REORDER (Screen 3 steps list) ============================ */
const Iter1Drag = ({ width = 1180, height = 720 }) => (
  <div className="studio" style={{
    width, height, background: "var(--studio-canvas)", padding: 20,
    display: "grid", gridTemplateColumns: "320px 1fr", gap: 16,
  }}>
    {/* Steps list with drag in flight */}
    <div className="studio-card" style={{ padding: 10, display: "flex", flexDirection: "column", gap: 4, position: "relative" }}>
      <div style={{ padding: "4px 6px 8px", borderBottom: "1px solid var(--studio-line)" }}>
        <span className="studio-eyebrow">Étapes — réordonnancement</span>
      </div>
      <DragRow step={TACOS_STEPS[0]} />
      <DragRow step={TACOS_STEPS[1]} />
      {/* Drop indicator */}
      <div style={{
        height: 2, background: "var(--cv1-border-emphasis)", borderRadius: 2, margin: "1px 6px",
        boxShadow: "0 0 0 3px rgba(37,99,235,0.18)",
      }} />
      <DragRow step={TACOS_STEPS[3]} />
      {/* The dragging row */}
      <div style={{
        position: "relative", margin: "0 4px", transform: "rotate(1deg)", opacity: 0.95,
        boxShadow: "var(--studio-elev-2)", borderRadius: 10, zIndex: 2,
      }}>
        <DragRow step={TACOS_STEPS[2]} dragging />
      </div>
      <DragRow step={TACOS_STEPS[4]} />
      <DragRow step={TACOS_STEPS[5]} kbdFocus />
      {/* Live region announcement */}
      <div style={{
        marginTop: 8, padding: "8px 10px", borderRadius: 10,
        background: "var(--cv1-status-info-bg)", border: "1px solid var(--cv1-status-info-border)",
        color: "var(--cv1-status-info-text)", fontSize: 11.5,
        display: "flex", alignItems: "center", gap: 6,
      }} role="status" aria-live="polite">
        <Icon name="info" size={12} />
        <code style={{ fontSize: 11 }}>aria-live="polite"</code>
        <span>Étape « Sauce » déplacée à la position 4 sur 6.</span>
      </div>
    </div>

    {/* Spec column */}
    <div style={{ display: "flex", flexDirection: "column", gap: 12, overflow: "auto" }}>
      <div className="studio-card" style={{ padding: 14 }}>
        <span className="studio-eyebrow">Spec interaction</span>
        <ul style={{ margin: "8px 0 0", paddingLeft: 18, fontSize: 12.5, lineHeight: 1.6, color: "var(--studio-ink-soft)" }}>
          <li>Poignée drag visible au hover · curseur <code>grab</code> → <code>grabbing</code> au press.</li>
          <li>Row en drag : <code>--studio-elev-2</code>, <code>rotate(1deg)</code>, <code>opacity 0.95</code>, <code>z-index 2</code>.</li>
          <li>Drop indicator : barre 2px <code>--cv1-border-emphasis</code> + halo <code>rgba(37,99,235,.18)</code>.</li>
          <li>Keyboard reorder : focus 3px sur la row · <kbd>Space</kbd> grab/release · <kbd>↑/↓</kbd> move · <kbd>Esc</kbd> annule.</li>
          <li>ARIA : <code>role="grid"</code> sur la liste, chaque row <code>role="row"</code> + <code>aria-grabbed</code>.</li>
          <li>Persistance optimiste : PATCH /composer-steps/reorder · rollback si 4xx · toast Undo 5s.</li>
        </ul>
      </div>
      <ToastUndo title="Étape « Sauce » déplacée" sub="position 3 → 4 sur 6" countdown={3.2} />
      <div className="studio-card" style={{ padding: 14 }}>
        <span className="studio-eyebrow">Légende</span>
        <div style={{ display: "flex", flexDirection: "column", gap: 6, marginTop: 8, fontSize: 12 }}>
          <Legend dotColor="var(--cv1-border-emphasis)" label="Drop indicator (où la row va atterrir)" />
          <Legend dotColor="#fcd34d" label="Row sélectionnée au clavier (focus 3px)" />
          <Legend dotColor="rgba(15,23,42,.18)" label="Row en cours de drag (élévation 2)" />
        </div>
      </div>
    </div>
  </div>
);

const DragRow = ({ step, dragging, kbdFocus }) => {
  const srcLabel = step.source_type === "item_attribute" ? "Attribut" : step.source_type === "extra_group" ? "Extra" : "Addon";
  return (
    <div style={{
      display: "flex", alignItems: "center", gap: 8, padding: "10px 10px",
      borderRadius: 10, background: "#fff",
      border: `1px solid ${dragging ? "#c7d2fe" : "var(--studio-line)"}`,
      outline: kbdFocus ? "3px solid var(--cv1-focus-ring-color)" : "none",
      outlineOffset: kbdFocus ? 2 : 0,
      cursor: dragging ? "grabbing" : "default",
    }}>
      <span style={{ color: dragging ? "var(--cv1-border-emphasis)" : "var(--studio-ink-mute)", cursor: dragging ? "grabbing" : "grab" }}>
        <Icon name="drag" size={14} />
      </span>
      <span style={{
        width: 22, height: 22, borderRadius: 999,
        background: "var(--cv1-surface-subtle)", color: "var(--studio-ink-soft)",
        display: "flex", alignItems: "center", justifyContent: "center",
        fontSize: 11, fontWeight: 700,
      }}>{step.position}</span>
      <span style={{ flex: 1, fontSize: 13, fontWeight: 600, minWidth: 0, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>
        {step.label}
      </span>
      <Chip src={step.source_type}>{srcLabel}</Chip>
      {step.visible_on.includes("pos") && <Chip channel="pos">POS</Chip>}
      {step.visible_on.includes("kiosk") && <Chip channel="kiosk">Kiosk</Chip>}
    </div>
  );
};

const Legend = ({ dotColor, label }) => (
  <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
    <span style={{ width: 10, height: 10, borderRadius: 999, background: dotColor }} />
    <span style={{ color: "var(--studio-ink-soft)" }}>{label}</span>
  </div>
);

const ToastUndo = ({ title, sub, countdown = 5 }) => (
  <div style={{
    background: "var(--cv1-surface-strong)", color: "#fff",
    borderRadius: 12, padding: "12px 14px",
    display: "flex", alignItems: "center", gap: 12, position: "relative", overflow: "hidden",
  }}>
    <span style={{
      width: 28, height: 28, borderRadius: 8, background: "rgba(255,255,255,.1)",
      display: "flex", alignItems: "center", justifyContent: "center",
    }}><Icon name="check" size={14} stroke={2.5} /></span>
    <div style={{ flex: 1 }}>
      <div style={{ fontSize: 13, fontWeight: 600 }}>{title}</div>
      <div style={{ fontSize: 11.5, opacity: 0.7 }}>{sub}</div>
    </div>
    <button style={{
      background: "transparent", color: "#fff", border: "1px solid rgba(255,255,255,.25)",
      borderRadius: 6, padding: "4px 10px", fontSize: 12, fontWeight: 600, cursor: "pointer",
    }}>Annuler</button>
    <div style={{
      position: "absolute", left: 0, bottom: 0, height: 2,
      width: `${(countdown / 5) * 100}%`, background: "#10b981",
    }} />
  </div>
);

/* ===== 2) BRANCH OVERRIDES (Inspector tab) ===================================== */
const Iter2Branch = ({ width = 420, height = 760 }) => (
  <div className="studio" style={{
    width, height, background: "#fff", display: "flex", flexDirection: "column",
    border: "1px solid var(--studio-line)",
  }}>
    {/* Inspector header */}
    <div style={{ padding: 14, borderBottom: "1px solid var(--studio-line)", display: "flex", gap: 10, alignItems: "center" }}>
      <ProductThumb kind="tacos-m" size={40} />
      <div style={{ flex: 1, minWidth: 0 }}>
        <div className="studio-eyebrow">Inspecteur</div>
        <div style={{ fontSize: 14, fontWeight: 700 }}>Tacos M (1 viande)</div>
      </div>
      <Pill status="active">Actif</Pill>
    </div>

    {/* Tabs */}
    <div style={{ display: "flex", gap: 0, padding: "0 14px", borderBottom: "1px solid var(--studio-line)" }}>
      {["Détails", "Wizard", "Disponibilité", "Stock", "Canaux"].map((t, i) => {
        const active = i === 2;
        return (
          <button key={t} style={{
            padding: "10px 12px", background: "transparent", border: "none",
            fontSize: 12.5, fontWeight: 600, cursor: "pointer",
            color: active ? "var(--studio-ink)" : "var(--studio-ink-mute)",
            borderBottom: active ? "2px solid var(--cv1-border-emphasis)" : "2px solid transparent",
            marginBottom: -1,
          }}>{t}</button>
        );
      })}
    </div>

    {/* Scope toggle */}
    <div style={{
      padding: "10px 14px", display: "flex", alignItems: "center", gap: 8,
      background: "var(--cv1-surface-subtle)", borderBottom: "1px solid var(--studio-line)",
    }}>
      <span className="studio-eyebrow" style={{ flex: 1 }}>Disponibilité par filiale</span>
      <div style={{
        display: "flex", border: "1px solid var(--studio-line-strong)", borderRadius: 7, overflow: "hidden",
        fontSize: 11, fontWeight: 600,
      }}>
        <button style={{ padding: "4px 8px", background: "#fff", border: "none", cursor: "pointer" }}>Filiale #1</button>
        <button style={{ padding: "4px 8px", background: "var(--cv1-surface-strong)", color: "#fff", border: "none", cursor: "pointer" }}>
          Toutes
        </button>
      </div>
    </div>

    {/* Branch list */}
    <div style={{ flex: 1, overflow: "auto", padding: 12, display: "flex", flexDirection: "column", gap: 8 }}>
      <BranchRow name="Filiale #1 — Le Cay" code="LCY" on={true}  reason={null} ago="il y a 2 min" by="Yann L." />
      <BranchRow name="Filiale #2 — Centre" code="CTR" on={false} reason="86" reasonLabel="Stock épuisé" ago="il y a 14 min" by="auto-86" />
      <BranchRow name="Filiale #3 — Gare"   code="GAR" on={false} reason="quota" reasonLabel="Quota épuisé" ago="il y a 1h"   by="système" />
      <BranchRow name="Filiale #4 — Nord"   code="NRD" on={true}  reason={null} ago="hier 18:42" by="Yann L." />
    </div>

    {/* Sticky bottom: sync */}
    <div style={{
      padding: 12, borderTop: "1px solid var(--studio-line)", background: "#fff",
      display: "flex", flexDirection: "column", gap: 6,
    }}>
      <Btn block><Icon name="refresh" size={13} />Synchroniser sur toutes les filiales</Btn>
      <span style={{ fontSize: 11, color: "var(--studio-ink-mute)", textAlign: "center" }}>
        Applique l'état de Filiale #1 aux 3 autres · les motifs 86 sont préservés.
      </span>
    </div>
  </div>
);

const BranchRow = ({ name, code, on, reason, reasonLabel, ago, by }) => (
  <div style={{
    padding: 10, border: "1px solid var(--studio-line)", borderRadius: 10,
    display: "flex", flexDirection: "column", gap: 6,
    background: !on ? "var(--studio-pill-86-bg)" : "#fff",
  }}>
    <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
      <span style={{
        width: 28, height: 28, borderRadius: 7, background: "var(--cv1-surface-subtle)",
        display: "flex", alignItems: "center", justifyContent: "center",
        fontSize: 10, fontWeight: 700, color: "var(--studio-ink-soft)",
      }}>{code}</span>
      <div style={{ flex: 1, minWidth: 0 }}>
        <div style={{ fontSize: 12.5, fontWeight: 600, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{name}</div>
        <div style={{ fontSize: 10.5, color: "var(--studio-ink-mute)" }}>maj {ago} · par {by}</div>
      </div>
      <Switch on={on} reason={reason ? "out_of_stock" : null} />
    </div>
    {!on && (
      <div style={{ display: "flex", gap: 6, alignItems: "center", paddingLeft: 36 }}>
        {reason === "86" && <Pill status="86">86 · {reasonLabel}</Pill>}
        {reason === "quota" && (
          <span className="studio-pill" style={{ background: "var(--studio-pill-draft-bg)", color: "var(--studio-pill-draft-fg)" }}>
            <span className="dot" style={{ background: "var(--studio-pill-draft-dot)" }} />
            {reasonLabel}
          </span>
        )}
        <button style={{
          background: "transparent", border: "none", color: "var(--cv1-border-emphasis)",
          fontSize: 11, fontWeight: 600, cursor: "pointer", padding: 0,
        }}>Forcer disponible</button>
      </div>
    )}
  </div>
);

/* ===== 3) IMAGE UPLOAD STATES ================================================== */
const Iter3Upload = ({ width = 1180, height = 580 }) => (
  <div className="studio" style={{
    width, height, background: "var(--studio-canvas)", padding: 20,
    display: "flex", flexDirection: "column", gap: 14,
  }}>
    <header>
      <span className="studio-eyebrow">Quick Create · zone photo · états</span>
      <h2 style={{ margin: "4px 0 0", fontSize: 18, fontWeight: 700 }}>Image upload — 5 états + 2 fallbacks</h2>
    </header>
    <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 12 }}>
      <UploadCard label="Idle" subtitle="Drop-zone par défaut">
        <div style={{
          border: "1.5px dashed var(--studio-line-strong)", borderRadius: 12,
          padding: 18, display: "flex", flexDirection: "column", alignItems: "center", gap: 8,
          background: "var(--cv1-surface-subtle)",
        }}>
          <div style={{
            width: 38, height: 38, borderRadius: 10, background: "#fff",
            display: "flex", alignItems: "center", justifyContent: "center", color: "var(--studio-ink-mute)",
          }}><Icon name="image" size={18} /></div>
          <div style={{ textAlign: "center" }}>
            <div style={{ fontSize: 12.5, fontWeight: 600 }}>Glisser une photo ou cliquer</div>
            <div style={{ fontSize: 11, color: "var(--studio-ink-mute)", marginTop: 2 }}>
              PNG / JPG / WebP · max 4 Mo · ratio 4:3 conseillé
            </div>
          </div>
          <Btn size="sm"><Icon name="upload" size={12} />Parcourir</Btn>
        </div>
      </UploadCard>

      <UploadCard label="Drag-over" subtitle="Survol avec un fichier">
        <div style={{
          border: "2px solid var(--cv1-border-emphasis)", borderRadius: 12,
          padding: 18, background: "#eef2ff",
          display: "flex", flexDirection: "column", alignItems: "center", gap: 8,
          boxShadow: "inset 0 0 0 4px rgba(37,99,235,.08)",
        }}>
          <div style={{
            width: 38, height: 38, borderRadius: 10, background: "var(--cv1-border-emphasis)", color: "#fff",
            display: "flex", alignItems: "center", justifyContent: "center",
          }}><Icon name="upload" size={18} /></div>
          <div style={{ fontSize: 12.5, fontWeight: 700, color: "var(--cv1-border-emphasis)" }}>Lâcher pour téléverser</div>
          <div style={{ fontSize: 11, color: "var(--cv1-border-emphasis)" }}>tacos-m.jpg · 1,2 Mo</div>
        </div>
      </UploadCard>

      <UploadCard label="Uploading" subtitle="Téléversement en cours">
        <div style={{ border: "1px solid var(--studio-line)", borderRadius: 12, padding: 14, background: "#fff", display: "flex", flexDirection: "column", gap: 10 }}>
          <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
            <ProductThumb kind="tacos-m" size={40} />
            <div style={{ flex: 1, minWidth: 0 }}>
              <div style={{ fontSize: 12.5, fontWeight: 600 }}>tacos-m.jpg</div>
              <div style={{ fontSize: 11, color: "var(--studio-ink-mute)" }}>1,2 Mo · 64 % téléversés</div>
            </div>
            <button style={{ background: "transparent", border: "none", color: "var(--studio-ink-mute)", cursor: "pointer" }}>
              <Icon name="x" size={14} />
            </button>
          </div>
          <div style={{ height: 4, background: "var(--studio-line)", borderRadius: 999, overflow: "hidden" }}>
            <div style={{ width: "64%", height: "100%", background: "var(--cv1-border-emphasis)", borderRadius: 999 }} />
          </div>
        </div>
      </UploadCard>

      <UploadCard label="Success" subtitle="Aperçu + actions">
        <div style={{ border: "1px solid #bbf7d0", borderRadius: 12, padding: 12, background: "#f0fdf4", display: "flex", flexDirection: "column", gap: 10 }}>
          <div style={{ display: "flex", gap: 10, alignItems: "center" }}>
            <ProductThumb kind="tacos-m" size={48} />
            <div style={{ flex: 1 }}>
              <div style={{ fontSize: 12.5, fontWeight: 600, color: "#166534" }}>Téléversement réussi</div>
              <div style={{ fontSize: 11, color: "#166534", opacity: 0.85 }}>1024×768 · 1,2 Mo</div>
            </div>
            <Icon name="check-c" size={18} />
          </div>
          <div style={{ display: "flex", gap: 6 }}>
            <Btn size="sm">Remplacer</Btn>
            <Btn size="sm">Recadrer (4:3)</Btn>
          </div>
        </div>
      </UploadCard>

      <UploadCard label="Error" subtitle="Échec réseau ou format">
        <div style={{ border: "1px solid #fecdd3", borderRadius: 12, padding: 12, background: "var(--studio-pill-86-bg)", display: "flex", flexDirection: "column", gap: 10 }}>
          <div style={{ display: "flex", gap: 10, alignItems: "flex-start" }}>
            <span style={{
              width: 32, height: 32, borderRadius: 8, background: "#fff",
              display: "flex", alignItems: "center", justifyContent: "center", color: "var(--studio-pill-86-fg)",
            }}><Icon name="alert" size={16} /></span>
            <div style={{ flex: 1 }}>
              <div style={{ fontSize: 12.5, fontWeight: 700, color: "var(--studio-pill-86-fg)" }}>Échec de l'upload</div>
              <div style={{ fontSize: 11, color: "var(--studio-pill-86-fg)" }}>413 — Fichier &gt; 4 Mo. Compresse ou choisis une image plus légère.</div>
            </div>
          </div>
          <div style={{ display: "flex", gap: 6 }}>
            <Btn size="sm" variant="primary"><Icon name="refresh" size={12} />Réessayer</Btn>
            <Btn size="sm">Choisir un autre fichier</Btn>
          </div>
        </div>
      </UploadCard>

      <UploadCard label="Fallback (no photo)" subtitle="Initiale + fond neutre">
        <div style={{ border: "1px solid var(--studio-line)", borderRadius: 12, padding: 14, background: "#fff" }}>
          <div style={{
            width: "100%", aspectRatio: "4/3",
            background: "var(--studio-pill-neutral-bg)", borderRadius: 10,
            display: "flex", alignItems: "center", justifyContent: "center", position: "relative",
          }}>
            <span style={{
              fontSize: 56, fontWeight: 800, color: "var(--studio-ink-mute)",
              fontFamily: "var(--studio-font)", letterSpacing: -2,
            }}>T</span>
            <span style={{
              position: "absolute", top: 8, right: 8,
              background: "#fff", border: "1px solid var(--studio-line)", borderRadius: 999,
              padding: "2px 8px", fontSize: 10, fontWeight: 600, color: "var(--studio-ink-mute)",
            }}>Photo manquante</span>
          </div>
          <div style={{ fontSize: 11, color: "var(--studio-ink-mute)", marginTop: 8 }}>
            Visible aussi sur ProductCard tant qu'aucune photo n'est ajoutée.
          </div>
        </div>
      </UploadCard>
    </div>
  </div>
);

const UploadCard = ({ label, subtitle, children }) => (
  <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
    <div style={{ display: "flex", alignItems: "baseline", justifyContent: "space-between" }}>
      <span style={{ fontSize: 12, fontWeight: 700 }}>{label}</span>
      <span style={{ fontSize: 11, color: "var(--studio-ink-mute)" }}>{subtitle}</span>
    </div>
    {children}
  </div>
);

/* ===== 4) DIFF MODAL — cases & conflict ======================================== */
const Iter4DiffCases = ({ width = 1180, height = 760 }) => (
  <div className="studio" style={{
    width, height, background: "var(--studio-canvas)", padding: 20,
    display: "grid", gridTemplateColumns: "1fr 360px", gap: 14, fontFamily: "var(--studio-font)",
  }}>
    <div className="studio-card" style={{ display: "flex", flexDirection: "column", overflow: "hidden" }}>
      {/* Header — conflict variant */}
      <header style={{
        padding: "14px 18px", background: "var(--cv1-status-blocker-bg)",
        borderBottom: "1px solid var(--cv1-status-blocker-border)",
        display: "flex", alignItems: "center", gap: 10,
      }}>
        <span style={{
          width: 32, height: 32, borderRadius: 8, background: "#fff",
          color: "var(--cv1-status-blocker-text)",
          display: "flex", alignItems: "center", justifyContent: "center",
        }}><Icon name="alert" size={16} /></span>
        <div style={{ flex: 1 }}>
          <div className="studio-eyebrow" style={{ color: "var(--cv1-status-blocker-text)" }}>Conflit de version</div>
          <h2 style={{ margin: "2px 0 0", fontSize: 16, fontWeight: 700, color: "var(--cv1-status-blocker-text)" }}>
            La version serveur a changé pendant ton édition (v3 → v5)
          </h2>
        </div>
        <Btn size="sm">Voir le diff serveur</Btn>
        <Btn size="sm" variant="brand">Recharger v5</Btn>
      </header>

      {/* Cases couverts */}
      <div style={{ padding: "12px 18px", borderBottom: "1px solid var(--studio-line)" }}>
        <div className="studio-eyebrow">Cas couverts par l'algorithme de diff</div>
        <div style={{
          fontFamily: "var(--studio-font-mono)", fontSize: 11, marginTop: 8,
          background: "var(--cv1-surface-subtle)", padding: 10, borderRadius: 8,
          color: "var(--studio-ink-soft)",
        }}>
          diff = compare(profile_published_v(n).steps, draft_v(n+1).steps, key = step_key)
        </div>
        <div style={{ display: "grid", gridTemplateColumns: "repeat(4, 1fr)", gap: 8, marginTop: 10 }}>
          <CaseChip tone="add" label="Step ajouté"             eg="+ accompagnement" />
          <CaseChip tone="rem" label="Step supprimé"           eg="− sauce" />
          <CaseChip tone="mod" label="Step déplacé (position)" eg="3 → 5" />
          <CaseChip tone="mod" label="Step renommé (label)"    eg="Choisis ta sauce → Quelle sauce ?" />
          <CaseChip tone="mod" label="min/max changé"          eg="max 1 → 2" />
          <CaseChip tone="mod" label="visible_on changé"       eg="+ kiosk" />
          <CaseChip tone="mod" label="addon_role changé"       eg="side → drink" />
          <CaseChip tone="mod" label="stockable_choices"       eg="false → true" />
        </div>
      </div>

      {/* Concrete diff line — sauce max 1 → 2 */}
      <div style={{ padding: "12px 18px" }}>
        <div className="studio-eyebrow">Cas concret — étape « sauce »</div>
        <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 10, marginTop: 8 }}>
          <BeforeAfter side="before" label="Publié v3">
            <KV k="step_key"   v='"sauce"' />
            <KV k="min_select" v="1" />
            <KV k="max_select" v="1" highlight="rem" />
            <KV k="visible_on" v='["pos","kiosk"]' />
            <KV k="addon_role" v="null" />
          </BeforeAfter>
          <BeforeAfter side="after" label="Brouillon v4">
            <KV k="step_key"   v='"sauce"' />
            <KV k="min_select" v="1" />
            <KV k="max_select" v="2" highlight="add" />
            <KV k="visible_on" v='["pos","kiosk"]' />
            <KV k="addon_role" v="null" />
          </BeforeAfter>
        </div>
        <div style={{
          marginTop: 10, padding: "8px 12px", borderRadius: 8,
          background: "var(--studio-diff-mod-bg)", color: "var(--studio-diff-mod-fg)",
          fontSize: 12.5, display: "flex", alignItems: "center", gap: 8,
          borderLeft: "3px solid var(--studio-diff-mod-bar)",
        }}>
          <Icon name="alert" size={13} />
          Modification détectée — <strong>max_select 1 → 2</strong>. Impact runtime : le client peut choisir 2 sauces.
        </div>
      </div>
    </div>

    {/* Right — conflict actions card */}
    <div style={{ display: "flex", flexDirection: "column", gap: 12 }}>
      <div className="studio-card" style={{ padding: 14 }}>
        <span className="studio-eyebrow">État conflict — actions</span>
        <ol style={{ margin: "8px 0 0", paddingLeft: 18, fontSize: 12.5, color: "var(--studio-ink-soft)", lineHeight: 1.6 }}>
          <li>Header passe en <code>--cv1-status-blocker-bg</code>.</li>
          <li>Bouton <em>Publier</em> de la TopBar Wizard désactivé.</li>
          <li>2 actions : <em>Recharger v5</em> (destructif) · <em>Voir diff serveur</em> (lecture).</li>
          <li>Si l'utilisateur choisit Recharger : ses modifs vont dans <code>localStorage.draft_backup</code> 24h.</li>
        </ol>
      </div>
      <div className="studio-card" style={{ padding: 14 }}>
        <span className="studio-eyebrow">Légende des couleurs</span>
        <div style={{ display: "flex", flexDirection: "column", gap: 6, marginTop: 8, fontSize: 12 }}>
          <Legend dotColor="var(--studio-diff-add-bar)" label="Ajout — nouvelle valeur uniquement dans le brouillon" />
          <Legend dotColor="var(--studio-diff-rem-bar)" label="Suppression — valeur disparue depuis v(n)" />
          <Legend dotColor="var(--studio-diff-mod-bar)" label="Modification — clé identique, valeur différente" />
        </div>
      </div>
    </div>
  </div>
);

const CaseChip = ({ tone, label, eg }) => {
  const tones = {
    add: { bg: "var(--studio-diff-add-bg)", fg: "var(--studio-diff-add-fg)", bar: "var(--studio-diff-add-bar)" },
    rem: { bg: "var(--studio-diff-rem-bg)", fg: "var(--studio-diff-rem-fg)", bar: "var(--studio-diff-rem-bar)" },
    mod: { bg: "var(--studio-diff-mod-bg)", fg: "var(--studio-diff-mod-fg)", bar: "var(--studio-diff-mod-bar)" },
  }[tone];
  return (
    <div style={{
      padding: "8px 10px", borderRadius: 8, background: tones.bg, color: tones.fg,
      borderLeft: `3px solid ${tones.bar}`,
    }}>
      <div style={{ fontSize: 11.5, fontWeight: 700 }}>{label}</div>
      <code style={{ fontSize: 10.5, opacity: 0.85 }}>{eg}</code>
    </div>
  );
};

const BeforeAfter = ({ side, label, children }) => (
  <div style={{
    border: "1px solid var(--studio-line)", borderRadius: 10, overflow: "hidden",
  }}>
    <div style={{
      padding: "6px 10px", background: "var(--cv1-surface-subtle)",
      borderBottom: "1px solid var(--studio-line)", display: "flex", alignItems: "center", gap: 6,
    }}>
      <Pill status={side === "before" ? "active" : "draft"}>{label}</Pill>
    </div>
    <div style={{ padding: 10, fontFamily: "var(--studio-font-mono)", fontSize: 11.5, display: "flex", flexDirection: "column", gap: 3 }}>
      {children}
    </div>
  </div>
);

const KV = ({ k, v, highlight }) => {
  const tones = {
    add: { bg: "var(--studio-diff-add-bg)", fg: "var(--studio-diff-add-fg)" },
    rem: { bg: "var(--studio-diff-rem-bg)", fg: "var(--studio-diff-rem-fg)" },
  };
  const style = highlight ? { background: tones[highlight].bg, color: tones[highlight].fg, padding: "1px 6px", borderRadius: 4 } : {};
  return (
    <div style={{ display: "flex", gap: 8, ...style }}>
      <span style={{ color: highlight ? "currentColor" : "var(--studio-ink-mute)", width: 96 }}>{k}:</span>
      <span style={{ fontWeight: 600 }}>{v}</span>
    </div>
  );
};

/* ===== 5) CONFLICT BANNER (Wizard top) ========================================= */
const Iter5Conflict = ({ width = 1440, height = 200 }) => (
  <div className="studio" style={{
    width, height, background: "var(--studio-canvas)", padding: 16,
    display: "flex", flexDirection: "column", gap: 12,
  }}>
    <div className="studio-card" style={{ overflow: "hidden" }}>
      {/* Conflict banner */}
      <div style={{
        padding: "12px 18px", background: "var(--cv1-status-blocker-bg)",
        borderBottom: "1px solid var(--cv1-status-blocker-border)",
        display: "flex", alignItems: "center", gap: 12,
      }}>
        <span style={{
          width: 32, height: 32, borderRadius: 999, background: "#fff",
          color: "var(--cv1-status-blocker-text)",
          display: "flex", alignItems: "center", justifyContent: "center",
        }}><Icon name="alert" size={16} /></span>
        <div style={{ flex: 1 }}>
          <div style={{ fontSize: 13.5, fontWeight: 700, color: "var(--cv1-status-blocker-text)" }}>
            Une autre session a publié v5 — ton brouillon v4 ne peut plus être publié tel quel.
          </div>
          <div style={{ fontSize: 11.5, color: "var(--cv1-status-blocker-text)", opacity: 0.85, marginTop: 2 }}>
            Recharger remplacera tes modifications par la dernière version serveur. Une copie de secours sera gardée 24 h.
          </div>
        </div>
        <Btn size="sm">Voir le diff serveur d'abord</Btn>
        <Btn size="sm" variant="brand"><Icon name="refresh" size={12} />Recharger v5 et perdre mes modifs</Btn>
      </div>

      {/* TopBar with disabled Publish */}
      <div style={{ padding: "10px 18px", display: "flex", alignItems: "center", gap: 12, background: "#fff" }}>
        <ProductThumb kind="tacos-m" size={32} />
        <div style={{ minWidth: 0 }}>
          <div style={{ fontSize: 12, color: "var(--studio-ink-mute)" }}>Catalog Studio › Nos Tacos › Tacos M (1 viande)</div>
          <div style={{ fontSize: 14, fontWeight: 700 }}>Éditeur de wizard</div>
        </div>
        <div style={{ flex: 1 }} />
        <span className="studio-pill" data-status="86"><span className="dot" />Conflit · v5 distante</span>
        <span className="studio-pill"><Icon name="lock" size={11} />Publication verrouillée</span>
        <Btn>Aperçu plein écran</Btn>
        <Btn variant="primary" disabled style={{ opacity: 0.45 }}>
          <Icon name="publish" size={13} />Publier v4
        </Btn>
      </div>
    </div>
  </div>
);

window.Iter1Drag = Iter1Drag;
window.Iter2Branch = Iter2Branch;
window.Iter3Upload = Iter3Upload;
window.Iter4DiffCases = Iter4DiffCases;
window.Iter5Conflict = Iter5Conflict;

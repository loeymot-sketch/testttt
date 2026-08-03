/* studio-screen2.jsx — Screen 2: Product Quick Create (expanded, 2-step inline)
   Lives inside CatalogStudioComponent.vue's center column — never a modal.
*/

const Studio2 = ({ width = 1180, height = 760, step = 2 }) => (
  <div className="studio" style={{
    width, height, background: "var(--studio-canvas)",
    padding: 20, display: "flex", flexDirection: "column", gap: 14,
    fontFamily: "var(--studio-font)",
  }}>
    {/* Breadcrumb / context */}
    <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
      <span className="studio-eyebrow">Catalog Studio · Nos Tacos</span>
      <Icon name="chevron-r" size={11} />
      <span style={{ fontSize: 13, fontWeight: 700 }}>Nouveau produit</span>
      <div style={{ flex: 1 }} />
      <Btn size="sm" variant="ghost"><Icon name="x" size={12} />Annuler</Btn>
    </div>

    {/* Stepper */}
    <div className="studio-card" style={{ padding: 14, display: "flex", alignItems: "center", gap: 14 }}>
      <QcStep n={1} label="Identité" sub="Nom · catégorie · photo" done={step >= 2} active={step === 1} />
      <div style={{ flex: 1, height: 2, background: step >= 2 ? "var(--cv1-border-emphasis)" : "var(--studio-line)" }} />
      <QcStep n={2} label="Tarif & template" sub="Prix TTC · TVA · wizard" active={step === 2} />
      <div style={{ flex: 1, height: 2, background: "var(--studio-line)" }} />
      <QcStep n={3} label="Brouillon prêt" sub="Configurer le wizard" muted />
    </div>

    {step === 1 && <Step1Identity />}
    {step === 2 && <Step2Pricing />}

    {/* Footer */}
    <div className="studio-card" style={{
      padding: "12px 16px", display: "flex", alignItems: "center", gap: 12,
    }}>
      <span style={{ fontSize: 12, color: "var(--studio-ink-mute)" }}>
        <Icon name="info" size={12} /> Le produit est créé en brouillon. Tu peux configurer son wizard à l'étape suivante.
      </span>
      <div style={{ flex: 1 }} />
      <Btn>Précédent</Btn>
      <Btn variant="ghost">Enregistrer brouillon</Btn>
      <Btn variant="primary">
        Créer & ouvrir le wizard <Icon name="arrow-r" size={13} />
      </Btn>
    </div>
  </div>
);

const QcStep = ({ n, label, sub, active, done, muted }) => (
  <div style={{ display: "flex", alignItems: "center", gap: 10, opacity: muted ? 0.5 : 1 }}>
    <span style={{
      width: 28, height: 28, borderRadius: 999,
      background: active ? "var(--cv1-border-emphasis)" : done ? "#10b981" : "var(--cv1-surface-subtle)",
      color: active || done ? "#fff" : "var(--studio-ink-mute)",
      display: "flex", alignItems: "center", justifyContent: "center",
      fontSize: 12, fontWeight: 700,
      border: active ? "3px solid #c7d2fe" : "none",
    }}>{done ? <Icon name="check" size={13} stroke={3} /> : n}</span>
    <div>
      <div style={{ fontSize: 13, fontWeight: 700 }}>{label}</div>
      <div style={{ fontSize: 11, color: "var(--studio-ink-mute)" }}>{sub}</div>
    </div>
  </div>
);

const Step1Identity = () => (
  <div style={{ display: "grid", gridTemplateColumns: "1fr 380px", gap: 14, flex: 1, minHeight: 0 }}>
    <section className="studio-card" style={{ padding: 18, display: "flex", flexDirection: "column", gap: 14 }}>
      <span className="studio-eyebrow">Étape 1 · Identité</span>
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12 }}>
        <Field2 label="Nom du produit" required>
          <input className="studio-input" defaultValue="Tacos M (1 viande)" />
        </Field2>
        <Field2 label="Catégorie" required>
          <SelectInline value="Nos Tacos" />
        </Field2>
      </div>
      <Field2 label="Description courte" hint="Vue dans les listings web. 140 caractères max.">
        <textarea className="studio-input" rows={2} style={{ height: "auto", padding: 10, resize: "none" }}
          defaultValue="Tortilla maison, 1 viande au choix, sauces et crudités fraîches." />
      </Field2>
      <Field2 label="Photo" hint="JPG ou PNG · 1024×1024 recommandé · 2 Mo max">
        <DropZone />
      </Field2>
    </section>
    <aside className="studio-card" style={{ padding: 16, display: "flex", flexDirection: "column", gap: 12 }}>
      <span className="studio-eyebrow">Aperçu carte</span>
      <ProductPreviewCard />
      <div style={{ fontSize: 11, color: "var(--studio-ink-mute)" }}>
        L'aperçu se met à jour à chaque modification. Le statut sera "Brouillon" tant que tu n'as pas publié.
      </div>
    </aside>
  </div>
);

const Step2Pricing = () => (
  <div style={{ display: "grid", gridTemplateColumns: "1fr 380px", gap: 14, flex: 1, minHeight: 0 }}>
    <section className="studio-card" style={{ padding: 18, display: "flex", flexDirection: "column", gap: 16, overflow: "auto" }}>
      <span className="studio-eyebrow">Étape 2 · Tarif & template</span>

      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 12 }}>
        <Field2 label="Prix TTC" required hint="Le backend reste source de vérité — pas de recalcul client.">
          <div style={{ display: "flex", alignItems: "stretch" }}>
            <input className="studio-input" defaultValue="6,50" style={{ borderTopRightRadius: 0, borderBottomRightRadius: 0 }} />
            <span style={{
              padding: "0 12px", display: "flex", alignItems: "center",
              border: "1px solid var(--studio-line-strong)", borderLeft: "none",
              borderRadius: "0 8px 8px 0", background: "var(--cv1-surface-subtle)",
              fontSize: 13, fontWeight: 600, color: "var(--studio-ink-soft)",
            }}>€</span>
          </div>
        </Field2>
        <Field2 label="TVA appliquée">
          <SelectInline value="10% (sur place)" />
        </Field2>
        <Field2 label="Disponible sur" hint="Décide où le produit apparaît.">
          <div style={{ display: "flex", gap: 4 }}>
            <Chip channel="pos">POS</Chip>
            <Chip channel="kiosk">Kiosk</Chip>
            <span className="studio-chip" style={{ opacity: 0.5 }}>Web</span>
          </div>
        </Field2>
      </div>

      <div>
        <div className="studio-eyebrow" style={{ marginBottom: 8 }}>Choisis un template de wizard</div>
        <div style={{ display: "grid", gridTemplateColumns: "repeat(4, 1fr)", gap: 10 }}>
          {STUDIO_TEMPLATES.map(t => <TemplateCard key={t.id} t={t} />)}
        </div>
      </div>
    </section>

    <aside className="studio-card" style={{ padding: 16, display: "flex", flexDirection: "column", gap: 12 }}>
      <span className="studio-eyebrow">Récap</span>
      <ProductPreviewCard />
      <div style={{
        padding: 10, borderRadius: 10, background: "var(--cv1-surface-subtle)",
        fontSize: 12, display: "flex", flexDirection: "column", gap: 6,
      }}>
        <RecapRow k="Catégorie"  v="Nos Tacos" />
        <RecapRow k="Prix TTC"   v="6,50 €" />
        <RecapRow k="TVA"        v="10 %" />
        <RecapRow k="Template"   v="Tacos · 6 étapes préremplies" highlight />
        <RecapRow k="Canaux"     v="POS · Kiosk" />
      </div>
    </aside>
  </div>
);

const Field2 = ({ label, hint, required, children }) => (
  <label style={{ display: "flex", flexDirection: "column", gap: 5 }}>
    <span style={{ fontSize: 12, fontWeight: 600 }}>
      {label} {required && <span style={{ color: "var(--fk-red)" }}>*</span>}
    </span>
    {children}
    {hint && <span style={{ fontSize: 11, color: "var(--studio-ink-mute)" }}>{hint}</span>}
  </label>
);

const SelectInline = ({ value }) => (
  <div style={{
    display: "flex", alignItems: "center", gap: 6, height: 32, padding: "0 10px",
    border: "1px solid var(--studio-line-strong)", borderRadius: 8, background: "#fff",
    fontSize: 13, fontWeight: 500,
  }}>
    <span style={{ flex: 1 }}>{value}</span>
    <Icon name="chevron-d" size={13} />
  </div>
);

const DropZone = () => (
  <div style={{
    border: "1.5px dashed var(--studio-line-strong)", borderRadius: 12,
    padding: 16, display: "flex", alignItems: "center", gap: 14, background: "var(--cv1-surface-subtle)",
  }}>
    <ProductThumb kind="tacos-m" size={64} />
    <div style={{ flex: 1 }}>
      <div style={{ fontSize: 13, fontWeight: 600 }}>Glisse une image ici</div>
      <div style={{ fontSize: 11, color: "var(--studio-ink-mute)", marginTop: 2 }}>
        ou clique pour parcourir · JPG/PNG · 2 Mo max
      </div>
    </div>
    <Btn size="sm"><Icon name="upload" size={12} />Parcourir</Btn>
  </div>
);

const TemplateCard = ({ t }) => (
  <div style={{
    padding: 12, borderRadius: 12, cursor: "pointer", position: "relative",
    border: `1px solid ${t.featured ? "var(--cv1-border-emphasis)" : "var(--studio-line)"}`,
    background: t.featured ? "#eef2ff" : "#fff",
  }}>
    {t.featured && (
      <span style={{
        position: "absolute", top: -8, right: 10,
        background: "var(--cv1-border-emphasis)", color: "#fff",
        fontSize: 9.5, fontWeight: 700, padding: "2px 8px", borderRadius: 999,
        letterSpacing: 0.04, textTransform: "uppercase",
      }}>Recommandé</span>
    )}
    <div className="studio-eyebrow" style={{ fontSize: 9.5, color: t.featured ? "var(--cv1-border-emphasis)" : "var(--studio-ink-mute)" }}>
      {t.kicker}
    </div>
    <div style={{ fontSize: 14, fontWeight: 700, marginTop: 4 }}>{t.name}</div>
    <div style={{ fontSize: 11.5, color: "var(--studio-ink-soft)", marginTop: 4, lineHeight: 1.35 }}>{t.desc}</div>
    <div style={{
      marginTop: 10, paddingTop: 8, borderTop: "1px dashed var(--studio-line)",
      display: "flex", alignItems: "center", gap: 6, fontSize: 11, color: "var(--studio-ink-mute)",
    }}>
      <Icon name="package" size={12} />
      {t.steps === 0 ? "Aucune étape" : `${t.steps} étapes prêtes`}
    </div>
  </div>
);

const ProductPreviewCard = () => (
  <div style={{
    border: "1px solid var(--studio-line)", borderRadius: 12, overflow: "hidden", background: "#fff",
  }}>
    <div style={{
      height: 90, background: "linear-gradient(180deg, #fef3c7, #fde68a)",
      display: "flex", alignItems: "center", justifyContent: "center", position: "relative",
    }}>
      <ProductThumb kind="tacos-m" size={64} />
      <div style={{ position: "absolute", top: 8, left: 8 }}>
        <Pill status="draft">Brouillon</Pill>
      </div>
    </div>
    <div style={{ padding: 12, display: "flex", flexDirection: "column", gap: 4 }}>
      <Chip>Nos Tacos</Chip>
      <div style={{ fontSize: 14, fontWeight: 700 }}>Tacos M (1 viande)</div>
      <div style={{ fontSize: 12, color: "var(--studio-ink-soft)" }}>
        Tortilla maison, 1 viande au choix, sauces et crudités…
      </div>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginTop: 6 }}>
        <span style={{ fontSize: 14, fontWeight: 700 }}>6,50 €</span>
        <Switch on={true} />
      </div>
    </div>
  </div>
);

const RecapRow = ({ k, v, highlight }) => (
  <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
    <span style={{ width: 80, color: "var(--studio-ink-mute)", fontSize: 11.5 }}>{k}</span>
    <span style={{
      fontWeight: 600,
      color: highlight ? "var(--cv1-border-emphasis)" : "var(--studio-ink)",
    }}>{v}</span>
  </div>
);

window.Studio2 = Studio2;

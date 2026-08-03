/* studio-data.jsx — fixtures + shared primitives */

/* =========================================================================
   FIXTURES — wire into ComposerStepResource[] / Item[] etc. on integration.
   ========================================================================= */

const STUDIO_BRANCHES = [
  { id: 1, name: "Filiale #1 — Le Cay", code: "LCY" },
  { id: 2, name: "Filiale #2 — Centre", code: "CTR" },
  { id: 3, name: "Filiale #3 — Gare", code: "GAR" },
];

const STUDIO_CATEGORIES = [
  { id: "tacos",    name: "Nos Tacos",       count: 8,  selected: true },
  { id: "sandwich", name: "Nos Sandwichs",   count: 6 },
  { id: "burger",   name: "Nos Burgers",     count: 7 },
  { id: "assiette", name: "Nos Assiettes",   count: 5 },
  { id: "ojja",     name: "Ojja",            count: 3 },
  { id: "omelette", name: "Omelettes",       count: 4 },
  { id: "salade",   name: "Nos Salades",     count: 4 },
  { id: "poulet",   name: "Poulet croustillant", count: 5 },
  { id: "enfants",  name: "Menus Enfants",   count: 3, hidden: true },
  { id: "frites",   name: "Frites & Accompagnements", count: 6 },
  { id: "dessert",  name: "Nos Desserts",    count: 5 },
  { id: "boisson",  name: "Nos Boissons",    count: 12 },
];

// Tacos products (selected category)
const STUDIO_PRODUCTS = [
  { id: 101, name: "Tacos M (1 viande)",   price: "6,50 €", status: "active", img: "tacos-m",  reason: null,           wizard: "v3", profile_id: 901 },
  { id: 102, name: "Tacos L (2 viandes)",  price: "8,50 €", status: "active", img: "tacos-l",  reason: null,           wizard: "v3", profile_id: 902 },
  { id: 103, name: "Tacos XL (3 viandes)", price: "10,50 €", status: "active", img: "tacos-xl", reason: null,           wizard: "v3", profile_id: 903 },
  { id: 104, name: "Tacos XXL (4 viandes)",price: "12,50 €", status: "draft",  img: "tacos-xxl",reason: null,           wizard: "draft", profile_id: 904 },
  { id: 105, name: "Le Méga",              price: "8,00 €", status: "86",     img: "mega",     reason: "out_of_stock", wizard: "v2", profile_id: 905 },
  { id: 106, name: "Le Terminator",        price: "9,00 €", status: "active", img: "terminator", reason: null,         wizard: "v4", profile_id: 906 },
  { id: 107, name: "Le Suprême",           price: "7,00 €", status: "active", img: "supreme",  reason: null,           wizard: "v3", profile_id: 907 },
  { id: 108, name: "Le Classique",         price: "5,50 €", status: "active", img: "classique",reason: null,           wizard: "v2", profile_id: 908 },
];

// Wizard steps for "Tacos M (1 viande)" — tacos template, prefilled.
// Schema mirrors ComposerStepResource exactly.
const TACOS_STEPS = [
  {
    id: 1, profile_id: 901, step_key: "taille",        label: "Choisis la taille",
    source_type: "item_attribute", source_ref: "size",     source_item_attribute_id: 11,
    min_select: 1, max_select: 1, allow_repeat: false,
    visible_on: ["pos", "kiosk"], stockable_choices: false,
    position: 1, is_active: true, addon_role: null,
    options: ["M", "L", "XL", "XXL"],
  },
  {
    id: 2, profile_id: 901, step_key: "viande",        label: "Choisis ta viande",
    source_type: "item_attribute", source_ref: "viande",   source_item_attribute_id: 12,
    min_select: 1, max_select: 1, allow_repeat: false,
    visible_on: ["pos", "kiosk"], stockable_choices: true,
    position: 2, is_active: true, addon_role: null,
    options: ["Merguez", "Kefta", "Mexicain", "Cordon Bleu", "Poulet"],
  },
  {
    id: 3, profile_id: 901, step_key: "sauce",         label: "Choisis ta sauce",
    source_type: "item_attribute", source_ref: "sauce",    source_item_attribute_id: 13,
    min_select: 1, max_select: 2, allow_repeat: false,
    visible_on: ["pos", "kiosk"], stockable_choices: false,
    position: 3, is_active: true, addon_role: null,
    options: ["Algérienne", "Blanche", "Samouraï", "Andalouse", "Harissa", "Curry", "Ketchup", "Mayo", "BBQ"],
  },
  {
    id: 4, profile_id: 901, step_key: "crudites",      label: "Choisis tes crudités",
    source_type: "extra_group", source_ref: "crudites_group", source_item_attribute_id: null,
    min_select: 0, max_select: 6, allow_repeat: false,
    visible_on: ["pos", "kiosk"], stockable_choices: false,
    position: 4, is_active: true, addon_role: null,
    options: ["Salade", "Tomate", "Oignon", "Cornichons", "Olives", "Maïs"],
  },
  {
    id: 5, profile_id: 901, step_key: "accompagnement",label: "Choisis ton accompagnement",
    source_type: "addon", source_ref: "side_addon", source_item_attribute_id: null,
    min_select: 0, max_select: 1, allow_repeat: false,
    visible_on: ["pos", "kiosk"], stockable_choices: true,
    position: 5, is_active: true, addon_role: "side",
    options: ["Frites", "Potatoes", "Salade verte", "Sans accompagnement"],
  },
  {
    id: 6, profile_id: 901, step_key: "boisson",       label: "Choisis ta boisson",
    source_type: "addon", source_ref: "drink_addon", source_item_attribute_id: null,
    min_select: 0, max_select: 1, allow_repeat: false,
    visible_on: ["pos", "kiosk"], stockable_choices: true,
    position: 6, is_active: true, addon_role: "drink",
    options: ["Coca 33cl", "Coca Zero", "Fanta", "Oasis", "Eau plate", "Sans boisson"],
  },
];

const STUDIO_TEMPLATES = [
  { id: "simple",   name: "Produit simple",  desc: "Pas d'étape — vente directe",       steps: 0, kicker: "Snacking, boissons" },
  { id: "sandwich", name: "Sandwich",        desc: "Pain · viande · sauce · garnitures",steps: 5, kicker: "Tacos, kebabs, sandwichs" },
  { id: "tacos",    name: "Tacos",           desc: "Taille · viandes · sauces · crudités · sup. · menu", steps: 6, kicker: "Tacos signature", featured: true },
  { id: "assiette", name: "Assiette",        desc: "Viande · sauce · garnitures",       steps: 3, kicker: "Plats à emporter" },
  { id: "snacking", name: "Snacking",        desc: "Suppléments uniquement",            steps: 1, kicker: "Frites, nuggets" },
  { id: "menu",     name: "Menu",            desc: "Plat · boisson · dessert",          steps: 3, kicker: "Formules complètes" },
  { id: "custom",   name: "Personnalisé",    desc: "Pars d'une page blanche",           steps: 0, kicker: "Cas particuliers" },
];

const STOCK_AUTO86 = [
  { id: "ev1", product: "Le Méga",      branch: "Filiale #1", reason: "Stock atteint 0", at: "il y a 12 min" },
  { id: "ev2", product: "Coca 33cl",    branch: "Filiale #1", reason: "Quota journalier épuisé", at: "il y a 47 min" },
  { id: "ev3", product: "Cordon Bleu",  branch: "Filiale #2", reason: "Marqué indisponible par Yann", at: "il y a 2h" },
];

const STOCK_LOW = [
  { id: "lo1", product: "Merguez",  qty: 4,  threshold: 8, branch: "Filiale #1" },
  { id: "lo2", product: "Kefta",    qty: 6,  threshold: 10, branch: "Filiale #1" },
  { id: "lo3", product: "Frites",   qty: 12, threshold: 20, branch: "Filiale #1" },
];

/* =========================================================================
   PRIMITIVES — small visual atoms
   ========================================================================= */

function Pill({ children, status }) {
  return (
    <span className="studio-pill" data-status={status || undefined}>
      {status && <span className="dot" />}
      {children}
    </span>
  );
}

function Chip({ children, src, channel }) {
  return (
    <span className="studio-chip" data-src={src} data-channel={channel}>{children}</span>
  );
}

function Btn({ children, variant, size, block, ...rest }) {
  const cls = ["studio-btn"];
  if (variant === "primary") cls.push("studio-btn--primary");
  if (variant === "brand")   cls.push("studio-btn--brand");
  if (variant === "ghost")   cls.push("studio-btn--ghost");
  if (size === "sm")         cls.push("studio-btn--sm");
  if (size === "lg")         cls.push("studio-btn--lg");
  if (block)                 cls.push("studio-btn--block");
  return <button className={cls.join(" ")} {...rest}>{children}</button>;
}

function Switch({ on, reason }) {
  return <span className="studio-switch" data-on={on} data-reason={reason || undefined} role="switch" aria-checked={on} tabIndex={0} />;
}

function Icon({ name, size = 16, stroke = 1.75 }) {
  // Minimal lucide-style line icons inline (avoid external dep at preview time)
  const s = size;
  const props = { width: s, height: s, viewBox: "0 0 24 24", fill: "none", stroke: "currentColor", strokeWidth: stroke, strokeLinecap: "round", strokeLinejoin: "round", "aria-hidden": true };
  switch (name) {
    case "search":    return <svg {...props}><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>;
    case "plus":      return <svg {...props}><path d="M12 5v14M5 12h14"/></svg>;
    case "minus":     return <svg {...props}><path d="M5 12h14"/></svg>;
    case "x":         return <svg {...props}><path d="M18 6 6 18M6 6l12 12"/></svg>;
    case "check":     return <svg {...props}><path d="m20 6-11 11-5-5"/></svg>;
    case "chevron-d": return <svg {...props}><path d="m6 9 6 6 6-6"/></svg>;
    case "chevron-r": return <svg {...props}><path d="m9 6 6 6-6 6"/></svg>;
    case "chevron-l": return <svg {...props}><path d="m15 6-6 6 6 6"/></svg>;
    case "drag":      return <svg {...props}><circle cx="9" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="18" r="1"/></svg>;
    case "more":      return <svg {...props}><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/></svg>;
    case "edit":      return <svg {...props}><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4Z"/></svg>;
    case "trash":     return <svg {...props}><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>;
    case "copy":      return <svg {...props}><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>;
    case "filter":    return <svg {...props}><path d="M3 6h18M6 12h12M10 18h4"/></svg>;
    case "sort":      return <svg {...props}><path d="M3 6h13M3 12h9M3 18h5"/></svg>;
    case "store":     return <svg {...props}><path d="M3 9 4 4h16l1 5"/><path d="M3 9v11h18V9"/><path d="M3 9h18"/></svg>;
    case "image":     return <svg {...props}><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/></svg>;
    case "upload":    return <svg {...props}><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/></svg>;
    case "package":   return <svg {...props}><path d="M16.5 9.4 7.55 4.24"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.27 6.96 8.73 5.05 8.73-5.05"/><path d="M12 22.08V12"/></svg>;
    case "alert":     return <svg {...props}><path d="m12 2 10 18H2L12 2Z"/><path d="M12 9v4M12 17h.01"/></svg>;
    case "info":      return <svg {...props}><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>;
    case "check-c":   return <svg {...props}><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>;
    case "x-c":       return <svg {...props}><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg>;
    case "monitor":   return <svg {...props}><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>;
    case "tablet":    return <svg {...props}><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M12 18h.01"/></svg>;
    case "globe":     return <svg {...props}><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 0 20M12 2a15.3 15.3 0 0 0 0 20"/></svg>;
    case "burger":    return <svg {...props}><path d="M3 11h18M3 16h18M5 7h14"/></svg>;
    case "cup":       return <svg {...props}><path d="M6 3h12l-1 17a2 2 0 0 1-2 2H9a2 2 0 0 1-2-2Z"/><path d="M6 8h12"/></svg>;
    case "cookie":    return <svg {...props}><path d="M21 12a9 9 0 1 1-9-9c0 1.5.5 2.5 2 3s2 2 1 4 1 3 3 3 3 0 3-1Z"/></svg>;
    case "tag":       return <svg {...props}><path d="M20.59 13.41 13.42 20.58a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82Z"/><circle cx="7" cy="7" r="1"/></svg>;
    case "star":      return <svg {...props}><path d="m12 2 3.09 6.26L22 9.27l-5 4.87L18.18 22 12 18.27 5.82 22 7 14.14 2 9.27l6.91-1.01Z"/></svg>;
    case "settings":  return <svg {...props}><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.6 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9c.36.13.7.34 1 .6"/></svg>;
    case "eye":       return <svg {...props}><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></svg>;
    case "eye-off":   return <svg {...props}><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A11 11 0 0 1 12 5c6.5 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.5 13.5 0 0 0 2 12s3.5 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><path d="m2 2 20 20"/></svg>;
    case "refresh":   return <svg {...props}><path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M3 21v-5h5"/></svg>;
    case "publish":   return <svg {...props}><path d="M12 19V5"/><path d="m5 12 7-7 7 7"/></svg>;
    case "git":       return <svg {...props}><circle cx="12" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="18" r="3"/><path d="M12 9v6M6 15a6 6 0 0 1 6-6 6 6 0 0 1 6 6"/></svg>;
    case "lock":      return <svg {...props}><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>;
    case "clock":     return <svg {...props}><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>;
    case "leaf":      return <svg {...props}><path d="M11 20A7 7 0 0 1 4 13c0-7 7-11 17-11 0 9-4 17-11 17"/><path d="M2 22s5-7 9-9"/></svg>;
    case "fries":     return <svg {...props}><path d="M5 9h14l-1.5 12h-11Z"/><path d="M7 9V5h2v4M11 9V3h2v6M15 9V6h2v3"/></svg>;
    case "menu":      return <svg {...props}><path d="M4 6h16M4 12h16M4 18h16"/></svg>;
    case "arrow-r":   return <svg {...props}><path d="M5 12h14M12 5l7 7-7 7"/></svg>;
    case "tap":       return <svg {...props}><path d="M9 11V6a3 3 0 0 1 6 0v8"/><path d="m9 11-3 3 5 7h8l1-7-3-3"/></svg>;
    case "trend":     return <svg {...props}><path d="m22 7-8.5 8.5-5-5L2 17"/><path d="M16 7h6v6"/></svg>;
    default: return <svg {...props}><circle cx="12" cy="12" r="9"/></svg>;
  }
}

/* Tiny SVG "photo" — flat illustrated thumbnail per product */
function ProductThumb({ kind, size = 64, round }) {
  const r = round ? "999px" : "10px";
  const palettes = {
    "tacos-m":   { bg: "#FFE0B2", a: "#D97706", b: "#92400E" },
    "tacos-l":   { bg: "#FED7AA", a: "#EA580C", b: "#9A3412" },
    "tacos-xl":  { bg: "#FECACA", a: "#DC2626", b: "#7F1D1D" },
    "tacos-xxl": { bg: "#FCA5A5", a: "#B91C1C", b: "#450A0A" },
    "mega":      { bg: "#1F2937", a: "#F59E0B", b: "#FBBF24" },
    "terminator":{ bg: "#0B1220", a: "#E11D48", b: "#FDA4AF" },
    "supreme":   { bg: "#FDE68A", a: "#92400E", b: "#451A03" },
    "classique": { bg: "#FEF3C7", a: "#B45309", b: "#78350F" },
    "merguez":   { bg: "#FDA4AF", a: "#9F1239", b: "#4C0519" },
    "kefta":     { bg: "#FCD34D", a: "#92400E", b: "#451A03" },
    "mexicain":  { bg: "#86EFAC", a: "#15803D", b: "#14532D" },
    "cordon":    { bg: "#FEF08A", a: "#A16207", b: "#713F12" },
    "frites":    { bg: "#FEF3C7", a: "#D97706", b: "#92400E" },
    "coca":      { bg: "#1F2937", a: "#DC2626", b: "#FCA5A5" },
    "salade":    { bg: "#BBF7D0", a: "#15803D", b: "#14532D" },
  };
  const p = palettes[kind] || { bg: "#E2E8F0", a: "#64748B", b: "#1E293B" };
  return (
    <div style={{
      width: size, height: size, borderRadius: r,
      background: p.bg, display: "flex", alignItems: "center", justifyContent: "center",
      overflow: "hidden", flexShrink: 0, position: "relative",
    }}>
      <svg width={size * 0.7} height={size * 0.7} viewBox="0 0 40 40" fill="none">
        <ellipse cx="20" cy="28" rx="14" ry="3" fill={p.b} opacity="0.18"/>
        <path d="M6 22 Q20 8 34 22 L34 26 Q20 30 6 26 Z" fill={p.a}/>
        <path d="M9 23 Q20 14 31 23" stroke={p.b} strokeWidth="1.5" fill="none" opacity="0.5"/>
        <circle cx="14" cy="20" r="1.5" fill={p.b} opacity="0.6"/>
        <circle cx="26" cy="20" r="1.5" fill={p.b} opacity="0.6"/>
        <circle cx="20" cy="18" r="1" fill={p.b} opacity="0.6"/>
      </svg>
    </div>
  );
}

/* expose */
Object.assign(window, {
  STUDIO_BRANCHES, STUDIO_CATEGORIES, STUDIO_PRODUCTS, TACOS_STEPS, STUDIO_TEMPLATES,
  STOCK_AUTO86, STOCK_LOW,
  Pill, Chip, Btn, Switch, Icon, ProductThumb,
});

// icons.jsx — Custom hand-drawn-feel iconography for Le Cayenne
// All 24x24 by default, stroke-based, currentColor

const Icon = ({ d, size = 24, fill = 'none', stroke = 'currentColor', sw = 2, children, viewBox = '0 0 24 24' }) => (
  <svg width={size} height={size} viewBox={viewBox} fill={fill} stroke={stroke} strokeWidth={sw} strokeLinecap="round" strokeLinejoin="round">
    {d ? <path d={d} /> : children}
  </svg>
);

const I = {
  Home: (p) => <Icon {...p}><path d="M3 11l9-7 9 7v9a1 1 0 01-1 1h-5v-6h-6v6H4a1 1 0 01-1-1v-9z"/></Icon>,
  Menu: (p) => <Icon {...p}><path d="M5 3v8a3 3 0 003 3v7"/><path d="M11 3v8a3 3 0 01-3 3"/><path d="M8 3v6"/><path d="M17 3l-2 8h4l-2-8zM17 11v10"/></Icon>,
  Receipt: (p) => <Icon {...p}><path d="M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2V3z"/><path d="M9 8h6M9 12h6M9 16h4"/></Icon>,
  User: (p) => <Icon {...p}><circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 016-6h4a6 6 0 016 6v1"/></Icon>,
  Heart: (p) => <Icon {...p}><path d="M12 21s-7-4.5-9-9a5 5 0 019-3 5 5 0 019 3c-2 4.5-9 9-9 9z"/></Icon>,
  HeartFilled: (p) => <Icon {...p} fill="currentColor"><path d="M12 21s-7-4.5-9-9a5 5 0 019-3 5 5 0 019 3c-2 4.5-9 9-9 9z"/></Icon>,
  Search: (p) => <Icon {...p}><circle cx="11" cy="11" r="7"/><path d="M21 21l-5-5"/></Icon>,
  Back: (p) => <Icon {...p}><path d="M15 6l-6 6 6 6"/></Icon>,
  Close: (p) => <Icon {...p}><path d="M6 6l12 12M6 18L18 6"/></Icon>,
  Arrow: (p) => <Icon {...p}><path d="M5 12h14M13 6l6 6-6 6"/></Icon>,
  Check: (p) => <Icon {...p}><path d="M5 12l5 5L20 7"/></Icon>,
  Plus: (p) => <Icon {...p}><path d="M12 5v14M5 12h14"/></Icon>,
  Minus: (p) => <Icon {...p}><path d="M5 12h14"/></Icon>,
  Clock: (p) => <Icon {...p}><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></Icon>,
  Bag: (p) => <Icon {...p}><path d="M5 7h14l-1 13a1 1 0 01-1 1H7a1 1 0 01-1-1L5 7z"/><path d="M9 7V5a3 3 0 016 0v2"/></Icon>,
  Star: (p) => <Icon {...p}><path d="M12 3l3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1 3-6z"/></Icon>,
  StarFilled: (p) => <Icon {...p} fill="currentColor"><path d="M12 3l3 6 7 1-5 5 1 7-6-3-6 3 1-7-5-5 7-1 3-6z"/></Icon>,
  Pin: (p) => <Icon {...p}><path d="M12 21s-7-7.5-7-12a7 7 0 0114 0c0 4.5-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/></Icon>,
  Bell: (p) => <Icon {...p}><path d="M6 9a6 6 0 0112 0c0 7 3 8 3 8H3s3-1 3-8z"/><path d="M10 21a2 2 0 004 0"/></Icon>,
  Card: (p) => <Icon {...p}><rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/></Icon>,
  Shield: (p) => <Icon {...p}><path d="M12 3l8 3v6c0 5-4 8-8 9-4-1-8-4-8-9V6l8-3z"/></Icon>,
  Globe: (p) => <Icon {...p}><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 010 18M12 3a14 14 0 000 18"/></Icon>,
  Logout: (p) => <Icon {...p}><path d="M9 4H5a1 1 0 00-1 1v14a1 1 0 001 1h4M16 8l4 4-4 4M20 12H10"/></Icon>,
  Chevron: (p) => <Icon {...p}><path d="M9 6l6 6-6 6"/></Icon>,
  ChevronDown: (p) => <Icon {...p}><path d="M6 9l6 6 6-6"/></Icon>,
  Fire: (p) => <Icon {...p}><path d="M12 3s4 4 4 8a4 4 0 11-8 0c0-2 1-3 1-3s-1 5 3 5c2 0 3-2 3-3 0-3-3-7-3-7zM6 14a6 6 0 1012 0"/></Icon>,
  Pepper: (p) => <Icon {...p}><path d="M14 4l2-1 1 1-2 2c2 4-1 11-7 13-3 1-5-1-4-4 1-2 4-3 6-2 1 1 2 0 3-2 1-3 1-7 1-7z"/></Icon>,
  Filter: (p) => <Icon {...p}><path d="M4 6h16M7 12h10M10 18h4"/></Icon>,
  Tag: (p) => <Icon {...p}><path d="M3 12V4a1 1 0 011-1h8l9 9-9 9-9-9z"/><circle cx="8" cy="8" r="1.5" fill="currentColor"/></Icon>,
  Gift: (p) => <Icon {...p}><rect x="3" y="9" width="18" height="11" rx="1"/><path d="M3 13h18M12 9v11M12 9c-3 0-5-2-5-3.5S9 4 12 9zM12 9c3 0 5-2 5-3.5S15 4 12 9z"/></Icon>,
  QR: (p) => <Icon {...p}><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3M21 17v4h-7M14 21h0"/></Icon>,
  Phone: (p) => <Icon {...p}><path d="M5 4h4l2 5-3 2a11 11 0 005 5l2-3 5 2v4a2 2 0 01-2 2A17 17 0 013 6a2 2 0 012-2z"/></Icon>,
  Truck: (p) => <Icon {...p}><rect x="2" y="7" width="11" height="9"/><path d="M13 10h5l3 3v3h-8M6 19a2 2 0 100-4 2 2 0 000 4zM18 19a2 2 0 100-4 2 2 0 000 4z"/></Icon>,
  Store: (p) => <Icon {...p}><path d="M3 9V7l2-4h14l2 4v2a3 3 0 01-6 0 3 3 0 01-6 0 3 3 0 01-6 0z"/><path d="M5 11v9h14v-9"/><path d="M10 20v-5h4v5"/></Icon>,
  Sparkle: (p) => <Icon {...p}><path d="M12 3v6M12 15v6M3 12h6M15 12h6M6 6l3 3M15 15l3 3M6 18l3-3M15 9l3-3"/></Icon>,
  Settings: (p) => <Icon {...p}><circle cx="12" cy="12" r="3"/><path d="M19 12a7 7 0 00-.1-1.2l2-1.5-2-3.5-2.4.8a7 7 0 00-2-1.2L14 3h-4l-.5 2.4a7 7 0 00-2 1.2l-2.4-.8-2 3.5 2 1.5a7 7 0 000 2.4l-2 1.5 2 3.5 2.4-.8a7 7 0 002 1.2L10 21h4l.5-2.4a7 7 0 002-1.2l2.4.8 2-3.5-2-1.5a7 7 0 00.1-1.2z"/></Icon>,
  Trash: (p) => <Icon {...p}><path d="M4 7h16M9 7V4h6v3M6 7l1 13a1 1 0 001 1h8a1 1 0 001-1l1-13M10 11v6M14 11v6"/></Icon>,
};

window.I = I;
window.LCIcon = Icon;

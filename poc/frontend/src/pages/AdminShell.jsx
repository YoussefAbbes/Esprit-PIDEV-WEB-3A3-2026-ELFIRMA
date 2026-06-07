import { Outlet, NavLink, useLocation } from 'react-router-dom';
import { motion } from 'framer-motion';
import AdminBackgroundScene from '../scenes/AdminBackgroundScene.jsx';
import ErrorBoundary from '../ui/ErrorBoundary.jsx';

const NAV = [
  {
    label: 'Overview',
    items: [
      { to: '/admin',             icon: 'dashboard', text: 'Dashboard',  pill: { kind: 'green', text: 'Live' }, end: true },
      { to: '/admin/analytics',   icon: 'analytics', text: 'Analytics' },
      { to: '/admin/notifications', icon: 'notifications', text: 'Notifications', pill: { kind: 'red', text: '4' } },
    ],
  },
  {
    label: 'Farm',
    items: [
      { to: '/admin/parcelles', icon: 'map',         text: 'Parcelles' },
      { to: '/admin/cultures',  icon: 'grass',       text: 'Cultures' },
      { to: '/admin/irrigation',icon: 'water_drop',  text: 'Irrigation' },
      { to: '/admin/livestock', icon: 'pets',        text: 'Livestock', pill: { kind: 'green', text: '3D' } },
      { to: '/admin/vaccination', icon: 'vaccines',  text: 'Vaccination' },
    ],
  },
  {
    label: 'Operations',
    items: [
      { to: '/admin/equipment',   icon: 'construction', text: 'Equipment' },
      { to: '/admin/maintenance', icon: 'build',        text: 'Maintenance', pill: { kind: 'red', text: '2' } },
      { to: '/admin/products',    icon: 'inventory_2',  text: 'Products' },
      { to: '/admin/orders',      icon: 'shopping_cart',text: 'Orders' },
      { to: '/admin/suppliers',   icon: 'handshake',    text: 'Suppliers' },
    ],
  },
  {
    label: 'Intelligence',
    items: [
      { to: '/admin/chatbot',     icon: 'smart_toy',  text: 'AI Chatbot',   pill: { kind: 'green', text: 'AI' } },
      { to: '/admin/predictions', icon: 'psychology', text: 'Predictions' },
      { to: '/admin/models3d',    icon: 'view_in_ar', text: '3D Models',    pill: { kind: 'green', text: 'New' } },
    ],
  },
];

// Page-meta hash used by Topbar to look up h1 / subtitle from the route.
const PAGE_META = {
  '/admin':              { title: 'Dashboard',    sub: 'Farm overview' },
  '/admin/parcelles':    { title: 'Parcelles',    sub: 'Field & soil management' },
  '/admin/livestock':    { title: 'Livestock',    sub: 'Herd health & 3D habitats' },
  '/admin/maintenance':  { title: 'Maintenance',  sub: 'Predictive equipment alerts' },
};

function Sidebar() {
  return (
    <aside className="sidebar">
      <div className="sidebar-header">
        <a href="/admin" className="brand">
          <div className="brand-icon">🌾</div>
          <div className="brand-text">El <span>Firma</span></div>
        </a>
        <div className="sidebar-badge">
          <div className="dot" />
          All systems online
        </div>
      </div>

      {NAV.map((section) => (
        <div key={section.label} className="nav-section">
          <div className="nav-label">{section.label}</div>
          {section.items.map((it) => (
            <NavLink
              key={it.to}
              to={it.to}
              end={it.end}
              className={({ isActive }) => 'nav-item' + (isActive ? ' active' : '')}
            >
              <span className="nav-icon">{it.icon}</span>
              {it.text}
              {it.pill && <span className={`nav-pill ${it.pill.kind}`}>{it.pill.text}</span>}
            </NavLink>
          ))}
        </div>
      ))}

      <div className="sidebar-foot">
        <div className="user-card">
          <div className="avatar">YA</div>
          <div className="u-info">
            <div className="u-name">Youssef Abbes</div>
            <div className="u-role">Administrator</div>
          </div>
          <span className="nav-icon" style={{ color: 'var(--muted)' }}>logout</span>
        </div>
      </div>
    </aside>
  );
}

function Topbar() {
  const { pathname } = useLocation();
  const meta = PAGE_META[pathname] || { title: 'Page', sub: '' };
  const today = new Date().toLocaleDateString('en-GB', {
    weekday: 'long', day: '2-digit', month: 'long', year: 'numeric',
  });
  return (
    <div className="topbar">
      <div>
        <h1>{meta.title}</h1>
        <p>{today} · {meta.sub}</p>
      </div>
      <div className="topbar-actions">
        <div className="searchbar">
          <span className="nav-icon" style={{ fontSize: '1rem', color: 'var(--muted)' }}>search</span>
          <input placeholder="Search anything..." />
        </div>
        <div className="icon-btn">
          <span className="nav-icon">notifications</span>
          <div className="notif-dot" />
        </div>
        <div className="icon-btn"><span className="nav-icon">help</span></div>
        <div className="avatar" style={{ cursor: 'pointer' }}>YA</div>
      </div>
    </div>
  );
}

function AnimatedOutlet() {
  const { pathname } = useLocation();
  return (
    <motion.div
      key={pathname}
      initial={{ opacity: 0, y: 16 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.4, ease: [0.22, 1, 0.36, 1] }}
    >
      <ErrorBoundary>
        <Outlet />
      </ErrorBoundary>
    </motion.div>
  );
}

export default function AdminShell() {
  return (
    <div className="admin-root">
      <AdminBackgroundScene />
      <main className="admin-main">
        <Topbar />
        <AnimatedOutlet />
      </main>
      <Sidebar />
    </div>
  );
}

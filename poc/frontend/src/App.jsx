import { Routes, Route, Link, useLocation } from 'react-router-dom';
import PublicLanding from './pages/PublicLanding.jsx';
import AdminShell from './pages/AdminShell.jsx';
import Dashboard from './pages/Dashboard.jsx';
import Parcelles from './pages/Parcelles.jsx';
import Livestock from './pages/Livestock.jsx';
import Maintenance from './pages/Maintenance.jsx';
import ComingSoon from './pages/ComingSoon.jsx';

// Every sidebar link gets a route. Implemented pages render their
// real component, everything else renders <ComingSoon /> with a
// route-specific title (see ComingSoon.jsx).
const STUB_ROUTES = [
  'analytics', 'notifications',
  'cultures', 'irrigation', 'vaccination',
  'equipment', 'products', 'orders', 'suppliers',
  'chatbot', 'predictions', 'models3d',
];

export default function App() {
  const { pathname } = useLocation();
  const isAdmin = pathname.startsWith('/admin');

  return (
    <>
      <div className="dev-switcher">
        <Link to="/" className={!isAdmin ? 'active' : ''}>Public</Link>
        <Link to="/admin" className={isAdmin ? 'active' : ''}>Admin</Link>
      </div>

      <Routes>
        <Route path="/" element={<PublicLanding />} />

        <Route path="/admin" element={<AdminShell />}>
          <Route index element={<Dashboard />} />
          <Route path="parcelles" element={<Parcelles />} />
          <Route path="livestock" element={<Livestock />} />
          <Route path="maintenance" element={<Maintenance />} />
          {STUB_ROUTES.map((p) => (
            <Route key={p} path={p} element={<ComingSoon />} />
          ))}
          {/* Anything else under /admin → ComingSoon as well */}
          <Route path="*" element={<ComingSoon />} />
        </Route>
      </Routes>
    </>
  );
}

import { useLocation } from 'react-router-dom';
import { motion } from 'framer-motion';

const TITLES = {
  '/admin/analytics':     ['Analytics',      'Aggregate KPIs across every module'],
  '/admin/notifications': ['Notifications',  'System events, alerts and SMS history'],
  '/admin/cultures':      ['Cultures',       'Crop lifecycle from planting to harvest'],
  '/admin/irrigation':    ['Irrigation',     'AUTO / MANUAL command + event history'],
  '/admin/vaccination':   ['Vaccination',    'Calendar, SMS alerts, vet schedule'],
  '/admin/equipment':     ['Equipment',      'Tractors, pumps, sensors inventory'],
  '/admin/products':      ['Products',       'Catalog, images, categories, pricing'],
  '/admin/orders':        ['Orders',         'Commandes pipeline + Stripe payments'],
  '/admin/suppliers':     ['Suppliers',      'Fournisseurs, contracts, performance scoring'],
  '/admin/chatbot':       ['AI Chatbot',     'Gemini RAG · agricultural Q&A'],
  '/admin/predictions':   ['Predictions',    'ML maintenance forecasts + crop recommender'],
  '/admin/models3d':      ['3D Models',      'Tripo3D-generated livestock habitats'],
};

export default function ComingSoon() {
  const { pathname } = useLocation();
  const [title, sub] = TITLES[pathname] || ['Coming soon', 'This module is part of the next sprint'];

  return (
    <div className="content" style={{ display: 'grid', placeItems: 'center', minHeight: '70vh' }}>
      <motion.div
        initial={{ opacity: 0, y: 20 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.6, ease: [0.22, 1, 0.36, 1] }}
        style={{ textAlign: 'center', maxWidth: 480 }}
      >
        <motion.div
          initial={{ scale: 0.7, opacity: 0 }}
          animate={{ scale: 1, opacity: 1 }}
          transition={{ delay: 0.2, type: 'spring', stiffness: 180 }}
          style={{
            width: 72, height: 72,
            margin: '0 auto 1.5rem',
            borderRadius: 20,
            background: 'var(--glow-dim)',
            border: '1px solid var(--border-h)',
            display: 'grid', placeItems: 'center',
            fontSize: '2rem',
          }}
        >
          <span className="nav-icon" style={{ fontSize: '2rem', color: 'var(--glow)' }}>schedule</span>
        </motion.div>
        <h2 style={{ fontFamily: 'var(--font-d)', fontSize: '2.5rem', fontWeight: 400, marginBottom: '0.75rem' }}>
          {title}
        </h2>
        <p style={{ color: 'var(--muted)', fontSize: '0.9rem', lineHeight: 1.7, marginBottom: '2rem' }}>
          {sub}.<br />
          Wire your existing Symfony controller into <code style={{ color: 'var(--glow)' }}>src/api.js</code> to bring this page online.
        </p>
        <div style={{
          display: 'inline-flex', alignItems: 'center', gap: '0.5rem',
          padding: '0.4rem 1rem',
          background: 'var(--surface)',
          border: '1px solid var(--border)',
          borderRadius: 30,
          fontSize: '0.7rem',
          letterSpacing: '0.15em',
          textTransform: 'uppercase',
          color: 'var(--muted)',
        }}>
          <span style={{
            width: 6, height: 6, borderRadius: 50,
            background: 'var(--warning)',
            boxShadow: '0 0 6px var(--warning)',
          }} />
          Stub route
        </div>
      </motion.div>
    </div>
  );
}

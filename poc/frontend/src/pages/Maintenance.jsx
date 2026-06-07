import { useEffect, useState } from 'react';
import { motion } from 'framer-motion';
import { fetchMaintenances } from '../api.js';

export default function Maintenance() {
  const [items, setItems] = useState([]);
  useEffect(() => { fetchMaintenances().then(setItems); }, []);

  const counts = items.reduce((a, m) => { a[m.statut] = (a[m.statut] || 0) + 1; return a; }, {});

  return (
    <div className="content">
      <div className="page-head">
        <div>
          <h2>Maintenance</h2>
          <p>Predictive scheduling · {items.length} scheduled tasks</p>
        </div>
      </div>

      <div className="kpi-grid">
        {['planifie', 'en_cours', 'en_attente', 'termine'].map((k, i) => (
          <motion.div
            key={k}
            className="kpi"
            initial={{ opacity: 0, y: 30 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: i * 0.08, duration: 0.5 }}
          >
            <div className="kpi-top">
              <div className={`kpi-icon ${
                k === 'en_cours' ? 'orange' :
                k === 'termine' ? 'green' :
                k === 'planifie' ? 'blue' : 'wheat'
              }`}>
                <span className="nav-icon">
                  {k === 'en_cours' ? 'build_circle' : k === 'termine' ? 'task_alt' : k === 'planifie' ? 'event' : 'schedule'}
                </span>
              </div>
            </div>
            <div className="kpi-v">{counts[k] || 0}</div>
            <div className="kpi-l">{k.replace('_', ' ')}</div>
          </motion.div>
        ))}
      </div>

      <div className="panel">
        <div className="panel-head">
          <span className="panel-title">Scheduled Maintenance</span>
          <a className="panel-action" href="#">+ New</a>
        </div>
        <div style={{ padding: '0 0.5rem' }}>
          <table>
            <thead>
              <tr>
                <th>Type</th>
                <th>Equipment</th>
                <th>Description</th>
                <th>Date</th>
                <th>Status</th>
                <th>Priority</th>
              </tr>
            </thead>
            <tbody>
              {items.map((m, i) => (
                <motion.tr
                  key={m.id}
                  initial={{ opacity: 0, x: -10 }}
                  animate={{ opacity: 1, x: 0 }}
                  transition={{ delay: 0.3 + i * 0.06, duration: 0.35 }}
                >
                  <td style={{ fontWeight: 500 }}>{m.type_m}</td>
                  <td style={{ color: 'var(--muted)' }}>{m.equipement}</td>
                  <td style={{ color: 'var(--muted)' }}>{m.description}</td>
                  <td>{m.date_m}</td>
                  <td><span className={`badge ${m.statut}`}>{m.statut.replace('_', ' ')}</span></td>
                  <td><span className={`badge ${m.priorite}`}>{m.priorite}</span></td>
                </motion.tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

import { useEffect, useState } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import { fetchParcelles, fetchParcellesStats } from '../api.js';
import FarmGlobeScene from '../scenes/FarmGlobeScene.jsx';
import Kpi from '../ui/Kpi.jsx';

export default function Parcelles() {
  const [parcelles, setParcelles] = useState([]);
  const [stats, setStats] = useState(null);
  const [selected, setSelected] = useState(null);
  const [filter, setFilter] = useState('all');

  useEffect(() => {
    Promise.all([fetchParcelles(), fetchParcellesStats()]).then(([p, s]) => {
      setParcelles(p); setStats(s);
    });
  }, []);

  const filtered = parcelles.filter((p) =>
    filter === 'all' || p.statut === filter
  );

  return (
    <div className="content">
      <div className="page-head">
        <div>
          <h2>Parcelles</h2>
          <p>{stats?.total ?? '…'} fields · {stats?.totalArea?.toFixed(1) ?? '…'} ha total</p>
        </div>
        <div style={{ display: 'flex', gap: '0.5rem' }}>
          {['all', 'Occupied', 'Available', 'Resting'].map((f) => (
            <button
              key={f}
              onClick={() => setFilter(f)}
              className={`badge ${filter === f ? 'active' : 'resting'}`}
              style={{ cursor: 'pointer', padding: '0.4rem 0.85rem' }}
            >
              {f === 'all' ? 'All' : f}
            </button>
          ))}
        </div>
      </div>

      {stats && (
        <div className="kpi-grid">
          <Kpi index={0} icon="map"     color="green"  value={stats.total}    label="Total Parcelles" />
          <Kpi index={1} icon="grass"   color="green"  value={stats.occupied} label="Occupied" />
          <Kpi index={2} icon="check"   color="wheat"  value={stats.available} label="Available" />
          <Kpi index={3} icon="bedtime" color="orange" value={stats.resting}  label="Resting" />
        </div>
      )}

      <div className="grid-3">
        {/* 3D globe with pins */}
        <div className="panel" style={{ minHeight: 460 }}>
          <div className="panel-head">
            <span className="panel-title">Field Map — Tunisia</span>
            <span className="panel-action">Drag to rotate · click pins</span>
          </div>
          <div style={{ height: 420 }}>
            <FarmGlobeScene parcelles={filtered} onSelect={setSelected} />
          </div>
        </div>

        {/* Selected detail */}
        <div className="panel" style={{ minHeight: 460 }}>
          <div className="panel-head">
            <span className="panel-title">Selected Field</span>
          </div>
          <div className="panel-body">
            <AnimatePresence mode="wait">
              {selected ? (
                <motion.div
                  key={selected.id}
                  initial={{ opacity: 0, y: 12 }}
                  animate={{ opacity: 1, y: 0 }}
                  exit={{ opacity: 0, y: -12 }}
                  transition={{ duration: 0.3 }}
                >
                  <div style={{ fontSize: '1.25rem', fontWeight: 600, marginBottom: '0.25rem' }}>
                    {selected.nom}
                  </div>
                  <div style={{ fontSize: '0.8rem', color: 'var(--muted)' }}>
                    {selected.localisation}
                  </div>
                  <div style={{ margin: '1.25rem 0' }}>
                    <span className={`badge ${selected.statut.toLowerCase()}`}>{selected.statut}</span>
                  </div>
                  <div className="entity-meta" style={{ borderTop: 'none', padding: 0 }}>
                    <div>Soil<strong>{selected.typeSol}</strong></div>
                    <div>Area<strong>{selected.superficie} ha</strong></div>
                    <div>Latitude<strong>{selected.latitude}</strong></div>
                    <div>Longitude<strong>{selected.longitude}</strong></div>
                    <div>Created<strong>{selected.dateCreation}</strong></div>
                  </div>
                </motion.div>
              ) : (
                <motion.div
                  key="empty"
                  initial={{ opacity: 0 }}
                  animate={{ opacity: 1 }}
                  style={{ fontSize: '0.85rem', color: 'var(--muted)' }}
                >
                  Click a pin on the globe to see details.
                </motion.div>
              )}
            </AnimatePresence>
          </div>
        </div>
      </div>

      {/* Card grid */}
      <div className="panel">
        <div className="panel-head">
          <span className="panel-title">All Parcelles ({filtered.length})</span>
          <a className="panel-action" href="#">Add new →</a>
        </div>
        <div className="panel-body">
          <div className="entity-grid">
            {filtered.map((p, i) => (
              <motion.div
                key={p.id}
                className="entity"
                onClick={() => setSelected(p)}
                initial={{ opacity: 0, y: 16 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: i * 0.05, duration: 0.4 }}
                style={{ cursor: 'pointer' }}
              >
                <div className="entity-head">
                  <div>
                    <div className="entity-title">{p.nom}</div>
                    <div className="entity-sub">{p.localisation}</div>
                  </div>
                  <span className={`badge ${p.statut.toLowerCase()}`}>{p.statut}</span>
                </div>
                <div className="entity-meta">
                  <div>Soil<strong>{p.typeSol}</strong></div>
                  <div>Area<strong>{p.superficie} ha</strong></div>
                </div>
              </motion.div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

import { useEffect, useState } from 'react';
import { motion } from 'framer-motion';
import { fetchLivestock, fetchLivestockStats } from '../api.js';
import Kpi from '../ui/Kpi.jsx';
import InlineScene from '../scenes/InlineScene.jsx';

export default function Livestock() {
  const [elevages, setElevages] = useState([]);
  const [stats, setStats] = useState(null);

  useEffect(() => {
    Promise.all([fetchLivestock(), fetchLivestockStats()]).then(([e, s]) => {
      setElevages(e); setStats(s);
    });
  }, []);

  return (
    <div className="content">
      <div className="page-head">
        <div>
          <h2>Livestock</h2>
          <p>Herd management · {stats?.total_animals ?? '…'} animals total</p>
        </div>
      </div>

      {stats && (
        <div className="kpi-grid">
          <Kpi index={0} icon="pets"           color="green"  value={stats.total_elevages} label="Élevages" />
          <Kpi index={1} icon="emoji_nature"   color="wheat"  value={stats.total_animals}  label="Animals" />
          <Kpi index={2} icon="favorite"       color="green"  value={stats.healthy}        label="Healthy" />
          <Kpi index={3} icon="medical_services" color="orange" value={stats.sick}          label="Needs Care" />
        </div>
      )}

      <div className="entity-grid" style={{ marginBottom: '1.5rem' }}>
        {elevages.map((e, i) => (
          <motion.div
            key={e.id}
            className="entity"
            initial={{ opacity: 0, y: 16 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: i * 0.08, duration: 0.4 }}
            style={{ overflow: 'hidden' }}
          >
            <div style={{ height: 160, margin: '-1.25rem -1.25rem 0.75rem', background: 'rgba(0,0,0,0.2)', borderBottom: '1px solid var(--border)' }}>
              <InlineScene variant={e.type_elevage === 'Volaille' ? 'ai' : 'livestock'} />
            </div>
            <div className="entity-head">
              <div>
                <div className="entity-title">{e.type_elevage}</div>
                <div className="entity-sub">{e.production}</div>
              </div>
              <span className={`badge ${e.etat_elevage === 'Active' ? 'active' : 'quarantine'}`}>
                {e.etat_elevage}
              </span>
            </div>
            <div className="entity-meta">
              <div>Animals<strong>{e.animal_count}</strong></div>
              <div>Status<strong>{e.etat_elevage}</strong></div>
            </div>
          </motion.div>
        ))}
      </div>
    </div>
  );
}

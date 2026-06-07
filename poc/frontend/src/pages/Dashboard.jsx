import { useEffect, useState } from 'react';
import { motion } from 'framer-motion';
import { fetchDashboard } from '../api.js';
import Kpi from '../ui/Kpi.jsx';
import MiniChart from '../ui/MiniChart.jsx';

const ACT_COLORS = {
  irrigation: 'green', order: 'wheat', alert: 'orange', vaccine: 'blue', contract: 'red',
};

export default function Dashboard() {
  const [data, setData] = useState(null);

  useEffect(() => {
    fetchDashboard().then(setData);
  }, []);

  if (!data) return <div className="content"><div className="skeleton" style={{ height: 120 }} /></div>;

  return (
    <div className="content">
      <div className="kpi-grid">
        <Kpi index={0} icon="map" color="green"  value={data.parcelles} label="Active Parcelles"
             trend={{ dir: 'up', text: '+3 fields' }} barPct={78}
             sub="78% of land in use" />
        <Kpi index={1} icon="pets" color="wheat" value={data.livestock} label="Livestock Count"
             trend={{ dir: 'up', text: '+12 heads' }}
             barColor="linear-gradient(90deg, #c9a227, #e8c35a)" barPct={91}
             sub="91% herd capacity" />
        <Kpi index={2} icon="shopping_cart" color="blue" value={data.revenue_dt}
             prefix="" suffix=" DT"
             label="Monthly Revenue" trend={{ dir: 'up', text: '+8 today' }}
             barColor="linear-gradient(90deg, #3b82f6, #60a5fa)" barPct={62}
             sub="62% of monthly target" />
        <Kpi index={3} icon="construction" color="orange" value={data.alerts} label="Pending Maintenances"
             trend={{ dir: 'down', text: '2 critical' }}
             barColor="linear-gradient(90deg, #f59e0b, #fbbf24)" barPct={35}
             sub="2 critical equipment alerts" />
      </div>

      <div className="grid-3">
        <div className="panel">
          <div className="panel-head">
            <span className="panel-title">Revenue & Orders — 6 months</span>
            <a className="panel-action" href="#">View report →</a>
          </div>
          <div className="panel-body">
            <MiniChart series={data.revenue_series} labels={data.months} color="#22c55e" />
          </div>
        </div>

        <div className="panel">
          <div className="panel-head">
            <span className="panel-title">Live Activity</span>
            <a className="panel-action" href="#">All events →</a>
          </div>
          <div className="panel-body" style={{ paddingTop: '0.5rem' }}>
            <div className="activity">
              {data.activity.map((a, i) => (
                <motion.div
                  key={i}
                  className="act-item"
                  initial={{ opacity: 0, x: -12 }}
                  animate={{ opacity: 1, x: 0 }}
                  transition={{ delay: 0.5 + i * 0.1, duration: 0.4 }}
                >
                  <div className={`act-dot ${ACT_COLORS[a.kind] || 'green'}`} />
                  <div>
                    <div className="act-text">{a.text}</div>
                    <div className="act-time">{a.ago} ago</div>
                  </div>
                </motion.div>
              ))}
            </div>
          </div>
        </div>
      </div>

      <div className="panel">
        <div className="panel-head">
          <span className="panel-title">Quick Access — Modules</span>
        </div>
        <div className="panel-body">
          <div className="entity-grid">
            {[
              { icon: 'map',        name: 'Parcelles',   sub: `${data.parcelles} fields active`, href: '/admin/parcelles' },
              { icon: 'grass',      name: 'Cultures',    sub: '18 crops tracked',  href: '#' },
              { icon: 'pets',       name: 'Livestock',   sub: `${data.livestock} animals`, href: '/admin/livestock' },
              { icon: 'water_drop', name: 'Irrigation',  sub: '3 active zones', href: '#' },
              { icon: 'construction', name: 'Equipment', sub: '12 units · 7 alerts', href: '#' },
              { icon: 'build',      name: 'Maintenance', sub: `${data.alerts} pending`, href: '/admin/maintenance' },
            ].map((m, i) => (
              <motion.a
                key={m.name}
                href={m.href}
                className="entity"
                initial={{ opacity: 0, y: 14 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ delay: 0.2 + i * 0.06, duration: 0.4 }}
              >
                <div className="entity-head">
                  <div>
                    <div className="entity-title">{m.name}</div>
                    <div className="entity-sub">{m.sub}</div>
                  </div>
                  <div className="kpi-icon green"><span className="nav-icon">{m.icon}</span></div>
                </div>
              </motion.a>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}

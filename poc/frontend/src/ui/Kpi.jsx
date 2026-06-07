import { useEffect, useRef, useState } from 'react';
import { motion, useInView } from 'framer-motion';

export function Counter({ to, prefix = '', suffix = '', duration = 1.6 }) {
  const ref = useRef();
  const inView = useInView(ref, { once: true });
  useEffect(() => {
    if (!inView) return;
    const start = performance.now();
    let raf;
    const tick = (now) => {
      const t = Math.min(1, (now - start) / (duration * 1000));
      const eased = 1 - Math.pow(1 - t, 3);
      const v = Math.round(to * eased);
      if (ref.current) ref.current.textContent = prefix + v.toLocaleString() + suffix;
      if (t < 1) raf = requestAnimationFrame(tick);
    };
    raf = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(raf);
  }, [inView, to, duration, prefix, suffix]);
  return <span ref={ref}>{prefix}0{suffix}</span>;
}

export default function Kpi({ icon, color, value, label, trend, sub, barPct, barColor, prefix, suffix, index = 0 }) {
  const ref = useRef();
  const tilt = useRef();

  // Simple mouse-tilt — no library needed.
  const onMove = (e) => {
    if (!tilt.current) return;
    const r = tilt.current.getBoundingClientRect();
    const x = (e.clientX - r.left) / r.width - 0.5;
    const y = (e.clientY - r.top) / r.height - 0.5;
    tilt.current.style.transform = `perspective(900px) rotateY(${x * 6}deg) rotateX(${-y * 6}deg) translateY(-3px)`;
  };
  const onLeave = () => { if (tilt.current) tilt.current.style.transform = ''; };

  return (
    <motion.div
      ref={tilt}
      className="kpi"
      onMouseMove={onMove}
      onMouseLeave={onLeave}
      initial={{ opacity: 0, y: 30 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ delay: index * 0.08, duration: 0.5, ease: [0.22, 1, 0.36, 1] }}
      style={{ transformStyle: 'preserve-3d', transition: 'transform 0.2s ease' }}
    >
      <div className="kpi-top">
        <div className={`kpi-icon ${color}`}>{icon}</div>
        {trend && <div className={`kpi-trend ${trend.dir}`}>{trend.dir === 'up' ? '↑' : '↓'} {trend.text}</div>}
      </div>
      <div className="kpi-v">
        <Counter to={value} prefix={prefix} suffix={suffix} />
      </div>
      <div className="kpi-l">{label}</div>
      {barPct != null && (
        <>
          <div className="kpi-bar">
            <motion.div
              className="kpi-bar-fill"
              style={{ background: barColor || `linear-gradient(90deg, var(--glow), #4ade80)` }}
              initial={{ width: 0 }}
              animate={{ width: `${barPct}%` }}
              transition={{ delay: 0.9 + index * 0.08, duration: 1.4, ease: 'easeOut' }}
            />
          </div>
          {sub && <div className="kpi-sub">{sub}</div>}
        </>
      )}
    </motion.div>
  );
}

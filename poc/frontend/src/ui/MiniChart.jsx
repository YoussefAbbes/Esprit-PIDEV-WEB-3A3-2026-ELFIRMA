import { motion } from 'framer-motion';

// SVG sparkline-style area chart, dependency-free.
export default function MiniChart({ series, labels, height = 220, color = '#22c55e' }) {
  if (!series?.length) return null;
  const W = 600;
  const H = height;
  const max = Math.max(...series);
  const min = Math.min(...series);
  const stepX = W / (series.length - 1);

  const norm = (v) => H - 30 - ((v - min) / Math.max(1, max - min)) * (H - 60);
  const pts = series.map((v, i) => [i * stepX, norm(v)]);
  const line = pts.map(([x, y], i) => `${i === 0 ? 'M' : 'L'} ${x} ${y}`).join(' ');
  const area = `${line} L ${W} ${H} L 0 ${H} Z`;

  return (
    <svg viewBox={`0 0 ${W} ${H}`} style={{ width: '100%', height }}>
      <defs>
        <linearGradient id={`grad-${color.replace('#','')}`} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%"  stopColor={color} stopOpacity="0.35" />
          <stop offset="100%" stopColor={color} stopOpacity="0" />
        </linearGradient>
      </defs>

      {/* Grid */}
      {[0.25, 0.5, 0.75].map((p, i) => (
        <line key={i} x1="0" x2={W} y1={H * p} y2={H * p}
          stroke="rgba(255,255,255,0.04)" strokeWidth="1" />
      ))}

      {/* Area */}
      <motion.path
        d={area}
        fill={`url(#grad-${color.replace('#','')})`}
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        transition={{ duration: 1.2, delay: 0.4 }}
      />
      {/* Line */}
      <motion.path
        d={line}
        fill="none"
        stroke={color}
        strokeWidth="2.5"
        strokeLinecap="round"
        strokeLinejoin="round"
        initial={{ pathLength: 0 }}
        animate={{ pathLength: 1 }}
        transition={{ duration: 1.6, ease: 'easeOut' }}
      />
      {/* Dots */}
      {pts.map(([x, y], i) => (
        <motion.circle
          key={i} cx={x} cy={y} r="4" fill={color}
          initial={{ opacity: 0, scale: 0 }}
          animate={{ opacity: 1, scale: 1 }}
          transition={{ delay: 1 + i * 0.07, duration: 0.3 }}
        />
      ))}
      {/* Labels */}
      {labels?.map((l, i) => (
        <text
          key={i}
          x={i * stepX} y={H - 6}
          fill="var(--muted)"
          fontSize="10" fontFamily="Space Grotesk"
          textAnchor={i === 0 ? 'start' : i === labels.length - 1 ? 'end' : 'middle'}
        >
          {l}
        </text>
      ))}
    </svg>
  );
}

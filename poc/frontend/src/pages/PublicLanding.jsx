import { useEffect, useRef } from 'react';
import { motion, useScroll, useTransform, useInView } from 'framer-motion';
import Lenis from '@studio-freight/lenis';
import FarmTerrainScene from '../scenes/FarmTerrainScene.jsx';
import InlineScene from '../scenes/InlineScene.jsx';

// Counter that animates from 0 → target as it enters view.
function Counter({ to, suffix = '', prefix = '', duration = 2 }) {
  const ref = useRef();
  const inView = useInView(ref, { once: true, margin: '-15%' });

  useEffect(() => {
    if (!inView) return;
    const start = performance.now();
    let raf;
    const step = (now) => {
      const t = Math.min(1, (now - start) / (duration * 1000));
      const eased = 1 - Math.pow(1 - t, 3);
      const value = Math.round(to * eased);
      if (ref.current) ref.current.textContent = prefix + value.toLocaleString() + suffix;
      if (t < 1) raf = requestAnimationFrame(step);
    };
    raf = requestAnimationFrame(step);
    return () => cancelAnimationFrame(raf);
  }, [inView, to, duration, suffix, prefix]);

  return <span ref={ref}>{prefix}0{suffix}</span>;
}

const fadeUp = {
  hidden: { opacity: 0, y: 40 },
  show: { opacity: 1, y: 0, transition: { duration: 0.8, ease: [0.22, 1, 0.36, 1] } },
};

const ModuleRow = ({ num, tag, title, desc, features, variant, reverse, scrollProgress }) => {
  const ref = useRef();
  const inView = useInView(ref, { once: true, margin: '-20%' });
  return (
    <motion.div
      ref={ref}
      className={`module-row ${reverse ? 'reverse' : ''}`}
      initial="hidden"
      animate={inView ? 'show' : 'hidden'}
      variants={{ hidden: {}, show: { transition: { staggerChildren: 0.1 } } }}
    >
      <motion.div className="module-visual" variants={fadeUp}>
        <InlineScene variant={variant} />
      </motion.div>
      <motion.div variants={fadeUp}>
        <div className="module-num">{num}</div>
        <div className="module-tag">{tag}</div>
        <h2 className="module-title">{title}</h2>
        <p className="module-desc">{desc}</p>
        <ul className="module-features">
          {features.map((f, i) => <li key={i}>{f}</li>)}
        </ul>
        <a href="/admin" className="btn-ghost">Open module</a>
      </motion.div>
    </motion.div>
  );
};

export default function PublicLanding() {
  // Lenis smooth scroll
  useEffect(() => {
    const lenis = new Lenis({
      duration: 1.4,
      easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
      smoothWheel: true,
    });
    const raf = (time) => { lenis.raf(time); requestAnimationFrame(raf); };
    requestAnimationFrame(raf);
    return () => lenis.destroy();
  }, []);

  const { scrollYProgress } = useScroll();
  const heroFade = useTransform(scrollYProgress, [0, 0.12], [1, 0]);

  return (
    <div className="public-root">
      {/* True 3D background — fixed canvas behind everything */}
      <FarmTerrainScene scrollPages={6} />

      {/* HERO */}
      <motion.section className="hero" style={{ opacity: heroFade }}>
        <div className="hero-content">
          <motion.p
            className="hero-eyebrow"
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.3, duration: 0.7 }}
          >
            Agricultural Intelligence Platform
          </motion.p>
          <motion.h1
            className="hero-title"
            initial={{ opacity: 0, y: 30 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.5, duration: 0.9 }}
          >
            EL
            <strong>FIRMA</strong>
          </motion.h1>
          <motion.p
            className="hero-sub"
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 0.8, duration: 0.7 }}
          >
            From soil data to harvest intelligence — a living 3D command center for the modern farm.
          </motion.p>
          <motion.div
            className="hero-ctas"
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ delay: 1.0, duration: 0.7 }}
          >
            <a href="/admin" className="btn-primary">Enter Dashboard</a>
            <a href="#modules" className="btn-ghost">Explore Modules</a>
          </motion.div>
        </div>
        <motion.div
          className="scroll-hint"
          initial={{ opacity: 0 }}
          animate={{ opacity: 1 }}
          transition={{ delay: 1.5, duration: 0.6 }}
        >
          Scroll
          <div className="scroll-line" />
        </motion.div>
      </motion.section>

      {/* MODULES — alternating immersive rows with inline 3D */}
      <section className="section" id="modules">
        <motion.p
          className="section-label"
          initial={{ opacity: 0, y: 20 }}
          whileInView={{ opacity: 1, y: 0 }}
          viewport={{ once: true }}
        >
          The Platform
        </motion.p>
        <motion.h2
          className="section-title"
          initial={{ opacity: 0, y: 30 }}
          whileInView={{ opacity: 1, y: 0 }}
          viewport={{ once: true }}
        >
          Built for the <em>entire</em> farm.
        </motion.h2>
        <motion.p
          className="section-desc"
          initial={{ opacity: 0 }}
          whileInView={{ opacity: 1 }}
          viewport={{ once: true }}
          transition={{ delay: 0.2 }}
        >
          Eleven integrated modules powered by Symfony, MySQL, and an embedded AI stack.
          Each piece talks to the others — your data is one connected world.
        </motion.p>

        <ModuleRow
          num="01"
          tag="Field Intelligence"
          title={<>Fields & <em style={{fontStyle:'italic', color:'var(--wheat)'}}>Parcelles</em></>}
          desc="GPS-precise field management with soil analysis, irrigation tracking, and AI-powered crop recommendations sourced from your historical yield data."
          variant="crop"
          features={[
            'GPS-tracked field boundaries',
            'Soil type & status monitoring',
            'AI crop recommendation engine',
            'Real-time irrigation control',
          ]}
        />
        <ModuleRow
          num="02"
          tag="Animal Intelligence"
          title={<>Livestock & <em style={{fontStyle:'italic', color:'var(--wheat)'}}>Herds</em></>}
          desc="Individual records, vaccination calendars, predictive health alerts, and Tripo3D-generated habitat visualizations — all wired into your SMS pipeline."
          variant="livestock"
          reverse
          features={[
            'Individual animal health records',
            'Vaccination calendar & SMS alerts',
            'Tripo3D habitat generation',
            'DNA-based sex detection',
          ]}
        />
        <ModuleRow
          num="03"
          tag="Predictive Maintenance"
          title={<>Equipment & <em style={{fontStyle:'italic', color:'var(--wheat)'}}>Uptime</em></>}
          desc="A machine-learning failure model that warns you 48 hours before a tractor or pump breaks. Maintenance is generated, scheduled, and dispatched automatically."
          variant="equipment"
          features={[
            'Predictive failure alerts',
            'Automated maintenance scheduling',
            'SMS & email notifications',
            'Auto-generated PDF reports',
          ]}
        />
        <ModuleRow
          num="04"
          tag="Embedded AI"
          title={<>Intelligence <em style={{fontStyle:'italic', color:'var(--wheat)'}}>everywhere</em></>}
          desc="Gemini RAG chatbot trained on your agricultural data, face-ID and fingerprint biometrics, voice and gesture commands, and a crop-recommendation ML model."
          variant="ai"
          reverse
          features={[
            'Gemini RAG agricultural chatbot',
            'Face ID + fingerprint auth',
            'Voice & gesture commands',
            'Crop recommendation ML',
          ]}
        />
      </section>

      {/* STATS STRIP */}
      <section className="stats-strip">
        <div className="stats-inner">
          <div>
            <div className="stat-v"><Counter to={2400} suffix="+" /></div>
            <div className="stat-l">Hectares Managed</div>
          </div>
          <div>
            <div className="stat-v"><Counter to={148} /></div>
            <div className="stat-l">Livestock Tracked</div>
          </div>
          <div>
            <div className="stat-v"><Counter to={31} /></div>
            <div className="stat-l">Integrated Modules</div>
          </div>
          <div>
            <div className="stat-v"><Counter to={99} suffix=".7%" /></div>
            <div className="stat-l">Platform Uptime</div>
          </div>
        </div>
      </section>

      {/* CLOSING CTA */}
      <section className="section" style={{ paddingBottom: '14rem', paddingTop: '8rem' }}>
        <motion.h2
          className="section-title"
          initial={{ opacity: 0, y: 30 }}
          whileInView={{ opacity: 1, y: 0 }}
          viewport={{ once: true }}
        >
          Step into the <em>command center.</em>
        </motion.h2>
        <motion.p
          className="section-desc"
          initial={{ opacity: 0 }}
          whileInView={{ opacity: 1 }}
          viewport={{ once: true }}
          transition={{ delay: 0.2 }}
        >
          The admin dashboard reads from your existing Symfony entities — Parcelles, Livestock,
          Maintenance, Commandes — and renders them as a glassmorphic 3D-backed cockpit.
        </motion.p>
        <motion.div
          style={{ textAlign: 'center' }}
          initial={{ opacity: 0, y: 20 }}
          whileInView={{ opacity: 1, y: 0 }}
          viewport={{ once: true }}
          transition={{ delay: 0.4 }}
        >
          <a href="/admin" className="btn-primary">Open Admin Dashboard →</a>
        </motion.div>
      </section>

      <footer className="public-footer">
        EL FIRMA · Agricultural Intelligence Platform · Built with Symfony 6.4, React, Three.js
      </footer>
    </div>
  );
}

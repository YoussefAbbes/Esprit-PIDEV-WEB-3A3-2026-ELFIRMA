# EL FIRMA — Immersive 3D Design Transformation Dossier

> **Project:** EL FIRMA Agricultural Management Platform  
> **Scope:** Full visual redesign — Public Client Site + Admin Dashboard  
> **Core Objective:** Replace static, Bootstrap-driven pages with a premium, scroll-driven 3D immersive experience  
> **Prepared:** 2026-06-03

---

## Table of Contents

1. [Vision Statement](#1-vision-statement)
2. [Technology Stack](#2-technology-stack)
3. [Design Language & System](#3-design-language--system)
4. [Public Site — Page-by-Page Blueprint](#4-public-site--page-by-page-blueprint)
5. [Admin Dashboard Blueprint](#5-admin-dashboard-blueprint)
6. [Code Templates](#6-code-templates)
7. [Integration Strategy with Symfony/Twig](#7-integration-strategy-with-symfonytwig)
8. [Implementation Roadmap](#8-implementation-roadmap)
9. [File Structure](#9-file-structure)

---

## 1. Vision Statement

### What We're Building

EL FIRMA currently looks like a typical PHP admin panel with Bootstrap cards and AOS fade-ins. The goal is to transform it into something that **feels alive** — where the farmland breathes, where scrolling pulls you deeper into the earth, and where the admin dashboard feels like the cockpit of a modern farm command center.

### Core Experience Pillars

| Pillar | Public Site | Admin Dashboard |
|--------|-------------|-----------------|
| **Depth** | Full 3D farm landscape in the hero, parallax terrain | Glassmorphic cards floating over a dark particle field |
| **Motion** | Scroll-locked 3D camera journeys through the farm | Micro-animations on every interaction, tilt-on-hover |
| **Atmosphere** | Golden-hour lighting, organic particle systems (pollen, seeds) | Dark mode, glowing green accents, animated data nodes |
| **Performance** | Lazy-loaded WebGL, LOD system, graceful degradation | requestAnimationFrame-throttled, CSS-GPU-accelerated |

---

## 2. Technology Stack

### Decision Matrix

| Library | Role | Size | Why |
|---------|------|------|-----|
| **Three.js r165** | Core 3D engine (WebGL) | ~180KB gzip | Industry standard, massive ecosystem, works in every browser |
| **GSAP 3 + ScrollTrigger** | Scroll orchestration, timelines | ~70KB | The gold standard — silky smooth, GPU-optimized, pin/scrub |
| **Lenis 1.x** | Smooth scroll momentum | ~8KB | Replaces default scroll with buttery inertia scroll |
| **Spline Runtime** | Pre-built 3D scene embeds | ~50KB | Design 3D scenes in Spline app → export as `<canvas>` or iframe |
| **Vanilla Tilt.js** | Card 3D tilt on hover | ~5KB | Zero-dep, 60fps CSS tilt effect for cards |
| **tsParticles** | Particle background systems | ~50KB | Configurable particles: seeds, dust, network nodes |
| **VANTA.js** | Pre-built animated 3D backgrounds | ~30KB | Quick wins: `VANTA.FOG`, `VANTA.WAVES`, `VANTA.NET` |
| **Tweakpane / dat.GUI** | Debug controls (dev only) | — | Tune 3D scene parameters in real time |

> **No npm / no build step required.** All of these load via CDN inside Twig templates. Zero changes to Symfony backend.

### CDN Imports (add to `base.html.twig` / `baseback.html.twig`)

```html
<!-- Core 3D -->
<script src="https://cdn.jsdelivr.net/npm/three@0.165.0/build/three.min.js"></script>
<!-- GSAP + Plugins -->
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js"></script>
<!-- Smooth Scroll -->
<script src="https://cdn.jsdelivr.net/npm/@studio-freight/lenis@1.0.42/dist/lenis.min.js"></script>
<!-- Tilt Effect -->
<script src="https://cdn.jsdelivr.net/npm/vanilla-tilt@1.8.1/dist/vanilla-tilt.min.js"></script>
<!-- Particles -->
<script src="https://cdn.jsdelivr.net/npm/tsparticles@3.3.0/tsparticles.bundle.min.js"></script>
```

---

## 3. Design Language & System

### Color Palette

```css
/* Public Site — "Golden Fields" */
--color-earth:       #1a1208;   /* Deep soil background */
--color-soil:        #2d1b0e;   /* Section dividers */
--color-wheat:       #d4a843;   /* Primary accent — wheat gold */
--color-sprout:      #4caf50;   /* Secondary accent — young plant green */
--color-fog:         rgba(212, 168, 67, 0.08); /* Atmospheric fog overlay */
--color-text-light:  #f5efe0;   /* Main text on dark */
--color-text-muted:  #a89070;   /* Muted text */

/* Admin Dashboard — "Command Center" */
--admin-bg:          #030d06;   /* Near-black green */
--admin-surface:     rgba(17, 101, 48, 0.08);  /* Card surfaces */
--admin-border:      rgba(17, 101, 48, 0.25);  /* Subtle borders */
--admin-glow:        #22c55e;   /* Glow accent */
--admin-text:        #e2f5e9;   /* Primary text */
--admin-muted:       #6b9b7a;   /* Muted text */
```

### Typography

```css
/* Public: Organic + Premium */
@import url('https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;600&family=Inter:wght@300;400;500&display=swap');

--font-display: 'Cormorant Garamond', serif;  /* Hero titles — elegant */
--font-body:    'Inter', sans-serif;           /* Body text — clean */

/* Admin: Technical + Sharp */
@import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600&display=swap');
--font-admin: 'Space Grotesk', sans-serif;
```

---

## 4. Public Site — Page-by-Page Blueprint

### 4.1 — Hero Section (`pages/index.html.twig`)

**Experience:** User lands on a full-screen 3D farm landscape. Rolling terrain stretches to the horizon. Golden particles (pollen/seeds) drift upward. As user scrolls, the camera glides FORWARD through the terrain — the farm world opens up around them. Text and CTAs fade in with GSAP timelines.

**Visual Description:**
```
┌──────────────────────────────────────────────────────┐
│  [THREE.JS CANVAS — FULL SCREEN BEHIND EVERYTHING]   │
│                                                      │
│     E L   F I R M A                                 │
│  ─────────────────────────────                      │
│  Agricultural Intelligence Platform                  │
│                                                      │
│  [Explore Platform →]    [Watch Demo]                │
│                                                      │
│  · · · · · floating pollen particles · · · ·         │
│  ═══ rolling green terrain below ════════════       │
└──────────────────────────────────────────────────────┘
    ↓ SCROLL — camera moves FORWARD through fields
```

**Three.js Scene Composition:**
- `PlaneGeometry(200, 200, 64, 64)` — displacement-mapped terrain
- `ShaderMaterial` with vertex displacement from a height-map texture
- `PointsGeometry` — 2000 floating pollen particles with upward drift
- `DirectionalLight` (warm yellow, low angle) + `AmbientLight` (twilight blue)
- `FogExp2` — atmospheric distance fog
- GSAP ScrollTrigger scrubs `camera.position.z` from 80 → 0 on scroll

### 4.2 — Services Section

**Experience:** As the user scrolls through the services, each card **rises from the terrain** with a 3D flip animation. The background shows a slow-rotating 3D hexagonal grid (farmland data visualization metaphor).

**Visual:**
```
[scroll pin — 100vh sticky]

 ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐
 │  🌾      │  │  🐄      │  │  🔧      │  │  📦      │
 │  Crops   │  │Livestock │  │Equipment │  │ Supply   │
 │          │  │          │  │          │  │  Chain   │
 │ [3D flip]│  │ [3D flip]│  │ [3D flip]│  │ [3D flip]│
 └──────────┘  └──────────┘  └──────────┘  └──────────┘
     ↑ Cards rise 0→1 opacity + translateY 80px→0
     ↑ Each card is staggered 0.15s apart (GSAP stagger)
```

**Tech:** GSAP ScrollTrigger `stagger`, Vanilla Tilt on cards

### 4.3 — Statistics Counter Section

**Experience:** Numbers count up as they enter viewport. Background: slow `VANTA.WAVES` or Three.js displaced plane.

```
  2,400+          148            31            99.7%
  Hectares      Livestock     Modules       Uptime
  Managed       Tracked       Integrated
```

**Tech:** GSAP `counter` tween, `IntersectionObserver` trigger

### 4.4 — Features / Modules Showcase

**Experience:** Horizontal scroll section (scroll-jacked). Each module card slides in from right. The active module shows a 3D render of the concept (field map, 3D animal, equipment).

```
[STICKY CONTAINER — horizontal scroll via ScrollTrigger]

→ → → → → → → → → → → → → → → → → → → → → → → →

[Parcelles]  [Cultures]  [Livestock]  [Irrigation]  [AI Chat]
   MAP 3D      GROWTH      3D MODEL      GAUGE         BOT
```

**Tech:** GSAP `ScrollTrigger` with `horizontal: true`, `x` transform scrub

### 4.5 — AI Feature Callout

**Experience:** Full-viewport dark section. A glowing neural network particle graph animates in the background. Text types itself out (typewriter effect). 

```
┌────────────────────────────────────────────────────┐
│  ░░░░ [tsParticles network graph background] ░░░░  │
│                                                    │
│  "Powered by AI"                                   │
│  ───────────────────────────                      │
│  Crop recommendations, maintenance predictions,    │
│  RAG chatbot, 3D livestock generation...          │
│                                                    │
│  [Try the Crop Recommender →]                     │
└────────────────────────────────────────────────────┘
```

**Tech:** tsParticles `links` preset, GSAP `TextPlugin` typewriter

### 4.6 — Footer

Dark soil-tone footer with floating seed particles. Three-column links with hover underline slide animation.

---

## 5. Admin Dashboard Blueprint

### 5.1 — Overall Admin Shell (`baseback.html.twig`)

**Experience:** The entire admin background is a **subtle animated 3D mesh** — a low-poly network of green nodes and edges slowly rotating, suggesting connected farm data. The sidebar floats as a glassmorphic panel over this background. All cards have a slight 3D tilt on hover.

**Layout:**
```
┌─────────────────────────────────────────────────────────────┐
│  [THREE.JS BG — dark green node mesh, slow rotation]        │
│  ┌──────────────────┐  ┌───────────────────────────────┐   │
│  │  GLASS SIDEBAR   │  │      MAIN CONTENT AREA        │   │
│  │                  │  │                               │   │
│  │  EL FIRMA logo   │  │  [Glassmorphic Cards]         │   │
│  │  ─────────────   │  │  [Charts — Chart.js 3D-style] │   │
│  │  > Dashboard     │  │  [Data Tables]                │   │
│  │  > Parcelles     │  │                               │   │
│  │  > Livestock     │  │                               │   │
│  │  > Equipment     │  │                               │   │
│  │  > Supply Chain  │  │                               │   │
│  │  > Analytics     │  │                               │   │
│  │                  │  │                               │   │
│  └──────────────────┘  └───────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

### 5.2 — Sidebar CSS (Glassmorphism)

```css
.admin-sidebar {
  background: rgba(3, 25, 10, 0.65);
  backdrop-filter: blur(20px) saturate(180%);
  -webkit-backdrop-filter: blur(20px) saturate(180%);
  border-right: 1px solid rgba(34, 197, 94, 0.12);
  box-shadow: 4px 0 40px rgba(0, 0, 0, 0.4),
              inset -1px 0 0 rgba(34, 197, 94, 0.08);
}

.admin-sidebar .nav-item.active {
  background: rgba(34, 197, 94, 0.12);
  border-left: 3px solid #22c55e;
  box-shadow: inset 0 0 20px rgba(34, 197, 94, 0.05),
              4px 0 15px rgba(34, 197, 94, 0.1);
}

.admin-sidebar .nav-item:hover {
  background: rgba(34, 197, 94, 0.07);
  transform: translateX(4px);
  transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);
}
```

### 5.3 — Dashboard Stat Cards

```css
.stat-card {
  background: rgba(17, 101, 48, 0.08);
  border: 1px solid rgba(34, 197, 94, 0.15);
  backdrop-filter: blur(10px);
  border-radius: 16px;
  transform-style: preserve-3d;
  transition: transform 0.1s ease, box-shadow 0.3s ease;
}

.stat-card:hover {
  box-shadow: 0 20px 60px rgba(34, 197, 94, 0.15),
              0 0 0 1px rgba(34, 197, 94, 0.25);
}

/* Vanilla Tilt handles the 3D tilt — applied via JS */
```

### 5.4 — Admin 3D Background (Three.js)

The background is a `WireframeGeometry` of connected spheres forming a network — representing sensor nodes across the farm. It slowly auto-rotates.

```javascript
// Sparse, minimal — won't compete with content
const geometry = new THREE.IcosahedronGeometry(1, 1);
const wireframe = new THREE.WireframeGeometry(geometry);
// Replicated 200x across a 3D grid at random positions
// Color: #0d3320 (very dark green — barely visible)
// Rotation: 0.001 radians/frame
```

### 5.5 — Module Pages

Each module page inherits the glass aesthetic:

**Tableau de Bord (Dashboard):**
- 4 KPI stat cards with glowing number counters + Vanilla Tilt
- Line/bar charts restyled to dark theme with green datasets
- A 3D globe or map for geographic farm data
- Live activity feed with slide-in animations

**Parcelles/Cultures (Fields/Crops):**
- Map view with glowing field overlays
- Crop status cards with `progress-ring` SVG animations
- "Add Field" button opens a modal with GSAP scale-in animation

**Livestock:**
- Animal cards with the existing 3D model viewer
- Health status shown as animated radial gauges
- Herd overview with a 3D scatter plot (Three.js `Points`)

**Equipment/Maintenance:**
- Equipment cards show a subtle pulse animation for "needs maintenance" status
- Predictive timeline as a horizontal 3D bar chart
- Alert badges with animated ring pulse

---

## 6. Code Templates

### Template A — Three.js Farm Terrain Hero

```html
<!-- In templates/pages/index.html.twig — replace existing hero -->

<section id="hero" style="position:relative; height:100vh; overflow:hidden;">
  <canvas id="farm-canvas" style="position:absolute;inset:0;width:100%;height:100%;z-index:0;"></canvas>
  
  <div id="hero-content" style="position:relative;z-index:10;
    display:flex;flex-direction:column;align-items:center;
    justify-content:center;height:100%;color:#f5efe0;text-align:center;">
    <p class="hero-eyebrow">Agricultural Intelligence</p>
    <h1 class="hero-title">EL FIRMA</h1>
    <p class="hero-subtitle">Smart farm management from soil to sale</p>
    <div class="hero-ctas">
      <a href="/elfirma" class="btn-primary-3d">Explore Platform</a>
      <a href="/about"   class="btn-ghost-3d">Learn More</a>
    </div>
  </div>
</section>

<script>
(function() {
  // ── THREE.JS FARM TERRAIN HERO ────────────────────────────────────
  const canvas  = document.getElementById('farm-canvas');
  const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: false });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
  renderer.setSize(window.innerWidth, window.innerHeight);
  renderer.setClearColor(0x0a1a08);       // deep dark green-black
  renderer.shadowMap.enabled = true;

  const scene  = new THREE.Scene();
  scene.fog    = new THREE.FogExp2(0x0a1a08, 0.018);

  const camera = new THREE.PerspectiveCamera(60, window.innerWidth / window.innerHeight, 0.1, 200);
  camera.position.set(0, 8, 50);
  camera.lookAt(0, 0, 0);

  // ── TERRAIN ─────────────────────────────────────────────────────
  const terrainGeo = new THREE.PlaneGeometry(120, 120, 80, 80);
  terrainGeo.rotateX(-Math.PI / 2);

  // Vertex displacement — rolling hills
  const pos = terrainGeo.attributes.position;
  for (let i = 0; i < pos.count; i++) {
    const x = pos.getX(i), z = pos.getZ(i);
    const y = Math.sin(x * 0.05) * 3 + Math.cos(z * 0.07) * 2
            + Math.sin(x * 0.12 + z * 0.08) * 1.5;
    pos.setY(i, y);
  }
  terrainGeo.computeVertexNormals();

  const terrainMat = new THREE.MeshLambertMaterial({
    color: 0x1a3d1a,           // dark green field
    wireframe: false
  });
  const terrain = new THREE.Mesh(terrainGeo, terrainMat);
  terrain.receiveShadow = true;
  scene.add(terrain);

  // ── LIGHTS ──────────────────────────────────────────────────────
  const sun = new THREE.DirectionalLight(0xd4a843, 1.2);  // golden hour
  sun.position.set(30, 40, 20);
  sun.castShadow = true;
  scene.add(sun);
  scene.add(new THREE.AmbientLight(0x1a3050, 0.8));       // twilight sky

  // ── PARTICLES — pollen/seeds ─────────────────────────────────────
  const particleCount = 1800;
  const pGeo = new THREE.BufferGeometry();
  const pPos = new Float32Array(particleCount * 3);
  const pVel = new Float32Array(particleCount);     // upward drift speed

  for (let i = 0; i < particleCount; i++) {
    pPos[i*3]   = (Math.random() - 0.5) * 100;
    pPos[i*3+1] = Math.random() * 30;
    pPos[i*3+2] = (Math.random() - 0.5) * 100;
    pVel[i]     = 0.005 + Math.random() * 0.01;
  }
  pGeo.setAttribute('position', new THREE.BufferAttribute(pPos, 3));

  const pMat  = new THREE.PointsMaterial({ color: 0xd4a843, size: 0.18, transparent: true, opacity: 0.7 });
  const particles = new THREE.Points(pGeo, pMat);
  scene.add(particles);

  // ── SCROLL → CAMERA JOURNEY ─────────────────────────────────────
  gsap.registerPlugin(ScrollTrigger);
  gsap.to(camera.position, {
    z: 5,          // camera rushes forward into the terrain
    y: 3,
    scrollTrigger: {
      trigger: '#hero',
      start: 'top top',
      end:   'bottom top',
      scrub: 1.5
    }
  });

  // ── ANIMATION LOOP ───────────────────────────────────────────────
  let frame = 0;
  function animate() {
    requestAnimationFrame(animate);
    frame++;

    // Drift particles upward, reset when out of bounds
    const arr = particles.geometry.attributes.position.array;
    for (let i = 0; i < particleCount; i++) {
      arr[i*3+1] += pVel[i];
      if (arr[i*3+1] > 30) arr[i*3+1] = 0;
    }
    particles.geometry.attributes.position.needsUpdate = true;

    // Subtle terrain pulse
    terrain.rotation.z = Math.sin(frame * 0.002) * 0.002;

    renderer.render(scene, camera);
  }
  animate();

  // ── RESIZE ───────────────────────────────────────────────────────
  window.addEventListener('resize', () => {
    camera.aspect = window.innerWidth / window.innerHeight;
    camera.updateProjectionMatrix();
    renderer.setSize(window.innerWidth, window.innerHeight);
  });
})();
</script>
```

---

### Template B — GSAP Scroll-Staggered Service Cards

```html
<!-- In the services section of index.html.twig -->
<section id="services" class="services-section">
  <div class="services-grid">
    {% for service in services %}
    <div class="service-card" data-tilt data-tilt-max="8" data-tilt-speed="400">
      <div class="service-card-inner">
        <div class="service-icon">{{ service.icon }}</div>
        <h3>{{ service.name }}</h3>
        <p>{{ service.description }}</p>
        <a href="{{ service.link }}" class="card-cta">Explore →</a>
      </div>
      <div class="card-glow"></div>
    </div>
    {% endfor %}
  </div>
</section>

<script>
// Cards rise from below on scroll
gsap.from('.service-card', {
  y: 80,
  opacity: 0,
  duration: 0.8,
  stagger: 0.15,
  ease: 'power3.out',
  scrollTrigger: {
    trigger: '#services',
    start: 'top 75%',
  }
});

// Vanilla Tilt — 3D tilt on hover (auto-initializes via [data-tilt] attr)
VanillaTilt.init(document.querySelectorAll('[data-tilt]'), {
  max: 8,
  speed: 400,
  glare: true,
  'max-glare': 0.2
});
</script>

<style>
.service-card {
  background: rgba(255, 255, 255, 0.03);
  border: 1px solid rgba(212, 168, 67, 0.15);
  border-radius: 20px;
  padding: 2rem;
  transform-style: preserve-3d;
  cursor: default;
  position: relative;
  overflow: hidden;
}

.card-glow {
  position: absolute;
  inset: -1px;
  border-radius: 20px;
  background: radial-gradient(ellipse at 50% 0%, rgba(212,168,67,0.15) 0%, transparent 60%);
  opacity: 0;
  transition: opacity 0.4s ease;
  pointer-events: none;
}
.service-card:hover .card-glow { opacity: 1; }
</style>
```

---

### Template C — Admin 3D Background (baseback.html.twig)

```html
<!-- Place right after <body> in baseback.html.twig -->
<canvas id="admin-bg" style="
  position: fixed; inset: 0; z-index: 0;
  width: 100%; height: 100%;
  pointer-events: none;
"></canvas>

<script>
(function adminBg() {
  const canvas   = document.getElementById('admin-bg');
  const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
  renderer.setPixelRatio(1);                // intentionally lo-res — it's a BG
  renderer.setSize(window.innerWidth, window.innerHeight);

  const scene  = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(60, window.innerWidth/window.innerHeight, 0.1, 100);
  camera.position.z = 30;

  // Sparse node mesh — icosahedrons as farm sensor nodes
  const nodeGeo = new THREE.IcosahedronGeometry(0.3, 0);
  const nodeMat = new THREE.MeshBasicMaterial({ color: 0x116530, wireframe: true });
  
  const nodes = [];
  for (let i = 0; i < 60; i++) {
    const node = new THREE.Mesh(nodeGeo, nodeMat);
    node.position.set(
      (Math.random() - 0.5) * 60,
      (Math.random() - 0.5) * 40,
      (Math.random() - 0.5) * 20
    );
    scene.add(node);
    nodes.push(node);
  }

  // Connecting lines between nearby nodes
  const lineMat  = new THREE.LineBasicMaterial({ color: 0x0a3318, transparent: true, opacity: 0.4 });
  for (let i = 0; i < nodes.length; i++) {
    for (let j = i + 1; j < nodes.length; j++) {
      if (nodes[i].position.distanceTo(nodes[j].position) < 12) {
        const lineGeo = new THREE.BufferGeometry().setFromPoints([
          nodes[i].position, nodes[j].position
        ]);
        scene.add(new THREE.Line(lineGeo, lineMat));
      }
    }
  }

  let t = 0;
  function animate() {
    requestAnimationFrame(animate);
    t += 0.003;
    scene.rotation.y = t * 0.1;
    scene.rotation.x = Math.sin(t * 0.07) * 0.05;
    renderer.render(scene, camera);
  }
  animate();

  window.addEventListener('resize', () => {
    camera.aspect = window.innerWidth / window.innerHeight;
    camera.updateProjectionMatrix();
    renderer.setSize(window.innerWidth, window.innerHeight);
  });
})();
</script>
```

---

### Template D — Smooth Scroll (Lenis) — Global Init

```html
<!-- In base.html.twig <head> or before </body> -->
<script>
// Initialize Lenis smooth scroll
const lenis = new Lenis({
  duration: 1.2,
  easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
  direction: 'vertical',
  gestureDirection: 'vertical',
  smooth: true,
  mouseMultiplier: 1,
  smoothTouch: false,
  touchMultiplier: 2,
});

// Connect Lenis to GSAP ScrollTrigger
lenis.on('scroll', ScrollTrigger.update);
gsap.ticker.add((time) => lenis.raf(time * 1000));
gsap.ticker.lagSmoothing(0);
</script>
```

---

### Template E — Admin Stat Card with Tilt + Counter

```html
<!-- In tableau_de_bord.html.twig -->
<div class="stats-grid">
  <div class="stat-card" data-tilt data-tilt-max="5" data-tilt-glare="true">
    <div class="stat-icon-wrap">
      <span class="material-symbols-outlined">grass</span>
    </div>
    <div class="stat-value" data-count="{{ totalParcelles }}">0</div>
    <div class="stat-label">Active Fields</div>
    <div class="stat-trend up">↑ +3 this month</div>
  </div>
  <!-- repeat for other KPIs -->
</div>

<script>
// Animate counters when visible
document.querySelectorAll('.stat-value[data-count]').forEach(el => {
  const target = parseInt(el.dataset.count);
  gsap.fromTo(el,
    { textContent: 0 },
    {
      textContent: target,
      duration: 2,
      ease: 'power2.out',
      snap: { textContent: 1 },
      scrollTrigger: { trigger: el, start: 'top 85%', once: true }
    }
  );
});

VanillaTilt.init(document.querySelectorAll('.stat-card[data-tilt]'), {
  max: 5, speed: 600, glare: true, 'max-glare': 0.1
});
</script>

<style>
.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 1.25rem;
}

.stat-card {
  background: rgba(17, 101, 48, 0.08);
  border: 1px solid rgba(34, 197, 94, 0.15);
  backdrop-filter: blur(12px);
  border-radius: 16px;
  padding: 1.5rem;
  transform-style: preserve-3d;
  position: relative;
  overflow: hidden;
}

.stat-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 2px;
  background: linear-gradient(90deg, transparent, #22c55e, transparent);
}

.stat-value {
  font-size: 2.5rem;
  font-weight: 600;
  color: #22c55e;
  line-height: 1;
  margin: 0.75rem 0 0.25rem;
}

.stat-icon-wrap {
  width: 40px; height: 40px;
  display: grid; place-items: center;
  background: rgba(34, 197, 94, 0.1);
  border-radius: 10px;
  color: #22c55e;
}

.stat-trend.up   { color: #22c55e; font-size: 0.75rem; }
.stat-trend.down { color: #ef4444; font-size: 0.75rem; }
</style>
```

---

### Template F — Horizontal Scroll Module Showcase

```html
<!-- In pages/index.html.twig — module feature strip -->
<section id="modules-showcase">
  <div class="showcase-sticky-wrapper">
    <div class="showcase-track">
      
      <div class="module-slide">
        <div class="module-3d">
          <!-- Spline or Three.js embed for each module -->
          <canvas class="module-canvas" data-module="parcelles"></canvas>
        </div>
        <div class="module-info">
          <span class="module-number">01</span>
          <h2>Field Management</h2>
          <p>GPS-tracked parcelles with real-time crop status and irrigation control.</p>
          <a href="/elfirma/parcelles">Manage Fields →</a>
        </div>
      </div>
      
      <!-- More slides for each module... -->
      
    </div>
  </div>
</section>

<script>
// Horizontal scroll driven by vertical scroll (ScrollTrigger)
const track = document.querySelector('.showcase-track');
const slides = document.querySelectorAll('.module-slide');
const trackWidth = slides.length * window.innerWidth;

gsap.to(track, {
  x: () => -(trackWidth - window.innerWidth),
  ease: 'none',
  scrollTrigger: {
    trigger: '#modules-showcase',
    start: 'top top',
    end: () => `+=${trackWidth}`,
    scrub: 1,
    pin: true,
    anticipatePin: 1,
  }
});
</script>

<style>
#modules-showcase .showcase-sticky-wrapper {
  overflow: hidden;
}
.showcase-track {
  display: flex;
  width: max-content;
}
.module-slide {
  width: 100vw;
  height: 100vh;
  display: grid;
  grid-template-columns: 1fr 1fr;
  align-items: center;
  padding: 4rem;
  gap: 4rem;
}
</style>
```

---

### Template G — tsParticles AI Section Background

```html
<!-- In pages/index.html.twig -->
<section id="ai-section" style="position:relative; min-height:80vh;">
  <div id="ai-particles" style="position:absolute;inset:0;z-index:0;"></div>
  <div class="ai-content" style="position:relative;z-index:1;">
    <h2 class="ai-headline">Powered by AI</h2>
    <!-- content -->
  </div>
</section>

<script>
tsParticles.load('ai-particles', {
  particles: {
    number: { value: 80 },
    color:  { value: '#22c55e' },
    links:  { enable: true, color: '#22c55e', distance: 120, opacity: 0.25, width: 1 },
    move:   { enable: true, speed: 0.6, outModes: 'bounce' },
    opacity:{ value: 0.5 },
    shape:  { type: 'circle' },
    size:   { value: { min: 1, max: 3 } }
  },
  background: { color: '#030d06' },
  detectRetina: true
});
</script>
```

---

## 7. Integration Strategy with Symfony/Twig

### Zero-Config Approach (Recommended)

Since all libraries are CDN-loaded, no Symfony or Webpack changes are needed.

**Step 1** — Add CDN scripts to `base.html.twig` before `</body>`:

```twig
{# templates/base.html.twig — before </body> #}
<script src="https://cdn.jsdelivr.net/npm/three@0.165.0/build/three.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@studio-freight/lenis@1.0.42/dist/lenis.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/vanilla-tilt@1.8.1/dist/vanilla-tilt.min.js"></script>
{% block page_scripts %}{% endblock %}
```

**Step 2** — Add CDN scripts to `baseback.html.twig` for admin:

```twig
{# templates/baseback.html.twig — before </body> #}
<script src="https://cdn.jsdelivr.net/npm/three@0.165.0/build/three.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/vanilla-tilt@1.8.1/dist/vanilla-tilt.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/tsparticles@3.3.0/tsparticles.bundle.min.js"></script>
{% block admin_scripts %}{% endblock %}
```

**Step 3** — Each page template uses `{% block page_scripts %}` to add its own 3D scene without polluting other pages:

```twig
{# templates/pages/index.html.twig #}
{% block page_scripts %}
<script>
  // Only the homepage loads the farm terrain Three.js scene
  // ... Template A code here ...
</script>
{% endblock %}
```

### Performance Considerations

| Concern | Solution |
|---------|----------|
| Three.js is 180KB | Loaded async, deferred — renders after page paint |
| WebGL on low-end devices | `renderer.setPixelRatio(Math.min(devicePixelRatio, 2))` + fallback CSS gradient |
| Mobile battery drain | Reduce to 30fps on mobile: `if (isMobile) renderer.setAnimationLoop(null)` then `setTimeout` loop |
| Scroll jank | Lenis handles smooth scroll; GSAP ScrollTrigger is GPU-composited |
| Admin BG hurts readability | Very low opacity (0.03-0.06 range), barely perceptible — atmospheric only |

---

## 8. Implementation Roadmap

### Phase 1 — Foundations (Week 1)

| Task | File(s) | Effort |
|------|---------|--------|
| Add CDN libs to base templates | `base.html.twig`, `baseback.html.twig` | 30 min |
| Implement Lenis smooth scroll globally | Both base templates | 1 hr |
| Redesign `base.html.twig` — dark "golden fields" theme | CSS overhaul | 1 day |
| Redesign `baseback.html.twig` — glassmorphic dark admin | CSS overhaul | 1 day |
| New color system + typography (Google Fonts) | New `public/styles/design-system.css` | 3 hrs |

### Phase 2 — Public Site 3D (Week 2)

| Task | File(s) | Effort |
|------|---------|--------|
| Hero terrain + particle scene (Template A) | `pages/index.html.twig` | 1 day |
| Services section scroll-stagger (Template B) | `pages/index.html.twig` | 3 hrs |
| Stats counter section | `pages/index.html.twig` | 2 hrs |
| Horizontal module showcase (Template F) | `pages/index.html.twig` | 1 day |
| AI section tsParticles (Template G) | `pages/index.html.twig` | 2 hrs |
| Update `about.html.twig`, `services.html.twig` | Parallax + GSAP | 1 day |
| New header/nav (glass blur on scroll) | `partials/_header.html.twig` | 3 hrs |

### Phase 3 — Admin Dashboard 3D (Week 3)

| Task | File(s) | Effort |
|------|---------|--------|
| Admin 3D background mesh (Template C) | `baseback.html.twig` | 3 hrs |
| Glassmorphic sidebar redesign | `elfirma/_admin_sidebar.html.twig` | 1 day |
| Stat cards with Tilt + Counter (Template E) | `elfirma/tableau_de_bord.html.twig` | 4 hrs |
| Smooth page transitions (GSAP + Turbo) | `baseback.html.twig` | 3 hrs |
| Dark-themed Chart.js (green/dark palette) | All chart pages | 1 day |
| Admin module cards on index page | `elfirma/index.html.twig` | 4 hrs |

### Phase 4 — Polish & Secondary Pages (Week 4)

| Task | Effort |
|------|--------|
| Authentication pages 3D redesign (login/signup) | 1 day |
| Livestock page with 3D card reveals | 4 hrs |
| Equipment/Maintenance data visualization | 4 hrs |
| Commandes/Products e-commerce styling | 1 day |
| Responsive mobile audit + fixes | 1 day |
| Performance audit (Lighthouse) + optimizations | 1 day |
| Cross-browser testing | 4 hrs |

---

## 9. File Structure

### New Files to Create

```
public/
├── styles/
│   ├── design-system.css        ← New: variables, typography, base reset
│   ├── public-theme.css         ← New: client site styles (dark golden)
│   ├── admin-theme.css          ← New: admin dashboard styles (replace elfirma-theme.css)
│   ├── animations.css           ← New: keyframes, transition utilities
├── js/
│   ├── scenes/
│   │   ├── hero-terrain.js      ← Template A code
│   │   ├── admin-bg.js          ← Template C code
│   │   ├── module-canvas.js     ← Per-module 3D scenes
│   ├── scroll/
│   │   ├── lenis-init.js        ← Smooth scroll setup
│   │   ├── scrolltrigger-init.js← GSAP ScrollTrigger setup
│   ├── ui/
│   │   ├── tilt-init.js         ← Vanilla Tilt initialization
│   │   ├── counters.js          ← Stat counter animations
│   │   ├── particles-config.js  ← tsParticles configurations
```

### Modified Template Files

```
templates/
├── base.html.twig               ← Add CDN libs, new CSS links, Lenis init
├── baseback.html.twig           ← Admin CDN libs, 3D bg canvas
├── auth_base.html.twig          ← Upgrade existing animations (already good)
├── partials/
│   ├── _header.html.twig        ← Glass blur nav, scroll-triggered style change
│   ├── _footer.html.twig        ← Dark footer redesign
├── pages/
│   ├── index.html.twig          ← Full 3D hero + all scroll sections
├── elfirma/
│   ├── _admin_sidebar.html.twig ← Glassmorphic sidebar
│   ├── tableau_de_bord.html.twig← 3D cards, counters
│   ├── index.html.twig          ← Module selection with 3D cards
```

---

## Quick Start — Minimal Proof of Concept

To validate the approach in **2 hours** before full implementation:

1. Add Three.js + GSAP CDN to `base.html.twig`
2. Paste Template A into `pages/index.html.twig` (replace the Bootstrap carousel)
3. Add Lenis smooth scroll (Template D)
4. Add Vanilla Tilt to existing service cards (just add `data-tilt` attribute + 1 JS line)
5. Preview result

This gives you the 3D hero + smooth scroll + card tilt as an immediate visual proof — zero risk to the backend.

---

*Dossier prepared for EL FIRMA project — ready for implementation.*

# EL FIRMA — Immersive 3D Frontend POC

A standalone **React + Three.js (R3F)** frontend that consumes your Symfony app as a pure JSON backend. Built to validate the immersive 3D direction before integration.

---

## Stack

| Layer | Tech | Why |
|-------|------|-----|
| **3D engine**        | `three` + `@react-three/fiber` + `@react-three/drei` + `@react-three/postprocessing` | The standard for declarative WebGL in React. Real meshes, lights, fog, bloom. |
| **Animation**        | `framer-motion` + `gsap` + `@studio-freight/lenis` | Springs, enter/exit, smooth inertia scroll, scroll-pinned camera. |
| **Routing**          | `react-router-dom` | SPA navigation between public site and admin shell. |
| **Build**            | `vite` | Fast dev server with HMR + proxy to Symfony. |
| **State**            | `zustand` (installed, ready) | Minimal global store when needed. |
| **Symfony backend**  | unchanged | Continues to serve Twig admin if you want; the JSON API is additive. |

---

## File layout

```
poc/
├── README.md                       ← you are here
├── frontend/                       ← Vite + React + R3F app
│   ├── package.json
│   ├── vite.config.js             (proxies /api → 127.0.0.1:8000)
│   ├── index.html
│   └── src/
│       ├── main.jsx
│       ├── App.jsx                 (router: / and /admin/*)
│       ├── api.js                  (Symfony JSON client + mock fallbacks)
│       ├── styles/theme.css        (design system: golden + glass-green)
│       ├── scenes/
│       │   ├── FarmTerrainScene.jsx       (public hero — terrain + pollen + scroll camera + bloom)
│       │   ├── AdminBackgroundScene.jsx   (admin bg — sensor mesh + pulsing accents)
│       │   ├── FarmGlobeScene.jsx         (3D globe with pins per Parcelle lat/lng)
│       │   └── InlineScene.jsx            (reusable thumbnail: crop/livestock/equipment/ai variants)
│       ├── pages/
│       │   ├── PublicLanding.jsx          (hero, modules, stats, CTA)
│       │   ├── AdminShell.jsx             (sidebar + topbar + animated outlet)
│       │   ├── Dashboard.jsx              (KPIs, sparkline, activity feed)
│       │   ├── Parcelles.jsx              (KPIs + 3D globe + selected detail + grid)
│       │   ├── Livestock.jsx              (KPIs + 3D card thumbnails)
│       │   └── Maintenance.jsx            (KPIs + animated table)
│       └── ui/
│           ├── Kpi.jsx                    (tilt KPI card + Counter)
│           └── MiniChart.jsx              (SVG sparkline area chart)
└── symfony-api/
    └── ApiController.php           ← drop into src/Controller/ to enable live data
```

---

## Quick start

### 1. Install + run the frontend

```bash
cd poc/frontend
npm install
npm run dev          # → http://localhost:5173
```

That alone is enough — without Symfony running, the app uses the realistic mock data in `src/api.js`. **You can demo everything offline.**

### 2. (Optional) Wire to live Symfony data

Start Symfony separately on `127.0.0.1:8000`:

```bash
# from project root
symfony serve
# or
php -S 127.0.0.1:8000 -t public
```

Copy the API controller in:

```bash
cp poc/symfony-api/ApiController.php src/Controller/ApiController.php
php bin/console cache:clear
```

Now `fetch('/api/parcelles')` succeeds and the frontend silently switches from mocks to live DB rows.

`vite.config.js` already proxies `/api/*` to `127.0.0.1:8000`, so no CORS setup is required during dev. For production deployment, see the CORS notes in `symfony-api/ApiController.php`.

---

## What's actually 3D in here

### Public landing (`/`)
- **Fixed full-screen R3F canvas** behind every section — the page never escapes the scene.
- **Procedural terrain**: 120×120 vertex displacement (sine/cosine compositing), Lambert material, fog.
- **Scroll-pinned camera rig** via `@react-three/drei`'s `ScrollControls` — camera flies forward, drifts sideways, gains altitude across 4 scroll beats.
- **2500 drifting pollen particles** with per-particle speed + offset, looping vertically.
- **Float component** wraps mid-scene crop tokens for organic motion.
- **EffectComposer**: `Bloom` for golden glow + `Vignette` for cinematic edges.
- **Stars** for distant atmosphere.

### Admin shell (every `/admin/*` route)
- **Persistent R3F canvas behind the UI** — 90 wireframe icosahedron sensor nodes connected by edges, slowly rotating, with 14 pulsing green accent spheres + bloom.
- **Glassmorphic sidebar** (`backdrop-filter: blur(24px)`) with glowing active state.
- **Page transitions** via Framer Motion (key=pathname → enter/exit).

### Parcelles page (`/admin/parcelles`)
- **3D globe** with one glowing pin per parcelle, color-coded by `statut` (Occupied/Available/Resting).
- Pin position is computed from real `latitude`/`longitude` via spherical → Cartesian math.
- Click a pin → animated detail panel switches via `AnimatePresence`.
- Drag to rotate (OrbitControls), no zoom/pan.

### Livestock page (`/admin/livestock`)
- Each élevage card embeds an inline 3D thumbnail (icosahedron / torus knot / octahedron + orbit ring + sparkles + bloom).

---

## How the data shapes match your Symfony code

| Frontend uses | Symfony source | Notes |
|---|---|---|
| `fetchParcelles()` → `{id, nom, localisation, superficie, typeSol, statut, latitude, longitude, dateCreation}` | `ParcelleRepository::findAllWithCultures()` + entity getters | All fields are direct getters on `App\Entity\Parcelle` |
| `fetchParcellesStats()` → `{total, available, occupied, resting, totalArea}` | `ParcelleRepository::getClientStats()` | Returns the exact shape already |
| `fetchLivestock()` → array of `{id, type_elevage, etat_elevage, production, animal_count}` | `LivestockRepository::findAllForManagement()` | Already returns array-hydrated rows |
| `fetchLivestockStats()` | `LivestockRepository::fetchStats()` + `AnimalRepository::fetchStats()` merged | Composite |
| `fetchMaintenances()` → `{id, type_m, description, date_m, statut, priorite, equipement}` | `MaintenanceRepository::findAllOrderedByDate()` + entity getters | `equipement` flattened to its name |
| `fetchDashboard()` | `ApiController::dashboard()` — composes the above + activity stub | Replace `revenue_series` with a real `CommandeRepository` query when ready |

---

## Integration path into the main app

This POC stays standalone. When you're ready to integrate:

**Option A — Replace only the admin UI (recommended)**
1. `npm run build` in `poc/frontend/` → emits `dist/`.
2. Configure Vite to output to `public/admin-spa/` (set `build.outDir`).
3. Add a Symfony catch-all route at `/admin/{any}` that serves `public/admin-spa/index.html`.
4. Keep the existing Twig templates as a fallback during transition.

**Option B — Full SPA replacement**
1. Move `poc/frontend/` to repo root as `frontend/`.
2. Symfony becomes API-only — strip Twig from controllers progressively, mark them as `Accept: application/json`.
3. Deploy frontend as a static SPA (Vercel/Netlify) pointing to your Symfony API URL.

**Option C — Twig + sprinkled React islands**
1. Keep Twig pages.
2. Mount individual R3F canvases into specific Twig templates via `<div id="hero-3d"></div>` + a small `mount.jsx`.
3. Useful if you only want the immersive bits without rewriting page logic.

---

## What's missing / next steps

| Area | Status | Next |
|------|--------|------|
| Public hero scene  | ✅ Real R3F terrain, pollen, scroll camera, bloom | Add per-crop GLB models on the terrain |
| Admin background   | ✅ R3F sensor mesh + bloom | Tie pulse rate to real sensor data via SSE |
| Parcelles 3D globe | ✅ Real lat/lng pins | Add heightmap + cluster zoom on click |
| Livestock cards    | ✅ Inline 3D | Wire your existing Tripo3D-generated GLBs (one per élevage) |
| Charts             | ✅ SVG sparkline | Swap for Chart.js or victory if you need full charting |
| Auth               | ⏳ Not wired | Reuse the Symfony session — `credentials: 'include'` is already on the fetch client |
| Routes             | ⏳ Stubbed | Add `/admin/cultures`, `/admin/orders`, etc., mirroring existing controllers |
| Backend mutations  | ⏳ GET only | Add POST/PUT endpoints in `ApiController` when ready to drive forms from React |

---

## Performance notes

- R3F canvas uses `dpr={[1, 2]}` — caps pixel ratio so retina phones don't melt.
- Admin background canvas runs at `dpr={1}` intentionally — it's atmospheric, not focal.
- `EffectComposer` is shared on each canvas; no double-rendering.
- Lenis is destroyed in the cleanup function — no leaks on route change.
- All KPI/section enter animations use `whileInView` with `once: true` — no rerun on rescroll.

---

## Why this stack instead of plain Symfony + Twig

The user's brief was *"true 3D immersive experience"*. That requires:

1. **A persistent WebGL canvas that survives section changes** — Twig page renders kill the canvas every navigation. SPA solves this.
2. **Scroll-pinned camera animation** — needs the camera state alive across many DOM sections. R3F + ScrollControls is the cleanest way.
3. **Per-component 3D mounts** with React's lifecycle — embedding R3F inside a Twig template means hand-managing Three.js instances. R3F handles it automatically.
4. **Page transition animations** — Framer Motion's `AnimatePresence` and Outlet pattern is purpose-built for this.

Symfony stays exactly where it shines: ORM, security, validation, business logic, AI services, biometric auth, SMS, payments. You're only replacing the *view layer*, not your platform.

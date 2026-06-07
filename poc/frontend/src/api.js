// ─── Symfony JSON API client ─────────────────────────────────────────
// All endpoints proxied via vite.config.js → http://127.0.0.1:8000
//
// To add new endpoints on the Symfony side, see poc/symfony-api/ApiController.php
// (a drop-in read-only controller mirroring the data shapes used here).

const BASE = '/api';

// Toggle the live API. Set VITE_USE_API=false (in .env.local) to force mocks,
// or VITE_USE_API=true to always try the network. Default = auto-probe.
const ENV_FORCE = import.meta.env.VITE_USE_API;
let apiAvailable = ENV_FORCE === 'true' ? true
                 : ENV_FORCE === 'false' ? false
                 : null;  // null = not probed yet

// Probe runs once. If anything other than a JSON 2xx is returned we go
// silent for the rest of the session — no more red lines in DevTools.
let probePromise = null;
function probeOnce() {
  if (apiAvailable !== null) return Promise.resolve(apiAvailable);
  if (probePromise) return probePromise;
  probePromise = fetch(`${BASE}/dashboard`, {
    headers: { Accept: 'application/json' },
    credentials: 'include',
  })
    .then((r) => { apiAvailable = r.ok; return apiAvailable; })
    .catch(() => { apiAvailable = false; return false; });
  return probePromise;
}

async function get(path) {
  if (!(await probeOnce())) throw new Error('api-unavailable');
  const r = await fetch(`${BASE}${path}`, {
    headers: { Accept: 'application/json' },
    credentials: 'include',
  });
  if (!r.ok) throw new Error(`API ${path} → ${r.status}`);
  return r.json();
}

// Helper: try /api, fall back silently to mock.
const useMock = (key, fallback) => async (...args) => {
  try {
    return await get(typeof key === 'function' ? key(...args) : key);
  } catch {
    return typeof fallback === 'function' ? fallback(...args) : fallback;
  }
};

// One-time banner in the console so you know the mode.
probeOnce().then((ok) => {
  const css = 'background:#22c55e;color:#000;padding:2px 8px;border-radius:4px;font-weight:600;';
  console.info(
    `%cEL FIRMA POC%c  data mode: ${ok ? 'LIVE Symfony API' : 'MOCK (Symfony not reachable)'}`,
    css, ''
  );
});

// ─── Parcelles ───────────────────────────────────────────────────────
// Shape mirrors ParcelleRepository::findPaginated() and getClientStats()
export const fetchParcellesStats = useMock('/parcelles/stats', {
  total: 24, available: 6, occupied: 14, resting: 4, totalArea: 142.6,
});

export const fetchParcelles = useMock('/parcelles', [
  { id: 1, nom: 'Parcelle A-1',  localisation: 'Béja',      superficie: 4.2, typeSol: 'Loamy',  statut: 'Occupied',  latitude: 36.726, longitude: 9.181,  dateCreation: '2024-03-12' },
  { id: 2, nom: 'Parcelle B-3',  localisation: 'Kairouan',  superficie: 2.8, typeSol: 'Sandy',  statut: 'Occupied',  latitude: 35.677, longitude: 10.101, dateCreation: '2024-05-02' },
  { id: 3, nom: 'Parcelle C-7',  localisation: 'Sidi Bouzid', superficie: 6.1, typeSol: 'Clay',  statut: 'Resting',   latitude: 35.038, longitude: 9.484,  dateCreation: '2023-11-18' },
  { id: 4, nom: 'Parcelle D-12', localisation: 'Béja',      superficie: 3.4, typeSol: 'Humus',  statut: 'Available', latitude: 36.701, longitude: 9.205,  dateCreation: '2025-01-08' },
  { id: 5, nom: 'Parcelle E-2',  localisation: 'Kairouan',  superficie: 5.0, typeSol: 'Loamy',  statut: 'Occupied',  latitude: 35.711, longitude: 10.040, dateCreation: '2024-08-21' },
  { id: 6, nom: 'Parcelle F-4',  localisation: 'Sidi Bouzid', superficie: 7.2, typeSol: 'Sandy', statut: 'Occupied',  latitude: 35.092, longitude: 9.521,  dateCreation: '2024-06-14' },
]);

// ─── Livestock (Elevage) ─────────────────────────────────────────────
// Shape mirrors LivestockRepository::findAllForManagement()
export const fetchLivestockStats = useMock('/livestock/stats', {
  total_elevages: 12, total_animals: 148, healthy: 132, sick: 9, quarantined: 7,
});

export const fetchLivestock = useMock('/livestock', [
  { id: 1, type_elevage: 'Bovin',    etat_elevage: 'Active',     production: 'Milk',        animal_count: 32 },
  { id: 2, type_elevage: 'Ovin',     etat_elevage: 'Active',     production: 'Wool & meat', animal_count: 64 },
  { id: 3, type_elevage: 'Volaille', etat_elevage: 'Quarantine', production: 'Eggs',        animal_count: 28 },
  { id: 4, type_elevage: 'Caprin',   etat_elevage: 'Active',     production: 'Cheese',      animal_count: 24 },
]);

// ─── Maintenance ─────────────────────────────────────────────────────
export const fetchMaintenances = useMock('/maintenances', [
  { id: 1, type_m: 'Oil change',         description: 'Tractor #3 — scheduled',     date_m: '2026-06-08', statut: 'planifie',  priorite: 'moyenne', equipement: 'Tractor #3' },
  { id: 2, type_m: 'Predictive alert',   description: 'Pump #04 vibration high',    date_m: '2026-06-05', statut: 'en_cours',  priorite: 'urgente', equipement: 'Pump #04' },
  { id: 3, type_m: 'Belt replacement',   description: 'Harvester — wear detected',  date_m: '2026-06-12', statut: 'planifie',  priorite: 'haute',   equipement: 'Harvester #1' },
  { id: 4, type_m: 'Coolant top-up',     description: 'Tractor #1 — routine',       date_m: '2026-06-04', statut: 'termine',   priorite: 'basse',   equipement: 'Tractor #1' },
  { id: 5, type_m: 'Sensor calibration', description: 'Irrigation sensors batch',   date_m: '2026-06-10', statut: 'en_attente', priorite: 'moyenne', equipement: 'Irrigation array' },
]);

// ─── Dashboard KPIs ──────────────────────────────────────────────────
export const fetchDashboard = useMock('/dashboard', {
  parcelles: 24, livestock: 148, revenue_dt: 42850, alerts: 4, orders_in_progress: 18,
  revenue_series: [9200, 11800, 10400, 14200, 16100, 18420],
  order_series:   [38, 52, 44, 61, 68, 75],
  months: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
  activity: [
    { kind: 'irrigation', text: 'Parcelle B-12 irrigation started automatically', ago: '2 min' },
    { kind: 'order',      text: 'New order #CMD-2891 — 340 DT',                   ago: '15 min' },
    { kind: 'alert',      text: 'Tractor #3 predicted failure in 48h',            ago: '1 h' },
    { kind: 'vaccine',    text: 'Cow #47 vaccination scheduled — SMS sent',       ago: '3 h' },
    { kind: 'contract',   text: 'AgroTech Maroc contract expires in 7 days',      ago: '1 d' },
  ],
});

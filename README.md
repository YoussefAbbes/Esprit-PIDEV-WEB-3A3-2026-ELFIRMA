<div align="center">

<img src="public/assets/img/logo.png" alt="EL FIRMA Logo" width="120" />

# 🌿 EL FIRMA
### Agricultural Enterprise Management Platform

*A full-stack Symfony 6.4 web application for modern farm management —  
from soil to sale, from livestock to logistics.*

<br/>

[![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4?style=for-the-badge&logo=php&logoColor=white)](https://php.net)
[![Symfony](https://img.shields.io/badge/Symfony-6.4_LTS-000000?style=for-the-badge&logo=symfony&logoColor=white)](https://symfony.com)
[![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3-7952B3?style=for-the-badge&logo=bootstrap&logoColor=white)](https://getbootstrap.com)
[![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://mysql.com)
[![Firebase](https://img.shields.io/badge/Firebase-Realtime_DB-FFCA28?style=for-the-badge&logo=firebase&logoColor=black)](https://firebase.google.com)

[![License](https://img.shields.io/badge/License-MIT-22c55e?style=for-the-badge)](LICENSE)
[![GitHub Stars](https://img.shields.io/github/stars/YoussefAbbes/Esprit-PIDEV-WEB-3A3-2026-ELFIRMA?style=for-the-badge&color=116530)](https://github.com/YoussefAbbes/Esprit-PIDEV-WEB-3A3-2026-ELFIRMA/stargazers)
[![GitHub Forks](https://img.shields.io/github/forks/YoussefAbbes/Esprit-PIDEV-WEB-3A3-2026-ELFIRMA?style=for-the-badge&color=5e9c3d)](https://github.com/YoussefAbbes/Esprit-PIDEV-WEB-3A3-2026-ELFIRMA/network)
[![Last Commit](https://img.shields.io/github/last-commit/YoussefAbbes/Esprit-PIDEV-WEB-3A3-2026-ELFIRMA?style=for-the-badge&color=0c4a23)](https://github.com/YoussefAbbes/Esprit-PIDEV-WEB-3A3-2026-ELFIRMA/commits)

</div>

---

## 📖 Table of Contents

- [About the Project](#-about-the-project)
- [Feature Highlights](#-feature-highlights)
- [Tech Stack](#-tech-stack)
- [System Architecture](#-system-architecture)
- [Getting Started](#-getting-started)
- [Environment Variables](#-environment-variables)
- [Module Overview](#-module-overview)
- [AI & Intelligence](#-ai--intelligence)
- [Biometric Authentication](#-biometric-authentication)
- [API Integrations](#-api-integrations)
- [Database Schema](#-database-schema)
- [Project Structure](#-project-structure)
- [Contributing](#-contributing)
- [Team](#-team)

---

## 🌾 About the Project

**EL FIRMA** is a comprehensive, production-grade agricultural enterprise management system built with **Symfony 6.4 LTS**. Born from the need to digitize and modernize farm operations, EL FIRMA brings together every aspect of agricultural business — from managing hectares of land and crop cycles to tracking livestock health, automating supply chains, and leveraging AI-driven insights.

This platform goes far beyond a typical CRUD application. It integrates **biometric authentication** (fingerprint + face ID), a **RAG-powered chatbot**, **machine-learning crop recommendations**, **3D livestock visualization**, **predictive equipment maintenance**, and **real-time SMS/push notifications** — all within a polished, mobile-friendly interface.

> 🎓 Developed as a PIDEV (Projet d'Intégration et de Développement) at **ESPRIT School of Engineering** — 3rd Year, Group 3A3, Academic Year 2025–2026.

---

## ✨ Feature Highlights

<table>
<tr>
<td width="50%">

### 🌱 Agricultural Core
- **Parcel & Crop Management** — Track land parcels with GPS coordinates, soil types, and full crop lifecycle (planting → harvest)
- **Livestock & Animal Management** — Manage herds, individual animals, health records, and vaccination schedules
- **Smart Irrigation** — Automated irrigation event scheduling and monitoring
- **Crop Recommendation Engine** — ML-based optimal crop suggestions from soil & climate data

</td>
<td width="50%">

### 🤖 AI & Intelligence
- **RAG Chatbot** — Retrieval-Augmented Generation with Gemini Flash LLM
- **Predictive Maintenance** — ML forecasting of equipment failures before they happen
- **3D Livestock Models** — Generate 3D animal visualizations via Tripo3D API
- **Voice & Gesture Commands** — Accessibility-first hands-free navigation
- **Animal DNA Sex Detection** — DNA sequence analysis for livestock breeding

</td>
</tr>
<tr>
<td width="50%">

### 🔐 Security & Authentication
- **Fingerprint Biometrics** — ZKFinger SDK bridge (Java) for enrollment & verification
- **Face ID** — Python-based facial recognition (threshold: 0.28)
- **Two-Factor Auth (OTP)** — TOTP via RFC 6238
- **OAuth 2.0** — Google & GitHub single sign-on
- **reCAPTCHA** — Bot protection on all forms

</td>
<td width="50%">

### 🏪 Supply Chain & Commerce
- **Supplier Analytics** — Scoring, performance metrics, and contract tracking
- **Order Management** — Full order lifecycle with cart, checkout & Stripe payments
- **Contract PDF Generation** — Auto-generated supplier contracts with DOMPDF
- **Meeting Scheduling** — Calendar + Jitsi video integration for supplier meetings
- **Multi-currency Support** — Live exchange rate conversion

</td>
</tr>
<tr>
<td width="50%">

### 📡 Communications
- **SMS Alerts** — Twilio-powered vaccination reminders, capacity warnings, maintenance alerts
- **Email Notifications** — SendGrid transactional emails (invitations, reports, alerts)
- **Firebase Push Notifications** — Real-time mobile app sync
- **Google Calendar Export** — One-click meeting/event export

</td>
<td width="50%">

### 📊 Reporting & Data
- **PDF Reports** — DOMPDF-generated maintenance, contract & analytics reports
- **Excel Export/Import** — Bulk data via PHPSpreadsheet (CSV/XLSX)
- **ChartJS Dashboards** — Live KPI charts with Symfony UX
- **Supplier Analytics Dashboard** — Visual performance metrics and trends
- **Weather Integration** — OpenWeather API for farm location forecasts

</td>
</tr>
</table>

---

## 🛠 Tech Stack

### Backend
| Technology | Version | Purpose |
|---|---|---|
| **PHP** | `≥ 8.1` | Runtime language |
| **Symfony** | `6.4 LTS` | Web framework |
| **Doctrine ORM** | `3.6` | Database abstraction & migrations |
| **MySQL** | `8.0` | Primary relational database |
| **Symfony Messenger** | `6.4` | Async message queue |
| **Symfony Security** | `6.4` | Authentication & authorization |
| **DOMPDF** | `3.1` | PDF generation |
| **PHPSpreadsheet** | `5.6` | Excel/CSV import & export |
| **VichUploaderBundle** | `2.9` | Smart file upload management |
| **KnpPaginatorBundle** | `6.10` | List pagination |
| **Firebase Bundle** | `3.1` | Mobile backend & push notifications |
| **OTPHP** | `11.4` | TOTP two-factor authentication |
| **endroid/qr-code** | `6.0` | QR code generation |
| **Geocoder PHP** | `5.8` | Nominatim/OSM geocoding |

### Frontend
| Technology | Purpose |
|---|---|
| **Bootstrap 5.3.3** | Responsive UI framework |
| **Stimulus JS** | Lightweight frontend controllers |
| **Turbo** | SPA-like navigation without full reloads |
| **Symfony UX ChartJS** | Data visualization |
| **AOS (Animate On Scroll)** | Scroll-driven animations |
| **Swiper** | Touch-friendly carousels |
| **GLightbox** | Image/video lightbox |
| **Twig 3** | Server-side templating |
| **Material Symbols** | Icon system |

### AI / Python Services
| Service | Port | Technology | Purpose |
|---|---|---|---|
| **RAG Chatbot Engine** | — | Python + Gemini Flash | Agricultural Q&A chatbot |
| **Crop Recommender** | — | ExtraTreesClassifier | ML-based crop selection |
| **Face ID Service** | `8765` | Python + face_recognition | Biometric face authentication |
| **Chatbot NLP API** | `8002` | FastAPI + NaiveBayes | Voice/gesture intent detection |

### Java Services
| Service | Port | Technology | Purpose |
|---|---|---|---|
| **Fingerprint Bridge** | `8085` | Java + ZKFinger SDK | Fingerprint enrollment & matching |

### External APIs & Integrations
| API | Purpose |
|---|---|
| **Google Gemini Flash** | LLM for chatbot responses |
| **OpenAI** | Alternative LLM support |
| **Tripo3D** | 3D livestock model generation |
| **Pixabay** | Auto-fetch product/crop images |
| **Trefle** | Plant species & growing guide data |
| **USDA** | Agricultural datasets |
| **OpenWeather** | Real-time farm weather |
| **Twilio** | SMS notifications |
| **SendGrid** | Transactional emails |
| **Stripe** | Payment processing |
| **Google OAuth2** | Social login |
| **GitHub OAuth2** | Social login |
| **Google reCAPTCHA** | Form bot protection |
| **Jitsi Meet** | Video meetings with suppliers |
| **MapTiler** | Map tiles for parcel views |
| **Exchange Rates API** | Live currency conversion |
| **API-Ninjas** | Profanity content moderation |

---

## 🏗 System Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                          EL FIRMA                               │
│                    Symfony 6.4 Application                      │
├──────────────┬──────────────┬──────────────┬────────────────────┤
│   Web Layer  │ Service Layer│  Data Layer  │  External Services │
│              │              │              │                    │
│  37 Controllers  36+ Services  Doctrine ORM   Firebase RT DB   │
│  22 Dashboard │  Business    │  MySQL 8.0   │  Twilio SMS        │
│  Templates   │  Logic       │  8 Migrations│  SendGrid Email    │
│  Twig Views  │  AI/ML       │  Repos       │  Stripe Payments   │
├──────────────┴──────────────┴──────────────┴────────────────────┤
│                      Microservice Layer                          │
│                                                                  │
│  [Python:8002]    [Python:8765]    [Java:8085]                  │
│  FastAPI/NLP      Face ID          ZKFinger SDK                 │
│  Chatbot          Recognition      Fingerprint                  │
│  Crop ML          Biometrics       Biometrics                   │
└─────────────────────────────────────────────────────────────────┘
```

---

## 🚀 Getting Started

### Prerequisites

Ensure you have the following installed:

- **PHP** `>= 8.1` with extensions: `pdo_mysql`, `intl`, `mbstring`, `gd`, `zip`, `curl`
- **Composer** `>= 2.0`
- **MySQL** `>= 8.0`
- **Node.js** `>= 18` (for asset building)
- **Python** `>= 3.9` (for AI/ML features)
- **Java JRE** `>= 11` (for fingerprint bridge)
- **Git**

### Installation

**1. Clone the repository**
```bash
git clone https://github.com/YoussefAbbes/Esprit-PIDEV-WEB-3A3-2026-ELFIRMA.git
cd Esprit-PIDEV-WEB-3A3-2026-ELFIRMA
```

**2. Install PHP dependencies**
```bash
composer install
```

**3. Configure environment**
```bash
cp .env .env.local
# Edit .env.local and fill in all required values (see Environment Variables section)
```

**4. Set up the database**
```bash
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate
```

**5. Set up Python environment (for AI features)**
```bash
# RAG Chatbot
python -m venv .venv
.venv\Scripts\activate        # Windows
# source .venv/bin/activate   # Linux/macOS
pip install -r rag/requirements.txt

# Face ID Service
cd scripts/faceid
python -m venv .venv
pip install -r requirements.txt
```

**6. Start microservices**
```bash
# Terminal 1 — Chatbot NLP API
cd chatbot && uvicorn main:app --port 8002

# Terminal 2 — Face ID Service  
cd scripts/faceid && python server.py

# Terminal 3 — Fingerprint Bridge
cd fingerprint && java -jar FingerprintBridgeServer.jar
```

**7. Start the Symfony server**
```bash
symfony server:start
# or
php -S localhost:8000 -t public/
```

**8. Open the application**

| Interface | URL |
|---|---|
| Public website | http://localhost:8000 |
| Admin dashboard | http://localhost:8000/elfirma |
| Login | http://localhost:8000/login |

---

## 🔑 Environment Variables

<details>
<summary><strong>Click to expand — full .env reference</strong></summary>

```dotenv
# ─── Application ──────────────────────────────────────
APP_ENV=dev
APP_SECRET=your_app_secret_32chars

# ─── Database ─────────────────────────────────────────
DATABASE_URL="mysql://root:@127.0.0.1:3306/elfirma"

# ─── Async Messaging ──────────────────────────────────
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0

# ─── Email ────────────────────────────────────────────
MAILER_DSN=sendgrid+api://SG.YOUR_KEY@default
MAILER_FROM=no-reply@elfirma.com

# ─── Firebase ─────────────────────────────────────────
FIREBASE_CREDENTIALS_PATH=/path/to/serviceAccount.json
FIREBASE_PROJECT_ID=elfirma-project
FIREBASE_DATABASE_URI=https://elfirma-project-default-rtdb.firebaseio.com

# ─── SMS (Twilio) ─────────────────────────────────────
TWILIO_ACCOUNT_SID=ACxxxxxxxxxxxxxxxx
TWILIO_API_KEY_SID=SKxxxxxxxxxxxxxxxx
TWILIO_API_KEY_SECRET=your_key_secret
TWILIO_FROM_PHONE=+1xxxxxxxxxx
TWILIO_TO_PHONE=+1xxxxxxxxxx

# ─── Payments (Stripe) ────────────────────────────────
STRIPE_PUBLIC_KEY=pk_test_xxxx
STRIPE_SECRET_KEY=sk_test_xxxx
STRIPE_CURRENCY=eur

# ─── AI / LLM ─────────────────────────────────────────
RAG_PYTHON_PATH=.venv\Scripts\python.exe
RAG_CHAT_ENGINE_PATH=rag/scripts/chat_engine.py
RAG_LLM_PROVIDER=gemini
RAG_LLM_MODEL=gemini-flash-latest
OPENAI_API_KEY=sk-xxxx

# ─── 3D Generation ────────────────────────────────────
TRIPO3D_API_KEY=your_tripo3d_key
TRIPO3D_API_BASE_URL=https://api.tripo3d.ai
TRIPO3D_TIMEOUT=180

# ─── Biometrics ───────────────────────────────────────
FACE_ID_HOST=127.0.0.1
FACE_ID_PORT=8765
FACE_ID_THRESHOLD=0.28
FACE_ID_PYTHON_BIN=scripts/faceid/.venv/Scripts/python.exe

# ─── Agriculture APIs ─────────────────────────────────
TREFLE_API_KEY=your_trefle_key
USDA_API_KEY=your_usda_key
OPENWEATHER_API_KEY=your_openweather_key
PIXABAY_API_KEY=your_pixabay_key
MAPTILER_API_KEY=your_maptiler_key
EXCHANGE_RATE_API_KEY=your_exchange_key

# ─── OAuth ────────────────────────────────────────────
GOOGLE_CLIENT_ID=xxxx.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your_google_secret
GITHUB_CLIENT_ID=your_github_client_id
GITHUB_CLIENT_SECRET=your_github_secret

# ─── reCAPTCHA ────────────────────────────────────────
RECAPTCHA_SITE_KEY=your_recaptcha_site_key
RECAPTCHA_SECRET_KEY=your_recaptcha_secret

# ─── Content Moderation ───────────────────────────────
PROFANITY_FILTER_API_KEY=your_api_ninjas_key

# ─── Alerts & Monitoring ──────────────────────────────
CAPACITY_ALERT_THRESHOLD_PERCENT=85
CAPACITY_ALERT_TO_EMAIL=admin@elfirma.com
CAPACITY_ALERT_FROM_EMAIL=alerts@elfirma.com
```

</details>

---

## 🗂 Module Overview

### 🌐 Public Website (`/`)
The customer-facing site includes a hero carousel, service catalog, agricultural product showcase, livestock catalog, team pages, blog, testimonials, and contact form — all with SEO-optimized templates.

### 🖥 Admin Dashboard (`/elfirma`)

| Module | Route | Features |
|---|---|---|
| 📊 **Dashboard** | `/elfirma` | KPI overview, ChartJS analytics, activity feed |
| 👥 **Users** | `/elfirma/utilisateurs` | User management, role assignment, 2FA status |
| 🗺 **Parcels & Crops** | `/elfirma/parcelles-cultures` | Map view, crop lifecycle, CSV/XLSX import, recommendation engine |
| 🐄 **Livestock & Animals** | `/elfirma/animaux-elevages` | Herd management, vaccination, 3D modeling, DNA detection |
| 📦 **Products** | `/elfirma/produits` | Inventory, auto-image fetch, video generation |
| 🛒 **Orders** | `/elfirma/produits-commandes` | Order workflow, cart, Stripe checkout |
| 🔧 **Equipment** | `/elfirma/equipements-maintenance` | Asset tracking, predictive maintenance, service history |
| 🤝 **Suppliers & Contracts** | `/elfirma/fournisseurs-contrats` | Supplier scoring, contract PDF, meeting scheduling |
| 📋 **Complaints** | `/elfirma/reclamations` | Complaint tracking, resolution workflow, SMS notification |
| 📈 **Analytics** | `/elfirma/supplier-analytics` | Supplier performance charts, trend analysis |

---

## 🧠 AI & Intelligence

### RAG Chatbot
The built-in chatbot uses a **Retrieval-Augmented Generation** pipeline. It embeds your farm's knowledge base and retrieves relevant context before querying **Google Gemini Flash** for natural-language responses about crops, livestock, and operations.

```
User Query → Document Retrieval (Top-K=5) → Gemini Flash LLM → Response
```

### Crop Recommendation Engine
Input soil parameters to get ML-powered crop recommendations:

| Input Feature | Description |
|---|---|
| N / P / K | Nitrogen, Phosphorus, Potassium levels (mg/kg) |
| Temperature | Average temperature (°C) |
| Humidity | Relative humidity (%) |
| pH | Soil pH level |
| Rainfall | Annual rainfall (mm) |

**Output:** Recommended crop, confidence score, top-3 alternatives, and agronomic guidance. Falls back to a profile-scoring algorithm if the Python model is unavailable.

### Predictive Maintenance
Analyzes equipment age, service hours, and historical maintenance patterns to **predict when next maintenance is required** — before a breakdown occurs. Generates calendar events and SMS alerts automatically.

### Voice & Gesture Assistant
- **Voice Commands:** Navigate catalog, add to cart, and complete checkout hands-free using natural language
- **Supplier Form Filling:** Dictate supplier details to populate forms automatically
- **Gesture Recognition:** Map hand gestures to application actions (built with `NaiveBayesClassifier`)

### 3D Livestock Visualization
Generate photorealistic 3D models of animals by providing a text description or image via the **Tripo3D API**. Models are stored and viewable in an interactive 3D viewer.

---

## 🔐 Biometric Authentication

EL FIRMA implements a multi-layered biometric security system — a feature rare in web applications:

### Fingerprint Recognition

```
Browser ──► Symfony Controller ──► HTTP ──► Java Bridge (port 8085)
                                              │
                                              └──► ZKFinger SDK
                                                   Enrollment / Match
```

| Endpoint | Action |
|---|---|
| `POST /fingerprint/enroll/start` | Start fingerprint enrollment |
| `POST /fingerprint/enroll/capture` | Capture fingerprint sample |
| `POST /fingerprint/verify` | Verify against stored template |
| `POST /fingerprint/identify` | Identify user from database |

### Face ID

```
Browser ──► Symfony Controller ──► HTTP ──► Python Service (port 8765)
                                              │
                                              └──► face_recognition library
                                                   Encode / Compare (threshold: 0.28)
```

---

## 🔗 API Integrations

```
┌─────────────────────────────────────────────────────────────┐
│                    External API Map                          │
├──────────────┬──────────────────────────────────────────────┤
│ 🌱 Agri      │ Trefle (plants) · USDA (data) · OpenWeather  │
│ 🤖 AI        │ Google Gemini · OpenAI · Tripo3D             │
│ 💬 Comms     │ Twilio SMS · SendGrid Email · Jitsi Video    │
│ 🗺 Geo       │ Nominatim (OSM) · MapTiler · OpenLayers      │
│ 💳 Finance   │ Stripe Payments · Exchange Rates API         │
│ 🔐 Auth      │ Google OAuth2 · GitHub OAuth2 · reCAPTCHA   │
│ 📸 Media     │ Pixabay Images · Firebase Storage           │
│ 🛡 Safety    │ API-Ninjas Profanity Filter                  │
│ 📅 Calendar  │ Google Calendar · Zoho Meeting              │
└──────────────┴──────────────────────────────────────────────┘
```

---

## 🗄 Database Schema

**23 entities** across 8 migrations, organized into domain clusters:

<details>
<summary><strong>View entity relationships</strong></summary>

```
AGRICULTURAL CORE
─────────────────
Parcelle ──┐
           ├──[1:N]──► Culture
           └── (GPS coords, soil type, status)

Livestock ──┐
            ├──[1:N]──► Animal ──[1:N]──► Vaccination
            └── (type, capacity, headcount)

EQUIPMENT
─────────
Equipement ──[1:N]──► Maintenance
  └── (type, acquisition cost, service hours, predictive alerts)

COMMERCE
────────
Fournisseur ──┬──[1:N]──► Contrat
              ├──[1:N]──► Meeting
              └──[1:N]──► Commande

Produit ──[M:N]──► Commande
  └── (type, stock qty, expiry date, image)

Categorie ──[1:N]──► Produit

USERS & FEEDBACK
────────────────
Utilisateur ──[1:N]──► Reclamation
  └── (role, biometric templates, OAuth IDs)

Rating
Notification
IrrigationEvent
```

</details>

---

## 📁 Project Structure

```
elfirma/
├── config/                     # Symfony configuration
│   ├── packages/               # Bundle configs (doctrine, security, mailer…)
│   └── routes/                 # Route definitions
│
├── migrations/                 # Doctrine database migrations (8 files)
│
├── public/                     # Web root
│   └── assets/
│       ├── css/main.css        # 2300+ line custom stylesheet
│       ├── img/                # Hero images, team photos, logos
│       ├── js/                 # JavaScript modules
│       └── vendor/             # Bootstrap, AOS, Swiper, GLightbox…
│
├── src/
│   ├── Controller/             # 37 controllers
│   ├── Entity/                 # 23 Doctrine entities
│   ├── Repository/             # Data access layer
│   ├── Service/                # 36+ business services
│   ├── EventSubscriber/        # Symfony event listeners
│   ├── Security/               # Auth providers, OAuth handlers
│   ├── AI/                     # Intent classifiers, NLP models
│   └── DTO/                    # Data transfer objects
│
├── templates/
│   ├── base.html.twig          # Main layout
│   ├── elfirma/                # 22 admin dashboard templates
│   │   ├── cultures/           # Crop management views
│   │   ├── parcelles/          # Parcel management views
│   │   └── Livestock&Animal Management/
│   ├── pages/                  # 10 public website pages
│   ├── auth/                   # Login, signup, 2FA templates
│   ├── emails/                 # Transactional email templates
│   └── partials/               # Header, footer, navigation
│
├── fingerprint/                # Java ZKFinger SDK bridge
│   └── FingerprintBridgeServer.java
│
├── rag/                        # Python RAG chatbot
│   └── scripts/chat_engine.py
│
└── scripts/
    └── faceid/                 # Python Face ID service
```

---

## 🧪 Running Tests

```bash
# Run the full test suite
php bin/phpunit

# Run with coverage report
php bin/phpunit --coverage-html coverage/

# Static analysis
vendor/bin/phpstan analyse src --level=5
```

---

## 🤝 Contributing

Contributions are welcome! Please follow these steps:

1. **Fork** the repository
2. **Create** a feature branch — `git checkout -b feature/your-feature-name`
3. **Commit** your changes — `git commit -m 'feat: add amazing feature'`
4. **Push** to your branch — `git push origin feature/your-feature-name`
5. **Open** a Pull Request against `main`

### Commit Convention

We follow **Conventional Commits**:

| Prefix | Use For |
|---|---|
| `feat:` | New feature |
| `fix:` | Bug fix |
| `refactor:` | Code restructuring |
| `docs:` | Documentation update |
| `test:` | Test additions |
| `chore:` | Build / tooling changes |

---

## 👥 Team

This project was built by a dedicated team of engineering students at **ESPRIT School of Engineering**:

<div align="center">

| | Contributor | GitHub |
|---|---|---|
| 🧑‍💻 | **Youssef Abbes** | [@YoussefAbbes](https://github.com/YoussefAbbes) |
| 🧑‍💻 | **Mohamed Yassine Labidi** | [@yassine241206](https://github.com/yassine241206) |
| 🧑‍💻 | **Ahmed Zouari** | [@ahmedzouari-Xa](https://github.com/ahmedzouari-Xa) |
| 🧑‍💻 | **Nourhene Zouabi** | [@nourhene-zouabi](https://github.com/nourhene-zouabi) |
| 🧑‍💻 | **Ikam** | [@Ikam2](https://github.com/Ikam2) |

</div>

> 🎓 **ESPRIT — École Supérieure Privée d'Ingénierie et de Technologies**  
> 3rd Year Engineering · PIDEV · Group 3A3 · 2025–2026

---

## 📄 License

This project is licensed under the **MIT License** — see the [LICENSE](LICENSE) file for details.

---

<div align="center">

**Built with 💚 and a lot of ☕ by Team 3A3 at ESPRIT**

*Turning the age-old practice of farming into a data-driven, AI-powered enterprise.*

<br/>

[![GitHub](https://img.shields.io/badge/View_on_GitHub-181717?style=for-the-badge&logo=github&logoColor=white)](https://github.com/YoussefAbbes/Esprit-PIDEV-WEB-3A3-2026-ELFIRMA)

</div>

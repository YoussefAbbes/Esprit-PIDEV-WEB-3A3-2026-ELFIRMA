# Elfirma Agriculture — JavaFX Desktop Client Spec

## Briefing for Claude

You are building a **JavaFX desktop application** that is a feature-complete desktop client for the **Elfirma** agriculture management system. This system is also served by a Symfony web application that shares the **same MySQL database**. Your JavaFX app reads and writes to that same DB — there is no API layer between them, just direct JDBC.

**Your job**: Check what the JavaFX project already has, then implement everything that is missing from this spec. Do not duplicate what already works.

**Tech stack for the JavaFX app**:
- Java 17+ with JavaFX 17+
- JDBC + MySQL Connector/J for database
- Maven or Gradle for dependency management
- Suggested libs: Apache POI (Excel), iText or Apache PDFBox (PDF), Gson or Jackson (JSON/API calls), JavaFX Charts (built-in)
- Scene Builder for FXML layouts
- Use `Task<T>` and `Platform.runLater()` for all background work (DB queries, API calls)

---

## 1. Database Connection

**Database**: MySQL (same instance as the Symfony web app)

Connection details come from the Symfony `.env` file:
```
DB Host:     127.0.0.1
DB Port:     3306
DB Name:     personne
DB User:     root
DB Password: (empty)
```

JDBC URL: `jdbc:mysql://127.0.0.1:3306/personne?useSSL=false&allowPublicKeyRetrieval=true&serverTimezone=UTC`

> **Important**: All string values stored in the DB (statuses, types, enums) must match exactly as documented in Section 8. A mismatch will break the Symfony web app's ability to read data you wrote.

---

## 2. Complete Database Schema

### `parcelle`
| Column | Type | Notes |
|--------|------|-------|
| id | int PK AUTO | |
| nom | varchar(255) NOT NULL | Plot name |
| localisation | varchar(255) NOT NULL | Address/location text |
| superficie | float NOT NULL | Area in hectares |
| type_sol | varchar(100) | Soil type — see enums |
| statut | varchar(50) | Plot status — see enums |
| image | longblob | Raw image bytes (nullable) |
| date_creation | date | |
| latitude | double | GPS latitude (nullable) |
| longitude | double | GPS longitude (nullable) |

### `culture`
| Column | Type | Notes |
|--------|------|-------|
| id | int PK AUTO | |
| parcelle_id | int FK → parcelle.id | Nullable |
| nom_culture | varchar(255) NOT NULL | Crop name |
| variete | varchar(255) | Variety/cultivar |
| date_plantation | date | |
| date_recolte_prevue | date | Expected harvest date |
| date_recolte_reelle | date | Actual harvest date |
| quantite_plantee | double NOT NULL | Quantity planted (kg/units) |
| quantite_recoltee | double NOT NULL | Quantity harvested |
| statut | varchar(50) | Crop status — see enums |
| cout_production | double NOT NULL | Production cost (TND) |
| rendement | float | Yield percentage |
| observations | text | Free notes |
| image | longblob | Crop photo (nullable) |

### `elevage` (Livestock Farm)
| Column | Type | Notes |
|--------|------|-------|
| id_elevage | int PK AUTO | |
| type_elevage | varchar(100) NOT NULL | Farm type — see enums |
| etat_elevage | varchar(50) NOT NULL | Farm state — see enums |
| capacite | int NOT NULL | Max animal capacity |
| nombre_animaux | int NOT NULL | Current animal count (auto-synced) |
| production | varchar(200) NOT NULL | What the farm produces (e.g. "Milk, Wool") |
| latitude | double | GPS (nullable) |
| longitude | double | GPS (nullable) |

### `animal`
| Column | Type | Notes |
|--------|------|-------|
| id_animal | int PK AUTO | |
| id_elevage | int NOT NULL FK → elevage.id_elevage | |
| type_animal | varchar(50) NOT NULL | Species/type |
| sexe | varchar(20) NOT NULL | See enums |
| age | int NOT NULL | Age in months |
| etat_sante | varchar(50) NOT NULL | Health state — see enums |
| statut | varchar(50) NOT NULL | Animal status — see enums |
| photo_name | varchar(255) | Filename of uploaded photo (nullable) |

### `vaccination`
| Column | Type | Notes |
|--------|------|-------|
| id_vaccination | int PK AUTO | |
| id_animal | int NOT NULL FK → animal.id_animal | |
| vaccine_name | varchar(100) NOT NULL | |
| date_done | date | Date vaccinated (nullable if scheduled) |
| date_next | date NOT NULL | Next vaccination due date |
| notes | varchar(255) | |
| status | enum('Scheduled','Done','Overdue') | |

### `equipement`
| Column | Type | Notes |
|--------|------|-------|
| id_eq | int PK AUTO | |
| nom_eq | varchar(100) NOT NULL | Equipment name |
| type_eq | varchar(100) NOT NULL | Equipment category |
| date_achat | date NOT NULL | Purchase date |
| etat | enum('disponible','maintenance','panne') | State |
| cout_achat | double NOT NULL | Purchase cost (TND) |
| description_eq | varchar(200) NOT NULL | |
| image_eq | varchar(255) NOT NULL | Filename (stored by Symfony VichUploader) |

### `maintenance`
| Column | Type | Notes |
|--------|------|-------|
| id_m | int PK AUTO | |
| type_m | varchar(50) NOT NULL | Type of maintenance |
| date_m | date NOT NULL | Maintenance date |
| description | varchar(200) NOT NULL | |
| cout | double NOT NULL | Cost (TND) |
| statut | varchar(255) | Status — see enums |
| priorite | varchar(255) | Priority — see enums |
| id_equipement | int FK → equipement.id_eq | Nullable |
| technicien_id | int NOT NULL FK → utilisateur.id_u | Assigned technician |

### `produit`
| Column | Type | Notes |
|--------|------|-------|
| id_produit | int PK AUTO | |
| nom | varchar(100) NOT NULL | Product name |
| type | varchar(30) NOT NULL | Product type — see enums |
| prix_unitaire | decimal(10,2) NOT NULL | Price in TND |
| quantite_stock | int NOT NULL | Stock quantity |
| qualite | varchar(20) | Quality grade |
| date_production | date | |
| date_expiration | date | |
| image | varchar(255) | Filename (Symfony VichUploader) |
| statut | varchar(20) | Status — see enums |
| categorie_id | int FK → categorie.id | Nullable |

### `categorie`
| Column | Type | Notes |
|--------|------|-------|
| id | int PK AUTO | |
| nom | varchar(100) NOT NULL | Category name |

### `commande` (Orders)
| Column | Type | Notes |
|--------|------|-------|
| id_commande | int PK AUTO | |
| quantite | int NOT NULL | |
| prix_total | decimal(10,2) NOT NULL | |
| statut_commande | varchar(20) | Order status — see enums |
| mode_paiement | varchar(30) | Payment method — see enums |
| statut_paiement | varchar(30) | Payment status — see enums |
| facture | varchar(255) | Invoice filename |
| nom_client | varchar(100) | Client name |
| adresse_livraison | varchar(255) | Delivery address |
| date_commande | date NOT NULL | |
| id_produit | int FK → produit.id_produit | Nullable |
| id_utilisateur | int FK → utilisateur.id_u | Nullable |
| telephone | varchar(20) | |

### `fournisseur` (Suppliers)
| Column | Type | Notes |
|--------|------|-------|
| id_f | int PK AUTO | |
| type_f | varchar(100) NOT NULL | Supplier category |
| description_f | text | |
| adresse_f | varchar(255) | |
| tel_f | varchar(20) | |
| email_f | varchar(100) UNIQUE | |
| statut_f | varchar(50) NOT NULL | Status — see enums |
| latitude_f | double | GPS (nullable) |
| longitude_f | double | GPS (nullable) |

### `contrat` (Contracts)
| Column | Type | Notes |
|--------|------|-------|
| id_contrat | int PK AUTO | |
| date_debut_f | date NOT NULL | Start date |
| date_fin_f | date NOT NULL | End date |
| type_c_f | varchar(255) NOT NULL | Contract type — see enums |
| statut_c_f | varchar(255) NOT NULL | Status — see enums |
| id_f | int NOT NULL FK → fournisseur.id_f | |
| pdf_file | varchar(255) | PDF filename |

### `meeting`
| Column | Type | Notes |
|--------|------|-------|
| id | int PK AUTO | |
| supplier_id | int NOT NULL FK → fournisseur.id_f | |
| meeting_link | varchar(500) NOT NULL | Jitsi Meet URL |
| created_at | timestamp NOT NULL | |
| created_by | int FK → utilisateur.id_u | |
| updated_by | int FK → utilisateur.id_u | |

### `rating`
| Column | Type | Notes |
|--------|------|-------|
| id_rating | int PK AUTO | |
| id_f | int NOT NULL FK → fournisseur.id_f | |
| user_id | int NOT NULL FK → utilisateur.id_u | |
| number_of_stars | int NOT NULL | 1–5 |
| comment | text | |
| created_at | timestamp NOT NULL | |
| updated_at | timestamp | auto-updated |

### `reclamation` (Complaints)
| Column | Type | Notes |
|--------|------|-------|
| idr_u | int PK AUTO | |
| titre_u | varchar(100) | Title |
| type_reclamation_u | varchar(50) | Type |
| description_u | text | |
| date_reclamation_u | date | |
| statut_u | varchar(30) | Status |
| utilisateur_id_u | int FK → utilisateur.id_u | |

### `utilisateur` (Users)
| Column | Type | Notes |
|--------|------|-------|
| id_u | int PK AUTO | |
| nom_u | varchar(50) | Last name |
| prenom_u | varchar(50) | First name |
| email_u | varchar(100) UNIQUE | Login email |
| mot_de_passe_u | varchar(100) | Bcrypt hashed password |
| role_u | varchar(30) | See enums |
| image_u | varchar(255) | Profile photo filename |
| photo_face | varchar(255) | Face photo filename |
| qr_code_token | varchar(36) UNIQUE | UUID for QR login |
| date_creation_u | timestamp NOT NULL | |
| fingerprint_template | blob | Raw biometric data |
| fingerprint_length | int unsigned | |

### `user_notifications`
| Column | Type | Notes |
|--------|------|-------|
| id | int PK AUTO | |
| user_id | int NOT NULL FK → utilisateur.id_u | |
| title | varchar(255) NOT NULL | Notification text |
| type | varchar(50) | Category |
| is_read | tinyint(1) | 0=unread, 1=read |

### Irrigation Tables
**`irrigation_command`** — Commands queued to hardware devices
- `id` bigint PK, `parcelle_id` int FK, `command` enum('AUTO','MANUAL_ON','MANUAL_OFF'), `requested_by` varchar(100), `status` enum('PENDING','ACK','FAILED')

**`irrigation_state`** — Current state per parcel (PK = parcelle_id)
- `parcelle_id` int PK, `mode` enum('AUTO','MANUAL_ON','MANUAL_OFF'), `pump_running` tinyint(1), `soil_value` tinyint(4), `needs_water` tinyint(1)

**`irrigation_event`** — Event log
- `id` bigint PK, `parcelle_id` int FK, `source` enum('WEB','DEVICE','AUTO'), `event_type` varchar(50), `soil_value`, `needs_water`, `created_by`, `updated_by`

---

## 3. Enum Reference — Exact String Values

> These must match exactly in INSERT/UPDATE statements. Case-sensitive.

### Parcelle
- **statut**: `Available`, `Occupied`, `Resting`
- **type_sol**: `Sandy`, `Loamy`, `Clay`, `Humus`

### Culture
- **statut**: `Planned`, `In Progress`, `Harvested`

### Animal
- **sexe**: `Male`, `Female`
- **etat_sante**: `Healthy`, `Sick`
- **statut**: `For Sale`, `Retained`, `Pending Decision`

### Elevage (Livestock Farm)
- **type_elevage**: `Sheep Farm`, `Cattle Farm`, `Poultry Farm`, `Bovin Farm`
- **etat_elevage**: `Clean`, `Dirty`

### Vaccination
- **status**: `Scheduled`, `Done`, `Overdue`

### Equipement
- **etat** (DB enum): `disponible`, `maintenance`, `panne`

### Maintenance
- **statut**: `planifie`, `en_cours`, `termine`, `en_attente`
- **priorite**: `urgente`, `haute`, `moyenne`, `basse`

### Produit
- **type**: `Frais`, `Biologique`, `Transformé`
- **statut**: `Disponible`, `Expiré`  *(note the accents — store exactly as shown)*

### Fournisseur
- **statut_f**: `active`, `inactive`, `suspended`

### Contrat
- **statut_c_f**: `active`, `inactive`, `suspended`
- **type_c_f**: `annual`, `monthly`

### Commande
- **statut_commande**: `En attente`, `Confirmée`, `Annulée`
- **mode_paiement**: `Cash`, `Carte bancaire`
- **statut_paiement**: `Payé`, `Non payé`

### Utilisateur
- **role_u**: `admin`, `employee`, `client`

---

## 4. Module Specifications

---

### Module 1: Parcelles (Land Plots)

**Purpose**: Manage agricultural land plots. Each plot has a soil type, GPS location, and is linked to crops and irrigation.

**Features to implement**:

#### 4.1.1 CRUD
- List view: table with columns nom, localisation, superficie (ha), type_sol, statut, date_creation, culture count
- Search by nom or localisation (SQL LIKE)
- Filter by statut and type_sol
- Add/Edit form: all fields, image upload (store as BLOB in `parcelle.image`)
- Delete with confirmation dialog
- Bulk delete (checkboxes + one action)

#### 4.1.2 Map View
- Embed a map (use JavaFX WebView with Leaflet.js, or a Java map library)
- Show all parcelles that have non-null latitude/longitude as markers
- Marker popup: name, location, area, soil type, culture count
- Click marker → navigate to parcelle detail

#### 4.1.3 AI Crop Recommendation
- Button on parcelle detail screen: "Get Crop Recommendation"
- Collect inputs from user (or pre-fill from parcelle data):
  - Nitrogen (N), Phosphorus (P), Potassium (K) — numeric (0–140)
  - Temperature °C — numeric
  - Humidity % — numeric (0–100)
  - pH — numeric (0–14)
  - Rainfall mm — numeric
- Call the Python ML model via subprocess (see Section 6 — ML Models)
- Show top 3 recommended crops with confidence scores
- Display as a result panel with crop name + confidence bar

#### 4.1.4 Import / Export
- Export to CSV: all parcelle fields (no image)
- Export to Excel: same, using Apache POI, with bold headers, auto-sized columns
- Import from CSV or Excel: parse rows, validate required fields, insert to DB
  - Required: nom, localisation, superficie
  - Show import summary: X imported, Y skipped (with reasons)

---

### Module 2: Cultures (Crops)

**Purpose**: Track crops planted on specific parcelles from planting to harvest.

**Features to implement**:

#### 4.2.1 CRUD
- List with columns: nom_culture, variete, parcelle name, date_plantation, date_recolte_prevue, statut, rendement%
- Filter by statut and parcelle
- Add/Edit form: all fields, parcelle selector (dropdown from parcelle table), image upload
- Delete with confirmation

#### 4.2.2 Calendar View
- Show all cultures on a monthly calendar
- Each culture appears on its date_plantation (green) and date_recolte_prevue (orange)
- Cultures with date_recolte_reelle appear on actual harvest date (blue)
- Use JavaFX custom calendar or embed FullCalendar in WebView

#### 4.2.3 Yield Tracking
- On the culture detail view, show:
  - Yield efficiency = (quantite_recoltee / quantite_plantee) × 100 formatted as percentage
  - Production cost per kg = cout_production / quantite_recoltee
  - Profit indicator if prix_unitaire of linked product is available
- Render a small bar chart (JavaFX BarChart) comparing planned vs actual harvest

#### 4.2.4 Export
- Export cultures to CSV and Excel (same pattern as parcelles)

---

### Module 3: Livestock & Animals

**Purpose**: Manage livestock farms (élevages) and their animals. Track health, vaccinations, and status.

#### 4.3.1 Livestock Farms CRUD
- List: type_elevage, etat_elevage, capacite, nombre_animaux, production, occupancy %
- Occupancy % = (nombre_animaux / capacite) × 100
- Alert: if nombre_animaux >= capacite → show red badge "At Capacity"
- Add/Edit form: all elevage fields + optional GPS coordinates
- Delete: warn if farm has animals

#### 4.3.2 Animal CRUD (per farm)
- Drill-down from farm → show its animals
- List: type_animal, sexe, age (in months, display as "X months" or "X years Y months"), etat_sante, statut
- Add/Edit form: all animal fields, photo upload (store filename; photos stored by Symfony in `public/uploads/animals/`)
- When adding/editing an animal, auto-update `elevage.nombre_animaux` (COUNT animals for that elevage)
- Delete: also decrement nombre_animaux

#### 4.3.3 Vaccination Management
- Per-animal vaccination history list: vaccine_name, date_done, date_next, status
- Auto-compute status on load:
  - If date_done is null and date_next > today → `Scheduled`
  - If date_done is not null → `Done`
  - If date_next < today and date_done is null → `Overdue`
  - Update `vaccination.status` column accordingly on every read
- Add/Edit vaccination form
- Dashboard badge: count of Overdue vaccinations (pull all vaccinations with status='Overdue')
- SMS alert button: compose SMS via Twilio API (see Section 5.4) with reminder text

#### 4.3.4 Livestock Nutrition Lookup
- On a farm detail screen, add a "Nutrition Guide" button
- User enters feed type (e.g. "barley", "hay")
- Call USDA FDC API:
  - `GET https://api.nal.usda.gov/fdc/v1/foods/search?query={feedType}&api_key={USDA_API_KEY}`
  - Parse first result: show food name, calories, protein, fat, carbohydrates per 100g
- Display in a panel to help optimize feed

---

### Module 4: Products & Orders

**Purpose**: Manage agricultural product inventory and customer orders.

#### 4.4.1 Product Inventory CRUD
- List: nom, type, categorie, prix_unitaire, quantite_stock, statut, date_expiration
- Color rows: red if statut='Expiré' or date_expiration < today, orange if quantite_stock < 5
- Filter by type, statut, categorie
- Search by nom
- Add/Edit form: all product fields, category selector, image upload
- Restock action: dialog to add quantity to quantite_stock

#### 4.4.2 Stock Alerts Dashboard
- Dedicated panel showing:
  - Expired products (date_expiration < today or statut = 'Expiré')
  - Low stock (quantite_stock < 5 and quantite_stock > 0)
  - Out of stock (quantite_stock = 0)
- Each category shown as a count badge + expandable list

#### 4.4.3 Weather-Based Product Recommendations
- Dropdown: select Tunisia region (24 regions — see list below)
- Call OpenWeather API (see Section 5.1)
- Based on temperature + humidity, suggest relevant product categories:
  - Temp > 30°C and humidity < 50% → suggest: watermelon, melon, tomatoes, irrigation products
  - Temp > 25°C and humidity > 65% → suggest: fresh fruits, leafy vegetables, preservation products
  - Temp 15–25°C → suggest: citrus, seasonal vegetables, aromatic herbs
  - Temp < 15°C → suggest: potatoes, onions, carrots, root vegetables
- Show matched products from inventory that fit the suggestion

**Tunisia regions**: Tunis, Ariana, Ben Arous, Manouba, Nabeul, Sousse, Monastir, Mahdia, Sfax, Kairouan, Bizerte, Beja, Jendouba, Kef, Siliana, Zaghouan, Kasserine, Sidi Bouzid, Gabes, Medenine, Tataouine, Gafsa, Tozeur, Kebili

#### 4.4.4 Orders Management
- List orders: client name, product, quantity, prix_total, statut_commande, statut_paiement, date_commande
- Filter by statut_commande and statut_paiement
- Add order form: select product, quantity (auto-compute prix_total = prix_unitaire × quantite), client info
- Update order status: En attente → Confirmée or Annulée
- Update payment status: Non payé → Payé
- On order creation: validate quantite_stock >= quantite ordered

#### 4.4.5 PDF Invoice Export
- Generate a PDF invoice for any order using Apache PDFBox or iText
- Include: Elfirma logo (if available), order ID, client name, product, quantity, unit price, total, date

---

### Module 5: Equipment & Maintenance

**Purpose**: Track farm equipment assets and schedule preventive maintenance. AI risk prediction flags equipment at risk of failure.

#### 4.5.1 Equipment CRUD
- List: nom_eq, type_eq, date_achat, etat, cout_achat
- Filter by etat
- Add/Edit form: all fields, image upload
- Etat displayed as colored badge: `disponible`=green, `maintenance`=orange, `panne`=red

#### 4.5.2 Maintenance Records
- Per-equipment maintenance history
- List: type_m, date_m, cout, statut, priorite, technicien name
- Priorite badge: `urgente`=red, `haute`=orange, `moyenne`=yellow, `basse`=blue
- Add/Edit maintenance form: all fields, equipment selector, technician selector (from utilisateur table, role='employee')
- Total maintenance cost per equipment (SUM of maintenance.cout)

#### 4.5.3 AI Risk Prediction
- On the equipment list or detail, show a "Risk Level" indicator per equipment
- Compute risk score using this logic (mirrors the Python model):
  - Age in days = (today - date_achat) / 365
  - Total maintenance cost from DB
  - Maintenance count from DB
  - Risk score = normalize( age_years × 0.4 + total_cost × 0.001 + count × 0.3 )
  - Low risk (green): score < 0.35
  - Medium risk (orange): 0.35 ≤ score < 0.70
  - High risk (red): score ≥ 0.70
- Alternatively, call the Python script for the exact model output (see Section 6)

#### 4.5.4 Maintenance Calendar
- Monthly calendar view showing all scheduled maintenance by date_m
- Color-coded by priorite
- Click a maintenance entry → open detail/edit dialog

---

### Module 6: Suppliers & Contracts

**Purpose**: Manage supplier relationships, contracts, meetings, and ratings.

#### 4.6.1 Supplier CRUD
- List: type_f, email_f, tel_f, statut_f, GPS location indicator, avg star rating
- Filter by statut_f
- Add/Edit form: all fournisseur fields
- Status badge: `active`=green, `inactive`=grey, `suspended`=red

#### 4.6.2 Contract Management
- Per-supplier contract list + global contracts list
- Columns: type_c_f, date_debut_f, date_fin_f, statut_c_f, days until expiry
- Alert: contracts expiring within 30 days → highlight in orange
- Alert: contracts already expired (date_fin_f < today) → highlight in red
- Add contract: all fields + supplier selector
- Auto-compute statut_c_f on load:
  - date_fin_f < today → `inactive` (mark as expired)
  - date_debut_f > today → `inactive` (not started)
  - Otherwise → keep existing value

#### 4.6.3 Meetings (Supplier Video Calls)
- Per-supplier meeting list + global calendar view
- Add meeting: select supplier, date+time, auto-generate Jitsi Meet link:
  - Format: `https://meet.jit.si/elfirma-supplier-{supplierId}-{timestamp}`
  - Store link in `meeting.meeting_link`
- Display meeting as clickable link → open in browser
- Calendar: monthly grid showing all meetings with supplier name

#### 4.6.4 Ratings
- Per-supplier ratings list: stars (1-5), comment, date
- Add rating: star selector (1–5) + optional comment
- Show average rating as star display on supplier card

---

### Module 7: AI Voice Assistant

**Purpose**: Allow users to control the app with voice commands processed by an LLM. This is the most advanced AI feature.

#### How it works (replicate from Symfony):
1. User speaks → record audio OR type a text command
2. Send transcript text to **OpenRouter API** (LLM) with a structured prompt
3. LLM returns a JSON response with `intent`, `query` or `product_name`, and `confidence`
4. App performs the action based on intent

#### 4.7.1 OpenRouter API Call
```
POST https://openrouter.ai/api/v1/chat/completions
Authorization: Bearer {OPENROUTER_API_KEY}
Content-Type: application/json

{
  "model": "openai/gpt-4o-mini",
  "messages": [
    {
      "role": "system",
      "content": "You are an assistant for an agriculture management desktop app. The user gives voice commands. Extract the intent and any relevant entity. Return JSON only: {\"intent\": \"...\", \"query\": \"...\", \"confidence\": 0.95}"
    },
    {
      "role": "user",
      "content": "{transcript}"
    }
  ]
}
```

Response: `choices[0].message.content` is a JSON string.

#### 4.7.2 Supported Intents

| Intent | Action |
|--------|--------|
| `search_product` | Filter product list by query |
| `show_parcelles` | Navigate to parcelles module |
| `show_livestock` | Navigate to livestock module |
| `show_orders` | Navigate to orders module |
| `add_product` | Open add product dialog |
| `export_csv` | Trigger CSV export of current view |
| `show_stock_alerts` | Open stock alert dashboard |
| `help` | Show list of available voice commands |

#### 4.7.3 UI
- Microphone button (or voice command bar) in the main toolbar
- If recording: use `javax.sound.sampled` (Java built-in) or a JavaFX audio input
- Show recognized transcript in a status bar
- Show the executed action as a brief toast/notification

#### Fallback (no API key or API down):
- Use simple keyword matching on the transcript:
  - Contains "produit" or "product" → navigate to products
  - Contains "parcelle" → navigate to parcelles
  - Contains "alerte" or "stock" → show stock alerts
  - Contains "exporter" or "export" → trigger export

---

### Module 8: AI Chatbot

**Purpose**: A conversational assistant that answers questions about the farm's data.

#### 4.8.1 Architecture
The Symfony app runs a Python FastAPI backend on `localhost:8002`. You have two options:
1. **Connect to the same FastAPI backend** (if it's running): `POST http://localhost:8002/chat` with `{"message": "...", "session_id": "desktop-session-1"}`
2. **Direct LLM call** (standalone): Use OpenRouter API with a system prompt that includes current DB stats

#### 4.8.2 Standalone Chatbot (Recommended for desktop)
Build the context from live DB data:

```
System prompt:
"You are Elfirma, an AI assistant for an agriculture management app. 
Here is current data: 
- Total parcelles: {count}, available: {available_count}  
- Total cultures: {total}, in progress: {in_progress_count}
- Total livestock farms: {farm_count}, total animals: {animal_count}
- Total products: {product_count}, low stock alerts: {alert_count}
- Pending orders: {pending_orders}
Answer questions about this farm data. Be concise."
```

Send each user message to OpenRouter API with this context. Stream or show response in a chat bubble UI.

#### 4.8.3 UI
- Chat panel: scrollable message list with user/bot alternating bubbles
- Text input field + Send button
- "New conversation" button to reset context
- Show typing indicator while awaiting API response

---

### Module 9: Reports & Export

**Purpose**: Generate printable reports for all modules.

#### 4.9.1 PDF Report (Apache PDFBox or iText)
Generate PDF reports for:
- **Parcelles report**: table of all plots with stats
- **Maintenance report**: equipment list with maintenance history and costs
- **Order invoice**: individual order receipt (see Module 4)
- **Supplier contract**: formal contract PDF

Each PDF should include:
- Elfirma header with date
- Data table with borders
- Summary row (totals, counts)

#### 4.9.2 Excel Export (Apache POI)
For every list view, provide "Export to Excel" button:
- Bold header row with background color
- Auto-sized columns
- Proper data types (dates as date cells, numbers as numeric cells)
- Sheet name = module name

#### 4.9.3 CSV Export
Simple comma-separated export for all list views. Include header row. UTF-8 with BOM for Excel compatibility.

---

## 5. External API Integration Guide

### 5.1 OpenWeather API
- **Purpose**: Get real-time weather for Tunisia regions → drive product recommendations
- **Endpoint**: `GET https://api.openweathermap.org/data/2.5/weather`
- **Auth**: query param `appid={OPENWEATHER_API_KEY}`
- **Key params**: `q=Tunis,TN`, `units=metric`, `lang=en`
- **Response fields used**: `main.temp` (°C), `main.humidity` (%), `weather[0].description`, `weather[0].icon`
- **Cache**: Store result in memory for 1 hour per region (avoid repeated calls)
- **Fallback**: Show "Weather unavailable" message, still show product list without recommendations
- **Env var**: `OPENWEATHER_API_KEY`

**Example response**:
```json
{
  "main": { "temp": 28.5, "humidity": 62 },
  "weather": [{ "description": "clear sky", "icon": "01d" }]
}
```

### 5.2 OpenRouter LLM API
- **Purpose**: Voice command parsing (Module 7), chatbot (Module 8)
- **Endpoint**: `POST https://openrouter.ai/api/v1/chat/completions`
- **Auth**: `Authorization: Bearer {OPENROUTER_API_KEY}`
- **Model**: `openai/gpt-4o-mini` (cheap, fast) or `anthropic/claude-haiku`
- **Request format**:
```json
{
  "model": "openai/gpt-4o-mini",
  "messages": [
    {"role": "system", "content": "...system prompt..."},
    {"role": "user", "content": "...user message..."}
  ],
  "max_tokens": 200
}
```
- **Response**: `choices[0].message.content`
- **Fallback**: Keyword matching (see Module 7 fallback)
- **Env var**: `OPENROUTER_API_KEY`

### 5.3 USDA FDC API (Livestock Nutrition)
- **Purpose**: Look up nutritional data for animal feed ingredients
- **Endpoint**: `GET https://api.nal.usda.gov/fdc/v1/foods/search`
- **Auth**: query param `api_key={USDA_API_KEY}`
- **Key params**: `query={feedType}`, `pageSize=1`
- **Response fields**: `foods[0].description`, `foods[0].foodNutrients` array
  - Nutrient IDs of interest: 1008 (Energy kcal), 1003 (Protein g), 1004 (Fat g), 1005 (Carbs g)
- **Fallback**: Show "Nutrition data unavailable" message
- **Env var**: `USDA_API_KEY`

### 5.4 Twilio SMS (Vaccination Alerts)
- **Purpose**: Send SMS reminders for upcoming/overdue vaccinations
- **SDK approach**: Use Twilio Java SDK or plain HTTP call
- **Endpoint**: `POST https://api.twilio.com/2010-04-01/Accounts/{TWILIO_ACCOUNT_SID}/Messages.json`
- **Auth**: HTTP Basic Auth with TWILIO_ACCOUNT_SID:TWILIO_AUTH_TOKEN
- **Body params**: `From={TWILIO_FROM_NUMBER}`, `To={farmerPhone}`, `Body={message}`
- **Message template**: "Elfirma Reminder: {animalType} needs {vaccineName} vaccination by {date_next}."
- **Env vars**: `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_FROM_NUMBER`
- **Fallback**: Show formatted message in app for user to send manually

### 5.5 Jitsi Meet (Supplier Meetings)
- No API key needed. Just construct a room URL:
- Format: `https://meet.jit.si/elfirma-{supplierId}-{System.currentTimeMillis()}`
- Store URL in `meeting.meeting_link`
- Open with: `Desktop.getDesktop().browse(new URI(meetingLink))`

### 5.6 Tripo3D API (3D Livestock Models — Advanced)
- **Purpose**: Generate 3D habitat model from livestock type description
- **Endpoint**: `POST https://api.tripo3d.ai/v2/openapi/task`
- **Auth**: `Authorization: Bearer {TRIPO3D_API_KEY}`
- **Request**:
```json
{
  "type": "text_to_model",
  "prompt": "A {livestock_type} farm habitat, agricultural style, realistic"
}
```
- **Response**: `data.task_id` — then poll `GET /v2/openapi/task/{taskId}` until `status = success`
- **Completed response**: `data.output.model` (GLB file URL), `data.output.rendered_image` (preview URL)
- **Display**: Show preview image in JavaFX ImageView, provide download button for GLB
- **Env var**: `TRIPO3D_API_KEY`
- **Fallback**: Show "3D generation service unavailable" message

### 5.7 ExchangeRate API (Currency)
- **Purpose**: Show prices in EUR alongside TND
- **Endpoint**: `GET https://api.exchangerate-api.com/v4/latest/TND`
- **Auth**: None required for free tier
- **Response**: `rates.EUR` — multiply TND amount by this rate
- **Cache**: Store rate for 24 hours

---

## 6. ML Model Integration

The Symfony app runs Python ML models via subprocess. You can do the same in JavaFX using `ProcessBuilder`.

### 6.1 Crop Recommendation Model
- **Script location**: `scripts/ml/crop_recommendation_infer.py` (in Symfony project)
- **Model file**: `ml/crop_recommendation/best_model.joblib`
- **Input** (command-line JSON argument):
```json
{"N": 90, "P": 42, "K": 43, "temperature": 20.8, "humidity": 82.0, "ph": 6.5, "rainfall": 202.9}
```
- **Output** (stdout JSON):
```json
[
  {"crop": "rice", "confidence": 0.94},
  {"crop": "maize", "confidence": 0.72},
  {"crop": "wheat", "confidence": 0.61}
]
```

**JavaFX call pattern**:
```java
ProcessBuilder pb = new ProcessBuilder("python", 
    "path/to/crop_recommendation_infer.py",
    jsonInput);
pb.redirectErrorStream(true);
Process process = pb.start();
String output = new String(process.getInputStream().readAllBytes());
// Parse output JSON
```

**Run in a JavaFX Task** (never on UI thread):
```java
Task<List<CropResult>> task = new Task<>() {
    @Override protected List<CropResult> call() throws Exception {
        // run subprocess here
    }
};
task.setOnSucceeded(e -> Platform.runLater(() -> updateUI(task.getValue())));
new Thread(task).start();
```

### 6.2 Equipment Risk Prediction
- If you want to use the Symfony Python model: look for `scripts/ml/equipment_predict.py`
- Otherwise use the inline computation from Section 4.5.3 (same logic, no subprocess needed)

---

## 7. JavaFX Architecture Recommendations

### 7.1 Project Structure
```
src/main/java/
  com.elfirma/
    controller/          # JavaFX controllers (one per FXML view)
    model/               # POJO data models matching DB tables
    dao/                 # Data Access Objects (one per table)
    service/             # Business logic + API calls
    util/                # DB connection, config loader, formatting
    enums/               # Java enums mirroring DB values
src/main/resources/
  fxml/                  # FXML layout files
  css/                   # Stylesheets
  config.properties      # API keys, DB credentials
```

### 7.2 DB Connection Pool
Use a simple connection pool (HikariCP recommended):
```xml
<dependency>
  <groupId>com.zaxxer</groupId>
  <artifactId>HikariCP</artifactId>
  <version>5.1.0</version>
</dependency>
```

### 7.3 Java Enum Definitions
Mirror the DB string values exactly:
```java
public enum MaintenanceStatut {
    PLANIFIE("planifie"), EN_COURS("en_cours"), TERMINE("termine"), EN_ATTENTE("en_attente");
    private final String dbValue;
    MaintenanceStatut(String dbValue) { this.dbValue = dbValue; }
    public String getDbValue() { return dbValue; }
    public static MaintenanceStatut fromDb(String val) {
        for (MaintenanceStatut s : values()) if (s.dbValue.equals(val)) return s;
        throw new IllegalArgumentException("Unknown statut: " + val);
    }
}
```

Do the same for: `MaintenancePriorite`, `EquipementEtat`, `ParcelleStatut`, `CultureStatut`, `AnimalStatut`, `VaccinationStatus`, `ProduitStatut`, `FournisseurStatut`, `CommandeStatut`

### 7.4 Image Handling
- **BLOB images** (parcelle.image, culture.image): read bytes from DB → convert to JavaFX Image:
  ```java
  byte[] blob = rs.getBytes("image");
  if (blob != null) {
      Image img = new Image(new ByteArrayInputStream(blob));
      imageView.setImage(img);
  }
  ```
- **Filename images** (produit.image, equipement.image_eq): Symfony stores files in `public/uploads/`. Read the file directly:
  ```java
  File imgFile = new File("path/to/symfony/public/uploads/produits/" + filename);
  Image img = new Image(imgFile.toURI().toString());
  ```

### 7.5 Configuration File
Store API keys and DB credentials in `src/main/resources/config.properties`:
```properties
db.url=jdbc:mysql://127.0.0.1:3306/personne?useSSL=false&serverTimezone=UTC
db.user=root
db.password=

openweather.api_key=YOUR_KEY
openrouter.api_key=YOUR_KEY
twilio.account_sid=YOUR_SID
twilio.auth_token=YOUR_TOKEN
twilio.from_number=+1234567890
usda.api_key=YOUR_KEY
tripo3d.api_key=YOUR_KEY
pixabay.api_key=55415437-b3420b2f0246b02e1eae7d44b

symfony.uploads_path=C:/path/to/symfony/public/uploads/
```

---

## 8. UI/UX Guidelines

Match the Elfirma web app's theme where possible:
- **Primary color**: `#0a2200` (dark green)
- **Secondary color**: `#934b19` (earth orange)
- **Background**: `#fbfbe2` (cream)
- **Font**: use system font or include "Inter" if bundling fonts

**Layout pattern**:
- Left sidebar navigation (collapsible) with module icons
- Main content area changes on navigation
- Top toolbar with: search bar, voice assistant button, notifications bell, user avatar

**List views**: Use `TableView<T>` with sortable columns, pagination via `Pagination` control

**Forms**: Use `GridPane` or `VBox` layouts; validate before DB write; show inline error messages

**Notifications**: Use `Alert` dialogs for confirmations; use a custom toast component for success messages

---

## 9. Feature Priority Order

If the JavaFX project already has some features, implement in this order:

1. ✅ (Verify) Parcelles CRUD — most foundational
2. ✅ (Verify) Cultures CRUD
3. ✅ (Verify) Livestock & Animals CRUD
4. ✅ (Verify) Products & Orders CRUD
5. ✅ (Verify) Equipment & Maintenance CRUD
6. **ADD** AI Crop Recommendation (ML subprocess call)
7. **ADD** AI Risk Prediction for equipment
8. **ADD** Weather-based product recommendations (OpenWeather API)
9. **ADD** Stock alerts dashboard
10. **ADD** Vaccination auto-status + SMS alerts
11. **ADD** Supplier contracts with expiry tracking
12. **ADD** Supplier meetings with Jitsi links
13. **ADD** Supplier ratings
14. **ADD** Calendar views (cultures + maintenance)
15. **ADD** Export (CSV, Excel, PDF) for all modules
16. **ADD** AI Voice Assistant (OpenRouter + intent routing)
17. **ADD** AI Chatbot panel (OpenRouter with live DB context)
18. **ADD** Map view for parcelles and livestock farms
19. **ADD** Livestock Nutrition lookup (USDA API)
20. **ADD** 3D Model Generation (Tripo3D API) — optional advanced feature

---

## 10. Shared Data Integrity Rules

Since both Symfony and JavaFX write to the same DB, follow these rules to avoid conflicts:

1. **Never delete enums/status values** that exist in the DB — always add new values, never rename
2. **animal.id_elevage count**: whenever you insert/delete an animal, run `UPDATE elevage SET nombre_animaux = (SELECT COUNT(*) FROM animal WHERE id_elevage = ?) WHERE id_elevage = ?`
3. **Date format**: store all dates as `YYYY-MM-DD` strings in JDBC (`PreparedStatement.setDate()` with `java.sql.Date`)
4. **Timestamps**: use `java.sql.Timestamp` for timestamp columns
5. **Decimal precision**: `prix_unitaire` and `prix_total` use `DECIMAL(10,2)` — use `BigDecimal` in Java, never `double`
6. **BLOB images**: always check for null before reading; store null if no image provided
7. **Accented characters**: ensure JDBC connection uses UTF-8 (`&characterEncoding=UTF-8` in JDBC URL)

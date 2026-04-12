# Analyse complete: Parcelles & Cultures (Java) -> Symfony

## 1) Objectif de ce document
Ce document recense tout ce qui concerne le module **Parcelles/Cultures** dans le projet JavaFX actuel afin de le reproduire en Symfony (web + API).

Sources principales analysees:
- `src/main/java/entities/Parcelle.java`
- `src/main/java/entities/Culture.java`
- `src/main/java/services/ParcelleService.java`
- `src/main/java/services/CultureService.java`
- `src/main/java/services/DashboardService.java`
- `src/main/java/services/ImportService.java`
- `src/main/java/gui/ParcelleController.java`
- `src/main/java/gui/CultureController.java`
- `src/main/java/gui/ParcelleDialogController.java`
- `src/main/java/gui/CultureDialogController.java`
- `src/main/java/gui/StatsDialog.java`
- `src/main/java/gui/CalendarController.java`
- `src/main/java/gui/MapDialog.java`
- `src/main/java/utils/ExportUtils.java`
- `src/main/resources/views/parcelle-view.fxml`
- `src/main/resources/views/culture-view.fxml`
- `src/main/resources/views/parcelle-dialog.fxml`
- `src/main/resources/views/culture-dialog.fxml`
- `src/main/resources/views/dashboard-view.fxml`
- `src/main/resources/assets/calendar.html`
- `database.sql`
- `src/test/java/services/ParcelleServiceTest.java`
- `src/test/java/services/CultureServiceTest.java`
- `src/main/java/utils/MyDB.java`

---

## 2) Architecture actuelle (module Parcelles/Cultures)

### Couches
1. **Entites Java simples (POJO)**
   - `Parcelle`
   - `Culture`
2. **Services JDBC directs**
   - SQL ecrit a la main (pas d'ORM)
   - connexions via singleton `MyDB`
3. **Controllers JavaFX (UI desktop)**
   - CRUD, filtres, recherches, dialogs, notifications
4. **Fonctions transverses**
   - Import CSV/Excel + images Pixabay
   - Export Excel/PDF
   - Dashboard statistique + alertes
   - Calendrier web embarque (FullCalendar)
   - Carte (OpenLayers)

### Point important pour Symfony
- Il n'y a **pas d'API REST Parcelles/Cultures exposee** dans ce projet Java.
- La logique metier est surtout dans les services JDBC + controllers UI.
- En Symfony, tu vas transformer cette logique en:
  - Entites Doctrine
  - Services metier
  - Controllers web/API
  - Validation Symfony

---

## 3) Modele de donnees exact

## 3.1 Entite Parcelle
Fichier: `src/main/java/entities/Parcelle.java`

Champs:
- `id: int`
- `nom: String`
- `localisation: String`
- `superficie: float`
- `typeSol: String`
- `statut: String`
- `image: byte[]`
- `dateCreation: Date`
- `latitude: double`
- `longitude: double`

Comportement:
- `hasCoordinates()` retourne vrai si coordonnees != (0,0).

## 3.2 Entite Culture
Fichier: `src/main/java/entities/Culture.java`

Champs:
- `id: int`
- `parcelleId: int` (FK)
- `nomCulture: String`
- `variete: String`
- `datePlantation: Date`
- `dateRecoltePrevue: Date`
- `dateRecolteReelle: Date`
- `quantitePlantee: float`
- `quantiteRecoltee: float`
- `statut: String`
- `coutProduction: float`
- `rendement: float`
- `observations: String`
- `image: byte[]`

## 3.3 Schema SQL
Fichier: `database.sql`

### Table `parcelle`
- `id` INT PK AI
- `nom` VARCHAR(255) NOT NULL
- `localisation` VARCHAR(255) NOT NULL
- `superficie` FLOAT NOT NULL CHECK > 0
- `typeSol` VARCHAR(100)
- `statut` VARCHAR(50) DEFAULT 'disponible'
- `image` LONGBLOB
- `dateCreation` DATE NOT NULL
- `latitude` DOUBLE NULL
- `longitude` DOUBLE NULL

Indexes:
- `idx_parcelle_statut(statut)`
- `idx_parcelle_typeSol(typeSol)`

### Table `culture`
- `id` INT PK AI
- `parcelleId` INT NOT NULL
- `nomCulture` VARCHAR(255) NOT NULL
- `variete` VARCHAR(255)
- `datePlantation` DATE
- `dateRecoltePrevue` DATE
- `dateRecolteReelle` DATE
- `quantitePlantee` FLOAT DEFAULT 0 CHECK >= 0
- `quantiteRecoltee` FLOAT DEFAULT 0 CHECK >= 0
- `statut` VARCHAR(50) DEFAULT 'planifiee'
- `coutProduction` FLOAT DEFAULT 0 CHECK >= 0
- `rendement` FLOAT DEFAULT 0
- `observations` TEXT
- `image` LONGBLOB
- FK `parcelleId` -> `parcelle(id)` ON DELETE CASCADE

Indexes:
- `idx_culture_parcelleId(parcelleId)`
- `idx_culture_statut(statut)`
- `idx_culture_nomCulture(nomCulture)`

### Donnees seed
- 3 parcelles
- 3 cultures

### Connexion DB reelle
Fichier: `src/main/java/utils/MyDB.java`
- URL: `jdbc:mysql://localhost:3306/personne`
- user: `root`, password vide
- Ping SQL `SELECT 1`
- option JDBC `maxAllowedPacket=67108864` (64 MB)

---

## 4) Fonctionnalites metier existantes (services)

## 4.1 ParcelleService
Fichier: `src/main/java/services/ParcelleService.java`

CRUD + recherche:
- `ajouterParcelle(Parcelle)`
- `afficherToutesParcelles()` (sans image pour performance)
- `getParcelleById(int)` (avec image)
- `rechercherParLocalisation(String)` (LIKE)
- `filtrerParTypeSol(String)`
- `filtrerParStatut(String)`
- `modifierParcelle(Parcelle)`
- `supprimerParcelle(int)`

Stats:
- `calculerSuperficieTotale()`
- `calculerSuperficieDisponible()` (**filtre strict SQL sur 'disponible'**)
- `countParcelles()`
- `countParcellesByStatut(String)`
- `getParcelleByName(String)` (LOWER + trim)
- `getParcellesCountByTypeSol()`
- `getParcellesCountByStatut()`

## 4.2 CultureService
Fichier: `src/main/java/services/CultureService.java`

CRUD + recherche:
- `ajouterCulture(Culture)`
- `afficherToutesCultures()` (sans image)
- `getCultureById(int)` (avec image)
- `getCulturesByParcelleId(int)`
- `rechercherParNom(String)` (LIKE)
- `filtrerParStatut(String)`
- `modifierCulture(Culture)`
- `supprimerCulture(int)`

Stats / metrique:
- `calculerRendementMoyen(String nomCulture)`
- `getCulturesPretesARecolter()`
  - `dateRecoltePrevue <= CURDATE()` et `statut != 'recoltee'`
- `countCultures()`
- `countCulturesByStatut(String)`
- `getTotalCoutProduction()`
- `getTotalQuantitePlantee()`
- `getTotalQuantiteRecoltee()`
- `getCulturesCountByStatut()`
- `getCulturesCountByNom()`
- `getUpcomingHarvests(int days)`
- `getRecentCultures(int limit)`

## 4.3 DashboardService (agregations)
Fichier: `src/main/java/services/DashboardService.java`

Sorties:
- `GlobalStatistics`
- `ChartData`
- `List<Alert>`
- `ProductivityMetrics`

Regles:
- Parcelles disponibles reconnues par statut:
  - `Available` ou `disponible`
- Cultures en cours:
  - `In Progress` ou `en cours`
- Cultures recoltees:
  - `Harvested` ou `recoltee`
- ROI calcule comme `(quantiteRecoltee - cout) / cout * 100`

---

## 5) Fonctionnalites UI (JavaFX) a reproduire sur le web

## 5.1 Parcelles (vue liste)
Fichiers:
- `src/main/resources/views/parcelle-view.fxml`
- `src/main/java/gui/ParcelleController.java`

Fonctionnalites:
- Liste tabulaire avec colonnes:
  - image, nom, localisation, superficie, typeSol, statut, dateCreation, actions
- Chargement image a la demande par ID (eviter gros payload)
- Recherche texte sur `nom` et `localisation`
- Filtres:
  - statut (`All`, `Available`, `Occupied`, `Resting`)
  - type sol (`All`, `Clay`, `Sandy`, `Loamy`, `Humus`)
- CRUD via dialog
- Suppression avec confirmation
- Export Excel/PDF
- Import CSV/Excel
- Popup stats (graphiques)
- Carte des parcelles (`MapDialog`)

## 5.2 Dialog Parcelle (create/edit)
Fichiers:
- `src/main/resources/views/parcelle-dialog.fxml`
- `src/main/java/gui/ParcelleDialogController.java`

Validation:
- nom: longueur 2-100
- localisation: longueur 3-200
- superficie: intervalle 0.01 - 10000
- typeSol obligatoire
- statut obligatoire

Fonctions:
- upload image (max 20 MB)
- previsualisation image
- selection coordonnees via carte WebView
- clear coordonnees

## 5.3 Cultures (vue liste)
Fichiers:
- `src/main/resources/views/culture-view.fxml`
- `src/main/java/gui/CultureController.java`

Fonctionnalites:
- Liste tabulaire avec colonnes:
  - image, nomCulture, variete, dates, quantites, statut, actions
- Chargement image a la demande
- Recherche texte (`nomCulture`, `variete`)
- Filtres:
  - statut (`All`, `In Progress`, `Harvested`, `Planned`)
  - parcelle (`All` ou `id - nom`)
- CRUD via dialog
- Suppression avec confirmation
- Export Excel/PDF
- Import CSV/Excel
- Stats popup
- Calendrier agricole (FullCalendar)
- Bouton "Growing Guide" par culture (dialog guide)

## 5.4 Dialog Culture (create/edit)
Fichiers:
- `src/main/resources/views/culture-dialog.fxml`
- `src/main/java/gui/CultureDialogController.java`

Validation:
- parcelle obligatoire
- nomCulture: longueur 2-100
- variete: longueur 2-100
- datePlantation obligatoire
- dateRecoltePrevue obligatoire + > datePlantation
- dateRecolteReelle optionnelle + > datePlantation
- quantitePlantee > 0
- quantiteRecoltee optionnelle >= 0
- statut obligatoire
- coutProduction optionnel >= 0

Calcul automatique:
- `rendement = (quantiteRecoltee / quantitePlantee) * 100` si possible

Image:
- upload max 20 MB
- preview

---

## 6) Import / Export (important pour Symfony)

## 6.1 Import CSV/Excel
Service: `src/main/java/services/ImportService.java`
UI: `src/main/java/gui/ImportDialog.java`

### Import Parcelles
Formats:
- Excel: colonnes attendues `Name | Location | Area | SoilType | Status | CreationDate`
- CSV: `Name;Location;Area;SoilType;Status;CreationDate`

Regles:
- nom/localisation obligatoires
- superficie > 0
- valeurs par defaut:
  - `typeSol = Inconnu`
  - `statut = disponible`
  - `dateCreation = now`

### Import Cultures
Formats:
- Excel/CSV col 1 = `ParcelName` (nom parcelle, pas ID)
- resolution de `parcelleId` via `getParcelleByName`

Colonnes supportees:
- `ParcelName, CropName, Variety, PlantingDate, ExpectedHarvestDate, QuantityPlanted, Status, ProductionCost, Notes, ActualHarvestDate, HarvestedQty, Yield`

Regles:
- echec si nom parcelle absent en base
- statut par defaut: `Planned`
- rendement auto-calcule si non fourni et quantites presentes

### Images auto
- Option `fetchImages`
- API Pixabay integree (cle dans code)
- Images telechargees et stockees en BLOB

### Templates
- generation fichier modele Excel pour parcelles/cultures

## 6.2 Exports
Fichier: `src/main/java/utils/ExportUtils.java`

Types:
- Parcelles -> Excel + PDF
- Cultures -> Excel + PDF
- Dashboard -> Excel + PDF

Contenu:
- Donnees detaillees
- Onglets statistiques (Excel)
- Pages de couverture + tableaux pagines (PDF)

---

## 7) Cartes & calendrier

## 7.1 Carte Parcelles
Fichier: `src/main/java/gui/MapDialog.java`

Fonctionnalites:
- carte OpenLayers
- affichage de toutes les parcelles avec coordonnees
- marqueur couleur selon statut
- popup details parcelle
- mode picker pour choisir une position

## 7.2 Calendrier cultures/parcelles
Fichiers:
- `src/main/java/gui/CalendarController.java`
- `src/main/resources/assets/calendar.html`

Evenements generes:
- Plantation
- Recolte prevue
- Recolte reelle
- Overdue (retard)
- Creation parcelle

Vue:
- month/week/list
- panel detail jour
- upcoming 14 jours

---

## 8) Dashboard & KPI lies au module

Fichiers:
- `src/main/java/gui/DashboardController.java`
- `src/main/resources/views/dashboard-view.fxml`
- `src/main/java/services/DashboardService.java`

KPI Parcelles/Cultures:
- total parcelles
- superficie totale/disponible
- total cultures
- cultures en cours/recoltees
- cout production total
- quantite recoltee
- rendement moyen

Metriques:
- taux reussite
- ROI
- taux utilisation parcelles

Visualisations:
- pie statut parcelles
- pie statut cultures
- bar cultures par type
- bar parcelles par type de sol
- liste alertes
- cultures recentes
- recoltes a venir

---

## 9) API existante dans le projet Java

Constat:
- Le dossier `src/main/java/api` contient des serveurs HTTP pour QR/login/profils.
- **Aucun endpoint dedie Parcelles/Cultures** n'est implemente actuellement.

Conclusion:
- Pour Symfony, il faut definir une API from scratch pour ce module.

---

## 10) Incoherences/points d'attention metier a corriger pendant migration

1. **Statuts multilingues incoherents**
   - Parcelles: `Available/disponible`, `Occupied/occupee`, `Resting/repos`
   - Cultures: `Planned/planifiee`, `In Progress/en cours`, `Harvested/recoltee`
   - Certains calculs SQL filtrent uniquement une langue (`disponible`, `recoltee`) alors que l'UI utilise souvent l'anglais.

2. **Comparaisons exactes sensibles**
   - certains filtres UI comparent `equals` strict au lieu de normaliser.

3. **BLOB images en base**
   - impact perf et taille DB.
   - pattern actuel: liste sans image + chargement detail.

4. **Import cultures depend du nom exact de parcelle**
   - fragilite sur orthographe/casse/espace.

5. **Calcul ROI actuel**
   - compare quantite recoltee vs cout financier (unites heterogenes).
   - a revisiter fonctionnellement.

6. **Pas d'audit explicite**
   - pas de `created_at/updated_at` standard sur toutes les colonnes metier.

---

## 11) Couverture de tests actuelle

Fichiers:
- `src/test/java/services/ParcelleServiceTest.java` (7 tests)
- `src/test/java/services/CultureServiceTest.java` (8 tests)

Couverts:
- CRUD principal
- quelques stats (`count`, `superficie`, `cout total`)

Manquants (a ajouter en Symfony):
- validation metier detaillee
- recherche/filtres
- import CSV/Excel (cas erreurs)
- dashboards/agregrations
- endpoints API (auth, pagination, tri, erreurs)
- tests d'integration FK/cascade

---

## 12) Specification API Symfony recommandee (proposition)

## 12.1 Parcelles
- `GET /api/parcelles`
  - filtres: `q`, `statut`, `typeSol`, `hasCoordinates`, `page`, `limit`, `sort`
- `GET /api/parcelles/{id}`
- `POST /api/parcelles`
- `PUT /api/parcelles/{id}`
- `DELETE /api/parcelles/{id}`
- `GET /api/parcelles/stats`
  - total, disponible, superficieTotale, superficieDisponible
- `GET /api/parcelles/distribution/statut`
- `GET /api/parcelles/distribution/type-sol`

## 12.2 Cultures
- `GET /api/cultures`
  - filtres: `q`, `statut`, `parcelleId`, `dateMin`, `dateMax`, `page`, `limit`, `sort`
- `GET /api/cultures/{id}`
- `POST /api/cultures`
- `PUT /api/cultures/{id}`
- `DELETE /api/cultures/{id}`
- `GET /api/cultures/stats`
  - count, coutTotal, qtePlantee, qteRecoltee, rendementMoyen
- `GET /api/cultures/pretes-a-recolter`
- `GET /api/cultures/recoltes-a-venir?days=30`
- `GET /api/cultures/distribution/statut`
- `GET /api/cultures/distribution/nom`

## 12.3 Dashboard
- `GET /api/dashboard/agri`
  - objet unique avec:
    - globalStatistics
    - productivityMetrics
    - chartData
    - alerts
    - recentCultures
    - upcomingHarvests

## 12.4 Import/Export
- `POST /api/import/parcelles` (multipart file)
- `POST /api/import/cultures` (multipart file)
- `GET /api/import/template/parcelles`
- `GET /api/import/template/cultures`
- `GET /api/export/parcelles.xlsx|pdf`
- `GET /api/export/cultures.xlsx|pdf`
- `GET /api/export/dashboard.xlsx|pdf`

---

## 13) Mapping Symfony (entites/validation)

## 13.1 Entite Parcelle (Doctrine)
- id, nom, localisation, superficie, typeSol, statut, imagePath ou imageBlob, dateCreation, latitude, longitude
- relation: `OneToMany` vers `Culture`

Validation suggeree:
- `nom`: NotBlank, Length(2,100)
- `localisation`: NotBlank, Length(3,200)
- `superficie`: Positive, Range(0.01, 10000)
- `typeSol`: Choice enum
- `statut`: Choice enum
- latitude/longitude: pair coherent

## 13.2 Entite Culture (Doctrine)
- id, parcelle (ManyToOne), nomCulture, variete, datePlantation, dateRecoltePrevue, dateRecolteReelle, quantitePlantee, quantiteRecoltee, statut, coutProduction, rendement, observations, imagePath/imageBlob

Validation suggeree:
- parcelle obligatoire
- nom/variete longueur 2-100
- datePlantation <= dateRecoltePrevue
- dateRecolteReelle >= datePlantation si fournie
- quantites et cout >= 0
- quantitePlantee > 0
- rendement recalcule serveur

---

## 14) Plan de migration concret (ordre recommande)

1. Creer schema Doctrine + migrations (`Parcelle`, `Culture`, FK cascade)
2. Normaliser les statuts via enums
3. Implementer services metier Symfony (equivalent `ParcelleService`/`CultureService`)
4. Exposer endpoints API (CRUD + stats + distributions + upcoming)
5. Ajouter validations serveur + gestion erreurs standard
6. Implementer import CSV/Excel (sans images d'abord)
7. Implementer export Excel/PDF
8. Ajouter dashboard endpoint agrege
9. Ajouter map + calendar dans front web
10. Ajouter tests unitaires + integration + API

---

## 15) Check-list "parite fonctionnelle" Java -> Symfony

- [ ] CRUD Parcelles
- [ ] CRUD Cultures
- [ ] Filtres/recherche Parcelles
- [ ] Filtres/recherche Cultures
- [ ] Stats Parcelles (totaux/disponibles/distributions)
- [ ] Stats Cultures (cout/qtes/rendement/distributions)
- [ ] Alertes (recoltes imminentes, parcelles dispo, cultures actives)
- [ ] Import CSV/Excel Parcelles
- [ ] Import CSV/Excel Cultures avec resolution par nom de parcelle
- [ ] Templates import
- [ ] Export Excel/PDF Parcelles
- [ ] Export Excel/PDF Cultures
- [ ] Export dashboard
- [ ] Carte parcelles (vue + picker)
- [ ] Calendrier agricole
- [ ] Tests equivalence metier

---

## 16) Decision technique recommandee pour ton Symfony

Pour faciliter la replication complete:
- **Backend Symfony API-first** (controllers JSON)
- **Doctrine + Validator + Serializer**
- **Enums PHP** pour statuts (evite les incoherences actuelles)
- **Stockage image en fichier (path)** plutot que BLOB (performance)
- **Import via Symfony Messenger** si gros fichiers
- **Frontend Twig/React/Vue** selon ton choix, consume API

Si tu veux, je peux ensuite te generer un **squelette Symfony complet** (entites, migrations, controllers API, DTO, validators, tests) base exactement sur ce document.

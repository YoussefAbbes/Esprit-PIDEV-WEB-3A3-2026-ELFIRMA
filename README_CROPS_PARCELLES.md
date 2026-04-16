# Parcelles & Cultures - API and Functional Documentation

This README documents all routes, APIs, data structures, and functional workflows related to:

- Parcelles (land parcels)
- Cultures (crops)
- AI crop recommendation
- Client pages and harvest notifications

The goal is to provide one technical reference for backend, frontend, and integration behavior.

## 1. Where the logic lives

- Parcelles controller: `src/Controller/ParcelleController.php`
- Cultures controller: `src/Controller/CultureController.php`
- Client pages routes: `src/Controller/HomeController.php`
- Recommendation service: `src/Service/CropRecommendationService.php`
- Crop guide service (external APIs): `src/Service/CropGuideService.php`
- Image enrichment service: `src/Service/PixabayService.php`
- Doctrine entities:
  - `src/Entity/Parcelle.php`
  - `src/Entity/Culture.php`
- Repositories:
  - `src/Repository/ParcelleRepository.php`
  - `src/Repository/CultureRepository.php`
- Forms:
  - `src/Form/ParcelleType.php`
  - `src/Form/CultureType.php`
- Frontend integration:
  - `templates/elfirma/parcelles/show.html.twig`
  - `templates/elfirma/cultures/new.html.twig`
  - `templates/pages/client_parcelles.html.twig`
  - `templates/pages/client_cultures.html.twig`
  - `templates/partials/_header.html.twig`

## 2. Domain model

### 2.1 Parcelle entity

Main fields:

- `id` (int)
- `nom` (string, required)
- `localisation` (string, required)
- `superficie` (float, required, > 0)
- `typeSol` (choice: `Sandy`, `Loamy`, `Clay`, `Humus`)
- `statut` (choice: `Available`, `Occupied`, `Resting`)
- `dateCreation` (date, required, cannot be in the future)
- `latitude` (float, required in form constraints, range -90..90)
- `longitude` (float, required in form constraints, range -180..180)
- `image` (blob, optional)

Relation:

- `Parcelle` has many `Culture` records (`OneToMany`).

### 2.2 Culture entity

Main fields:

- `id` (int)
- `parcelle` (ManyToOne, required)
- `nomCulture` (string, required)
- `variete` (string, required)
- `datePlantation` (date, required)
- `dateRecoltePrevue` (date, required, must be >= planting date)
- `dateRecolteReelle` (date, optional)
- `quantitePlantee` (float, required, > 0)
- `quantiteRecoltee` (float, >= 0)
- `coutProduction` (float, >= 0)
- `rendement` (float, >= 0)
- `statut` (choice: `Harvested`, `In Progress`, `Planned`)
- `observations` (text, optional)
- `image` (blob, optional)

### 2.3 Yield behavior

In create/edit culture actions, yield is auto-calculated when quantity planted is > 0:

`rendement = (quantiteRecoltee / quantitePlantee) * 100`

## 3. Route catalog (Parcelles)

Base path: `/elfirma/parcelles`

### 3.1 HTML routes

1. `GET /elfirma/parcelles` (`parcelle_index`)
   - Renders list page.
   - Query params:
     - `page` (default 1)
     - `limit` (default 10)
     - `search`
     - `sort` (allowed: `id`, `nom`, `localisation`, `superficie`, `typeSol`, `statut`, `dateCreation`)
     - `order` (`ASC` or `DESC`)
     - `statut`
     - `typeSol`
   - Adds global stats (`available`, `occupied`, `resting`, `totalArea`).

2. `GET|POST /elfirma/parcelles/new` (`parcelle_new`)
   - Form create flow.
   - Handles optional upload `imageFile` with validation:
     - max size 5MB
     - extensions: jpg, jpeg, png, webp, gif
     - content must be a valid image

3. `GET /elfirma/parcelles/map` (`parcelle_map`)
   - Renders map view and injects JSON payload for parcels with coordinates.

4. `GET /elfirma/parcelles/{id}` (`parcelle_show`)
   - Parcel details page.
   - Injects recommendation model summary from `CropRecommendationService`.

5. `GET|POST /elfirma/parcelles/{id}/edit` (`parcelle_edit`)
   - Form edit flow with same image validation rules.

6. `POST /elfirma/parcelles/{id}/delete` (`parcelle_delete`)
   - Requires CSRF token: `delete{id}`.

7. `POST /elfirma/parcelles/delete-multiple` (`parcelle_delete_multiple`)
   - Requires CSRF token: `parcelle_bulk_delete`.
   - Expects `ids[]` in POST form data.

8. `GET /elfirma/parcelles/{id}/image` (`parcelle_image`)
   - Streams image blob.
   - Returns 404 if no image.

9. `GET /elfirma/parcelles/export/csv` (`parcelle_export_csv`)
   - Exports all parcels as CSV.

10. `GET /elfirma/parcelles/export/excel` (`parcelle_export_excel`)
    - Exports all parcels as XLSX.

11. `POST /elfirma/parcelles/import` (`parcelle_import`)
    - Imports parcels from CSV/XLS/XLSX (multipart file field: `importFile`).

### 3.2 JSON API route

1. `POST /elfirma/parcelles/{id}/recommendation` (`parcelle_recommendation`)
   - Computes AI crop recommendation from feature values.
   - Accepts either:
     - raw feature object, or
     - `{ "features": { ... } }`
   - Required numeric features:
     - `N`, `P`, `K`, `temperature`, `humidity`, `ph`, `rainfall`

Example request body:

```json
{
  "features": {
    "N": 90,
    "P": 42,
    "K": 43,
    "temperature": 21,
    "humidity": 82,
    "ph": 6.5,
    "rainfall": 203
  }
}
```

Typical response structure:

```json
{
  "recommended_crop": "rice",
  "confidence": 0.91,
  "top_predictions": [
    { "crop": "rice", "probability": 0.91 },
    { "crop": "maize", "probability": 0.06 },
    { "crop": "cotton", "probability": 0.03 }
  ],
  "explanation": {
    "summary": "...",
    "supporting_factors": ["..."],
    "limiting_factors": ["..."],
    "feature_alignment": [
      {
        "feature": "N",
        "value": 90,
        "class_mean": 88,
        "alignment": 0.94,
        "z_distance": 0.2,
        "weighted_alignment": 0.17
      }
    ]
  },
  "feature_importance": [
    { "feature": "rainfall", "importance": 0.28 }
  ],
  "agronomic_advice": ["..."],
  "model": {
    "selected_name": "ExtraTrees",
    "selected_class": "ExtraTreesClassifier",
    "test_accuracy": 0.98,
    "test_macro_f1": 0.98
  },
  "input": {
    "N": 90,
    "P": 42,
    "K": 43,
    "temperature": 21,
    "humidity": 82,
    "ph": 6.5,
    "rainfall": 203
  },
  "inference_mode": "python",
  "parcel": {
    "id": 1,
    "name": "North Field",
    "soil_type": "Loamy",
    "status": "Available"
  }
}
```

Errors:

- `400` when payload is invalid or missing required features.
- `503` when computation fails unexpectedly.

## 4. Route catalog (Cultures)

Base path: `/elfirma/cultures`

### 4.1 HTML routes

1. `GET /elfirma/cultures` (`culture_index`)
   - Renders list page.
   - Query params:
     - `page`, `limit`, `search`, `sort`, `order`, `statut`, `parcelleId`
   - Sort supports culture fields and `parcelle` name sorting.

2. `GET|POST /elfirma/cultures/new` (`culture_new`)
   - Form create flow.
   - Supports recommendation prefill query params:
     - `parcelleId`
     - `prefillCrop`
     - `prefillVariety`

3. `GET /elfirma/cultures/calendar` (`culture_calendar`)
   - Calendar page.

4. `GET /elfirma/cultures/{id}` (`culture_show`)
   - Crop details page.

5. `GET|POST /elfirma/cultures/{id}/edit` (`culture_edit`)
   - Form edit flow.

6. `POST /elfirma/cultures/{id}/delete` (`culture_delete`)
   - Requires CSRF token: `delete{id}`.

7. `POST /elfirma/cultures/delete-multiple` (`culture_delete_multiple`)
   - Requires CSRF token: `culture_bulk_delete`.
   - Expects `ids[]` in POST form data.

8. `GET /elfirma/cultures/{id}/image` (`culture_image`)
   - Streams image blob.

9. `GET /elfirma/cultures/export/csv` (`culture_export_csv`)
   - Exports all crops as CSV.

10. `GET /elfirma/cultures/export/excel` (`culture_export_excel`)
    - Exports all crops as XLSX.

11. `POST /elfirma/cultures/import` (`culture_import`)
    - Imports crops from CSV/XLS/XLSX (multipart field: `importFile`).

### 4.2 JSON API routes

1. `GET /elfirma/cultures/calendar/events` (`culture_calendar_events`)
   - Returns calendar events as JSON.
   - Includes:
     - planting event
     - expected harvest event
     - overdue harvest event (if expected date in past and status != Harvested)
     - actual harvest event
   - Event payload includes `extendedProps` with `type`, `crop`, `parcel`, `status`, `url`.

2. `GET /elfirma/cultures/{id}/guide` (`culture_guide`)
   - Returns crop guide JSON from `CropGuideService`.
   - Aggregates external knowledge from:
     - Trefle API (if key configured)
     - Wikipedia REST API

## 5. Client-facing routes and navigation

Routes in `HomeController`:

1. `/client/parcelles` (`app_client_parcelles`)
   - Uses `ParcelleRepository::findAllWithCultures()`.
   - Computes stats (`total`, `available`, `occupied`, `resting`, `totalArea`).

2. `/client/cultures` (`app_client_cultures`)
   - Uses `CultureRepository::findAllWithParcelle()`.
   - Computes stats (`total`, `inProgress`, `planned`, `harvested`, `totalPlanted`, `totalHarvested`).

Navbar integration in `templates/partials/_header.html.twig`:

- Dropdown entry: `Parcels & Crops`
- Links:
  - `app_client_parcelles`
  - `app_client_cultures`

## 6. Upcoming harvest notifications (header bell)

Data source and flow:

1. `templates/pages/client_cultures.html.twig` builds hidden payload in `#ccUpcomingData`.
2. Payload includes non-harvested crops with expected harvest date.
3. `templates/partials/_header.html.twig` JS reads this payload and renders bell panel items.
4. If user is not on crops page (payload missing), bell panel shows a prompt to visit the crops page.

Notification item fields:

- crop name
- parcel name
- expected date (`date`, `dateDisplay`)
- status
- urgency text computed client-side (overdue/today/in X days)

## 7. Recommendation workflow (end-to-end)

1. User opens parcel details page (`parcelle_show`).
2. User fills agronomic features in AI form (`N`, `P`, `K`, `temperature`, `humidity`, `ph`, `rainfall`).
3. Frontend calls `parcelle_recommendation` via fetch (`POST` JSON).
4. Backend calls `CropRecommendationService`.
5. Service tries Python inference script first.
6. If Python inference fails, service falls back to metadata profile scoring.
7. Result is rendered in UI (recommended crop, confidence, top predictions, explanation, advice).
8. "Create Crop Plan" button is updated with query params:
   - `parcelleId`
   - `prefillCrop`
9. User is redirected to `culture_new` with prefilled crop and parcel.

## 8. Import/Export contracts

### 8.1 Parcelles import

Accepted file formats:

- `.csv`, `.xls`, `.xlsx`

Multipart field name:

- `importFile`

Header normalization:

- lowercased
- spaces/underscores/parentheses removed

Accepted header aliases:

- Name: `name` or `nom`
- Location: `location` or `localisation`
- Area: `area` or `superficie`
- Soil type: `soiltype` or `typesol`
- Status: `status` or `statut`
- Creation date: `creationdate` or `datecreation`
- Coordinates: `latitude`, `longitude`

Defaults/guards:

- missing/invalid area -> `1.0`
- invalid soil type -> `Loamy`
- invalid status -> `Available`
- missing/unparseable date -> `today`

Also auto-fetches parcel image using `PixabayService`.

### 8.2 Cultures import

Accepted file formats:

- `.csv`, `.xls`, `.xlsx`

Multipart field name:

- `importFile`

Important behavior:

- Parcel is resolved by parcel name match (case-insensitive compare against existing parcel names).
- If parcel is not found, row is skipped.

Accepted header aliases:

- Parcel: `parcelname`, `parcelle`, `parcel`
- Crop name: `cropname`, `nomculture`, `name`
- Variety: `variety`, `variete`
- Planting date: `plantingdate`, `dateplantation`
- Expected harvest date: `expectedharvestdate`, `daterecolteprevue`
- Actual harvest date: `actualharvestdate`
- Quantity planted: `quantityplanted`, `quantiteplantee`
- Quantity harvested: `harvestedqty`, `quantiterecoltee`
- Production cost: `productioncost`, `coutproduction`
- Status: `status`, `statut`
- Notes: `notes`, `observations`

Defaults/guards:

- missing planting date -> `today`
- missing expected harvest date -> `planting + 3 months`
- quantity planted <= 0 -> `1.0`
- quantity harvested < 0 -> `0.0`
- production cost <= 0 -> `0.01`
- invalid status -> `Planned`

Also auto-fetches crop image using `PixabayService`.

### 8.3 Date parsing formats used in imports

Supported formats:

- `d/m/Y`
- `Y-m-d`
- `m/d/Y`
- `d-m-Y`
- `d.m.Y`

## 9. External integrations and environment variables

### 9.1 ML recommendation (Python)

Used by `CropRecommendationService`.

Files expected:

- `ml/crop_recommendation/best_model.joblib`
- `ml/crop_recommendation/model_metadata.json`
- `scripts/ml/crop_recommendation_infer.py`

Optional env var:

- `ML_PYTHON_BIN` (custom python executable command)

Fallback execution order when `ML_PYTHON_BIN` is not set or fails:

1. `python3`
2. `python`
3. `py -3`

If all Python commands fail, service runs profile-based fallback inference from metadata.

### 9.2 Crop guide APIs

`CropGuideService` combines:

- Trefle API (requires `TREFLE_API_KEY`)
- Wikipedia REST summary (no key)

### 9.3 Pixabay image enrichment

`PixabayService` uses:

- `PIXABAY_API_KEY`

Used during bulk import to auto-fill image blobs for parcels and crops.

## 10. Useful developer commands

Check routes:

```bash
php bin/console debug:router
```

Lint Twig templates:

```bash
php bin/console lint:twig templates
```

Train recommendation model:

```bash
py -3 scripts/ml/train_crop_recommendation.py --dataset Crop_recommendation.csv --model-output ml/crop_recommendation/best_model.joblib --metadata-output ml/crop_recommendation/model_metadata.json
```

Run recommendation inference manually:

```bash
py -3 scripts/ml/crop_recommendation_infer.py --model ml/crop_recommendation/best_model.joblib --metadata ml/crop_recommendation/model_metadata.json --input-json "{\"N\":90,\"P\":42,\"K\":43,\"temperature\":21,\"humidity\":82,\"ph\":6.5,\"rainfall\":203}"
```

## 11. Summary

Parcelles and Cultures are implemented as Symfony web modules with:

- full CRUD
- import/export CSV/XLSX
- map/calendar visualization
- JSON APIs for recommendation, events, and guide data
- client-facing pages and header-level upcoming harvest notifications
- external integrations (ML, Trefle, Wikipedia, Pixabay)

This documentation should be used as the source of truth before adding new endpoints or changing import/export contracts.
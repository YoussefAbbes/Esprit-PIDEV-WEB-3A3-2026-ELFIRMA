# Schema Reference - Evidence Aligned (Animals, Vaccination, Elevage)

## Scope
This reference documents only what is currently supported by screenshot evidence and cautious schema inference.

## Confidence Taxonomy
- confirmed: visible in phpMyAdmin screenshots.
- inferred: logical inference from table/field structure and naming.
- assumed: hypothetical business policy that still needs owner validation.

## confirmed_from_screenshot

### Table: animals
Confirmed fields:
- id_animal
- type_animal
- sexe
- age
- etat_sante
- statut
- id_elevage

Observed sample values (not exhaustive):
- type_animal: Hen, Sheep
- sexe: Female, Male
- etat_sante: Sick, Healthy
- statut: For Sale, Retained

### Table: vaccinations
Confirmed fields:
- id_vaccination
- vaccine_name
- date_done
- date_next
- notes
- status
- id_animal

Observed sample values (not exhaustive):
- vaccine_name: Brucellosis
- status: Scheduled

### Table: elevage
Confirmed fields:
- id_elevage
- type_elevage
- etat_elevage
- capacite
- nombre_animaux
- production
- latitude
- longitude

Observed sample values (not exhaustive):
- type_elevage: Poultry Farm, Sheep Farm, Bovin Farm
- etat_elevage: Cleaning, Under Cleaning
- production: Egg, Milk

## inferred_from_schema

### Likely key roles (not yet confirmed by SQL DDL)
- animals.id_animal likely acts as primary key.
- vaccinations.id_vaccination likely acts as primary key.
- elevage.id_elevage likely acts as primary key.

### Likely relationships (not yet confirmed by SQL constraints)
- animals.id_elevage likely references elevage.id_elevage.
- vaccinations.id_animal likely references animals.id_animal.

### Likely cardinality
- One elevage to many animals.
- One animal to many vaccinations.

## assumed_business_rule
No canonical policy rules are asserted in this file.

Only a technical assumption for future checks:
- If SQL DDL confirms FK constraints, referential integrity should be enforced at database level.

## Entity Data Flow (Conservative)
1. An elevage record defines livestock context (type, state label, capacity, production label, geolocation).
2. Animal records are associated to elevage through id_elevage.
3. Vaccination records are associated to animals through id_animal.

This flow is suitable for future cross-entity retrieval and analytics, but exact enforcement rules remain to be validated.

## Multilingual Note
The schema uses French field names while observed values may be English. RAG processing must preserve original field names and support bilingual querying (French and English wording).

## Validation Gaps To Close Next
- Export and review full SQL DDL for all three tables.
- Confirm actual PK/FK constraints and indexes.
- Confirm data types and nullability for date and status fields.
- Confirm whether displayed values are free text or controlled labels.

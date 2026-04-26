# Assumptions And Gaps - Master Alignment Register

## Purpose
This file is the authoritative guardrail for evidence quality across the full rag workspace.

## Confidence Taxonomy (Canonical)
- confirmed: directly observed in screenshot evidence.
- inferred: cautious interpretation from schema structure or naming.
- assumed: hypothetical business policy requiring owner validation.

All corpus and scripts must use these exact labels.

## 1) confirmed

### Confirmed structure
- Three tables are visible: animals, vaccinations, elevage.
- Field lists for all three tables are confirmed.

### Confirmed sample values (non-exhaustive)
- animals.type_animal: Hen, Sheep
- animals.sexe: Female, Male
- animals.etat_sante: Sick, Healthy
- animals.statut: For Sale, Retained
- vaccinations.vaccine_name: Brucellosis
- vaccinations.status: Scheduled
- elevage.type_elevage: Poultry Farm, Sheep Farm, Bovin Farm
- elevage.etat_elevage: Cleaning, Under Cleaning
- elevage.production: Egg, Milk

## 2) inferred
- Key roles and joins are likely:
  - animals.id_elevage -> elevage.id_elevage
  - vaccinations.id_animal -> animals.id_animal
- date_done/date_next likely carry historical/planning semantics.
- capacite/nombre_animaux likely support occupancy context.

## 3) assumed
No operational policy is officially confirmed for:
- overdue definition,
- mandatory status transitions,
- numeric thresholds,
- compliance criteria,
- severity taxonomy for etat_elevage.

Any such rule remains assumed until business validation.

## 4) unknowns_to_confirm
1. SQL DDL constraints (PK/FK, nullability, defaults, indexes).
2. Official controlled vocabularies (if any).
3. Date interpretation policy for scheduling and delay.
4. Whether production is categorical, numeric, or both.
5. Any legal/regulatory policy attached to vaccination workflows.

## 5) corpus_alignment_requirements
1. No file may present assumed values as confirmed truth.
2. Observed sample values must be marked non-exhaustive.
3. Retrieval metadata must use canonical taxonomy values exactly.
4. Unknown policy questions must trigger transparent uncertainty responses.

## 6) release_readiness_checkpoint
Before enabling strict business recommendations, confirm:
- DDL export reviewed,
- policy vocabulary validated by owner,
- confidence tagging audited across corpus and chunks.

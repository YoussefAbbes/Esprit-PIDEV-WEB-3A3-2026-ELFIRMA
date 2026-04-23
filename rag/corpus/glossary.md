# Glossary - Confirmed vs Candidate Vocabulary

## Purpose
Provide vocabulary clarity for multilingual farm RAG while separating observed evidence from candidate terminology.

## A) confirmed_vocabulary_from_screenshot

### Entity terms
- animals: confirmed table name.
- vaccinations: confirmed table name.
- elevage: confirmed table name.

### Confirmed field terms
- id_animal, type_animal, sexe, age, etat_sante, statut, id_elevage
- id_vaccination, vaccine_name, date_done, date_next, notes, status, id_animal
- id_elevage, type_elevage, etat_elevage, capacite, nombre_animaux, production, latitude, longitude

### Observed value terms (non-exhaustive)
- type_animal: Hen, Sheep
- sexe: Female, Male
- etat_sante: Sick, Healthy
- statut: For Sale, Retained
- vaccine_name: Brucellosis
- status: Scheduled
- type_elevage: Poultry Farm, Sheep Farm, Bovin Farm
- etat_elevage: Cleaning, Under Cleaning
- production: Egg, Milk

## B) candidate_vocabulary_unconfirmed
The following terms may be useful in future workflows but are not confirmed as official database labels:
- overdue
- upcoming
- delayed
- compliance
- risk score
- occupancy alert

Use these only as explanatory language, not as canonical data values, until validated.

## C) inferred_semantic_pairs
- etat_sante <-> health state
- statut <-> operational/commercial status
- elevage <-> livestock unit

Confidence: inferred from schema naming and project context.

## D) multilingual_guideline
- Preserve original French field names in outputs.
- Allow bilingual interpretation in user-facing explanations.
- Avoid translating field names when referencing exact database columns.

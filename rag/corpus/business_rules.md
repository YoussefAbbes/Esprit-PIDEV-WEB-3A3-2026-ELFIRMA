# Business Rules - Conservative Revision

## Purpose
This document is intentionally split into three levels of confidence to avoid presenting assumptions as confirmed truth.

## Confidence Taxonomy
- confirmed: explicit screenshot evidence.
- inferred: low-risk interpretation from schema and naming.
- assumed: hypothetical policy that requires validation.

## 1) confirmed_structural_rules

1. Table and field existence is confirmed for:
- animals
- vaccinations
- elevage

2. Screenshot-observed sample values are confirmed as samples only (non-exhaustive), including:
- animals.type_animal: Hen, Sheep
- animals.sexe: Female, Male
- animals.etat_sante: Sick, Healthy
- animals.statut: For Sale, Retained
- vaccinations.vaccine_name: Brucellosis
- vaccinations.status: Scheduled
- elevage.type_elevage: Poultry Farm, Sheep Farm, Bovin Farm
- elevage.etat_elevage: Cleaning, Under Cleaning
- elevage.production: Egg, Milk

3. No screenshot evidence confirms that these observed values are fixed enums.

## 2) inferred_low_risk_logic

1. animals.id_elevage likely links animals to elevage.
2. vaccinations.id_animal likely links vaccinations to animals.
3. date_done and date_next likely represent historical and planning dates.
4. capacite and nombre_animaux likely support capacity monitoring use cases.
5. latitude and longitude likely support map-based visualization.

These inferences are technical and low-risk, but still require SQL DDL validation for strict certainty.

## 3) hypothetical_policies_requiring_validation

The following are examples of possible policy logic and MUST be confirmed before production enforcement:

1. Vaccination schedule interpretation
- Example hypothesis: date_next is used for upcoming task planning.
- Validation needed: official rule for due date semantics.

2. Vaccination delay interpretation
- Example hypothesis: if date_next is in the past and action is unfinished, the record is considered delayed.
- Validation needed: official business definition and allowed status labels.

3. Livestock operational interpretation
- Example hypothesis: etat_elevage labels can express operational activity state.
- Validation needed: official vocabulary and governance for state transitions.

4. Animal status governance
- Example hypothesis: statut values may drive retention or sale workflows.
- Validation needed: official compliance criteria and decision policy.

5. Threshold-based alerting
- No numeric threshold is confirmed from screenshots.
- Any threshold (time-based or ratio-based) is assumed until validated.

## Production Safety Notes
- Do not encode assumed rules as hard logic in retrieval or prompts without owner validation.
- Do not hardcode status vocabularies beyond confirmed samples.
- Keep all unknown policy responses explicit and traceable.

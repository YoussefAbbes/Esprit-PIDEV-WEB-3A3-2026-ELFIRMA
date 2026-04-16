# Golden Answers - Confidence Grouped

## Purpose
This reference set defines expected answer behavior for confirmed, inferred, and unknown_policy questions.

Important:
- unknown_policy is a query class / response outcome label.
- It is not a confidence metadata value.
- Confidence values remain confirmed, inferred, assumed.

## A) confirmed

### C001
Question: What fields are confirmed in the animals table?
Expected essentials:
- List all confirmed animals fields exactly.
- Confidence label should be confirmed.

### C003
Question: What vaccination status value is confirmed from screenshots?
Expected essentials:
- State that Scheduled is observed.
- Clarify that observed values are not exhaustive.

### C004
Question: What etat_elevage values were observed?
Expected essentials:
- Cleaning and Under Cleaning.
- Explicit non-exhaustive warning.

## B) inferred

### I001
Question: How are animals, vaccinations, and elevage likely connected?
Expected essentials:
- Explain inferred joins via id_elevage and id_animal.
- Mark confidence as inferred.
- Mention need for DDL confirmation.

### I002
Question: What is the likely difference between etat_sante and statut?
Expected essentials:
- etat_sante as health-oriented label.
- statut as operational/commercial label.
- Mark as inferred from naming and observed examples.

### I004
Question: Can capacite and nombre_animaux support occupancy monitoring?
Expected essentials:
- Yes, as an inferred use case.
- No hard threshold claim.

## C) unknown_policy

### U001
Question: What is the official overdue vaccination rule in this project?
Expected essentials:
- State policy is not confirmed by current evidence.
- Do not invent an official threshold or mandatory logic.
- Ask for validation from owner.

### U002
Question: Is etat_elevage officially based on stable, warning, and critical levels?
Expected essentials:
- No evidence confirming that taxonomy.
- Remind observed samples are Cleaning and Under Cleaning.

### U005
Question: What are the official numeric thresholds for livestock alerts?
Expected essentials:
- Not confirmed.
- No numeric threshold should be asserted as fact.

## Evaluation Notes
- Prefer concise answers with explicit confidence wording.
- Penalize unsupported certainty.
- Reward explicit separation of confirmed, inferred, and unknown_policy.
- Accept equivalent French or English phrasing for same intent.

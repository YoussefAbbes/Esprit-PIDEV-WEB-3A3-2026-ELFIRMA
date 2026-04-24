# Intents Catalog - Evidence Aligned

## Intent Taxonomy Goal
Keep intent coverage broad while staying conservative about business-policy certainty.

## Domain Groups
- animals
- vaccination
- livestock
- cross_entity

## Confidence Reminder
Intent existence is assumed as a product design artifact, while factual answers must be grounded in confirmed or inferred corpus evidence.

## Animal Intents

### animal.field_explanation
Description: Explain animal table fields and observed sample values.
Utterances:
- What fields exist in animals?
- What values were seen for type_animal?

### animal.health_status_difference
Description: Clarify etat_sante versus statut using evidence.
Utterances:
- Difference between etat_sante and statut?
- Etat sante vs statut, same thing?

### animal.listing_request
Description: Request filtered animal views (future SQL/hybrid stage).
Utterances:
- Show animals with statut For Sale.
- List animals with etat_sante Sick.

## Vaccination Intents

### vaccination.field_explanation
Description: Explain vaccination fields and observed examples.
Utterances:
- What does date_next mean?
- Which status values are confirmed?

### vaccination.schedule_interpretation
Description: Discuss date_done/date_next usage with confidence labels.
Utterances:
- How should we interpret date_next?
- Is this vaccine delayed?

### vaccination.policy_unknown
Description: Handle requests requiring undocumented policy.
Utterances:
- What is the official overdue definition?
- What statuses are mandatory in our workflow?

## Livestock Intents

### livestock.field_explanation
Description: Explain elevage fields and observed values.
Utterances:
- What fields are in elevage?
- What etat_elevage values were observed?

### livestock.capacity_context
Description: Explain capacite and nombre_animaux without hard thresholds.
Utterances:
- How do capacite and nombre_animaux relate?
- Can we monitor occupancy with current schema?

### livestock.geolocation_context
Description: Explain latitude/longitude usage.
Utterances:
- Why store latitude and longitude?
- How can geolocation support monitoring?

## Cross-Entity Intents

### cross_entity.relationship_explanation
Description: Explain inferred links between elevage, animals, and vaccinations.
Utterances:
- How are the three tables connected?
- Can we join animal and vaccination data?

### cross_entity.evidence_scope_check
Description: Ask whether claim is confirmed, inferred, or assumed.
Utterances:
- Is this rule confirmed or assumed?
- Do we have evidence for this policy?

### cross_entity.multilingual_mapping
Description: Map French schema terms to English user wording.
Utterances:
- Elevage means what in English context?
- Match health status to etat_sante.

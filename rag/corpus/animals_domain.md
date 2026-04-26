# Animals Domain - Evidence Aligned Guide

## Scope
This guide describes the animals domain with conservative confidence labeling.

## confirmed_from_screenshot
- Table exists: animals.
- Confirmed fields: id_animal, type_animal, sexe, age, etat_sante, statut, id_elevage.
- Observed sample values (not exhaustive):
  - type_animal: Hen, Sheep
  - sexe: Female, Male
  - etat_sante: Sick, Healthy
  - statut: For Sale, Retained

## inferred_from_schema
- Each animal appears to be linked to an elevage via id_elevage.
- Animal records likely serve as the central entity for cross-domain questions:
  - health status overview,
  - sales/retention monitoring,
  - vaccination follow-up through id_animal.
- etat_sante and statut are likely distinct concepts:
  - etat_sante: health condition label,
  - statut: operational/commercial label.

## assumed_business_rule
No hard lifecycle policy is confirmed from screenshots.

Possible workflow scenarios (hypothetical only):
- Animal onboarding and assignment to an elevage.
- Periodic updates to health and operational status.
- Vaccination follow-up through linked records.

These scenarios should not be treated as official policy until validated by the project owner.

## Typical User Questions (Conservative)
- What fields define an animal record?
- Which values have been observed for type_animal and statut?
- How does an animal connect to elevage and vaccination data?
- Can observed values be treated as complete enums? (answer: no)

## Multilingual Notes
- Keep French schema terms in system responses.
- Support bilingual phrasing in retrieval (for example animal type/type_animal, health state/etat_sante).

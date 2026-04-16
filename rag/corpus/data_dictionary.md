# Data Dictionary - Evidence Aligned

## Scope
This dictionary is intentionally conservative. It separates:
- confirmed examples seen in screenshots,
- inferred meaning from schema naming,
- optional candidate examples that are explicitly unconfirmed.

Important:
- Observed values are samples only, not fixed enums.
- The system must stay open to additional values.

## Confidence Taxonomy
- confirmed: explicit screenshot evidence.
- inferred: likely interpretation from schema design.
- assumed: hypothetical usage pattern pending business validation.

## Table: animals

| Field | Business Meaning | confirmed_examples_from_screenshot | candidate_values_unconfirmed | Confidence Notes |
|---|---|---|---|---|
| id_animal | Technical identifier for one animal record | none shown | n/a | inferred as unique key role, not yet DDL-confirmed |
| type_animal | Animal type label | Hen, Sheep | Any additional species labels used by operations | confirmed examples are non-exhaustive |
| sexe | Sex label | Female, Male | Any other operational labels if present | confirmed examples are non-exhaustive |
| age | Age value of animal | none shown | Numeric or text format depending on implementation | meaning inferred, unit not confirmed |
| etat_sante | Health state label | Sick, Healthy | Any additional health labels used in real data | do not treat observed values as complete list |
| statut | Operational/commercial status label | For Sale, Retained | Any additional status labels used in business workflow | observed values are examples only |
| id_elevage | Reference to livestock unit | none shown | n/a | inferred relational role |

## Table: vaccinations

| Field | Business Meaning | confirmed_examples_from_screenshot | candidate_values_unconfirmed | Confidence Notes |
|---|---|---|---|---|
| id_vaccination | Technical identifier for one vaccination record | none shown | n/a | inferred key role |
| vaccine_name | Vaccine name label | Brucellosis | Any other vaccine names present in database | examples are not exhaustive |
| date_done | Date when action was done | none shown | Any valid date format configured by DB/application | meaning inferred from naming |
| date_next | Next planned date | none shown | Any valid date format configured by DB/application | meaning inferred from naming |
| notes | Free text notes | none shown | Any text notes entered by users | inferred from naming |
| status | Vaccination status label | Scheduled | Any additional workflow labels used in real usage | only Scheduled is currently observed |
| id_animal | Reference to animal | none shown | n/a | inferred relational role |

## Table: elevage

| Field | Business Meaning | confirmed_examples_from_screenshot | candidate_values_unconfirmed | Confidence Notes |
|---|---|---|---|---|
| id_elevage | Technical identifier for livestock unit | none shown | n/a | inferred key role |
| type_elevage | Livestock type label | Poultry Farm, Sheep Farm, Bovin Farm | Any additional farm-type labels | examples are non-exhaustive |
| etat_elevage | Livestock state/condition label | Cleaning, Under Cleaning | Any additional operational condition labels | avoid assuming severity taxonomy |
| capacite | Capacity value | none shown | Numeric values according to business setup | inferred from naming |
| nombre_animaux | Current number of animals | none shown | Numeric values according to business setup | inferred from naming |
| production | Production label/value | Egg, Milk | Any additional production labels or metrics | observed examples only |
| latitude | Geographic latitude | none shown | Decimal coordinate values | inferred from naming |
| longitude | Geographic longitude | none shown | Decimal coordinate values | inferred from naming |

## Similar Concepts Clarified

### etat_sante vs statut (animals)
- etat_sante: health-oriented label.
- statut: operational or commercial placement label.

Confidence: inferred from field naming and observed sample values.

### vaccinations.status vs elevage.etat_elevage
- vaccinations.status: workflow label for a vaccination record.
- etat_elevage: condition label for a livestock unit.

Confidence: inferred from entity context.

## Multilingual Guidance
- Keep original French field names in retrieval metadata and answers.
- Support query matching with French and English wording (for example elevage/livestock, etat_sante/health state).

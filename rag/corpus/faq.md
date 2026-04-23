# FAQ - Evidence Aligned Farm Assistant

## Confidence Reminder
Answers below follow the conservative model:
- confirmed where screenshot evidence exists,
- inferred where structure suggests behavior,
- assumed only when clearly labeled.

## Q1: What data domains are confirmed right now?
Confirmed tables are animals, vaccinations, and elevage.

## Q2: Are the observed values fixed enums?
No. The observed values are sample values from screenshots and are not exhaustive.

## Q3: What values were observed for animals.type_animal?
Observed samples: Hen, Sheep.

## Q4: What values were observed for animals.statut?
Observed samples: For Sale, Retained.

## Q5: What value was observed for vaccinations.status?
Observed sample: Scheduled.

## Q6: Is Scheduled the only status possible?
Unknown. Only Scheduled is confirmed as observed; other values may exist.

## Q7: What values were observed for elevage.etat_elevage?
Observed samples: Cleaning, Under Cleaning.

## Q8: Can I treat Cleaning and Under Cleaning as a complete state model?
No. They are observed examples only.

## Q9: Are primary keys and foreign keys confirmed?
Not fully. Key roles are inferred from naming but should be confirmed via SQL DDL.

## Q10: How are animals and vaccinations connected?
Inferred link: vaccinations.id_animal likely references animals.id_animal.

## Q11: How are animals and elevage connected?
Inferred link: animals.id_elevage likely references elevage.id_elevage.

## Q12: Can the assistant provide strict overdue policy now?
Not as a confirmed policy. It can provide a hypothetical interpretation and ask for validation.

## Q13: Can the assistant answer in French?
Yes. The corpus is multilingual-aware and keeps French schema names.

## Q14: Why is confidence labeling important in this project?
Because current evidence is limited, confidence labels prevent hallucinated certainty and support safe production rollout.

## Q15: What should be confirmed before enforcing business rules?
- SQL DDL constraints,
- official status/state vocabularies,
- date interpretation policy,
- any threshold-based alert policy.

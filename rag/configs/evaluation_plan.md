# Evaluation Plan - Evidence Safety First

## Objective
Evaluate response quality with a strong focus on confidence correctness.

## Dimensions
1. Evidence correctness
- Is the answer aligned with confirmed screenshot evidence?

2. Confidence correctness
- Does the answer correctly label confirmed, inferred, or assumed content?

3. Faithfulness
- Does the answer avoid unsupported invented policy?

4. Clarity
- Is the explanation understandable for bilingual farm operations context?

5. Utility
- Is the answer useful without overstating certainty?

## Test Input Sources
- rag/tests/sample_queries.json grouped by:
  - confirmed
  - inferred
  - unknown_policy (query class)
- rag/tests/golden_answers.md

Important:
- unknown_policy is not part of confidence taxonomy.
- Confidence labels remain: confirmed, inferred, assumed.

## Pass Criteria (Initial)
- No fabricated official policy in unknown_policy query-class tests.
- Confidence label behavior is correct in at least 95 percent of evaluated answers.
- Confirmed queries return confirmed facts without contradiction.

## Failure Categories
- F1: unsupported certainty
- F2: wrong confidence label
- F3: missed confirmed evidence
- F4: poor multilingual handling
- F5: incomplete response

## Iteration Loop
1. Run validation and chunk build.
2. Execute query set.
3. Score per dimension.
4. Update corpus or retrieval routing.
5. Re-test before deployment.

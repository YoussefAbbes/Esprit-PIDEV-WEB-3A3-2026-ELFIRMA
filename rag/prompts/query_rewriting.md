# Query Rewriting - Confidence Safe

## Objective
Rewrite user questions to improve retrieval precision without introducing unsupported assumptions.

## Rewriting Rules
1. Preserve intent.
2. Keep original field names when present (for example etat_sante, id_elevage).
3. Expand bilingual synonyms conservatively:
- elevage <-> livestock
- etat_sante <-> health state
- statut <-> operational status label
4. Do not inject undocumented thresholds or status vocabularies.
5. If query asks unknown policy, classify it as unknown_policy query class.

Important:
- unknown_policy is query classification for routing/evaluation, not a metadata confidence label.

## Confidence-Aware Rewrite Examples

### Example 1
Input: late vaccine rules
Rewrite: What policy is currently documented for interpreting vaccination delay from date_next and status?
Expected confidence path: assumed/inferred depending on evidence.
Expected response class: unknown_policy if no confirmed policy exists.

### Example 2
Input: difference between etat_sante and statut
Rewrite: Explain the distinction between animals.etat_sante and animals.statut using current evidence.
Expected confidence path: inferred.

### Example 3
Input: elevage states list
Rewrite: List observed sample values for elevage.etat_elevage and clarify whether the list is exhaustive.
Expected confidence path: confirmed for observed values, unknown for completeness.

### Example 4
Input: Quelles sont les valeurs observees pour statut dans animals ?
Rewrite: List observed sample values for animals.statut and state explicitly that examples are non-exhaustive.
Expected confidence path: confirmed for observed values.

### Example 5
Input: Donne moi la regle officielle des vaccins en retard
Rewrite: What officially documented policy exists for delayed vaccination interpretation in this project?
Expected confidence path: assumed/inferred.
Expected response class: unknown_policy when no canonical policy source is retrieved.

## Unknown Policy Trigger Patterns
- official threshold
- mandatory workflow
- strict compliance rule
- legal requirement

When these appear without evidence, retrieval should include assumptions_and_gaps and answer should remain conservative.

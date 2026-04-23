# Retrieval Strategy - Taxonomy Normalized

## Goal
Retrieve high-signal context while preserving evidence confidence boundaries.

## Canonical Metadata Usage
Use exact taxonomy from rag/configs/metadata_schema.json.

Mandatory retrieval filters:
- domain in {animals, vaccination, livestock, cross_entity, rag_operations}
- document_type in canonical enum
- confidence in {confirmed, inferred, assumed}
- language in {fr, en, fr-en}
- evidence_scope in {observed_sample, schema_inference, policy_hypothesis} when available

Important semantic rule:
- unknown_policy is a query class / response outcome, not a confidence metadata value.

## Routing By Query Type

### 1) Confirmed-fact queries
Examples:
- "What fields exist in animals?"
- "Which status value was observed in vaccinations?"
- "Quelles valeurs ont ete observees pour etat_elevage ?"

Prioritize:
- schema_reference
- data_dictionary
- glossary

Filter preference:
- confidence = confirmed first
- fallback to inferred only if needed for explanation

### 2) Structural interpretation queries
Examples:
- "How are tables connected?"
- "What does id_elevage likely represent?"
- "Comment relier animals, vaccinations et elevage ?"

Prioritize:
- schema_reference
- data_dictionary
- assumptions_and_gaps

Filter preference:
- confidence = inferred, then confirmed for anchor facts

### 3) Policy or recommendation queries
Examples:
- "What is official overdue policy?"
- "Which threshold defines critical state?"
- "Quelle est la regle officielle pour les vaccins en retard ?"

Prioritize:
- assumptions_and_gaps
- business_rules
- faq

Filter preference:
- include assumed content with explicit transparency
- if no confirmed policy exists, use unknown_policy response outcome with explicit uncertainty

evidence_scope preference:
- confirmed-fact questions: observed_sample first, then schema_inference
- structural interpretation: schema_inference first, then observed_sample for anchor values
- policy questions: policy_hypothesis + assumptions_and_gaps, with clear non-canonical wording

## Noise Control
1. Apply domain and document_type filters before semantic reranking.
2. Penalize chunks that do not contain requested field names.
3. Limit final context to 4-6 chunks from at least two source files when possible.
4. For strict factual queries, prefer confirmed over inferred over assumed.

## Confidence-Aware Response Contract
- confirmed chunks: can support direct factual statements.
- inferred chunks: require "likely" or equivalent caution wording.
- assumed chunks: require explicit assumption disclaimer.

## Multilingual Retrieval Guidance
- Keep French schema tokens searchable as exact terms.
- Expand user queries with bilingual synonyms when helpful:
  - elevage <-> livestock
  - etat_sante <-> health state
  - statut <-> status label (animal context)
- Keep French-first user intent intact when rewriting:
  - "etat_elevage" should remain literal in retrieval queries
  - "statut" should not be merged with vaccination status without context

## Unknown Policy Handling
If user asks for policy not confirmed in corpus:
1. State that policy is not confirmed.
2. Return closest confirmed/inferred context.
3. Ask for owner validation or supporting business documentation.
4. Mark outcome as unknown_policy in evaluation/reporting only, not as metadata confidence.

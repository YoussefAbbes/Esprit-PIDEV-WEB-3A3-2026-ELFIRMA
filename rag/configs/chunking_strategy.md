# Chunking Strategy - Script Aligned

## Objective
Generate consistent, heading-aware chunks for markdown corpus files under rag/corpus.

## Canonical Metadata Taxonomy
All chunks must use values from rag/configs/metadata_schema.json:
- confidence: confirmed, inferred, assumed
- domain: animals, vaccination, livestock, cross_entity, rag_operations
- document_type: canonical enum from metadata schema
- language: fr, en, fr-en
- evidence_scope (optional): observed_sample, schema_inference, policy_hypothesis

Important semantic rule:
- unknown_policy is a query class or response outcome and is never a chunk confidence value.

## Source Selection
build_chunks.py processes markdown files that meet both conditions:
1. Listed in rag/configs/corpus_manifest.json
2. include_in_chunks = true

Current intent: chunk corpus knowledge files in rag/corpus.

## Section Parsing Rules
1. Split markdown by ATX headings (# to ######).
2. Preserve heading lines inside chunk text.
3. Build section path from nested headings using " > " separator.
4. If a file has text before first heading, section is "document_root".

## Chunk Size Rules (Word-Based)
The script applies the following per document_type:

| document_type | max_words | overlap_words |
|---|---:|---:|
| schema_reference | 260 | 40 |
| data_dictionary | 220 | 30 |
| domain_guide | 240 | 35 |
| business_rules | 220 | 30 |
| faq | 180 | 20 |
| chat_examples | 220 | 30 |
| intents_catalog | 220 | 30 |
| glossary | 170 | 20 |
| assumptions_and_gaps | 220 | 30 |

Default rule if document_type is not mapped:
- max_words: 220
- overlap_words: 30

## Output Format
build_chunks.py writes JSONL to rag/outputs/chunks.jsonl.
Each line contains:
- chunk_id
- source_file
- domain
- document_type
- confidence
- evidence_scope (optional)
- section
- language
- text

evidence_scope assignment comes from manifest metadata when available.

## ID Strategy
chunk_id format:
- <source_stem>__<section_slug>__pNNN

Example:
- schema_reference__confirmed_from_screenshot__p001

## Safety Rules
1. Do not infer metadata values outside allowed taxonomy.
2. Keep section text coherent; avoid cross-topic mixing in one chunk.
3. Keep observed sample values and confidence labels intact.
4. Never emit unknown_policy as a confidence value.

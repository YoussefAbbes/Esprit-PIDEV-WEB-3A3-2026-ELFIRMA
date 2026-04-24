# RAG Roadmap - Post Correction Baseline

## Current Phase Status
The project now has an evidence-aligned corpus with explicit confidence labeling and normalized metadata taxonomy.

## Phase A - Completed (Current)
- Corpus corrected to separate confirmed, inferred, and assumed content.
- Unsupported canonical rules downgraded or removed.
- Metadata taxonomy normalized across configs.

## Phase B - Current Technical Preparation
1. Build and maintain corpus manifest from canonical metadata model.
2. Validate corpus consistency and taxonomy usage.
3. Generate chunks with heading-preserving strategy.

Deliverables in this phase:
- build_manifest.py
- validate_corpus.py
- build_chunks.py
- metadata_schema.json

## Phase C - Next Step (After This Turn)
Embeddings and vector index setup:
1. Choose embedding model compatible with bilingual content.
2. Embed rag/outputs/chunks.jsonl.
3. Store vectors plus metadata in vector database.
4. Verify retrieval quality with sample_queries and golden_answers.

## Phase D - Retrieval Integration In Symfony
1. Add retrieval service in src/Service.
2. Apply metadata filtering by confidence and domain.
3. Inject retrieved context into chat prompt template.
4. Return confidence-aware responses.

## Phase E - Hybrid RAG + SQL (Future)
1. Add policy for when to query live SQL.
2. Keep RAG for semantic/business explanations.
3. Use SQL for live counts, filters, and operational snapshots.

## Governance Rule
No policy-style recommendation should be marked as confirmed unless validated by owner documentation.

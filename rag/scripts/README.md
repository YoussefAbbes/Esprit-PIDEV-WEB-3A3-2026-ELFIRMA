# RAG Scripts - Run Guide

## Purpose
These scripts implement the local end-to-end RAG preparation workflow:
- manifest generation
- corpus validation
- chunk generation
- embedding generation
- vector index build
- metadata-aware retrieval search
- retrieval evaluation
- route-aware retrieval regression reporting
- query routing + document-type preference reranking
- context assembly
- answer prompt preparation
- local chatbot backend payload preparation
- Symfony backend integration preparation artifacts

## Dependency Files
- rag/requirements-rag.txt: human-facing core runtime overview (non-locking).
- rag/requirements-lock.txt: known-working machine-origin snapshot for this workspace.

Use the lockfile for reproducible installation:

python -m pip install -r rag/requirements-lock.txt

Lockfile note:
- The lockfile improves reproducibility, but it does not replace validation in the active interpreter.
- Always run doctor.py before retrieval scripts.

## Virtual Environment In VS Code
- Select the project interpreter: .venv/Scripts/python.exe.
- Optional activation in PowerShell:

.\.venv\Scripts\Activate.ps1

- Prefer `python` after activation. Avoid `py` for runtime scripts because it may bypass the selected project venv.

- Recommended preflight check before retrieval scripts:

python rag/scripts/doctor.py

- Retrieval scripts are considered runtime-ready only when doctor.py reports Overall: PASS in the active interpreter.

## RAG Runtime Dependency Map
Corpus-only scripts (no ML runtime required):
- build_manifest.py
- validate_corpus.py
- build_chunks.py

ML/retrieval scripts (require venv runtime packages):
- build_embeddings.py
- build_vector_index.py
- retrieval_core.py
- query_router.py
- search_index.py
- retrieval_smoke_test.py
- evaluate_retrieval.py
- build_context.py
- answer_with_rag_stub.py
- chat_engine.py

Environment-sensitive scripts:
- doctor.py (checks Python/runtime/model/artifacts)
- runtime_report.py (captures runtime context + doctor summary, optional JSON export)

Scripts included:
- build_manifest.py: regenerate rag/configs/corpus_manifest.json
- validate_corpus.py: validate taxonomy, manifest integrity, and chunk integrity
- build_chunks.py: create rag/outputs/chunks.jsonl from markdown corpus files
- doctor.py: verify Python/runtime packages/model/artifacts and print PASS/WARN/FAIL summary
- runtime_report.py: collect interpreter/runtime package info and doctor summary
- build_embeddings.py: create rag/outputs/chunk_embeddings.jsonl from chunks
- build_vector_index.py: build local vector index artifacts under rag/outputs/vector_index
- query_router.py: detect query intent route and define document_type preference policy
- search_index.py: query local index from CLI (FR/EN) with optional metadata filters
- retrieval_smoke_test.py: run quick retrieval checks from rag/tests/sample_queries.json
- evaluate_retrieval.py: evaluate retrieval quality with route-aware metrics and optional JSON regression report
- build_context.py: assemble retrieved context blocks for prompt construction
- answer_with_rag_stub.py: prepare final prompt payload and response contract stub
- chat_engine.py: run local chatbot backend flow (route -> retrieve -> context -> Symfony-friendly JSON contract)

## Routing Relevance Layer
Route names:
- field_definition
- observed_value_lookup
- structural_relationship
- policy_unknown
- example_request

Current route preference examples:
- field_definition: prefer data_dictionary, domain_guide, faq; de-prioritize intents_catalog, chat_examples
- observed_value_lookup: prefer faq, data_dictionary, schema_reference; de-prioritize intents_catalog, chat_examples
- structural_relationship: prefer schema_reference, domain_guide; de-prioritize intents_catalog, chat_examples
- policy_unknown: prefer assumptions_and_gaps, business_rules; de-prioritize intents_catalog, chat_examples
- example_request: prefer chat_examples, faq

Debug visibility:
- detected route
- preferred/de-prioritized document types
- rerank pool size
- whether reranking changed top ordering

## Requirements
- Python 3.10 or newer recommended
- Run commands from project root in VS Code terminal
- Install dependencies (reproducible target):

python -m pip install -r rag/requirements-lock.txt

- Optional human-facing core overview file:

python -m pip install -r rag/requirements-rag.txt

## Execution Order
1. Build manifest

python rag/scripts/build_manifest.py

2. Validate corpus and metadata

python rag/scripts/validate_corpus.py

3. Build chunks

python rag/scripts/build_chunks.py

4. Validate again

python rag/scripts/validate_corpus.py

5. Run runtime doctor

python rag/scripts/doctor.py

Optional reproducibility report export:

python rag/scripts/runtime_report.py --output-json

6. Build embeddings

python rag/scripts/build_embeddings.py

7. Build vector index

python rag/scripts/build_vector_index.py

8. Search index (English example)

python rag/scripts/search_index.py What fields are confirmed in animals --top-k 5

Equivalent option form:

python rag/scripts/search_index.py --query What fields are confirmed in animals --top-k 5

9. Search index (French example)

python rag/scripts/search_index.py Quelles valeurs sont observees pour etat_elevage --top-k 5

Equivalent option form:

python rag/scripts/search_index.py --query Quelles valeurs sont observees pour etat_elevage --top-k 5

10. Run retrieval smoke test

python rag/scripts/retrieval_smoke_test.py --top-k 5

11. Run retrieval evaluation cases

python rag/scripts/evaluate_retrieval.py --top-k 5

12. Run route-aware retrieval regression pack and export report

python rag/scripts/evaluate_retrieval.py --cases rag/tests/route_relevance_cases.json --top-k 5 --report-json

13. Build context block for a query

python rag/scripts/build_context.py Quelles valeurs ont ete observees pour etat_elevage --top-k 5

14. Prepare answer payload (stub)

python rag/scripts/answer_with_rag_stub.py Quelle est la regle officielle pour definir un vaccin en retard --top-k 5

15. Run chatbot backend engine (CLI)

python rag/scripts/chat_engine.py --query What does vaccination status mean --top-k 5

Optional route override:

python rag/scripts/chat_engine.py --query What does vaccination status mean --route field_definition --top-k 5

Machine-readable JSON mode for Symfony:

python rag/scripts/chat_engine.py --query What does vaccination status mean --top-k 5 --json

Stable chat_engine JSON contract fields:
- answer_text
- route
- sources
- confidence_summary
- evidence_summary
- context_metadata
- retrieval_debug

Optional debug payload flags:
- --include-context-items
- --include-prompt-payload

Optional metadata-aware search filters:
- --domain
- --document-type
- --confidence
- --language
- --evidence-scope

Optional routing controls:
- --route
- --disable-routing
- --rerank-pool-size

## Expected Outputs
- rag/configs/corpus_manifest.json (regenerated)
- rag/outputs/chunks.jsonl (generated)
- rag/outputs/chunk_embeddings.jsonl (generated)
- rag/outputs/vector_index/embeddings.npy
- rag/outputs/vector_index/metadata.jsonl
- rag/outputs/vector_index/index_meta.json
- rag/outputs/reports/runtime_report.json (optional)
- rag/outputs/reports/route_relevance_regression.json (optional, from --report-json)
- optional JSON output from build_context.py and answer_with_rag_stub.py when --output-file is used
- optional JSON output from chat_engine.py when --json and/or --output-file is used

## Notes
- Chunking behavior is defined in rag/configs/chunking_strategy.md and implemented in build_chunks.py.
- Taxonomy values are enforced from rag/configs/metadata_schema.json.
- unknown_policy is used as query class / response outcome in evaluation, never as confidence metadata.
- Use doctor.py before retrieval scripts when environment health is uncertain.
- Artifacts can exist from earlier successful runs even when the current interpreter is unhealthy.
- Retrieval is runtime-stable only when doctor.py passes in the active interpreter.
- For reproducibility, prefer requirements-lock.txt over requirements-rag.txt, then validate with doctor.py.
- Runtime is now validated in project .venv; current optimization priority is retrieval relevance and backend chatbot flow.
- Route-aware regression reports are now available for backend quality checks before Symfony endpoint integration.
- Symfony prep artifacts now exist in rag/configs/symfony_backend_plan.md and rag/configs/chat_api_contract.json.
- Frontend UX is intentionally out of scope at this stage.
- If validation reports unknown confidence labels or missing files, fix those before embedding.

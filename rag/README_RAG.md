# README - RAG Workspace (Corrected Baseline)

## What This Workspace Contains
This rag workspace is a production-oriented RAG foundation aligned to limited but explicit evidence.

Main folders:
- rag/corpus: business knowledge corpus with confidence-aware content
- rag/configs: metadata schema, chunking, retrieval, roadmap, evaluation, manifest
- rag/prompts: system and query rewriting prompt assets
- rag/tests: query sets, retrieval evaluation cases, route relevance cases, and answer-pipeline cases
- rag/scripts: executable Python utilities for validation, retrieval, and answer-pipeline preparation
- rag/outputs: generated artifacts (chunks, reports)

## Evidence Quality Policy
All documentation and retrieval assets must separate:
- confirmed (direct screenshot evidence)
- inferred (low-risk schema interpretation)
- assumed (hypothetical policy requiring validation)

Observed values are examples only and must not be treated as exhaustive enums.

unknown_policy usage:
- unknown_policy is a query class / response outcome in retrieval and evaluation.
- It is not part of metadata confidence taxonomy.

## Canonical Metadata Model
Defined in rag/configs/metadata_schema.json.

This schema controls:
- manifest validation
- chunk metadata
- retrieval filters
- optional evidence_scope metadata (observed_sample, schema_inference, policy_hypothesis)

## Runtime Dependencies
- rag/requirements-rag.txt: human-facing core runtime overview (non-locking).
- rag/requirements-lock.txt: known-working machine-origin snapshot for this workspace.

Use reproducible install target:
- python -m pip install -r rag/requirements-lock.txt

Portability note:
- Lockfile improves reproducibility but does not guarantee cross-machine portability.
- Always validate runtime in the active interpreter with doctor.py.

Use lightweight overview only for quick orientation:
- python -m pip install -r rag/requirements-rag.txt

## VS Code Virtual Environment Setup
1. Select Python interpreter: .venv/Scripts/python.exe
2. Optional PowerShell activation:
- .\.venv\Scripts\Activate.ps1
3. Prefer `python` after activation. Avoid `py` for runtime scripts because it may bypass project .venv.
4. Run runtime preflight before retrieval stack:
- python rag/scripts/doctor.py

Runtime readiness gate:
- Retrieval scripts are considered ready only when doctor.py reports Overall: PASS in the active interpreter.

## RAG Runtime Dependency Map
Corpus-only scripts (no ML runtime required):
- rag/scripts/build_manifest.py
- rag/scripts/validate_corpus.py
- rag/scripts/build_chunks.py

ML/retrieval scripts (require virtual environment runtime packages):
- rag/scripts/build_embeddings.py
- rag/scripts/build_vector_index.py
- rag/scripts/retrieval_core.py
- rag/scripts/query_router.py
- rag/scripts/search_index.py
- rag/scripts/retrieval_smoke_test.py
- rag/scripts/evaluate_retrieval.py
- rag/scripts/build_context.py
- rag/scripts/answer_with_rag_stub.py
- rag/scripts/chat_engine.py

Environment-sensitive scripts:
- rag/scripts/doctor.py
- rag/scripts/runtime_report.py

## Script Workflow (VS Code Terminal)
From project root:

0. Install dependencies
- python -m pip install -r rag/requirements-lock.txt

1. Build manifest
- python rag/scripts/build_manifest.py

2. Validate corpus and metadata
- python rag/scripts/validate_corpus.py

3. Build chunks
- python rag/scripts/build_chunks.py

4. Run runtime doctor
- python rag/scripts/doctor.py

Optional runtime report export
- python rag/scripts/runtime_report.py --output-json

5. Build embeddings
- python rag/scripts/build_embeddings.py

6. Build local vector index
- python rag/scripts/build_vector_index.py

7. Search index (EN)
- python rag/scripts/search_index.py What fields are confirmed in the animals table --top-k 5
- python rag/scripts/search_index.py --query What fields are confirmed in the animals table --top-k 5

8. Search index (FR)
- python rag/scripts/search_index.py Quelles valeurs ont ete observees pour etat_elevage --top-k 5
- python rag/scripts/search_index.py --query Quelles valeurs ont ete observees pour etat_elevage --top-k 5

9. Run retrieval smoke test
- python rag/scripts/retrieval_smoke_test.py --top-k 5

10. Run retrieval evaluation cases
- python rag/scripts/evaluate_retrieval.py --top-k 5

11. Run route-aware backend regression pack
- python rag/scripts/evaluate_retrieval.py --cases rag/tests/route_relevance_cases.json --top-k 5 --report-json

12. Build context block for prompting
- python rag/scripts/build_context.py Quelles valeurs ont ete observees pour etat_elevage --top-k 5

13. Prepare answer payload (stub)
- python rag/scripts/answer_with_rag_stub.py Quelle est la regle officielle pour definir un vaccin en retard --top-k 5

14. Run chatbot backend engine (CLI)
- python rag/scripts/chat_engine.py --query What does vaccination status mean --top-k 5

15. Run chatbot backend engine in Symfony-ready JSON mode
- python rag/scripts/chat_engine.py --query What does vaccination status mean --top-k 5 --json

chat_engine stable response contract fields:
- contract_version
- query
- answer_text
- route
- sources
- confidence_summary
- evidence_summary
- context_metadata
- retrieval_debug

Metadata-aware retrieval filters supported by search and context scripts:
- --domain
- --document-type
- --confidence
- --language
- --evidence-scope

Routing controls supported by search/context/answer/chat backend scripts:
- --route
- --disable-routing
- --rerank-pool-size

Output file:
- rag/outputs/chunks.jsonl
- rag/outputs/chunk_embeddings.jsonl
- rag/outputs/vector_index/embeddings.npy
- rag/outputs/vector_index/metadata.jsonl
- rag/outputs/vector_index/index_meta.json
- rag/outputs/reports/runtime_report.json (optional)
- rag/outputs/reports/route_relevance_regression.json (optional)
- optional context/prompt payload JSON files when --output-file is provided in context/answer scripts
- optional structured chat JSON payload from chat_engine.py when --json or --output-file is used

Important runtime caveat:
- Existing artifacts can still be present from prior runs even if the current interpreter/runtime is unhealthy.
- Run doctor.py first; do not assume artifact presence means runtime readiness.

## Current Readiness
The project now supports:
- runtime-validated local chunk-to-index retrieval
- metadata-aware filtering
- query routing with document-type preference reranking
- route-aware retrieval quality evaluation and regression reporting
- context and prompt payload preparation for answer generation
- local chatbot backend orchestration via chat_engine.py with stable request/response JSON contract fields
- Symfony web-facing backend integration via POST /api/chat
- Symfony process-based Python invocation with timeout and error mapping
- Symfony request validation and normalized error payload contract
- full request contract support for min_score, disable_routing, rerank_pool_size, and debug
- strict response contract gate on contract_version/query/answer_text/route/sources summaries

Runtime validation status:
- doctor.py passes in project .venv.
- runtime_report.py confirms active .venv interpreter and core package health.
- retrieval scripts are operational when doctor.py is PASS.

Symfony runtime prerequisite:
- request validation uses mbstring when available and falls back safely when unavailable.

## Backend UX Readiness Gate
Backend is UX-ready only after all checks pass in target environment:
1. php bin/console debug:router api_chat
2. php bin/console lint:container
3. POST /api/chat smoke test returns required fields:
	- contract_version
	- query
	- answer_text
	- route
	- sources
	- confidence_summary
	- evidence_summary
	- context_metadata
	- retrieval_debug
4. Optional recommended runtime check: php -m | findstr mbstring

## Next Technical Step
1. Run and record endpoint smoke-test evidence for /api/chat in target environment.
2. Add Symfony integration tests for happy path and failure mapping around /api/chat.
3. Continue tuning retrieval relevance/routing on real question sets and monitor route regression outputs.
4. Keep chat_engine.py in local-only mode while preserving the explicit LLM extension hook.
5. Start final frontend UX implementation on top of verified /api/chat contract.

## Symfony Integration Direction
Implemented integration points:
- src/Service/ChatbotEngineService.php for retrieval orchestration and Python process handling
- src/Service/ChatbotRequestValidator.php for request schema validation and normalization
- src/Controller/ChatbotController.php exposing POST /api/chat
- rag/configs/chat_api_contract.json for request/response/error contract reference
- docs/elfirma/CHATBOT_BACKEND_INTEGRATION.md for local runbook

Keep confidence-aware behavior active during all integration stages.

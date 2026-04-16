# Symfony Backend Integration Plan

## Status
Implemented and stabilized in current backend phase.

- Service: src/Service/ChatbotEngineService.php
- Request validator: src/Service/ChatbotRequestValidator.php
- DTO: src/Dto/ChatbotRequest.php
- Controller: src/Controller/ChatbotController.php
- Endpoint: POST /api/chat

## Objective
Expose the local Python RAG backend (`rag/scripts/chat_engine.py`) through Symfony API endpoints without frontend coupling.

Contract strategy in use: Strategy B (full support now).

## Target Architecture
1. Symfony controller receives HTTP JSON request.
2. Symfony service validates payload and launches Python command safely.
3. Python `chat_engine.py` executes retrieval pipeline and returns JSON output.
4. Symfony service parses JSON, normalizes error handling, and returns API response.

## Symfony Mapping

### Python entrypoint
- Script: `rag/scripts/chat_engine.py`
- Required backend output fields:
  - `contract_version`
  - `query`
  - `answer_text`
  - `route`
  - `sources`
  - `confidence_summary`
  - `evidence_summary`
  - `context_metadata`
  - `retrieval_debug`

### Symfony classes
- `src/Service/ChatbotEngineService.php`
  - Responsibility: process launch, timeout, stderr capture, JSON decode, contract validation.
- `src/Controller/ChatbotController.php`
  - Responsibility: request validation and response mapping.
- `src/Service/ChatbotRequestValidator.php`
  - Responsibility: input schema validation and normalization.

### Route
- `POST /api/chat`

## Request and Response Flow

### Request JSON (from Symfony endpoint)
```json
{
  "query": "What does vaccination status mean?",
  "top_k": 5,
  "min_score": -0.2,
  "disable_routing": false,
  "rerank_pool_size": 25,
  "route_override": null,
  "filters": {
    "domain": ["vaccination"],
    "language": "fr-en"
  },
  "debug": false
}
```

### Symfony -> Python command mapping
- Use configured `RAG_PYTHON_PATH` (project `.venv` path is recommended).
- Build command arguments explicitly (never concatenate untrusted shell fragments).
- Example argument mapping:
  - `query` -> `--query`
  - `top_k` -> `--top-k`
  - `min_score` -> `--min-score`
  - filter arrays -> repeated args (`--domain`, `--language`, etc.)
  - optional route override -> `--route`
  - disable routing -> `--disable-routing`
  - rerank pool size -> `--rerank-pool-size`
  - request debug true -> `--include-context-items --include-prompt-payload`
- Always include `--json` for machine-readable output.

  ### Runtime configuration parameters
  - RAG_PYTHON_PATH
  - RAG_CHAT_ENGINE_PATH
  - RAG_DEFAULT_TOP_K
  - RAG_PROCESS_TIMEOUT

### Runtime prerequisites
- Request validation uses `mb_strlen` when available and safely falls back to native string length otherwise.
- Verify mbstring availability (recommended): `php -m | findstr mbstring`

### Response JSON (from Symfony endpoint)
Return Python payload directly when valid, plus optional Symfony envelope metadata (request_id, elapsed_ms).

## Safe Python Invocation in Symfony
1. Use Symfony Process component with argument arrays.
2. Set working directory to project root.
3. Enforce execution timeout (for example 15-20 seconds).
4. Capture stdout and stderr separately.
5. Parse stdout as JSON only when exit code is zero.
6. On parse failure or non-zero exit, map to standardized API error format.
7. Log command latency and route for backend observability.

## Contract Validation Rules (Symfony Side)
Validate that response includes these fields before returning:
- `contract_version` (string)
- `query` (string)
- `answer_text` (string)
- `route` (string)
- `sources` (array)
- `confidence_summary` (object)
- `evidence_summary` (object)
- `context_metadata` (object)
- `retrieval_debug` (object)

For each item in `sources`, require:
- `source_file` (string)
- `section` (string)
- `document_type` (string)
- `confidence` (string)
- `score` (number)

If missing, return `upstream_contract_error` with diagnostic metadata.

## What Stays in Python vs PHP

### Keep in Python
- Embedding model loading and vector retrieval.
- Query routing logic.
- Route-based reranking.
- Context assembly and retrieval debug production.
- Future LLM provider hook (`llm_extension_point`).

### Keep in PHP (Symfony)
- HTTP API, auth, rate limiting, and request validation.
- User/session context management.
- Error normalization and API contracts.
- Logging, tracing, and operational monitoring.

## Incremental Rollout Plan
1. Confirm backend readiness checklist in target runtime:
  - `php bin/console debug:router api_chat`
  - `php bin/console lint:container`
  - POST `/api/chat` smoke test
  - optional recommended check: `php -m | findstr mbstring`
2. Add integration tests with fixture requests.
3. Add timeout and error mapping tests.
4. Add lightweight telemetry (route distribution, error categories).
5. Start frontend UX integration on top of validated `/api/chat` contract.

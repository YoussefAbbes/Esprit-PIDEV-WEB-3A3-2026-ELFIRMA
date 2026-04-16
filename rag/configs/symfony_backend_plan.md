# Symfony Backend Integration Plan

## Objective
Expose the local Python RAG backend (`rag/scripts/chat_engine.py`) through Symfony API endpoints without frontend coupling.

## Target Architecture
1. Symfony controller receives HTTP JSON request.
2. Symfony service validates payload and launches Python command safely.
3. Python `chat_engine.py` executes retrieval pipeline and returns JSON output.
4. Symfony service parses JSON, normalizes error handling, and returns API response.

## Symfony Mapping

### Python entrypoint
- Script: `rag/scripts/chat_engine.py`
- Required backend output fields:
  - `answer_text`
  - `route`
  - `sources`
  - `confidence_summary`
  - `evidence_summary`
  - `context_metadata`
  - `retrieval_debug`

### Suggested Symfony classes
- `src/Service/RagChatService.php`
  - Responsibility: process launch, timeout, stderr capture, JSON decode, contract validation.
- `src/Controller/RagChatController.php`
  - Responsibility: request validation and response mapping.

### Suggested route
- `POST /api/rag/chat`

## Request and Response Flow

### Request JSON (from Symfony endpoint)
```json
{
  "query": "What does vaccination status mean?",
  "top_k": 5,
  "filters": {
    "domain": ["vaccination"],
    "language": ["fr-en"]
  },
  "route_override": null,
  "debug": false
}
```

### Symfony -> Python command mapping
- Always call Python from project `.venv`.
- Build command arguments explicitly (never concatenate untrusted shell fragments).
- Example argument mapping:
  - `query` -> positional query text
  - `top_k` -> `--top-k`
  - filter arrays -> repeated args (`--domain`, `--language`, etc.)
  - optional route override -> `--route`
  - request debug true -> `--include-context-items --include-prompt-payload`
- Always include `--json` for machine-readable output.

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
- `answer_text` (string)
- `route` (string)
- `sources` (array)
- `confidence_summary` (object)
- `evidence_summary` (object)
- `context_metadata` (object)
- `retrieval_debug` (object)

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
1. Add `RagChatService` with a minimal happy-path call to Python.
2. Add `RagChatController` endpoint with strict request schema.
3. Add integration tests with fixture requests.
4. Add timeout and error mapping tests.
5. Add lightweight telemetry (route distribution, error categories).
6. Keep frontend integration for a later phase.

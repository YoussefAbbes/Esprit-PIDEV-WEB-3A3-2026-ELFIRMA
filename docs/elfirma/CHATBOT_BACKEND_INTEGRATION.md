# Chatbot Backend Integration (Symfony + Python RAG)

## Scope
This document captures the final backend stabilization state before frontend UX build.

- Endpoint: POST /api/chat
- Backend strategy: full request contract support (Strategy B)
- Frontend UX can start only after readiness checks pass in target runtime.

## Implemented Backend Components
- Symfony service: src/Service/ChatbotEngineService.php
- Request validation service: src/Service/ChatbotRequestValidator.php
- Request DTO: src/Dto/ChatbotRequest.php
- API controller: src/Controller/ChatbotController.php
- Contract reference: rag/configs/chat_api_contract.json

## Symfony Wiring Status
The backend service parameters are already wired in config/services.yaml:

```yaml
parameters:
    app.maptiler_api_key: '%env(MAPTILER_API_KEY)%'
    rag.python_path: '%env(string:RAG_PYTHON_PATH)%'
    rag.chat_engine_path: '%env(string:RAG_CHAT_ENGINE_PATH)%'
    rag.default_top_k: '%env(int:RAG_DEFAULT_TOP_K)%'
    rag.process_timeout: '%env(float:RAG_PROCESS_TIMEOUT)%'
```

## Runtime Flow
1. Client posts JSON to POST /api/chat.
2. Symfony validates and normalizes request payload.
3. Symfony launches rag/scripts/chat_engine.py via Symfony Process.
4. Python returns JSON payload.
5. Symfony validates response contract and returns JSON to client.

## Final Request Contract (Symfony + Python Aligned)

### Required
- query: string, non-empty, max 2000 characters

### Optional
- top_k: integer, 1..20
- min_score: number, -1..1
- route_override: one of:
  - field_definition
  - observed_value_lookup
  - structural_relationship
  - policy_unknown
  - example_request
- disable_routing: boolean
- rerank_pool_size: integer, 0..200
- debug: boolean
- filters: object with keys:
  - domain
  - document_type
  - confidence
  - language
  - evidence_scope

For each filter key, value can be either:
- string
- array of strings

Unsupported top-level fields are rejected (request additionalProperties=false behavior).

### Symfony -> Python Argument Mapping
- query -> --query
- top_k -> --top-k
- min_score -> --min-score
- route_override -> --route
- disable_routing=true -> --disable-routing
- rerank_pool_size -> --rerank-pool-size
- filters -> repeated --domain / --document-type / --confidence / --language / --evidence-scope
- debug=true -> --include-context-items --include-prompt-payload
- always enforced -> --json

## Final Success Response Contract

### Required top-level fields
- contract_version (string)
- query (string)
- answer_text (string)
- route (string)
- sources (array)
- confidence_summary (object)
- evidence_summary (object)
- context_metadata (object)
- retrieval_debug (object)

### Required source item fields
Each element in sources must contain:
- source_file (string)
- section (string)
- document_type (string)
- confidence (string)
- score (number)

### Optional fields (when present)
- llm (object)
- context_items (array)
- context_block (string)
- prompt_payload (object)

## Error Response Contract
Error payload:

```json
{
  "error_code": "validation_error",
  "message": "Invalid chat request payload.",
  "details": {
    "violations": {
      "query": ["query must not be empty."]
    }
  },
  "request_id": "d6c9f5e9f7a21124"
}
```

Current backend error_code values:
- validation_error
- missing_python_executable
- missing_chat_engine_script
- python_execution_error
- python_timeout
- python_output_parse_error
- upstream_contract_error
- empty_answer_payload
- internal_error

## Runtime Note: mbstring
Chat request validation now uses a safe length fallback.

- If `mbstring` is available, multibyte-safe length checks are used.
- If `mbstring` is missing, backend falls back to native string length checks.

Check:

```powershell
php -m | findstr mbstring
```

Enabling mbstring is still recommended for best multibyte accuracy, but it is no longer a hard blocker for POST /api/chat.

## Endpoint Smoke Test Procedure

### 1) Verify route and service wiring

```powershell
php bin/console debug:router api_chat
php bin/console lint:container
```

### 2) Send a contract-level request

```powershell
$body = @{
  query = "What does vaccination status mean?"
  top_k = 5
  min_score = -0.2
  route_override = "field_definition"
  disable_routing = $false
  rerank_pool_size = 25
  debug = $false
  filters = @{
    domain = @("vaccination")
    language = "fr-en"
  }
} | ConvertTo-Json -Depth 6

Invoke-RestMethod -Method Post -Uri "http://127.0.0.1:8000/api/chat" -ContentType "application/json" -Body $body
```

### 3) Verify required success fields exist
- contract_version
- query
- answer_text
- route
- sources
- confidence_summary
- evidence_summary
- context_metadata
- retrieval_debug

## Backend UX-Readiness Checklist
Backend is considered UX-ready only when all checks pass in the target environment:

1. /api/chat route present (`php bin/console debug:router api_chat`)
2. container wiring valid (`php bin/console lint:container`)
3. Python runtime and script path valid (no missing_python_executable/missing_chat_engine_script)
4. POST /api/chat smoke test passes with required contract fields
5. Optional but recommended: confirm `mbstring` enabled (`php -m | findstr mbstring`)

## Responsibility Split

### Python (rag/scripts/chat_engine.py)
- Retrieval and ranking pipeline
- Query routing and reranking behavior
- Context assembly
- Structured chat payload generation

### Symfony
- Public HTTP contract
- Request validation/normalization
- Python process orchestration and timeout/error mapping
- Response contract gate before frontend consumption

## Final Statement
Backend implementation is complete and contract-aligned. Final UX implementation can begin after running and passing the readiness checklist above in the deployment environment.

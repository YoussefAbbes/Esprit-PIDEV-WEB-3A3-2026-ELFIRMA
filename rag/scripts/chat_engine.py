#!/usr/bin/env python3
"""Local CLI chatbot backend engine (retrieval + prompt payload + grounded fallback answer)."""

from __future__ import annotations

import argparse
import io
import json
import os
import re
import sys
import urllib.error
import urllib.parse
import urllib.request
from contextlib import contextmanager, redirect_stderr, redirect_stdout
from pathlib import Path
from typing import Dict, List, Optional

from build_context import build_context_payload, resolve_config_path
from query_router import ROUTE_NAMES
from retrieval_core import PROJECT_ROOT, active_filters, build_metadata_filters

DEFAULT_TEMPLATE_PATH = PROJECT_ROOT / "rag" / "prompts" / "rag_answer_template.md"
DEFAULT_CONFIG_PATH = PROJECT_ROOT / "rag" / "configs" / "embedding_config.json"
DEFAULT_GEMINI_MODELS = [
    "gemini-flash-latest",
    "gemini-2.0-flash",
    "gemini-1.5-flash",
    "gemini-1.5-pro",
]

QUERY_STOPWORDS = {
    "a",
    "an",
    "and",
    "are",
    "dans",
    "de",
    "des",
    "does",
    "est",
    "for",
    "is",
    "la",
    "le",
    "les",
    "of",
    "pour",
    "que",
    "quel",
    "quelle",
    "quels",
    "quelles",
    "the",
    "this",
    "what",
    "which",
}

FRENCH_HINTS = [
    " quel ",
    " quelle ",
    " quels ",
    " quelles ",
    " pourquoi ",
    " comment ",
    " est ce ",
    " c est ",
    " statut ",
    " prochaine ",
    " semaine ",
    " elevage ",
]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run local RAG chat backend engine")
    parser.add_argument("query", nargs="*", help="User query (FR or EN) (positional)")
    parser.add_argument(
        "--query",
        dest="query_option",
        nargs="+",
        help="User query (FR or EN) (option form)",
    )
    parser.add_argument("--top-k", type=int, default=5, help="Top context chunks to retrieve")
    parser.add_argument("--min-score", type=float, default=-1.0, help="Minimum similarity score")
    parser.add_argument(
        "--config",
        default=str(DEFAULT_CONFIG_PATH),
        help="Path to embedding config JSON file",
    )
    parser.add_argument(
        "--template",
        default=str(DEFAULT_TEMPLATE_PATH),
        help="Path to answer template markdown",
    )
    parser.add_argument("--domain", action="append", help="Optional domain filter")
    parser.add_argument("--document-type", action="append", help="Optional document_type filter")
    parser.add_argument("--confidence", action="append", help="Optional confidence filter")
    parser.add_argument("--language", action="append", help="Optional language filter")
    parser.add_argument("--evidence-scope", action="append", help="Optional evidence_scope filter")
    parser.add_argument(
        "--route",
        choices=ROUTE_NAMES,
        help="Optional route override for retrieval preference",
    )
    parser.add_argument(
        "--disable-routing",
        action="store_true",
        help="Disable route-based document_type preference reranking",
    )
    parser.add_argument(
        "--rerank-pool-size",
        type=int,
        default=0,
        help="Optional initial retrieval depth before route reranking",
    )
    parser.add_argument(
        "--enable-llm",
        action="store_true",
        help="Reserved extension point for a real LLM provider call",
    )
    parser.add_argument(
        "--include-context-items",
        action="store_true",
        help="Include full context_items and context_block in payload (debug mode)",
    )
    parser.add_argument(
        "--include-prompt-payload",
        action="store_true",
        help="Include assembled prompt payload in output (debug mode)",
    )
    parser.add_argument("--json", action="store_true", help="Print full payload as JSON")
    parser.add_argument("--output-file", default="", help="Optional output JSON file path")
    return parser.parse_args()


def resolve_path(path_str: str) -> Path:
    path = Path(path_str)
    if not path.is_absolute():
        path = PROJECT_ROOT / path
    return path


@contextmanager
def quiet_runtime_output(enabled: bool):
    """Suppress noisy third-party runtime output when strict JSON is required."""
    if not enabled:
        yield
        return

    env_updates = {
        "HF_HUB_DISABLE_PROGRESS_BARS": "1",
        "TRANSFORMERS_VERBOSITY": "error",
        "TOKENIZERS_PARALLELISM": "false",
    }
    previous_env = {key: os.environ.get(key) for key in env_updates}
    for key, value in env_updates.items():
        os.environ[key] = value

    sink_out = io.StringIO()
    sink_err = io.StringIO()
    try:
        with redirect_stdout(sink_out), redirect_stderr(sink_err):
            yield
    finally:
        for key, old_value in previous_env.items():
            if old_value is None:
                os.environ.pop(key, None)
            else:
                os.environ[key] = old_value


def parse_query(args: argparse.Namespace) -> str:
    if args.query_option and args.query:
        raise ValueError("provide query as positional text or --query, not both")

    query_parts = args.query_option if args.query_option else args.query
    query_text = " ".join(query_parts).strip()
    if not query_text:
        raise ValueError("empty query")
    return query_text


def build_prompt_payload(template_text: str, query_text: str, context_block: str) -> Dict[str, object]:
    assembled_prompt = (
        f"{template_text}\n\n"
        f"## User Query\n{query_text}\n\n"
        f"## Retrieved Context\n{context_block}\n"
    )
    return {
        "template_preview": template_text[:400],
        "assembled_prompt": assembled_prompt,
        "messages": [
            {"role": "system", "content": template_text},
            {
                "role": "user",
                "content": (
                    f"User query:\n{query_text}\n\n"
                    f"Retrieved context:\n{context_block}"
                ),
            },
        ],
    }


def llm_extension_point(messages: List[Dict[str, str]], query_text: str, route_name: str) -> Optional[str]:
    provider = (read_non_empty_env("RAG_LLM_PROVIDER") or "").strip().lower()
    if provider not in {"gemini", "google", "google_gemini"}:
        return None

    api_key = read_non_empty_env("GEMINI_API_KEY", "RAG_GEMINI_API_KEY")
    if api_key is None:
        return None

    model_candidates = build_model_candidates(read_non_empty_env("RAG_LLM_MODEL", "GEMINI_MODEL"))
    timeout_seconds = parse_timeout_seconds(read_non_empty_env("RAG_LLM_TIMEOUT_SECONDS"), fallback=20.0)
    max_output_tokens = parse_max_output_tokens(read_non_empty_env("RAG_LLM_MAX_OUTPUT_TOKENS"), fallback=1400)
    prompt_text = build_provider_prompt(messages=messages, query_text=query_text, route_name=route_name)

    payload = {
        "contents": [
            {
                "role": "user",
                "parts": [{"text": prompt_text}],
            }
        ],
        "generationConfig": {
            "temperature": 0.2,
            "maxOutputTokens": max_output_tokens,
        },
    }

    for api_version in ["v1beta", "v1"]:
        for model_name in model_candidates:
            request_payload = {
                "contents": payload["contents"],
                "generationConfig": dict(payload["generationConfig"]),
            }

            retries = 0
            while retries <= 2:
                if retries > 0:
                    previous_max_tokens = int(request_payload["generationConfig"].get("maxOutputTokens", max_output_tokens))
                    request_payload["generationConfig"]["maxOutputTokens"] = min(previous_max_tokens * 2, 4096)

                response_data = call_gemini_generate_content(
                    api_key=api_key,
                    model_name=model_name,
                    payload=request_payload,
                    api_version=api_version,
                    timeout_seconds=timeout_seconds,
                )
                text = extract_gemini_text(response_data)
                finish_reason = extract_gemini_finish_reason(response_data)
                if text:
                    if finish_reason == "MAX_TOKENS" and int(request_payload["generationConfig"].get("maxOutputTokens", 0)) < 4096:
                        retries += 1
                        continue
                    return text
                break

    return None


def read_non_empty_env(*names: str) -> Optional[str]:
    for name in names:
        value = os.environ.get(name)
        if value is not None and value.strip() != "":
            return value.strip()
    return None


def parse_timeout_seconds(raw_value: Optional[str], fallback: float) -> float:
    if raw_value is None:
        return fallback

    try:
        value = float(raw_value)
    except ValueError:
        return fallback

    if value <= 0:
        return fallback
    return min(value, 60.0)


def parse_max_output_tokens(raw_value: Optional[str], fallback: int) -> int:
    if raw_value is None:
        return fallback

    try:
        value = int(float(raw_value))
    except ValueError:
        return fallback

    if value < 128:
        return 128

    return min(value, 8192)


def normalize_model_name(model_name: str) -> str:
    cleaned = model_name.strip()
    if cleaned.startswith("models/"):
        cleaned = cleaned.split("/", 1)[1]
    return cleaned


def build_model_candidates(configured_model: Optional[str]) -> List[str]:
    ordered: List[str] = []
    seen = set()

    if configured_model is not None:
        normalized = normalize_model_name(configured_model)
        if normalized not in seen and normalized != "":
            seen.add(normalized)
            ordered.append(normalized)

    for model_name in DEFAULT_GEMINI_MODELS:
        if model_name in seen:
            continue
        seen.add(model_name)
        ordered.append(model_name)

    return ordered


def build_provider_prompt(messages: List[Dict[str, str]], query_text: str, route_name: str) -> str:
    system_blocks: List[str] = []
    user_blocks: List[str] = []
    for message in messages:
        role = str(message.get("role", "")).strip().lower()
        content = str(message.get("content", "")).strip()
        if not content:
            continue
        if role == "system":
            system_blocks.append(content)
        else:
            user_blocks.append(content)

    system_text = "\n\n".join(system_blocks)
    user_text = "\n\n".join(user_blocks)

    return (
        "You are an evidence-grounded assistant for agriculture operations. "
        "Use only retrieved context and avoid unsupported claims.\n\n"
        f"Detected route: {route_name}\n\n"
        f"System instructions:\n{system_text}\n\n"
        f"User query: {query_text}\n\n"
        f"Retrieved evidence payload:\n{user_text}\n\n"
        "Return a concise, actionable answer. Include uncertainty when evidence is weak."
    )


def call_gemini_generate_content(
    api_key: str,
    model_name: str,
    payload: Dict[str, object],
    api_version: str,
    timeout_seconds: float,
) -> Dict[str, object]:
    base_url = (
        f"https://generativelanguage.googleapis.com/{api_version}/models/"
        f"{model_name}:generateContent"
    )
    encoded_key = urllib.parse.quote(api_key, safe="")

    request_variants = [
        (
            base_url,
            {
                "Content-Type": "application/json",
                "x-goog-api-key": api_key,
            },
        ),
        (
            f"{base_url}?key={encoded_key}",
            {
                "Content-Type": "application/json",
            },
        ),
    ]

    body_bytes = json.dumps(payload, ensure_ascii=False).encode("utf-8")
    for url, headers in request_variants:
        request = urllib.request.Request(
            url=url,
            data=body_bytes,
            headers=headers,
            method="POST",
        )

        try:
            with urllib.request.urlopen(request, timeout=timeout_seconds) as response:
                content = response.read().decode("utf-8")
                parsed = json.loads(content)
                if isinstance(parsed, dict):
                    return parsed
                return {}
        except (urllib.error.HTTPError, urllib.error.URLError, TimeoutError, json.JSONDecodeError):
            continue

    return {}


def extract_gemini_text(response_data: Dict[str, object]) -> Optional[str]:
    candidates = response_data.get("candidates")
    if not isinstance(candidates, list) or not candidates:
        return None

    first_candidate = candidates[0]
    if not isinstance(first_candidate, dict):
        return None

    content = first_candidate.get("content")
    if not isinstance(content, dict):
        return None

    parts = content.get("parts")
    if not isinstance(parts, list) or not parts:
        return None

    text_parts: List[str] = []
    for part in parts:
        if not isinstance(part, dict):
            continue
        text = part.get("text")
        if isinstance(text, str) and text.strip() != "":
            text_parts.append(text.strip())

    if not text_parts:
        return None

    return "\n".join(text_parts)


def extract_gemini_finish_reason(response_data: Dict[str, object]) -> str:
    candidates = response_data.get("candidates")
    if not isinstance(candidates, list) or not candidates:
        return ""

    first_candidate = candidates[0]
    if not isinstance(first_candidate, dict):
        return ""

    finish_reason = first_candidate.get("finishReason")
    if not isinstance(finish_reason, str):
        return ""

    return finish_reason.strip().upper()


def build_sources(context_items: List[Dict[str, object]]) -> List[Dict[str, object]]:
    seen = set()
    sources: List[Dict[str, object]] = []

    for item in context_items:
        source_file = str(item.get("source_file", "n/a"))
        section = str(item.get("section", "n/a"))
        key = (source_file, section)
        if key in seen:
            continue
        seen.add(key)
        sources.append(
            {
                "source_file": source_file,
                "section": section,
                "document_type": item.get("document_type", "n/a"),
                "confidence": item.get("confidence", "n/a"),
                "score": item.get("score", 0.0),
            }
        )
    return sources


def build_confidence_summary(context_items: List[Dict[str, object]]) -> Dict[str, object]:
    counts: Dict[str, int] = {}
    for item in context_items:
        label = str(item.get("confidence", "unknown"))
        counts[label] = counts.get(label, 0) + 1

    top_confidence = str(context_items[0].get("confidence", "unknown")) if context_items else "none"
    return {
        "top_confidence": top_confidence,
        "counts": counts,
    }


def build_evidence_summary(context_items: List[Dict[str, object]], sources: List[Dict[str, object]]) -> Dict[str, object]:
    doc_type_counts: Dict[str, int] = {}
    for item in context_items:
        doc_type = str(item.get("document_type", "unknown"))
        doc_type_counts[doc_type] = doc_type_counts.get(doc_type, 0) + 1

    return {
        "chunk_count": len(context_items),
        "source_count": len(sources),
        "document_type_counts": doc_type_counts,
        "top_source": sources[0] if sources else None,
    }


def build_context_metadata(
    context_payload: Dict[str, object],
    filters: Dict[str, object],
    top_k: int,
) -> Dict[str, object]:
    routing = context_payload.get("routing", {})
    return {
        "retrieved_count": int(context_payload.get("retrieved_count", 0)),
        "candidate_count": int(context_payload.get("candidate_rows", routing.get("pool_candidates", 0))),
        "top_k": int(top_k),
        "retrieval_pool_top_k": int(context_payload.get("retrieval_pool_top_k", top_k)),
        "active_filters": active_filters(filters),
        "has_context_block": bool(str(context_payload.get("context_block", "")).strip()),
    }


def build_retrieval_debug(
    route_name: str,
    context_payload: Dict[str, object],
    context_items: List[Dict[str, object]],
) -> Dict[str, object]:
    routing = context_payload.get("routing", {})
    debug_sources = []
    for idx, item in enumerate(context_items[:5], start=1):
        debug_sources.append(
            {
                "rank": idx,
                "source_file": str(item.get("source_file", "n/a")),
                "document_type": str(item.get("document_type", "n/a")),
                "score": float(item.get("score", 0.0)),
                "raw_score": float(item.get("raw_score", item.get("score", 0.0))),
                "boost": float(item.get("boost", 0.0)),
            }
        )

    return {
        "detected_route": route_name,
        "preferred_document_types": list(routing.get("preferred_document_types", [])),
        "deprioritized_document_types": list(routing.get("deprioritized_document_types", [])),
        "reranking_applied": bool(routing.get("reranking_applied", False)),
        "order_changed": bool(routing.get("order_changed", False)),
        "adjustments_applied": int(routing.get("adjustments_applied", 0)),
        "top_candidates": debug_sources,
    }


def detect_query_language(query_text: str) -> str:
    normalized = f" {query_text.lower()} "
    french_hits = sum(1 for marker in FRENCH_HINTS if marker in normalized)
    return "fr" if french_hits > 0 else "en"


def tokenize_query(query_text: str) -> List[str]:
    normalized = re.sub(r"[^a-z0-9_]+", " ", query_text.lower())
    tokens: List[str] = []
    for token in normalized.split():
        if len(token) < 3 or token in QUERY_STOPWORDS:
            continue
        tokens.append(token)
    return tokens


def clean_inline_text(value: str) -> str:
    return " ".join(value.replace("\t", " ").split()).strip(" .")


def is_placeholder_value(value: str) -> bool:
    lowered = value.strip().lower()
    return lowered in {"", "none", "none shown", "n/a", "na", "unknown", "not specified"}


def extract_observed_samples(context_items: List[Dict[str, object]]) -> List[str]:
    pattern = re.compile(r"Observed sample:\s*([^\n]+)", re.IGNORECASE)
    samples: List[str] = []
    seen = set()

    for item in context_items:
        text = str(item.get("text", ""))
        for match in pattern.finditer(text):
            sample = clean_inline_text(match.group(1))
            if sample and sample not in seen:
                seen.add(sample)
                samples.append(sample)

    return samples


def extract_best_table_row(query_text: str, context_items: List[Dict[str, object]]) -> Optional[Dict[str, str]]:
    query_tokens = tokenize_query(query_text)
    if not query_tokens:
        return None

    best_row: Optional[Dict[str, str]] = None
    best_score = 0

    for item in context_items:
        text = str(item.get("text", ""))
        for raw_line in text.splitlines():
            line = raw_line.strip()
            if not line.startswith("|"):
                continue

            cells = [clean_inline_text(part) for part in line.strip("|").split("|")]
            if len(cells) < 4:
                continue
            if all(cell.replace("-", "") == "" for cell in cells):
                continue

            row_text = " ".join(cells).lower()
            score = 0
            for token in query_tokens:
                if token in row_text:
                    score += 1

            field_name = cells[0].lower()
            if any(token == field_name or token in field_name for token in query_tokens):
                score += 2

            if score <= best_score:
                continue

            best_score = score
            best_row = {
                "field": cells[0],
                "business_meaning": cells[1] if len(cells) > 1 else "",
                "observed_sample": cells[2] if len(cells) > 2 else "",
                "candidate_values": cells[3] if len(cells) > 3 else "",
                "confidence_notes": cells[4] if len(cells) > 4 else "",
            }

    return best_row


def compact_snippet(text: str, max_chars: int = 220) -> str:
    content_lines: List[str] = []
    for raw_line in text.splitlines():
        line = raw_line.strip()
        if not line:
            continue
        if line.startswith("#") or line.startswith("|"):
            continue
        content_lines.append(line)

    if not content_lines:
        content_lines = [line.strip() for line in text.splitlines() if line.strip()]

    snippet = clean_inline_text(" ".join(content_lines))
    if len(snippet) <= max_chars:
        return snippet
    return snippet[: max_chars - 3].rstrip() + "..."


def best_evidence_snippet(context_items: List[Dict[str, object]]) -> str:
    for item in context_items:
        text = str(item.get("text", ""))
        snippet = compact_snippet(text)
        if snippet:
            return snippet

    return "No textual snippet was available in the retrieved evidence."


def route_intro(route_name: str, language: str) -> str:
    english = {
        "field_definition": "Based on the retrieved documentation, this is the best grounded definition answer:",
        "observed_value_lookup": "Based on the retrieved evidence, this is the best observed-value answer:",
        "structural_relationship": "Based on the retrieved schema evidence, this is the best relationship answer:",
        "policy_unknown": "The retrieved evidence does not provide an official policy statement:",
        "example_request": "Using the retrieved evidence, here is a practical example-oriented answer:",
    }
    french = {
        "field_definition": "D'apres la documentation recuperee, voici la meilleure definition fondee sur les preuves:",
        "observed_value_lookup": "D'apres les preuves recuperees, voici la meilleure reponse de valeur observee:",
        "structural_relationship": "D'apres les preuves de schema recuperees, voici la meilleure relation structurelle:",
        "policy_unknown": "Les preuves recuperees ne donnent pas de politique officielle explicite:",
        "example_request": "En utilisant les preuves recuperees, voici une reponse orientee exemple:",
    }

    if language == "fr":
        return french.get(route_name, french["field_definition"])
    return english.get(route_name, english["field_definition"])


def build_no_evidence_answer(query_text: str, language: str) -> str:
    if language == "fr":
        return (
            "Je n'ai pas trouve de preuve suffisante dans le corpus pour repondre de facon fiable a cette question.\n"
            "Reformulez avec un champ ou une entite precise (ex: vaccinations.status, animals.etat_sante, elevage.capacite).\n"
            f"Question originale: {query_text}"
        )

    return (
        "I could not find enough grounded evidence in the indexed corpus to answer this reliably.\n"
        "Please rephrase with a precise field or entity (for example: vaccinations.status, animals.etat_sante, elevage.capacite).\n"
        f"Original question: {query_text}"
    )


def build_evidence_grounded_answer(query_text: str, route_name: str, context_items: List[Dict[str, object]]) -> str:
    language = detect_query_language(query_text)
    if not context_items:
        return build_no_evidence_answer(query_text=query_text, language=language)

    top_item = context_items[0]
    top_confidence = str(top_item.get("confidence", "inferred"))
    top_source = str(top_item.get("source_file", "n/a"))
    top_section = str(top_item.get("section", "n/a"))

    lines = [route_intro(route_name=route_name, language=language)]

    table_row = extract_best_table_row(query_text=query_text, context_items=context_items)
    observed_samples = extract_observed_samples(context_items=context_items)

    if table_row is not None and table_row.get("business_meaning", ""):
        field_name = table_row.get("field", "field")
        field_meaning = table_row.get("business_meaning", "")
        observed_sample = table_row.get("observed_sample", "")
        candidate_values = table_row.get("candidate_values", "")

        if language == "fr":
            lines.append(f"- Le champ {field_name} correspond a: {field_meaning}.")
            if not is_placeholder_value(observed_sample):
                lines.append(f"- Valeur observee dans les preuves: {observed_sample}.")
            if not is_placeholder_value(candidate_values):
                lines.append(
                    "- D'autres valeurs peuvent exister en base: "
                    f"{candidate_values} (a confirmer selon vos donnees reelles)."
                )
        else:
            lines.append(f"- The field {field_name} means: {field_meaning}.")
            if not is_placeholder_value(observed_sample):
                lines.append(f"- Observed value in retrieved evidence: {observed_sample}.")
            if not is_placeholder_value(candidate_values):
                lines.append(
                    "- Additional values may exist in production data: "
                    f"{candidate_values} (confirm with your live records)."
                )
    elif observed_samples:
        sample = observed_samples[0]
        if language == "fr":
            lines.append(f"- Valeur observee dans les preuves: {sample}.")
        else:
            lines.append(f"- Observed value in retrieved evidence: {sample}.")
    else:
        snippet = best_evidence_snippet(context_items)
        if language == "fr":
            lines.append(f"- Evidence principale: {snippet}")
        else:
            lines.append(f"- Closest grounded evidence: {snippet}")

    if route_name == "policy_unknown":
        if language == "fr":
            lines.append("- Aucune politique officielle n'est confirmee dans les preuves disponibles.")
            lines.append("- Verification recommandee: confirmer cette regle avec le responsable metier.")
        else:
            lines.append("- No official policy statement is confirmed in the retrieved evidence.")
            lines.append("- Recommended validation: confirm this rule with the business owner.")

    if language == "fr":
        lines.append(f"Confiance: {top_confidence}.")
        lines.append(f"Source principale: {top_source} > {top_section}.")
    else:
        lines.append(f"Confidence: {top_confidence}.")
        lines.append(f"Primary source: {top_source} > {top_section}.")

    return "\n".join(lines)


def build_response_payload(
    query_text: str,
    route_name: str,
    answer_text: str,
    sources: List[Dict[str, object]],
    confidence_summary: Dict[str, object],
    evidence_summary: Dict[str, object],
    context_metadata: Dict[str, object],
    retrieval_debug: Dict[str, object],
    llm_mode: str,
    llm_enabled: bool,
    llm_implemented: bool,
) -> Dict[str, object]:
    return {
        "contract_version": "1.0.0",
        "query": query_text,
        "answer_text": answer_text,
        "route": route_name,
        "sources": sources,
        "confidence_summary": confidence_summary,
        "evidence_summary": evidence_summary,
        "context_metadata": context_metadata,
        "retrieval_debug": retrieval_debug,
        "llm": {
            "mode": llm_mode,
            "enabled": llm_enabled,
            "implemented": llm_implemented,
        },
    }


def main() -> int:
    args = parse_args()

    try:
        query_text = parse_query(args)
    except ValueError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1

    config_path = resolve_config_path(args.config)
    template_path = resolve_path(args.template)

    if not config_path.exists():
        print(f"ERROR: config file not found: {config_path}", file=sys.stderr)
        return 1
    if not template_path.exists():
        print(f"ERROR: template file not found: {template_path}", file=sys.stderr)
        return 1

    filters = build_metadata_filters(
        domain=args.domain,
        document_type=args.document_type,
        confidence=args.confidence,
        language=args.language,
        evidence_scope=args.evidence_scope,
    )

    try:
        with quiet_runtime_output(enabled=bool(args.json)):
            context_payload = build_context_payload(
                query_text=query_text,
                config_path=config_path,
                top_k=max(1, int(args.top_k)),
                min_score=float(args.min_score),
                filters=filters,
                route_override=args.route,
                disable_routing=bool(args.disable_routing),
                rerank_pool_size=int(args.rerank_pool_size),
            )

        route_name = str(context_payload.get("routing", {}).get("route", "field_definition"))
        context_items = list(context_payload.get("context_items", []))
        sources = build_sources(context_items)
        confidence_summary = build_confidence_summary(context_items)
        evidence_summary = build_evidence_summary(context_items, sources)

        template_text = template_path.read_text(encoding="utf-8")
        prompt_payload = build_prompt_payload(
            template_text=template_text,
            query_text=query_text,
            context_block=str(context_payload.get("context_block", "")),
        )

        llm_text = None
        if args.enable_llm:
            llm_text = llm_extension_point(
                messages=prompt_payload["messages"],
                query_text=query_text,
                route_name=route_name,
            )

        if llm_text:
            answer_text = llm_text
            llm_mode = "provider"
        else:
            answer_text = build_evidence_grounded_answer(query_text, route_name, context_items)
            llm_mode = "local_evidence_fallback"

        context_metadata = build_context_metadata(
            context_payload=context_payload,
            filters=filters,
            top_k=max(1, int(args.top_k)),
        )
        retrieval_debug = build_retrieval_debug(
            route_name=route_name,
            context_payload=context_payload,
            context_items=context_items,
        )

        payload = build_response_payload(
            query_text=query_text,
            route_name=route_name,
            answer_text=answer_text,
            sources=sources,
            confidence_summary=confidence_summary,
            evidence_summary=evidence_summary,
            context_metadata=context_metadata,
            retrieval_debug=retrieval_debug,
            llm_mode=llm_mode,
            llm_enabled=bool(args.enable_llm),
            llm_implemented=llm_text is not None,
        )

        if args.include_context_items:
            payload["context_items"] = context_items
            payload["context_block"] = context_payload.get("context_block", "")
        if args.include_prompt_payload:
            payload["prompt_payload"] = prompt_payload

        if args.output_file:
            output_path = resolve_path(args.output_file)
            output_path.parent.mkdir(parents=True, exist_ok=True)
            output_path.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
            if not args.json:
                print(f"Chat payload written: {output_path}")

        if args.json:
            print(json.dumps(payload, indent=2, ensure_ascii=False))
        else:
            print("Chat Engine")
            print("===========")
            print(f"Query: {query_text}")
            print(f"Route: {route_name}")
            print(f"Retrieved chunks: {context_metadata.get('retrieved_count', 0)}")
            print(f"Reranking applied: {retrieval_debug.get('reranking_applied', False)}")
            print("\nAnswer")
            print("------")
            print(answer_text)
            print("\nConfidence Summary")
            print("------------------")
            print(json.dumps(confidence_summary, indent=2, ensure_ascii=False))
            print("\nEvidence Summary")
            print("----------------")
            print(json.dumps(evidence_summary, indent=2, ensure_ascii=False))
            print("\nSources")
            print("-------")
            for idx, source in enumerate(sources, start=1):
                print(
                    f"[{idx}] {source.get('source_file')} | {source.get('section')} | "
                    f"doc_type={source.get('document_type')} | confidence={source.get('confidence')}"
                )

        return 0

    except (RuntimeError, FileNotFoundError, ValueError, KeyError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())

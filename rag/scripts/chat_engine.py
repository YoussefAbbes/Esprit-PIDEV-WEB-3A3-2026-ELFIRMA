#!/usr/bin/env python3
"""Local CLI chatbot backend engine (retrieval + prompt payload + answer stub)."""

from __future__ import annotations

import argparse
import io
import json
import os
import sys
from contextlib import contextmanager, redirect_stderr, redirect_stdout
from pathlib import Path
from typing import Dict, List, Optional

from build_context import build_context_payload, resolve_config_path
from query_router import ROUTE_NAMES
from retrieval_core import PROJECT_ROOT, active_filters, build_metadata_filters

DEFAULT_TEMPLATE_PATH = PROJECT_ROOT / "rag" / "prompts" / "rag_answer_template.md"
DEFAULT_CONFIG_PATH = PROJECT_ROOT / "rag" / "configs" / "embedding_config.json"


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
    """Future extension hook for provider-backed LLM generation.

    Keep this function local-only for now. Future integration should:
    - Accept `messages` as the provider request body.
    - Use `query_text` and `route_name` for telemetry and guardrails.
    - Return grounded answer text or None if provider fails.
    """
    del messages, query_text, route_name
    return None


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


def build_answer_stub(query_text: str, route_name: str, context_items: List[Dict[str, object]]) -> str:
    if not context_items:
        return (
            f"[STUB] Route '{route_name}': no evidence chunks retrieved for query '{query_text}'. "
            "Provide final answer only after retrieval evidence is available."
        )

    top = context_items[0]
    return (
        f"[STUB] Route '{route_name}': retrieved {len(context_items)} evidence chunks. "
        f"Top evidence comes from {top.get('source_file', 'n/a')} "
        f"({top.get('document_type', 'n/a')}). "
        "Plug a real LLM provider into llm_extension_point to generate the final grounded answer text."
    )


def build_response_payload(
    query_text: str,
    route_name: str,
    answer_text: str,
    sources: List[Dict[str, object]],
    confidence_summary: Dict[str, object],
    evidence_summary: Dict[str, object],
    context_metadata: Dict[str, object],
    retrieval_debug: Dict[str, object],
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
            "mode": "local_stub",
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

        answer_text = llm_text if llm_text else build_answer_stub(query_text, route_name, context_items)

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

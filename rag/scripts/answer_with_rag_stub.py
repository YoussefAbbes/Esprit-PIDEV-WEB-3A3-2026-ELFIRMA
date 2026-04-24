#!/usr/bin/env python3
"""Prepare a RAG answer payload (context + prompt + response contract).

This script does not call an external LLM API. It prepares the payload for future integration.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Dict

from build_context import build_context_payload, resolve_config_path
from retrieval_core import PROJECT_ROOT, build_metadata_filters
from query_router import ROUTE_NAMES

DEFAULT_TEMPLATE_PATH = PROJECT_ROOT / "rag" / "prompts" / "rag_answer_template.md"
DEFAULT_CONFIG_PATH = PROJECT_ROOT / "rag" / "configs" / "embedding_config.json"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Prepare RAG answer payload (stub)")
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
        help="Path to RAG answer template markdown",
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
    parser.add_argument("--json", action="store_true", help="Print full output payload as JSON")
    parser.add_argument(
        "--output-file",
        default="",
        help="Optional output JSON file path",
    )
    return parser.parse_args()


def resolve_path(path_str: str) -> Path:
    path = Path(path_str)
    if not path.is_absolute():
        path = PROJECT_ROOT / path
    return path


def detect_response_outcome(query_text: str, route_name: str) -> str:
    if route_name == "policy_unknown":
        return "unknown_policy"

    lower = query_text.lower()
    policy_terms = [
        "official",
        "mandatory",
        "policy",
        "threshold",
        "compliance",
        "regle officielle",
        "obligatoire",
        "norme",
    ]
    if any(term in lower for term in policy_terms):
        return "unknown_policy"
    return "standard"


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
            {
                "role": "system",
                "content": template_text,
            },
            {
                "role": "user",
                "content": (
                    f"User query:\n{query_text}\n\n"
                    f"Retrieved context:\n{context_block}"
                ),
            },
        ],
    }


def main() -> int:
    args = parse_args()
    if args.query_option and args.query:
        print("ERROR: provide query as positional text or --query, not both.", file=sys.stderr)
        return 1

    query_parts = args.query_option if args.query_option else args.query
    query_text = " ".join(query_parts).strip()
    if not query_text:
        print("ERROR: empty query.", file=sys.stderr)
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

        template_text = template_path.read_text(encoding="utf-8")
        prompt_payload = build_prompt_payload(
            template_text=template_text,
            query_text=query_text,
            context_block=str(context_payload["context_block"]),
        )

        response_contract = {
            "answer_text": "<to be generated by LLM>",
            "confidence": "confirmed|inferred|assumed",
            "route": route_name,
            "response_outcome": detect_response_outcome(query_text, route_name),
            "policy_note": "unknown_policy is a response outcome label, never a metadata confidence value.",
            "citations": [
                {
                    "chunk_id": item.get("chunk_id"),
                    "source_file": item.get("source_file"),
                    "section": item.get("section"),
                }
                for item in context_payload.get("context_items", [])
            ],
            "assumptions": [
                "Only retrieved corpus evidence should be used.",
                "If official policy is missing, response_outcome should be unknown_policy.",
            ],
        }

        payload = {
            "query": query_text,
            "route": route_name,
            "context": context_payload,
            "prompt_payload": prompt_payload,
            "response_contract_stub": response_contract,
        }

        if args.output_file:
            output_path = resolve_path(args.output_file)
            output_path.parent.mkdir(parents=True, exist_ok=True)
            output_path.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
            print(f"RAG payload written to: {output_path}")

        if args.json:
            print(json.dumps(payload, indent=2, ensure_ascii=False))
        else:
            print("RAG Answer Stub")
            print("===============")
            print(f"Query: {query_text}")
            print(f"Detected route: {route_name}")
            routing = context_payload.get("routing", {})
            print(
                "Preferred document_types: "
                f"{routing.get('preferred_document_types') if routing.get('preferred_document_types') else 'none'}"
            )
            print(
                "De-prioritized document_types: "
                f"{routing.get('deprioritized_document_types') if routing.get('deprioritized_document_types') else 'none'}"
            )
            print(f"Reranking applied: {routing.get('reranking_applied', False)}")
            print(f"Order changed: {routing.get('order_changed', False)}")
            print("\nAssembled Context")
            print("-----------------")
            print(context_payload["context_block"])
            print("\nPrompt Template (Preview)")
            print("-------------------------")
            print(prompt_payload["template_preview"])
            print("\nResponse Contract (Stub)")
            print("------------------------")
            print(json.dumps(response_contract, indent=2, ensure_ascii=False))

        return 0

    except (RuntimeError, FileNotFoundError, ValueError, KeyError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())

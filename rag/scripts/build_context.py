#!/usr/bin/env python3
"""Build retrieved context block for a user query using local vector index."""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Dict, List, Optional

from query_router import (
    ROUTE_NAMES,
    compute_rerank_pool_size,
    rerank_results_with_policy,
    resolve_route_policy,
)

from retrieval_core import (
    DEFAULT_CONFIG_PATH,
    PROJECT_ROOT,
    active_filters,
    build_metadata_filters,
    ensure_dependencies,
    load_index,
    load_json,
    rank_results,
)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Build context block from local vector index")
    parser.add_argument("query", nargs="*", help="User query (FR or EN) (positional)")
    parser.add_argument(
        "--query",
        dest="query_option",
        nargs="+",
        help="User query (FR or EN) (option form)",
    )
    parser.add_argument("--top-k", type=int, default=5, help="Top chunks to include")
    parser.add_argument("--min-score", type=float, default=-1.0, help="Minimum similarity score")
    parser.add_argument(
        "--config",
        default=str(DEFAULT_CONFIG_PATH),
        help="Path to embedding config JSON file",
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
        "--json",
        action="store_true",
        help="Print output payload as JSON instead of human-readable block",
    )
    parser.add_argument(
        "--output-file",
        default="",
        help="Optional JSON output file path",
    )
    return parser.parse_args()


def resolve_config_path(config_arg: str) -> Path:
    config_path = Path(config_arg)
    if not config_path.is_absolute():
        config_path = PROJECT_ROOT / config_path
    return config_path


def context_items_from_results(results: List[Dict[str, object]]) -> List[Dict[str, object]]:
    items: List[Dict[str, object]] = []
    for item in results:
        row = item["row"]
        items.append(
            {
                "rank": int(item["rank"]),
                "score": round(float(item["score"]), 6),
                "raw_score": round(float(item.get("raw_score", item["score"])), 6),
                "boost": round(float(item.get("boost", 0.0)), 6),
                "chunk_id": row.get("chunk_id"),
                "source_file": row.get("source_file"),
                "domain": row.get("domain"),
                "document_type": row.get("document_type"),
                "confidence": row.get("confidence"),
                "evidence_scope": row.get("evidence_scope"),
                "language": row.get("language"),
                "section": row.get("section"),
                "text": row.get("text"),
            }
        )
    return items


def render_context_block(context_items: List[Dict[str, object]]) -> str:
    if not context_items:
        return "[No retrieved context]"

    blocks: List[str] = []
    for item in context_items:
        block = (
            f"[Chunk {item['rank']}]\n"
            f"score: {item['score']:.6f}\n"
            f"raw_score: {item['raw_score']:.6f}\n"
            f"boost: {item['boost']:+.6f}\n"
            f"source_file: {item.get('source_file', 'n/a')}\n"
            f"domain: {item.get('domain', 'n/a')}\n"
            f"document_type: {item.get('document_type', 'n/a')}\n"
            f"confidence: {item.get('confidence', 'n/a')}\n"
            f"evidence_scope: {item.get('evidence_scope', 'n/a')}\n"
            f"language: {item.get('language', 'n/a')}\n"
            f"section: {item.get('section', 'n/a')}\n"
            f"text:\n{item.get('text', '')}"
        )
        blocks.append(block)
    return "\n\n".join(blocks)


def build_context_payload(
    query_text: str,
    config_path: Path,
    top_k: int,
    min_score: float,
    filters: Dict[str, set],
    route_override: Optional[str] = None,
    disable_routing: bool = False,
    rerank_pool_size: int = 0,
) -> Dict[str, object]:
    ensure_dependencies()

    config = load_json(config_path)
    matrix, metadata, index_meta = load_index(config)

    model_name = str(config["embedding_model"]["primary"])
    normalize_vectors = bool(config["embedding_runtime"].get("normalize_vectors", True))

    final_top_k = max(1, int(top_k))

    if disable_routing:
        pool_top_k = final_top_k
        results, candidate_count = rank_results(
            query=query_text,
            model_name=model_name,
            normalize_vectors=normalize_vectors,
            matrix=matrix,
            metadata=metadata,
            filters=filters,
            top_k=pool_top_k,
            min_score=min_score,
        )
        rerank_debug: Dict[str, object] = {
            "route": "routing_disabled",
            "preferred_document_types": [],
            "deprioritized_document_types": [],
            "pool_candidates": len(results),
            "returned": len(results),
            "adjustments_applied": 0,
            "order_changed": False,
            "reranking_applied": False,
        }
    else:
        policy = resolve_route_policy(query_text=query_text, route_override=route_override)
        pool_top_k = compute_rerank_pool_size(top_k=final_top_k, rerank_pool_size=int(rerank_pool_size))
        pool_results, candidate_count = rank_results(
            query=query_text,
            model_name=model_name,
            normalize_vectors=normalize_vectors,
            matrix=matrix,
            metadata=metadata,
            filters=filters,
            top_k=pool_top_k,
            min_score=min_score,
        )
        results, rerank_debug = rerank_results_with_policy(
            results=pool_results,
            policy=policy,
            top_k=final_top_k,
        )

    items = context_items_from_results(results)
    payload = {
        "query": query_text,
        "model": model_name,
        "index_vectors": int(index_meta.get("vector_count", len(metadata))),
        "candidate_rows": int(candidate_count),
        "retrieval_pool_top_k": int(pool_top_k),
        "retrieved_count": len(items),
        "top_k": int(final_top_k),
        "active_filters": active_filters(filters),
        "routing": rerank_debug,
        "context_items": items,
        "context_block": render_context_block(items),
    }
    return payload


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
    if not config_path.exists():
        print(f"ERROR: config file not found: {config_path}", file=sys.stderr)
        return 1

    filters = build_metadata_filters(
        domain=args.domain,
        document_type=args.document_type,
        confidence=args.confidence,
        language=args.language,
        evidence_scope=args.evidence_scope,
    )

    try:
        payload = build_context_payload(
            query_text=query_text,
            config_path=config_path,
            top_k=max(1, int(args.top_k)),
            min_score=float(args.min_score),
            filters=filters,
            route_override=args.route,
            disable_routing=bool(args.disable_routing),
            rerank_pool_size=int(args.rerank_pool_size),
        )

        if args.output_file:
            output_path = Path(args.output_file)
            if not output_path.is_absolute():
                output_path = PROJECT_ROOT / output_path
            output_path.parent.mkdir(parents=True, exist_ok=True)
            output_path.write_text(json.dumps(payload, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
            print(f"Context payload written to: {output_path}")

        if args.json:
            print(json.dumps(payload, indent=2, ensure_ascii=False))
        else:
            print("Context Build")
            print("=============")
            print(f"Query: {payload['query']}")
            print(f"Model: {payload['model']}")
            print(f"Active filters: {payload['active_filters'] if payload['active_filters'] else 'none'}")
            routing = payload.get("routing", {})
            print(f"Detected route: {routing.get('route', 'n/a')}")
            print(
                "Preferred document_types: "
                f"{routing.get('preferred_document_types') if routing.get('preferred_document_types') else 'none'}"
            )
            print(
                "De-prioritized document_types: "
                f"{routing.get('deprioritized_document_types') if routing.get('deprioritized_document_types') else 'none'}"
            )
            print(f"Rerank pool top_k: {payload.get('retrieval_pool_top_k', payload['top_k'])}")
            print(f"Reranking applied: {routing.get('reranking_applied', False)}")
            print(f"Order changed: {routing.get('order_changed', False)}")
            print(f"Adjustments applied: {routing.get('adjustments_applied', 0)}")
            print(
                f"Candidates: {payload['candidate_rows']} | Retrieved: {payload['retrieved_count']} | top_k={payload['top_k']}"
            )
            print("\nAssembled Context Block")
            print("-----------------------")
            print(payload["context_block"])

        return 0

    except (RuntimeError, FileNotFoundError, ValueError, KeyError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())

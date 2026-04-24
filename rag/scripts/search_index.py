#!/usr/bin/env python3
"""Search local vector index with a FR/EN query."""

from __future__ import annotations

import argparse
import sys
from pathlib import Path
from typing import List

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
    snippet,
)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Search local vector index")
    parser.add_argument("query", nargs="*", help="FR/EN user query (positional)")
    parser.add_argument(
        "--query",
        dest="query_option",
        nargs="+",
        help="FR/EN user query (option form)",
    )
    parser.add_argument("--top-k", type=int, default=5, help="Top results to return")
    parser.add_argument("--min-score", type=float, default=-1.0, help="Minimum similarity score")
    parser.add_argument(
        "--config",
        default=str(DEFAULT_CONFIG_PATH),
        help="Path to embedding config JSON file",
    )
    parser.add_argument(
        "--domain",
        action="append",
        help="Optional domain filter (repeat or comma-separated)",
    )
    parser.add_argument(
        "--document-type",
        action="append",
        help="Optional document_type filter (repeat or comma-separated)",
    )
    parser.add_argument(
        "--confidence",
        action="append",
        help="Optional confidence filter (repeat or comma-separated)",
    )
    parser.add_argument(
        "--language",
        action="append",
        help="Optional language filter (repeat or comma-separated)",
    )
    parser.add_argument(
        "--evidence-scope",
        action="append",
        help="Optional evidence_scope filter (repeat or comma-separated)",
    )
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
    return parser.parse_args()


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

    config_path = Path(args.config)
    if not config_path.is_absolute():
        config_path = PROJECT_ROOT / config_path

    if not config_path.exists():
        print(f"ERROR: config file not found: {config_path}", file=sys.stderr)
        return 1

    try:
        ensure_dependencies()

        config = load_json(config_path)
        matrix, metadata, index_meta = load_index(config)

        filters = build_metadata_filters(
            domain=args.domain,
            document_type=args.document_type,
            confidence=args.confidence,
            language=args.language,
            evidence_scope=args.evidence_scope,
        )
        current_filters = active_filters(filters)

        top_k = max(1, int(args.top_k))
        model_name = str(config["embedding_model"]["primary"])
        normalize_vectors = bool(config["embedding_runtime"].get("normalize_vectors", True))

        if args.disable_routing:
            pool_top_k = top_k
            results, candidate_count = rank_results(
                query=query_text,
                model_name=model_name,
                normalize_vectors=normalize_vectors,
                matrix=matrix,
                metadata=metadata,
                filters=filters,
                top_k=pool_top_k,
                min_score=float(args.min_score),
            )
            rerank_debug = {
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
            policy = resolve_route_policy(query_text=query_text, route_override=args.route)
            pool_top_k = compute_rerank_pool_size(top_k=top_k, rerank_pool_size=int(args.rerank_pool_size))
            pool_results, candidate_count = rank_results(
                query=query_text,
                model_name=model_name,
                normalize_vectors=normalize_vectors,
                matrix=matrix,
                metadata=metadata,
                filters=filters,
                top_k=pool_top_k,
                min_score=float(args.min_score),
            )
            results, rerank_debug = rerank_results_with_policy(
                results=pool_results,
                policy=policy,
                top_k=top_k,
            )

        print(f"Query: {query_text}")
        print(f"Model: {model_name}")
        print(f"Index vectors: {index_meta.get('vector_count', len(metadata))}")
        print(f"Candidate rows after filters: {candidate_count}")
        print(f"Active filters: {current_filters if current_filters else 'none'}")
        print(f"Detected route: {rerank_debug.get('route', 'n/a')}")
        preferred = rerank_debug.get("preferred_document_types", [])
        deprioritized = rerank_debug.get("deprioritized_document_types", [])
        print(f"Preferred document_types: {preferred if preferred else 'none'}")
        print(f"De-prioritized document_types: {deprioritized if deprioritized else 'none'}")
        print(f"Rerank pool top_k: {pool_top_k}")
        print(f"Reranking applied: {rerank_debug.get('reranking_applied', False)}")
        print(f"Order changed: {rerank_debug.get('order_changed', False)}")
        print(f"Adjustments applied: {rerank_debug.get('adjustments_applied', 0)}")
        print("Results:")

        shown = len(results)
        for item in results:
            rank = int(item["rank"])
            score = float(item["score"])
            raw_score = float(item.get("raw_score", score))
            boost = float(item.get("boost", 0.0))
            row = item["row"]

            print(
                f"[{rank}] score={score:.4f} raw_score={raw_score:.4f} boost={boost:+.4f} "
                f"source_file={row.get('source_file', 'n/a')} "
                f"domain={row.get('domain', 'n/a')} "
                f"document_type={row.get('document_type', 'n/a')} "
                f"confidence={row.get('confidence', 'n/a')} "
                f"language={row.get('language', 'n/a')} "
                f"evidence_scope={row.get('evidence_scope', 'n/a')}"
            )
            print(f"    section={row.get('section', 'n/a')}")
            print(f"    snippet={snippet(str(row.get('text', '')))}")

        if shown == 0:
            if candidate_count == 0:
                print("No candidates matched the active filters.")
            else:
                print("No results above min-score threshold.")

        return 0

    except (RuntimeError, FileNotFoundError, ValueError, KeyError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())

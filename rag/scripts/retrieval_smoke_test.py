#!/usr/bin/env python3
"""Run a basic retrieval smoke test using rag/tests/sample_queries.json."""

from __future__ import annotations

import argparse
import sys
from pathlib import Path
from typing import Dict, List, Optional, Set

from retrieval_core import (
    DEFAULT_CONFIG_PATH,
    POLICY_DOC_TYPES,
    PROJECT_ROOT,
    ensure_dependencies,
    load_index,
    load_json,
    rank_results,
)

DEFAULT_QUERY_PATH = PROJECT_ROOT / "rag" / "tests" / "sample_queries.json"


def flatten_queries(payload: Dict[str, object]) -> List[Dict[str, object]]:
    groups = payload.get("confidence_groups", {})
    if not isinstance(groups, dict):
        raise ValueError("sample_queries.json has invalid confidence_groups structure.")

    items: List[Dict[str, object]] = []
    for group_name, group_queries in groups.items():
        if not isinstance(group_queries, list):
            continue
        for query in group_queries:
            if isinstance(query, dict):
                enriched = dict(query)
                enriched["query_class"] = group_name
                items.append(enriched)
    return items


def confidence_pref(query_class: str) -> List[str]:
    mapping = {
        "confirmed": ["confirmed"],
        "inferred": ["inferred", "confirmed"],
        # unknown_policy is a query class, not confidence metadata.
        "unknown_policy": ["assumed", "inferred", "confirmed"],
    }
    return mapping.get(query_class, ["confirmed", "inferred", "assumed"])


def expected_document_types(query_class: str, intent: str) -> Set[str]:
    normalized_intent = intent.lower()

    if query_class == "unknown_policy":
        return {"assumptions_and_gaps", "business_rules", "faq"}
    if "relationship_explanation" in normalized_intent:
        return {"schema_reference", "data_dictionary", "assumptions_and_gaps"}
    if "field_explanation" in normalized_intent:
        return {"schema_reference", "data_dictionary", "faq", "glossary", "domain_guide"}
    if "health_status_difference" in normalized_intent:
        return {"data_dictionary", "domain_guide", "faq"}
    if "schedule_interpretation" in normalized_intent:
        return {"domain_guide", "data_dictionary", "faq", "business_rules"}
    if "capacity_context" in normalized_intent:
        return {"domain_guide", "data_dictionary", "business_rules"}
    if "geolocation_context" in normalized_intent:
        return {"domain_guide", "data_dictionary", "faq"}
    if "multilingual_mapping" in normalized_intent:
        return {"glossary", "data_dictionary", "faq"}
    return {"schema_reference", "data_dictionary", "faq", "domain_guide"}


def bool_to_mark(value: Optional[bool]) -> str:
    if value is None:
        return "n/a"
    return "yes" if value else "no"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Run retrieval smoke test")
    parser.add_argument(
        "--config",
        default=str(DEFAULT_CONFIG_PATH),
        help="Path to embedding config JSON file",
    )
    parser.add_argument(
        "--queries",
        default=str(DEFAULT_QUERY_PATH),
        help="Path to sample queries JSON file",
    )
    parser.add_argument("--top-k", type=int, default=5, help="Top-K retrieval depth")
    parser.add_argument("--max-queries", type=int, default=0, help="Optional cap for quick runs")
    return parser.parse_args()


def main() -> int:
    args = parse_args()

    config_path = Path(args.config)
    if not config_path.is_absolute():
        config_path = PROJECT_ROOT / config_path

    queries_path = Path(args.queries)
    if not queries_path.is_absolute():
        queries_path = PROJECT_ROOT / queries_path

    if not config_path.exists():
        print(f"ERROR: config not found: {config_path}", file=sys.stderr)
        return 1
    if not queries_path.exists():
        print(f"ERROR: query file not found: {queries_path}", file=sys.stderr)
        return 1

    try:
        ensure_dependencies()

        config = load_json(config_path)
        query_payload = load_json(queries_path)
        queries = flatten_queries(query_payload)
        if args.max_queries > 0:
            queries = queries[: args.max_queries]

        matrix, metadata, _index_meta = load_index(config)
        model_name = str(config["embedding_model"]["primary"])
        normalize_vectors = bool(config["embedding_runtime"].get("normalize_vectors", True))
        top_k = max(1, min(int(args.top_k), len(metadata)))

        total = len(queries)
        if total == 0:
            print("ERROR: no queries available for smoke test.", file=sys.stderr)
            return 1

        top1_domain_hits = 0
        topk_domain_hits = 0
        top1_doc_type_hits = 0
        topk_doc_type_hits = 0
        confidence_pref_hits = 0
        unknown_policy_route_hits = 0

        print("Retrieval Smoke Test")
        print("====================")
        print(f"queries: {total}")
        print(f"top_k: {top_k}")

        for item in queries:
            query_id = str(item.get("id", "n/a"))
            query_text = str(item.get("query", "")).strip()
            query_domain = str(item.get("domain", ""))
            query_intent = str(item.get("intent", ""))
            query_class = str(item.get("query_class", ""))
            if not query_text:
                continue

            results, candidate_count = rank_results(
                query=query_text,
                model_name=model_name,
                normalize_vectors=normalize_vectors,
                matrix=matrix,
                metadata=metadata,
                filters=None,
                top_k=top_k,
                min_score=-1.0,
            )

            rows = [item_result["row"] for item_result in results]
            top1 = rows[0] if rows else {}
            top1_score = float(results[0]["score"]) if results else float("nan")

            top1_domain_hit = bool(rows and str(top1.get("domain", "")) == query_domain)
            topk_domain_hit = any(str(row.get("domain", "")) == query_domain for row in rows)
            if top1_domain_hit:
                top1_domain_hits += 1
            if topk_domain_hit:
                topk_domain_hits += 1

            expected_doc_types = expected_document_types(query_class, query_intent)
            top1_doc_type_hit = bool(rows and str(top1.get("document_type", "")) in expected_doc_types)
            topk_doc_type_hit = any(str(row.get("document_type", "")) in expected_doc_types for row in rows)
            if top1_doc_type_hit:
                top1_doc_type_hits += 1
            if topk_doc_type_hit:
                topk_doc_type_hits += 1

            top_conf = str(top1.get("confidence", "")) if rows else ""
            conf_hit = top_conf in confidence_pref(query_class)
            if conf_hit:
                confidence_pref_hits += 1

            unknown_policy_hit: Optional[bool] = None
            if query_class == "unknown_policy":
                unknown_policy_hit = any(
                    str(row.get("document_type", "")) in POLICY_DOC_TYPES for row in rows
                )
                if unknown_policy_hit:
                    unknown_policy_route_hits += 1

            print(
                f"- {query_id} class={query_class} domain={query_domain} "
                f"candidates={candidate_count} top1_score={top1_score:.4f}"
            )
            print(
                f"  top1 source={top1.get('source_file', 'n/a')} "
                f"doc_type={top1.get('document_type', 'n/a')} "
                f"confidence={top1.get('confidence', 'n/a')}"
            )
            print(
                "  metrics "
                f"top1_domain_hit={bool_to_mark(top1_domain_hit)} "
                f"topk_domain_hit={bool_to_mark(topk_domain_hit)} "
                f"top1_document_type_hit={bool_to_mark(top1_doc_type_hit)} "
                f"topk_document_type_hit={bool_to_mark(topk_doc_type_hit)} "
                f"confidence_preference_hit={bool_to_mark(conf_hit)} "
                f"unknown_policy_routing_hit={bool_to_mark(unknown_policy_hit)}"
            )

        print("\nSummary")
        print("-------")
        print(
            f"top1_domain_hit_rate: {top1_domain_hits}/{total} "
            f"= {top1_domain_hits / total:.2%}"
        )
        print(
            f"topk_domain_hit_rate: {topk_domain_hits}/{total} "
            f"= {topk_domain_hits / total:.2%}"
        )
        print(
            f"top1_document_type_hit_rate: {top1_doc_type_hits}/{total} "
            f"= {top1_doc_type_hits / total:.2%}"
        )
        print(
            f"topk_document_type_hit_rate: {topk_doc_type_hits}/{total} "
            f"= {topk_doc_type_hits / total:.2%}"
        )
        print(
            f"confidence_pref_hit_rate: {confidence_pref_hits}/{total} "
            f"= {confidence_pref_hits / total:.2%}"
        )

        unknown_policy_total = sum(1 for q in queries if str(q.get("query_class", "")) == "unknown_policy")
        if unknown_policy_total > 0:
            print(
                f"unknown_policy_routing_hit_rate: {unknown_policy_route_hits}/{unknown_policy_total} "
                f"= {unknown_policy_route_hits / unknown_policy_total:.2%}"
            )

        return 0

    except (RuntimeError, FileNotFoundError, ValueError, KeyError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())

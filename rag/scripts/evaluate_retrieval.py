#!/usr/bin/env python3
"""Evaluate retrieval quality with route-aware relevance and regression reporting."""

from __future__ import annotations

import argparse
import json
import sys
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path
from typing import Dict, List, Optional, Set, Tuple

from query_router import (
    compute_rerank_pool_size,
    rerank_results_with_policy,
    resolve_route_policy,
)
from retrieval_core import (
    DEFAULT_CONFIG_PATH,
    POLICY_DOC_TYPES,
    PROJECT_ROOT,
    ensure_dependencies,
    load_index,
    load_json,
    rank_results,
)

DEFAULT_CASES_PATH = PROJECT_ROOT / "rag" / "tests" / "retrieval_eval_cases.json"
DEFAULT_REPORT_PATH = PROJECT_ROOT / "rag" / "outputs" / "reports" / "route_relevance_regression.json"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Evaluate retrieval quality against test cases")
    parser.add_argument(
        "--config",
        default=str(DEFAULT_CONFIG_PATH),
        help="Path to embedding config JSON file",
    )
    parser.add_argument(
        "--cases",
        default=str(DEFAULT_CASES_PATH),
        help="Path to retrieval evaluation cases JSON file",
    )
    parser.add_argument("--top-k", type=int, default=5, help="Top-K retrieval depth")
    parser.add_argument(
        "--rerank-pool-size",
        type=int,
        default=0,
        help="Optional initial retrieval depth before route reranking",
    )
    parser.add_argument("--min-score", type=float, default=-1.0, help="Minimum similarity score")
    parser.add_argument("--max-cases", type=int, default=0, help="Optional cap for quick runs")
    parser.add_argument(
        "--report-json",
        action="store_true",
        help="Write machine-readable route regression report JSON",
    )
    parser.add_argument(
        "--report-file",
        default="",
        help="Optional route regression report output path",
    )
    return parser.parse_args()


def as_set(values: object) -> Set[str]:
    if not isinstance(values, list):
        return set()
    return {str(v) for v in values if str(v).strip()}


def bool_mark(value: Optional[bool]) -> str:
    if value is None:
        return "n/a"
    return "yes" if value else "no"


def safe_rate(hit: int, total: int) -> str:
    if total <= 0:
        return "n/a"
    return f"{hit}/{total} = {hit / total:.2%}"


def rate_value(hit: int, total: int) -> Optional[float]:
    if total <= 0:
        return None
    return round(hit / total, 4)


def resolve_path(path_str: str) -> Path:
    path = Path(path_str)
    if not path.is_absolute():
        path = PROJECT_ROOT / path
    return path


def top_counter_entries(counter: Counter[str], max_items: int = 8) -> List[Dict[str, object]]:
    return [{"name": key, "count": value} for key, value in counter.most_common(max_items)]


def build_preference_metrics(
    rows: List[Dict[str, object]],
    preferred_doc_types: Set[str],
    deprioritized_doc_types: Set[str],
) -> Dict[str, object]:
    doc_types = [str(row.get("document_type", "")) for row in rows]
    top1_doc_type = doc_types[0] if doc_types else ""

    preferred_ranks = [idx + 1 for idx, doc_type in enumerate(doc_types) if doc_type in preferred_doc_types]
    deprioritized_ranks = [
        idx + 1 for idx, doc_type in enumerate(doc_types) if doc_type in deprioritized_doc_types
    ]

    top1_preferred_hit = bool(top1_doc_type and top1_doc_type in preferred_doc_types)
    topk_preferred_hit = bool(preferred_ranks)
    top1_deprioritized_hit = bool(top1_doc_type and top1_doc_type in deprioritized_doc_types)
    topk_deprioritized_present = bool(deprioritized_ranks)

    if not deprioritized_ranks:
        deprioritized_pushed_down = True
    elif preferred_ranks:
        deprioritized_pushed_down = min(deprioritized_ranks) > min(preferred_ranks)
    else:
        deprioritized_pushed_down = False

    return {
        "top1_preferred_hit": top1_preferred_hit,
        "topk_preferred_hit": topk_preferred_hit,
        "top1_deprioritized_hit": top1_deprioritized_hit,
        "topk_deprioritized_present": topk_deprioritized_present,
        "deprioritized_pushed_down": deprioritized_pushed_down,
        "first_preferred_rank": min(preferred_ranks) if preferred_ranks else None,
        "first_deprioritized_rank": min(deprioritized_ranks) if deprioritized_ranks else None,
    }


def build_route_quality_summary(
    buckets: Dict[str, Dict[str, int]],
) -> Tuple[List[Dict[str, object]], List[Dict[str, object]], List[Dict[str, object]]]:
    summary_rows: List[Dict[str, object]] = []

    for route_name, bucket in buckets.items():
        total = int(bucket["total"])
        topk_preferred_rate = rate_value(int(bucket["topk_preferred_hits"]), total)
        pushed_down_rate = rate_value(int(bucket["deprioritized_pushed_down_hits"]), total)
        top1_deprioritized_leak_rate = rate_value(int(bucket["top1_deprioritized_leaks"]), total)
        route_expected_match_rate = rate_value(
            int(bucket["expected_route_hits"]),
            int(bucket["expected_route_total"]),
        )
        expected_document_type_topk_hit_rate = rate_value(
            int(bucket["expected_doc_type_topk_hits"]),
            int(bucket["expected_doc_type_total"]),
        )

        avoid_deprioritized_top1_rate = None
        if top1_deprioritized_leak_rate is not None:
            avoid_deprioritized_top1_rate = round(1.0 - top1_deprioritized_leak_rate, 4)

        quality_components = [
            value
            for value in [
                topk_preferred_rate,
                pushed_down_rate,
                avoid_deprioritized_top1_rate,
                route_expected_match_rate,
                expected_document_type_topk_hit_rate,
            ]
            if value is not None
        ]
        quality_score = round(sum(quality_components) / len(quality_components), 4) if quality_components else None

        row = {
            "route": route_name,
            "cases": total,
            "topk_preferred_doc_type_rate": topk_preferred_rate,
            "deprioritized_pushed_down_rate": pushed_down_rate,
            "avoid_deprioritized_top1_rate": avoid_deprioritized_top1_rate,
            "route_quality_score": quality_score,
            "expected_route_match_rate": route_expected_match_rate,
            "expected_domain_topk_hit_rate": rate_value(
                int(bucket["expected_domain_topk_hits"]),
                int(bucket["expected_domain_total"]),
            ),
            "expected_document_type_topk_hit_rate": expected_document_type_topk_hit_rate,
        }
        summary_rows.append(row)

    ranked_rows = sorted(
        summary_rows,
        key=lambda item: (
            -1.0 if item["route_quality_score"] is None else -float(item["route_quality_score"]),
            -int(item["cases"]),
            str(item["route"]),
        ),
    )

    rows_with_score = [row for row in ranked_rows if row["route_quality_score"] is not None]
    best_routes: List[Dict[str, object]] = []
    worst_routes: List[Dict[str, object]] = []
    if rows_with_score:
        best_score = rows_with_score[0]["route_quality_score"]
        worst_score = rows_with_score[-1]["route_quality_score"]
        best_routes = [
            row for row in rows_with_score if row["route_quality_score"] == best_score
        ][:2]
        worst_routes = [
            row for row in reversed(rows_with_score) if row["route_quality_score"] == worst_score
        ][:2]
    return ranked_rows, best_routes, worst_routes


def main() -> int:
    args = parse_args()

    config_path = resolve_path(args.config)
    cases_path = resolve_path(args.cases)

    if not config_path.exists():
        print(f"ERROR: config file not found: {config_path}", file=sys.stderr)
        return 1
    if not cases_path.exists():
        print(f"ERROR: cases file not found: {cases_path}", file=sys.stderr)
        return 1

    try:
        ensure_dependencies()

        config = load_json(config_path)
        payload = load_json(cases_path)
        cases = payload.get("cases", [])
        if not isinstance(cases, list):
            print("ERROR: retrieval eval cases file has invalid 'cases' structure.", file=sys.stderr)
            return 1

        if args.max_cases > 0:
            cases = cases[: args.max_cases]

        matrix, metadata, _index_meta = load_index(config)
        model_name = str(config["embedding_model"]["primary"])
        normalize_vectors = bool(config["embedding_runtime"].get("normalize_vectors", True))
        top_k = max(1, min(int(args.top_k), len(metadata)))
        rerank_pool_size = compute_rerank_pool_size(top_k, int(args.rerank_pool_size))

        total = len(cases)
        if total == 0:
            print("ERROR: no retrieval cases to evaluate.", file=sys.stderr)
            return 1

        processed = 0
        hits = {
            "top1_domain": 0,
            "topk_domain": 0,
            "top1_document_type": 0,
            "topk_document_type": 0,
            "confidence_preference": 0,
            "unknown_policy_routing": 0,
            "route_expected_match": 0,
            "top1_preferred_document_type": 0,
            "topk_preferred_document_type": 0,
            "deprioritized_pushed_down": 0,
            "top1_deprioritized_avoidance": 0,
        }
        totals = {
            "top1_domain": 0,
            "topk_domain": 0,
            "top1_document_type": 0,
            "topk_document_type": 0,
            "confidence_preference": 0,
            "unknown_policy_routing": 0,
            "route_expected_match": 0,
            "top1_preferred_document_type": 0,
            "topk_preferred_document_type": 0,
            "deprioritized_pushed_down": 0,
            "top1_deprioritized_avoidance": 0,
        }

        error_categories = {
            "no_results": 0,
            "domain_miss_topk": 0,
            "document_type_miss_topk": 0,
            "confidence_preference_miss": 0,
            "unknown_policy_route_miss": 0,
            "route_detection_miss": 0,
            "preferred_doc_type_miss_topk": 0,
            "deprioritized_not_pushed_down": 0,
            "deprioritized_leaked_top1": 0,
        }

        route_distribution: Counter[str] = Counter()
        route_buckets: Dict[str, Dict[str, int]] = defaultdict(
            lambda: {
                "total": 0,
                "topk_preferred_hits": 0,
                "deprioritized_pushed_down_hits": 0,
                "top1_deprioritized_leaks": 0,
                "expected_route_total": 0,
                "expected_route_hits": 0,
                "expected_domain_total": 0,
                "expected_domain_topk_hits": 0,
                "expected_doc_type_total": 0,
                "expected_doc_type_topk_hits": 0,
            }
        )

        expected_doc_type_misses: Counter[str] = Counter()
        route_preferred_type_misses: Counter[str] = Counter()
        reranking_misses: Counter[str] = Counter()
        case_results: List[Dict[str, object]] = []

        print("Retrieval Evaluation")
        print("====================")
        print(f"cases: {total}")
        print(f"top_k: {top_k}")
        print(f"rerank_pool_size: {rerank_pool_size}")

        for case in cases:
            if not isinstance(case, dict):
                continue

            case_id = str(case.get("id", "n/a"))
            query = str(case.get("query", "")).strip()
            query_class = str(case.get("query_class", "")).strip()
            expected_route = str(case.get("expected_route", "")).strip()
            expected_domains = as_set(case.get("expected_domains"))
            expected_doc_types = as_set(case.get("expected_document_types"))
            expected_conf_pref = as_set(case.get("expected_confidence_preference"))

            if not query:
                print(f"- {case_id} skipped: empty query")
                continue

            processed += 1

            route_policy = resolve_route_policy(query)
            detected_route = route_policy.route
            route_distribution[detected_route] += 1

            preferred_doc_types = set(route_policy.preferred_document_types)
            deprioritized_doc_types = set(route_policy.deprioritized_document_types)

            pool_results, candidate_count = rank_results(
                query=query,
                model_name=model_name,
                normalize_vectors=normalize_vectors,
                matrix=matrix,
                metadata=metadata,
                filters=None,
                top_k=rerank_pool_size,
                min_score=float(args.min_score),
            )

            results, rerank_debug = rerank_results_with_policy(
                results=pool_results,
                policy=route_policy,
                top_k=top_k,
            )

            rows = [r["row"] for r in results]
            top1 = rows[0] if rows else {}
            top1_score = float(results[0]["score"]) if results else float("nan")

            top1_domain_hit: Optional[bool] = None
            topk_domain_hit: Optional[bool] = None
            if expected_domains:
                totals["top1_domain"] += 1
                totals["topk_domain"] += 1
                top1_domain_hit = bool(rows and str(top1.get("domain", "")) in expected_domains)
                topk_domain_hit = any(str(row.get("domain", "")) in expected_domains for row in rows)

            top1_doc_hit: Optional[bool] = None
            topk_doc_hit: Optional[bool] = None
            if expected_doc_types:
                totals["top1_document_type"] += 1
                totals["topk_document_type"] += 1
                top1_doc_hit = bool(rows and str(top1.get("document_type", "")) in expected_doc_types)
                topk_doc_hit = any(str(row.get("document_type", "")) in expected_doc_types for row in rows)

            conf_hit: Optional[bool] = None
            if expected_conf_pref:
                totals["confidence_preference"] += 1
                conf_hit = bool(rows and str(top1.get("confidence", "")) in expected_conf_pref)

            unknown_policy_hit: Optional[bool] = None
            if query_class == "unknown_policy":
                totals["unknown_policy_routing"] += 1
                unknown_policy_hit = any(
                    str(row.get("document_type", "")) in POLICY_DOC_TYPES for row in rows
                )

            route_expected_hit: Optional[bool] = None
            if expected_route:
                totals["route_expected_match"] += 1
                route_expected_hit = detected_route == expected_route

            preference_metrics = build_preference_metrics(
                rows=rows,
                preferred_doc_types=preferred_doc_types,
                deprioritized_doc_types=deprioritized_doc_types,
            )

            totals["top1_preferred_document_type"] += 1
            totals["topk_preferred_document_type"] += 1
            totals["deprioritized_pushed_down"] += 1
            totals["top1_deprioritized_avoidance"] += 1

            if top1_domain_hit is True:
                hits["top1_domain"] += 1
            if topk_domain_hit is True:
                hits["topk_domain"] += 1
            if top1_doc_hit is True:
                hits["top1_document_type"] += 1
            if topk_doc_hit is True:
                hits["topk_document_type"] += 1
            if conf_hit is True:
                hits["confidence_preference"] += 1
            if unknown_policy_hit is True:
                hits["unknown_policy_routing"] += 1
            if route_expected_hit is True:
                hits["route_expected_match"] += 1
            if preference_metrics["top1_preferred_hit"]:
                hits["top1_preferred_document_type"] += 1
            if preference_metrics["topk_preferred_hit"]:
                hits["topk_preferred_document_type"] += 1
            if preference_metrics["deprioritized_pushed_down"]:
                hits["deprioritized_pushed_down"] += 1
            if not preference_metrics["top1_deprioritized_hit"]:
                hits["top1_deprioritized_avoidance"] += 1

            bucket = route_buckets[detected_route]
            bucket["total"] += 1
            if preference_metrics["topk_preferred_hit"]:
                bucket["topk_preferred_hits"] += 1
            if preference_metrics["deprioritized_pushed_down"]:
                bucket["deprioritized_pushed_down_hits"] += 1
            if preference_metrics["top1_deprioritized_hit"]:
                bucket["top1_deprioritized_leaks"] += 1
            if expected_route:
                bucket["expected_route_total"] += 1
                if route_expected_hit:
                    bucket["expected_route_hits"] += 1
            if expected_domains:
                bucket["expected_domain_total"] += 1
                if topk_domain_hit:
                    bucket["expected_domain_topk_hits"] += 1
            if expected_doc_types:
                bucket["expected_doc_type_total"] += 1
                if topk_doc_hit:
                    bucket["expected_doc_type_topk_hits"] += 1

            if not rows:
                error_categories["no_results"] += 1
            if rows and expected_domains and topk_domain_hit is False:
                error_categories["domain_miss_topk"] += 1
            if rows and expected_doc_types and topk_doc_hit is False:
                error_categories["document_type_miss_topk"] += 1
                for doc_type in expected_doc_types:
                    expected_doc_type_misses[doc_type] += 1
            if rows and expected_conf_pref and conf_hit is False:
                error_categories["confidence_preference_miss"] += 1
            if query_class == "unknown_policy" and unknown_policy_hit is False:
                error_categories["unknown_policy_route_miss"] += 1
            if expected_route and route_expected_hit is False:
                error_categories["route_detection_miss"] += 1
            if rows and not preference_metrics["topk_preferred_hit"]:
                error_categories["preferred_doc_type_miss_topk"] += 1
                for doc_type in preferred_doc_types:
                    route_preferred_type_misses[doc_type] += 1
                reranking_misses["missing_preferred_doc_type_in_topk"] += 1
            if rows and preference_metrics["deprioritized_pushed_down"] is False:
                error_categories["deprioritized_not_pushed_down"] += 1
                reranking_misses["deprioritized_not_pushed_down"] += 1
            if rows and preference_metrics["top1_deprioritized_hit"]:
                error_categories["deprioritized_leaked_top1"] += 1
                top1_doc_type = str(top1.get("document_type", "unknown"))
                reranking_misses[f"top1_deprioritized::{top1_doc_type}"] += 1

            top_results = []
            for rank, item in enumerate(results[:3], start=1):
                row = item["row"]
                top_results.append(
                    {
                        "rank": rank,
                        "source_file": str(row.get("source_file", "n/a")),
                        "section": str(row.get("section", "n/a")),
                        "document_type": str(row.get("document_type", "n/a")),
                        "domain": str(row.get("domain", "n/a")),
                        "confidence": str(row.get("confidence", "n/a")),
                        "score": round(float(item.get("score", 0.0)), 4),
                        "raw_score": round(float(item.get("raw_score", item.get("score", 0.0))), 4),
                        "boost": round(float(item.get("route_boost", 0.0)), 4),
                    }
                )

            case_results.append(
                {
                    "id": case_id,
                    "query": query,
                    "query_class": query_class,
                    "expected_route": expected_route,
                    "detected_route": detected_route,
                    "preferred_document_types": sorted(preferred_doc_types),
                    "deprioritized_document_types": sorted(deprioritized_doc_types),
                    "metrics": {
                        "top1_domain_hit": top1_domain_hit,
                        "topk_domain_hit": topk_domain_hit,
                        "top1_document_type_hit": top1_doc_hit,
                        "topk_document_type_hit": topk_doc_hit,
                        "confidence_preference_hit": conf_hit,
                        "unknown_policy_routing_hit": unknown_policy_hit,
                        "route_expected_match": route_expected_hit,
                        "top1_preferred_doc_type_hit": preference_metrics["top1_preferred_hit"],
                        "topk_preferred_doc_type_hit": preference_metrics["topk_preferred_hit"],
                        "deprioritized_pushed_down": preference_metrics["deprioritized_pushed_down"],
                        "top1_deprioritized_hit": preference_metrics["top1_deprioritized_hit"],
                    },
                    "retrieval_debug": {
                        "candidate_count": candidate_count,
                        "rerank_pool_size": rerank_pool_size,
                        "reranking_applied": bool(rerank_debug.get("reranking_applied", False)),
                        "order_changed": bool(rerank_debug.get("order_changed", False)),
                        "adjustments_applied": int(rerank_debug.get("adjustments_applied", 0)),
                    },
                    "top_results": top_results,
                }
            )

            print(
                f"- {case_id} class={query_class or 'n/a'} route={detected_route} "
                f"candidates={candidate_count} top1_score={top1_score:.4f}"
            )
            print(
                f"  top1 source={top1.get('source_file', 'n/a')} "
                f"domain={top1.get('domain', 'n/a')} "
                f"document_type={top1.get('document_type', 'n/a')} "
                f"confidence={top1.get('confidence', 'n/a')}"
            )
            print(
                "  route_policy "
                f"preferred={sorted(preferred_doc_types)} "
                f"deprioritized={sorted(deprioritized_doc_types)} "
                f"reranking_applied={bool_mark(bool(rerank_debug.get('reranking_applied', False)))} "
                f"order_changed={bool_mark(bool(rerank_debug.get('order_changed', False)))}"
            )
            print(
                "  metrics "
                f"top1_domain_hit={bool_mark(top1_domain_hit)} "
                f"topk_domain_hit={bool_mark(topk_domain_hit)} "
                f"top1_document_type_hit={bool_mark(top1_doc_hit)} "
                f"topk_document_type_hit={bool_mark(topk_doc_hit)} "
                f"confidence_preference_hit={bool_mark(conf_hit)} "
                f"unknown_policy_routing_hit={bool_mark(unknown_policy_hit)} "
                f"route_expected_match={bool_mark(route_expected_hit)}"
            )
            print(
                "  route_metrics "
                f"top1_preferred_hit={bool_mark(bool(preference_metrics['top1_preferred_hit']))} "
                f"topk_preferred_hit={bool_mark(bool(preference_metrics['topk_preferred_hit']))} "
                f"deprioritized_pushed_down={bool_mark(bool(preference_metrics['deprioritized_pushed_down']))} "
                f"top1_deprioritized_hit={bool_mark(bool(preference_metrics['top1_deprioritized_hit']))}"
            )

        if processed == 0:
            print("ERROR: no non-empty queries found in cases file.", file=sys.stderr)
            return 1

        route_quality_summary, best_routes, worst_routes = build_route_quality_summary(route_buckets)

        print("\nAggregate Hit Rates")
        print("-------------------")
        print(f"top1_domain_hit_rate: {safe_rate(hits['top1_domain'], totals['top1_domain'])}")
        print(f"topk_domain_hit_rate: {safe_rate(hits['topk_domain'], totals['topk_domain'])}")
        print(
            f"top1_document_type_hit_rate: "
            f"{safe_rate(hits['top1_document_type'], totals['top1_document_type'])}"
        )
        print(
            f"topk_document_type_hit_rate: "
            f"{safe_rate(hits['topk_document_type'], totals['topk_document_type'])}"
        )
        print(
            f"confidence_preference_hit_rate: "
            f"{safe_rate(hits['confidence_preference'], totals['confidence_preference'])}"
        )
        print(
            "unknown_policy_routing_hit_rate: "
            f"{safe_rate(hits['unknown_policy_routing'], totals['unknown_policy_routing'])}"
        )
        print(
            "route_expected_match_rate: "
            f"{safe_rate(hits['route_expected_match'], totals['route_expected_match'])}"
        )
        print(
            "top1_preferred_doc_type_hit_rate: "
            f"{safe_rate(hits['top1_preferred_document_type'], totals['top1_preferred_document_type'])}"
        )
        print(
            "topk_preferred_doc_type_hit_rate: "
            f"{safe_rate(hits['topk_preferred_document_type'], totals['topk_preferred_document_type'])}"
        )
        print(
            "deprioritized_pushed_down_rate: "
            f"{safe_rate(hits['deprioritized_pushed_down'], totals['deprioritized_pushed_down'])}"
        )
        print(
            "top1_deprioritized_avoidance_rate: "
            f"{safe_rate(hits['top1_deprioritized_avoidance'], totals['top1_deprioritized_avoidance'])}"
        )

        print("\nRoute Distribution")
        print("------------------")
        for route_name, count in sorted(route_distribution.items(), key=lambda item: (-item[1], item[0])):
            print(f"{route_name}: {count}")

        print("\nRoute Quality Summary")
        print("---------------------")
        for row in route_quality_summary:
            print(
                f"{row['route']}: cases={row['cases']} "
                f"quality={row['route_quality_score']} "
                f"topk_preferred_rate={row['topk_preferred_doc_type_rate']} "
                f"pushed_down_rate={row['deprioritized_pushed_down_rate']}"
            )

        print("\nBest/Worst Routes")
        print("-----------------")
        if best_routes:
            print("best_routes:")
            for item in best_routes:
                print(
                    f"- {item['route']} score={item['route_quality_score']} "
                    f"cases={item['cases']}"
                )
        else:
            print("best_routes: n/a")

        if worst_routes:
            print("worst_routes:")
            for item in worst_routes:
                print(
                    f"- {item['route']} score={item['route_quality_score']} "
                    f"cases={item['cases']}"
                )
        else:
            print("worst_routes: n/a")

        print("\nCommon Document-Type Misses")
        print("---------------------------")
        expected_misses = top_counter_entries(expected_doc_type_misses)
        preferred_misses = top_counter_entries(route_preferred_type_misses)
        if expected_misses:
            print("expected_document_type_miss_topk:")
            for row in expected_misses:
                print(f"- {row['name']}: {row['count']}")
        else:
            print("expected_document_type_miss_topk: none")

        if preferred_misses:
            print("route_preferred_type_miss_topk:")
            for row in preferred_misses:
                print(f"- {row['name']}: {row['count']}")
        else:
            print("route_preferred_type_miss_topk: none")

        print("\nCommon Reranking Misses")
        print("------------------------")
        rerank_miss_rows = top_counter_entries(reranking_misses)
        if rerank_miss_rows:
            for row in rerank_miss_rows:
                print(f"- {row['name']}: {row['count']}")
        else:
            print("none")

        aggregate_metrics = {
            key: {
                "hits": int(hits[key]),
                "total": int(totals[key]),
                "rate": rate_value(int(hits[key]), int(totals[key])),
            }
            for key in hits.keys()
        }

        report = {
            "generated_at": datetime.now(timezone.utc).isoformat(),
            "cases_file": str(cases_path),
            "config_file": str(config_path),
            "settings": {
                "top_k": top_k,
                "rerank_pool_size": rerank_pool_size,
                "min_score": float(args.min_score),
                "max_cases": int(args.max_cases),
            },
            "aggregate_metrics": aggregate_metrics,
            "route_distribution": dict(route_distribution),
            "route_quality_summary": route_quality_summary,
            "best_routes": best_routes,
            "worst_routes": worst_routes,
            "error_categories": error_categories,
            "common_document_type_misses": {
                "expected_document_type_miss_topk": expected_misses,
                "route_preferred_type_miss_topk": preferred_misses,
            },
            "common_reranking_misses": rerank_miss_rows,
            "case_results": case_results,
        }

        should_write_report = bool(args.report_json or args.report_file)
        if should_write_report:
            report_path = resolve_path(args.report_file) if args.report_file else DEFAULT_REPORT_PATH
            report_path.parent.mkdir(parents=True, exist_ok=True)
            report_path.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
            print(f"\nRoute regression report written: {report_path}")

        print("\nError Analysis")
        print("--------------")
        for key, value in error_categories.items():
            print(f"{key}: {value}")

        return 0

    except (RuntimeError, FileNotFoundError, ValueError, KeyError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env python3
"""Lightweight query routing and document-type preference reranking."""

from __future__ import annotations

import re
from dataclasses import dataclass
from typing import Dict, List, Optional, Sequence, Tuple

ROUTE_NAMES: Tuple[str, ...] = (
    "field_definition",
    "observed_value_lookup",
    "structural_relationship",
    "policy_unknown",
    "example_request",
)


@dataclass(frozen=True)
class RoutePolicy:
    route: str
    preferred_document_types: Tuple[str, ...]
    deprioritized_document_types: Tuple[str, ...]
    boost_base: float = 0.08
    boost_decay: float = 0.015
    penalty: float = 0.04


ROUTE_POLICIES: Dict[str, RoutePolicy] = {
    "field_definition": RoutePolicy(
        route="field_definition",
        preferred_document_types=("data_dictionary", "domain_guide", "faq"),
        deprioritized_document_types=("intents_catalog", "chat_examples"),
        boost_base=0.12,
        boost_decay=0.02,
        penalty=0.10,
    ),
    "observed_value_lookup": RoutePolicy(
        route="observed_value_lookup",
        preferred_document_types=("faq", "data_dictionary", "schema_reference"),
        deprioritized_document_types=("intents_catalog", "chat_examples"),
        boost_base=0.11,
        boost_decay=0.02,
        penalty=0.08,
    ),
    "structural_relationship": RoutePolicy(
        route="structural_relationship",
        preferred_document_types=("schema_reference", "domain_guide", "data_dictionary"),
        deprioritized_document_types=("intents_catalog", "chat_examples"),
        boost_base=0.10,
        boost_decay=0.015,
        penalty=0.08,
    ),
    "policy_unknown": RoutePolicy(
        route="policy_unknown",
        preferred_document_types=("assumptions_and_gaps", "business_rules", "faq"),
        deprioritized_document_types=("intents_catalog", "chat_examples"),
        boost_base=0.11,
        boost_decay=0.02,
        penalty=0.10,
    ),
    "example_request": RoutePolicy(
        route="example_request",
        preferred_document_types=("chat_examples", "faq"),
        deprioritized_document_types=("intents_catalog",),
        boost_base=0.10,
        boost_decay=0.02,
        penalty=0.08,
    ),
}

_ROUTE_PATTERNS: List[Tuple[str, Tuple[str, ...]]] = [
    (
        "policy_unknown",
        (
            r"\bofficial\b",
            r"\bmandatory\b",
            r"\bpolicy\b",
            r"\bthreshold",
            r"\bcompliance\b",
            r"\blegal\b",
            r"\bstrict\b",
            r"regle officielle",
            r"r[eè]gle officielle",
            r"\bobligatoire\b",
            r"\bnorme\b",
        ),
    ),
    (
        "example_request",
        (
            r"\bexample\b",
            r"\bexamples\b",
            r"\bexemple\b",
            r"\bexemples\b",
            r"\bdemo\b",
            r"\buse case\b",
            r"\bsc[ée]nario",
        ),
    ),
    (
        "structural_relationship",
        (
            r"\brelationship\b",
            r"\bconnected\b",
            r"\bconnect\b",
            r"\blink\b",
            r"\bjoin\b",
            r"\bhow are .* connected\b",
            r"\brelier\b",
            r"\bassocier\b",
            r"\bcomment relier\b",
        ),
    ),
    (
        "observed_value_lookup",
        (
            r"\bobserved\b",
            r"\bseen\b",
            r"\bscreenshot\b",
            r"\bsample value\b",
            r"\bwhat values\b",
            r"\bwhich values\b",
            r"\bquelles valeurs\b",
            r"\bvaleurs observ[ée]es\b",
            r"\bvaleurs observees\b",
        ),
    ),
    (
        "field_definition",
        (
            r"\bwhat does\b",
            r"\bmeaning\b",
            r"\bmean\b",
            r"\bdefinition\b",
            r"\bdefine\b",
            r"\bexplain\b",
            r"\bdifference between\b",
            r"\bque signifie\b",
            r"\bc[' ]?est quoi\b",
            r"\bdiff[ée]rence entre\b",
        ),
    ),
]


def detect_query_route(query_text: str) -> str:
    lowered = query_text.lower()
    for route_name, patterns in _ROUTE_PATTERNS:
        for pattern in patterns:
            if re.search(pattern, lowered):
                return route_name
    return "field_definition"


def resolve_route_policy(query_text: str, route_override: Optional[str] = None) -> RoutePolicy:
    if route_override:
        return ROUTE_POLICIES.get(route_override, ROUTE_POLICIES["field_definition"])
    route_name = detect_query_route(query_text)
    return ROUTE_POLICIES.get(route_name, ROUTE_POLICIES["field_definition"])


def compute_rerank_pool_size(top_k: int, rerank_pool_size: int = 0) -> int:
    base = max(1, int(top_k))
    if rerank_pool_size > 0:
        return max(base, int(rerank_pool_size))
    return max(base, base * 4, base + 8)


def rerank_results_with_policy(
    results: List[Dict[str, object]],
    policy: RoutePolicy,
    top_k: int,
) -> Tuple[List[Dict[str, object]], Dict[str, object]]:
    if not results:
        return [], {
            "route": policy.route,
            "preferred_document_types": list(policy.preferred_document_types),
            "deprioritized_document_types": list(policy.deprioritized_document_types),
            "boost_base": policy.boost_base,
            "boost_decay": policy.boost_decay,
            "penalty": policy.penalty,
            "pool_candidates": 0,
            "returned": 0,
            "adjustments_applied": 0,
            "order_changed": False,
            "reranking_applied": False,
        }

    original_order = [int(item.get("index", -1)) for item in results]
    enriched: List[Dict[str, object]] = []

    for item in results:
        row = item.get("row", {})
        doc_type = str(row.get("document_type", "")) if isinstance(row, dict) else ""
        raw_score = float(item.get("score", 0.0))
        boost = 0.0

        if doc_type in policy.preferred_document_types:
            pref_idx = policy.preferred_document_types.index(doc_type)
            boost += max(policy.boost_base - (pref_idx * policy.boost_decay), 0.0)

        if doc_type in policy.deprioritized_document_types:
            boost -= policy.penalty

        adjusted = raw_score + boost
        merged = dict(item)
        merged["raw_score"] = raw_score
        merged["boost"] = boost
        merged["score"] = adjusted
        enriched.append(merged)

    enriched.sort(key=lambda x: (-float(x.get("score", 0.0)), -float(x.get("raw_score", 0.0))))

    limited = enriched[: max(1, int(top_k))]
    for rank, item in enumerate(limited, start=1):
        item["rank"] = rank

    reranked_order = [int(item.get("index", -1)) for item in limited]
    compared_len = min(len(limited), len(original_order))
    order_changed = original_order[:compared_len] != reranked_order[:compared_len]
    adjustments_applied = sum(1 for item in limited if abs(float(item.get("boost", 0.0))) > 1e-12)

    debug = {
        "route": policy.route,
        "preferred_document_types": list(policy.preferred_document_types),
        "deprioritized_document_types": list(policy.deprioritized_document_types),
        "boost_base": policy.boost_base,
        "boost_decay": policy.boost_decay,
        "penalty": policy.penalty,
        "pool_candidates": len(results),
        "returned": len(limited),
        "adjustments_applied": adjustments_applied,
        "order_changed": order_changed,
        "reranking_applied": adjustments_applied > 0,
    }
    return limited, debug

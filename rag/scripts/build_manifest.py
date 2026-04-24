#!/usr/bin/env python3
"""Regenerate rag/configs/corpus_manifest.json from current workspace files.

This script enforces the canonical taxonomy declared in rag/configs/metadata_schema.json.
"""

from __future__ import annotations

import json
import sys
from datetime import date
from pathlib import Path
from typing import Dict, List

PROJECT_ROOT = Path(__file__).resolve().parents[2]
RAG_ROOT = PROJECT_ROOT / "rag"
CONFIG_DIR = RAG_ROOT / "configs"
SCHEMA_PATH = CONFIG_DIR / "metadata_schema.json"
MANIFEST_PATH = CONFIG_DIR / "corpus_manifest.json"

# Canonical metadata map by relative file path.
FILE_METADATA: Dict[str, Dict[str, object]] = {
    "rag/corpus/schema_reference.md": {
        "domain": "cross_entity",
        "document_type": "schema_reference",
        "confidence": "confirmed",
        "language": "fr-en",
        "description": "Schema and observed sample values with cautious relationship inference.",
        "include_in_chunks": True,
    },
    "rag/corpus/data_dictionary.md": {
        "domain": "cross_entity",
        "document_type": "data_dictionary",
        "confidence": "inferred",
        "language": "fr-en",
        "description": "Field-by-field meaning with observed examples and non-exhaustive warnings.",
        "include_in_chunks": True,
    },
    "rag/corpus/animals_domain.md": {
        "domain": "animals",
        "document_type": "domain_guide",
        "confidence": "inferred",
        "language": "fr-en",
        "description": "Conservative animals domain guide aligned to current evidence.",
        "include_in_chunks": True,
    },
    "rag/corpus/livestock_domain.md": {
        "domain": "livestock",
        "document_type": "domain_guide",
        "confidence": "inferred",
        "language": "fr-en",
        "description": "Conservative elevage domain guide aligned to observed values.",
        "include_in_chunks": True,
    },
    "rag/corpus/vaccination_domain.md": {
        "domain": "vaccination",
        "document_type": "domain_guide",
        "confidence": "inferred",
        "language": "fr-en",
        "description": "Conservative vaccination domain guide with policy-safe wording.",
        "include_in_chunks": True,
    },
    "rag/corpus/business_rules.md": {
        "domain": "cross_entity",
        "document_type": "business_rules",
        "confidence": "assumed",
        "language": "fr-en",
        "description": "Split into confirmed structure, inferred logic, and hypothetical policy.",
        "include_in_chunks": True,
    },
    "rag/corpus/faq.md": {
        "domain": "cross_entity",
        "document_type": "faq",
        "confidence": "inferred",
        "language": "fr-en",
        "description": "FAQ answers constrained by confirmed evidence scope.",
        "include_in_chunks": True,
    },
    "rag/corpus/chat_examples.md": {
        "domain": "cross_entity",
        "document_type": "chat_examples",
        "confidence": "assumed",
        "language": "fr-en",
        "description": "Conversation examples showing confidence-safe response patterns.",
        "include_in_chunks": True,
    },
    "rag/corpus/intents_catalog.md": {
        "domain": "cross_entity",
        "document_type": "intents_catalog",
        "confidence": "assumed",
        "language": "fr-en",
        "description": "Intent catalog for conservative domain and policy handling.",
        "include_in_chunks": True,
    },
    "rag/corpus/glossary.md": {
        "domain": "cross_entity",
        "document_type": "glossary",
        "confidence": "inferred",
        "language": "fr-en",
        "description": "Confirmed vocabulary versus candidate vocabulary separation.",
        "include_in_chunks": True,
    },
    "rag/corpus/assumptions_and_gaps.md": {
        "domain": "cross_entity",
        "document_type": "assumptions_and_gaps",
        "confidence": "confirmed",
        "language": "fr-en",
        "description": "Master register of known facts, inferred links, and unknown policy gaps.",
        "include_in_chunks": True,
    },
    "rag/configs/chunking_strategy.md": {
        "domain": "rag_operations",
        "document_type": "chunking_strategy",
        "confidence": "assumed",
        "language": "en",
        "description": "Heading-aware chunking contract aligned to script behavior.",
        "include_in_chunks": False,
    },
    "rag/configs/retrieval_strategy.md": {
        "domain": "rag_operations",
        "document_type": "retrieval_strategy",
        "confidence": "assumed",
        "language": "en",
        "description": "Metadata-filtered retrieval policy with confidence-aware behavior.",
        "include_in_chunks": False,
    },
    "rag/configs/rag_roadmap.md": {
        "domain": "rag_operations",
        "document_type": "roadmap",
        "confidence": "assumed",
        "language": "en",
        "description": "Implementation roadmap from corrected corpus to embedding pipeline.",
        "include_in_chunks": False,
    },
    "rag/configs/evaluation_plan.md": {
        "domain": "rag_operations",
        "document_type": "evaluation_plan",
        "confidence": "assumed",
        "language": "en",
        "description": "Evaluation framework with evidence and confidence correctness checks.",
        "include_in_chunks": False,
    },
    "rag/configs/metadata_schema.json": {
        "domain": "rag_operations",
        "document_type": "metadata_schema",
        "confidence": "assumed",
        "language": "en",
        "description": "Canonical metadata schema and taxonomy enums.",
        "include_in_chunks": False,
    },
    "rag/configs/embedding_config.json": {
        "domain": "rag_operations",
        "document_type": "embedding_config",
        "confidence": "assumed",
        "language": "en",
        "description": "Embedding and vector-index local experimentation configuration.",
        "include_in_chunks": False,
    },
    "rag/configs/corpus_manifest.json": {
        "domain": "rag_operations",
        "document_type": "manifest",
        "confidence": "assumed",
        "language": "en",
        "description": "Machine-readable index of RAG documents and metadata.",
        "include_in_chunks": False,
    },
    "rag/configs/symfony_backend_plan.md": {
        "domain": "rag_operations",
        "document_type": "symfony_backend_plan",
        "confidence": "assumed",
        "language": "en",
        "description": "Backend-first integration plan for Symfony service and controller wiring.",
        "include_in_chunks": False,
    },
    "rag/configs/chat_api_contract.json": {
        "domain": "rag_operations",
        "document_type": "chat_api_contract",
        "confidence": "assumed",
        "language": "en",
        "description": "Request, response, and error contract for Symfony-to-Python chat API.",
        "include_in_chunks": False,
    },
    "rag/prompts/system_rag_assistant.md": {
        "domain": "rag_operations",
        "document_type": "system_prompt",
        "confidence": "assumed",
        "language": "en",
        "description": "System prompt enforcing confidence-safe RAG answers.",
        "include_in_chunks": False,
    },
    "rag/prompts/query_rewriting.md": {
        "domain": "rag_operations",
        "document_type": "query_rewriting",
        "confidence": "assumed",
        "language": "en",
        "description": "Query rewriting guidance aligned to conservative retrieval.",
        "include_in_chunks": False,
    },
    "rag/prompts/rag_answer_template.md": {
        "domain": "rag_operations",
        "document_type": "rag_answer_template",
        "confidence": "assumed",
        "language": "fr-en",
        "description": "Grounded answer template for evidence-conservative FR/EN responses.",
        "include_in_chunks": False,
    },
    "rag/tests/sample_queries.json": {
        "domain": "rag_operations",
        "document_type": "test_queries",
        "confidence": "assumed",
        "language": "en",
        "description": "Confidence-grouped query benchmark set.",
        "include_in_chunks": False,
    },
    "rag/tests/golden_answers.md": {
        "domain": "rag_operations",
        "document_type": "golden_answers",
        "confidence": "assumed",
        "language": "en",
        "description": "Expected answer essentials for evaluation runs.",
        "include_in_chunks": False,
    },
    "rag/tests/retrieval_eval_cases.json": {
        "domain": "rag_operations",
        "document_type": "retrieval_eval_cases",
        "confidence": "assumed",
        "language": "en",
        "description": "Starter retrieval evaluation cases for domain and confidence routing checks.",
        "include_in_chunks": False,
    },
    "rag/tests/route_relevance_cases.json": {
        "domain": "rag_operations",
        "document_type": "route_relevance_cases",
        "confidence": "assumed",
        "language": "fr-en",
        "description": "Route-focused retrieval regression pack with realistic farm-domain FR/EN questions.",
        "include_in_chunks": False,
    },
    "rag/tests/rag_answer_cases.json": {
        "domain": "rag_operations",
        "document_type": "rag_answer_cases",
        "confidence": "assumed",
        "language": "fr-en",
        "description": "Starter answer-pipeline cases for confirmed, inferred, and unknown_policy outcomes.",
        "include_in_chunks": False,
    },
    "rag/README_RAG.md": {
        "domain": "rag_operations",
        "document_type": "workspace_readme",
        "confidence": "assumed",
        "language": "en",
        "description": "RAG workspace usage and execution workflow.",
        "include_in_chunks": False,
    },
}


def load_schema() -> Dict[str, object]:
    try:
        return json.loads(SCHEMA_PATH.read_text(encoding="utf-8"))
    except FileNotFoundError:
        raise SystemExit(f"ERROR: metadata schema not found: {SCHEMA_PATH}")


def enforce_taxonomy(schema: Dict[str, object], entry: Dict[str, object], rel_path: str) -> None:
    tax = schema["taxonomies"]
    if entry["domain"] not in tax["domain"]:
        raise ValueError(f"Invalid domain for {rel_path}: {entry['domain']}")
    if entry["document_type"] not in tax["document_type"]:
        raise ValueError(f"Invalid document_type for {rel_path}: {entry['document_type']}")
    if entry["confidence"] not in tax["confidence"]:
        raise ValueError(f"Invalid confidence for {rel_path}: {entry['confidence']}")
    if entry["language"] not in tax["language"]:
        raise ValueError(f"Invalid language for {rel_path}: {entry['language']}")
    if "evidence_scope" in entry and entry["evidence_scope"] not in tax["evidence_scope"]:
        raise ValueError(f"Invalid evidence_scope for {rel_path}: {entry['evidence_scope']}")


def derive_evidence_scope(confidence: str) -> str:
    if confidence == "confirmed":
        return "observed_sample"
    if confidence == "inferred":
        return "schema_inference"
    return "policy_hypothesis"


def discover_existing_files() -> List[str]:
    files: List[str] = []
    for rel_path in sorted(FILE_METADATA.keys()):
        abs_path = PROJECT_ROOT / rel_path
        if abs_path.exists():
            files.append(rel_path)
    return files


def build_manifest() -> Dict[str, object]:
    schema = load_schema()
    documents: List[Dict[str, object]] = []

    for rel_path in discover_existing_files():
        meta = dict(FILE_METADATA[rel_path])
        if "evidence_scope" not in meta:
            meta["evidence_scope"] = derive_evidence_scope(str(meta["confidence"]))
        enforce_taxonomy(schema, meta, rel_path)

        record = {
            "file": rel_path,
            "domain": meta["domain"],
            "document_type": meta["document_type"],
            "confidence": meta["confidence"],
            "evidence_scope": meta["evidence_scope"],
            "language": meta["language"],
            "description": meta["description"],
            "include_in_chunks": bool(meta["include_in_chunks"]),
        }
        documents.append(record)

    return {
        "manifest_version": "2.0.0",
        "taxonomy_version": schema.get("schema_version", "1.0.0"),
        "generated_on": date.today().isoformat(),
        "documents": documents,
    }


def main() -> int:
    manifest = build_manifest()
    MANIFEST_PATH.write_text(json.dumps(manifest, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"Manifest generated: {MANIFEST_PATH}")
    print(f"Documents indexed: {len(manifest['documents'])}")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except ValueError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        raise SystemExit(1)

#!/usr/bin/env python3
"""Validate corpus manifest, taxonomy usage, and chunk output integrity."""

from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Dict, List, Tuple

PROJECT_ROOT = Path(__file__).resolve().parents[2]
RAG_ROOT = PROJECT_ROOT / "rag"
CONFIG_DIR = RAG_ROOT / "configs"
OUTPUTS_DIR = RAG_ROOT / "outputs"

SCHEMA_PATH = CONFIG_DIR / "metadata_schema.json"
MANIFEST_PATH = CONFIG_DIR / "corpus_manifest.json"
CHUNKS_PATH = OUTPUTS_DIR / "chunks.jsonl"


def load_json(path: Path) -> Dict[str, object]:
    return json.loads(path.read_text(encoding="utf-8"))


def actual_trackable_files() -> List[str]:
    paths: List[str] = []
    for pattern in [
        "rag/corpus/*.md",
        "rag/configs/*.md",
        "rag/configs/*.json",
        "rag/prompts/*.md",
        "rag/tests/*.md",
        "rag/tests/*.json",
        "rag/README_RAG.md",
    ]:
        for file_path in PROJECT_ROOT.glob(pattern):
            rel = file_path.relative_to(PROJECT_ROOT).as_posix()
            paths.append(rel)
    return sorted(set(paths))


def validate_manifest(schema: Dict[str, object], manifest: Dict[str, object]) -> Tuple[List[str], List[str]]:
    errors: List[str] = []
    warnings: List[str] = []

    required_manifest_fields = set(schema["required_manifest_fields"])
    optional_manifest_fields = set(schema.get("optional_manifest_fields", []))
    taxonomies = schema["taxonomies"]

    docs = manifest.get("documents", [])
    if not isinstance(docs, list):
        errors.append("Manifest field 'documents' must be a list.")
        return errors, warnings

    seen_files = set()
    for idx, doc in enumerate(docs, start=1):
        if not isinstance(doc, dict):
            errors.append(f"Manifest document #{idx} is not an object.")
            continue

        missing = required_manifest_fields - set(doc.keys())
        if missing:
            errors.append(f"Manifest document #{idx} missing fields: {sorted(missing)}")
            continue

        allowed_fields = required_manifest_fields | optional_manifest_fields
        unexpected_fields = set(doc.keys()) - allowed_fields
        if unexpected_fields:
            warnings.append(f"Manifest document #{idx} has unexpected fields: {sorted(unexpected_fields)}")

        rel_path = doc["file"]
        if rel_path in seen_files:
            errors.append(f"Duplicate manifest file entry: {rel_path}")
        seen_files.add(rel_path)

        abs_path = PROJECT_ROOT / rel_path
        if not abs_path.exists():
            errors.append(f"Manifest references missing file: {rel_path}")

        for key in ["domain", "document_type", "confidence", "language"]:
            if doc[key] not in taxonomies[key]:
                errors.append(f"Unsupported {key} in {rel_path}: {doc[key]}")

        if "evidence_scope" in doc and doc["evidence_scope"] not in taxonomies["evidence_scope"]:
            errors.append(f"Unsupported evidence_scope in {rel_path}: {doc['evidence_scope']}")

    actual_files = set(actual_trackable_files())
    manifest_files = seen_files

    missing_from_manifest = sorted(actual_files - manifest_files)
    extra_in_manifest = sorted(manifest_files - actual_files)

    if missing_from_manifest:
        warnings.append("Files present in workspace but missing from manifest:")
        warnings.extend([f"  - {p}" for p in missing_from_manifest])

    if extra_in_manifest:
        warnings.append("Files listed in manifest but not found in workspace:")
        warnings.extend([f"  - {p}" for p in extra_in_manifest])

    return errors, warnings


def validate_chunks(schema: Dict[str, object]) -> Tuple[List[str], List[str]]:
    errors: List[str] = []
    warnings: List[str] = []

    if not CHUNKS_PATH.exists():
        warnings.append(f"Chunk file not found (build first): {CHUNKS_PATH.relative_to(PROJECT_ROOT).as_posix()}")
        return errors, warnings

    required_chunk_fields = set(schema["required_chunk_fields"])
    optional_chunk_fields = set(schema.get("optional_chunk_fields", []))
    confidence_allowed = set(schema["taxonomies"]["confidence"])
    domain_allowed = set(schema["taxonomies"]["domain"])
    doc_type_allowed = set(schema["taxonomies"]["document_type"])
    language_allowed = set(schema["taxonomies"]["language"])
    evidence_scope_allowed = set(schema["taxonomies"]["evidence_scope"])

    chunk_ids = set()
    with CHUNKS_PATH.open("r", encoding="utf-8") as handle:
        for line_number, line in enumerate(handle, start=1):
            row = line.strip()
            if not row:
                continue

            try:
                chunk = json.loads(row)
            except json.JSONDecodeError as exc:
                errors.append(f"chunks.jsonl line {line_number} is not valid JSON: {exc}")
                continue

            missing = required_chunk_fields - set(chunk.keys())
            if missing:
                errors.append(f"chunks.jsonl line {line_number} missing fields: {sorted(missing)}")
                continue

            allowed_fields = required_chunk_fields | optional_chunk_fields
            unexpected_fields = set(chunk.keys()) - allowed_fields
            if unexpected_fields:
                warnings.append(
                    f"chunks.jsonl line {line_number} has unexpected fields: {sorted(unexpected_fields)}"
                )

            chunk_id = chunk["chunk_id"]
            if chunk_id in chunk_ids:
                errors.append(f"Duplicate chunk_id detected: {chunk_id}")
            chunk_ids.add(chunk_id)

            if chunk["confidence"] not in confidence_allowed:
                errors.append(f"Unsupported confidence label at line {line_number}: {chunk['confidence']}")
            if chunk["domain"] not in domain_allowed:
                errors.append(f"Unsupported domain at line {line_number}: {chunk['domain']}")
            if chunk["document_type"] not in doc_type_allowed:
                errors.append(f"Unsupported document_type at line {line_number}: {chunk['document_type']}")
            if chunk["language"] not in language_allowed:
                errors.append(f"Unsupported language at line {line_number}: {chunk['language']}")
            if "evidence_scope" in chunk and chunk["evidence_scope"] not in evidence_scope_allowed:
                errors.append(f"Unsupported evidence_scope at line {line_number}: {chunk['evidence_scope']}")

            source_path = PROJECT_ROOT / chunk["source_file"]
            if not source_path.exists():
                errors.append(f"Chunk source file missing at line {line_number}: {chunk['source_file']}")

    return errors, warnings


def print_report(section: str, items: List[str]) -> None:
    print(f"\n[{section}]")
    if not items:
        print("  none")
        return
    for item in items:
        print(item)


def main() -> int:
    if not SCHEMA_PATH.exists():
        print(f"ERROR: missing metadata schema at {SCHEMA_PATH}", file=sys.stderr)
        return 1
    if not MANIFEST_PATH.exists():
        print(f"ERROR: missing manifest at {MANIFEST_PATH}", file=sys.stderr)
        return 1

    schema = load_json(SCHEMA_PATH)
    manifest = load_json(MANIFEST_PATH)

    manifest_errors, manifest_warnings = validate_manifest(schema, manifest)
    chunk_errors, chunk_warnings = validate_chunks(schema)

    all_errors = manifest_errors + chunk_errors
    all_warnings = manifest_warnings + chunk_warnings

    print("Corpus Validation Report")
    print("========================")
    print_report("ERRORS", all_errors)
    print_report("WARNINGS", all_warnings)

    doc_count = len(manifest.get("documents", [])) if isinstance(manifest.get("documents"), list) else 0
    print("\n[SUMMARY]")
    print(f"documents_in_manifest: {doc_count}")
    print(f"errors: {len(all_errors)}")
    print(f"warnings: {len(all_warnings)}")

    return 1 if all_errors else 0


if __name__ == "__main__":
    raise SystemExit(main())

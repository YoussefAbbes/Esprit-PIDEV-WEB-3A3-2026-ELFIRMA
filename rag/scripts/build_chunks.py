#!/usr/bin/env python3
"""Build heading-aware JSONL chunks from markdown corpus files."""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path
from typing import Dict, Iterable, List, Tuple

PROJECT_ROOT = Path(__file__).resolve().parents[2]
RAG_ROOT = PROJECT_ROOT / "rag"
CONFIG_DIR = RAG_ROOT / "configs"
OUTPUTS_DIR = RAG_ROOT / "outputs"

MANIFEST_PATH = CONFIG_DIR / "corpus_manifest.json"
SCHEMA_PATH = CONFIG_DIR / "metadata_schema.json"
OUTPUT_PATH = OUTPUTS_DIR / "chunks.jsonl"

HEADING_RE = re.compile(r"^(#{1,6})\s+(.*)$")

CHUNK_RULES: Dict[str, Dict[str, int]] = {
    "schema_reference": {"max_words": 260, "overlap_words": 40},
    "data_dictionary": {"max_words": 220, "overlap_words": 30},
    "domain_guide": {"max_words": 240, "overlap_words": 35},
    "business_rules": {"max_words": 220, "overlap_words": 30},
    "faq": {"max_words": 180, "overlap_words": 20},
    "chat_examples": {"max_words": 220, "overlap_words": 30},
    "intents_catalog": {"max_words": 220, "overlap_words": 30},
    "glossary": {"max_words": 170, "overlap_words": 20},
    "assumptions_and_gaps": {"max_words": 220, "overlap_words": 30},
}
DEFAULT_RULE = {"max_words": 220, "overlap_words": 30}


def load_json(path: Path) -> Dict[str, object]:
    return json.loads(path.read_text(encoding="utf-8"))


def slugify(value: str) -> str:
    slug = re.sub(r"[^a-zA-Z0-9]+", "_", value.strip().lower())
    return slug.strip("_") or "section"


def split_sections(markdown_text: str) -> List[Tuple[str, str]]:
    lines = markdown_text.splitlines()
    sections: List[Tuple[str, str]] = []

    heading_stack: List[str] = []
    current_section = "document_root"
    current_lines: List[str] = []

    for raw_line in lines:
        line = raw_line.rstrip("\n")
        match = HEADING_RE.match(line.strip())
        if match:
            if current_lines and "\n".join(current_lines).strip():
                sections.append((current_section, "\n".join(current_lines).strip()))

            level = len(match.group(1))
            title = match.group(2).strip()

            heading_stack = heading_stack[: level - 1]
            heading_stack.append(title)
            current_section = " > ".join(heading_stack)
            current_lines = [line]
        else:
            current_lines.append(line)

    if current_lines and "\n".join(current_lines).strip():
        sections.append((current_section, "\n".join(current_lines).strip()))

    if not sections:
        text = markdown_text.strip()
        if text:
            return [("document_root", text)]
        return []

    return sections


def chunk_words(text: str, max_words: int, overlap_words: int) -> List[str]:
    words = text.split()
    if not words:
        return []

    if len(words) <= max_words:
        return [text.strip()]

    chunks: List[str] = []
    start = 0

    while start < len(words):
        end = min(start + max_words, len(words))
        chunk_text = " ".join(words[start:end]).strip()
        if chunk_text:
            chunks.append(chunk_text)
        if end >= len(words):
            break
        start = max(0, end - overlap_words)

    return chunks


def docs_to_chunk(manifest: Dict[str, object]) -> Iterable[Dict[str, object]]:
    for doc in manifest.get("documents", []):
        if not isinstance(doc, dict):
            continue
        path = str(doc.get("file", ""))
        include_in_chunks = bool(doc.get("include_in_chunks", False))
        if include_in_chunks and path.endswith(".md"):
            yield doc


def validate_taxonomy(schema: Dict[str, object], doc: Dict[str, object]) -> None:
    tax = schema["taxonomies"]
    for key in ["domain", "document_type", "confidence", "language"]:
        if doc.get(key) not in tax[key]:
            raise ValueError(f"Unsupported {key} in manifest for {doc.get('file')}: {doc.get(key)}")
    if "evidence_scope" in doc and doc.get("evidence_scope") not in tax["evidence_scope"]:
        raise ValueError(
            f"Unsupported evidence_scope in manifest for {doc.get('file')}: {doc.get('evidence_scope')}"
        )


def build_chunks() -> List[Dict[str, object]]:
    if not MANIFEST_PATH.exists():
        raise FileNotFoundError(f"Manifest not found: {MANIFEST_PATH}")
    if not SCHEMA_PATH.exists():
        raise FileNotFoundError(f"Metadata schema not found: {SCHEMA_PATH}")

    schema = load_json(SCHEMA_PATH)
    manifest = load_json(MANIFEST_PATH)

    chunk_records: List[Dict[str, object]] = []
    used_ids = set()

    for doc in docs_to_chunk(manifest):
        validate_taxonomy(schema, doc)

        rel_path = doc["file"]
        abs_path = PROJECT_ROOT / rel_path
        if not abs_path.exists():
            raise FileNotFoundError(f"Manifest references missing chunk source file: {rel_path}")

        content = abs_path.read_text(encoding="utf-8")
        sections = split_sections(content)

        rules = CHUNK_RULES.get(doc["document_type"], DEFAULT_RULE)
        max_words = rules["max_words"]
        overlap_words = rules["overlap_words"]

        source_stem = Path(rel_path).stem

        for section_name, section_text in sections:
            piece_list = chunk_words(section_text, max_words=max_words, overlap_words=overlap_words)
            section_slug = slugify(section_name)
            for index, piece in enumerate(piece_list, start=1):
                chunk_id = f"{source_stem}__{section_slug}__p{index:03d}"
                # Guard against accidental collisions across files with same section names.
                if chunk_id in used_ids:
                    chunk_id = f"{source_stem}__{section_slug}__p{index:03d}__{len(used_ids):04d}"
                used_ids.add(chunk_id)

                chunk_records.append(
                    {
                        "chunk_id": chunk_id,
                        "source_file": rel_path,
                        "domain": doc["domain"],
                        "document_type": doc["document_type"],
                        "confidence": doc["confidence"],
                        "section": section_name,
                        "language": doc["language"],
                        "text": piece,
                    }
                )

                if "evidence_scope" in doc:
                    chunk_records[-1]["evidence_scope"] = doc["evidence_scope"]

    return chunk_records


def write_jsonl(records: List[Dict[str, object]]) -> None:
    OUTPUTS_DIR.mkdir(parents=True, exist_ok=True)
    with OUTPUT_PATH.open("w", encoding="utf-8") as handle:
        for record in records:
            handle.write(json.dumps(record, ensure_ascii=False) + "\n")


def main() -> int:
    try:
        chunks = build_chunks()
        write_jsonl(chunks)
    except (FileNotFoundError, ValueError, json.JSONDecodeError) as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1

    print(f"Chunk file generated: {OUTPUT_PATH}")
    print(f"Total chunks: {len(chunks)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

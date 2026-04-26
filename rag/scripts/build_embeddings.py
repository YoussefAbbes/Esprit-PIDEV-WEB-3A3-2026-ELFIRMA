#!/usr/bin/env python3
"""Build embeddings from rag/outputs/chunks.jsonl.

This script uses a local sentence-transformers model and writes
rag/outputs/chunk_embeddings.jsonl.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Dict, Iterable, List

PROJECT_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_CONFIG_PATH = PROJECT_ROOT / "rag" / "configs" / "embedding_config.json"


def load_json(path: Path) -> Dict[str, object]:
    return json.loads(path.read_text(encoding="utf-8"))


def iter_jsonl(path: Path) -> Iterable[Dict[str, object]]:
    with path.open("r", encoding="utf-8") as handle:
        for line_number, line in enumerate(handle, start=1):
            row = line.strip()
            if not row:
                continue
            try:
                payload = json.loads(row)
            except json.JSONDecodeError as exc:
                raise ValueError(f"Invalid JSON at {path}:{line_number}: {exc}") from exc
            if not isinstance(payload, dict):
                raise ValueError(f"Expected object at {path}:{line_number}")
            yield payload


def ensure_dependencies() -> None:
    try:
        import numpy  # noqa: F401
        import sentence_transformers  # noqa: F401
    except Exception as exc:  # pragma: no cover - runtime guard
        raise RuntimeError(
            "Missing dependencies for embeddings. Install with: "
            "python -m pip install -r rag/requirements-rag.txt"
        ) from exc


def build_embeddings(config_path: Path) -> int:
    ensure_dependencies()

    import numpy as np
    from sentence_transformers import SentenceTransformer

    config = load_json(config_path)

    chunk_input = PROJECT_ROOT / str(config["pipeline_paths"]["chunk_input"])
    embedding_output = PROJECT_ROOT / str(config["pipeline_paths"]["embedding_output"])
    model_name = str(config["embedding_model"]["primary"])
    batch_size = int(config["embedding_runtime"].get("batch_size", 32))
    normalize_vectors = bool(config["embedding_runtime"].get("normalize_vectors", True))

    if not chunk_input.exists():
        print(f"ERROR: chunk input file not found: {chunk_input}", file=sys.stderr)
        return 1

    chunks: List[Dict[str, object]] = list(iter_jsonl(chunk_input))
    if not chunks:
        print("ERROR: chunk input is empty. Run build_chunks.py first.", file=sys.stderr)
        return 1

    for idx, chunk in enumerate(chunks, start=1):
        if "text" not in chunk or not isinstance(chunk["text"], str) or not chunk["text"].strip():
            print(f"ERROR: chunk #{idx} missing non-empty text field.", file=sys.stderr)
            return 1

    model = SentenceTransformer(model_name)
    embedding_output.parent.mkdir(parents=True, exist_ok=True)

    total_written = 0
    with embedding_output.open("w", encoding="utf-8") as handle:
        for start in range(0, len(chunks), batch_size):
            batch = chunks[start : start + batch_size]
            texts = [str(item["text"]) for item in batch]

            vectors = model.encode(
                texts,
                batch_size=min(batch_size, len(batch)),
                show_progress_bar=False,
                convert_to_numpy=True,
                normalize_embeddings=normalize_vectors,
            )

            for chunk, vector in zip(batch, vectors):
                vector_f32 = np.asarray(vector, dtype=np.float32)
                record = dict(chunk)
                record["embedding"] = [float(x) for x in vector_f32.tolist()]
                handle.write(json.dumps(record, ensure_ascii=False) + "\n")
                total_written += 1

    print(f"Embedding model: {model_name}")
    print(f"Chunks embedded: {total_written}")
    print(f"Output file: {embedding_output}")
    return 0


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Build chunk embeddings JSONL")
    parser.add_argument(
        "--config",
        default=str(DEFAULT_CONFIG_PATH),
        help="Path to embedding config JSON file",
    )
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    config_path = Path(args.config)
    if not config_path.is_absolute():
        config_path = PROJECT_ROOT / config_path

    if not config_path.exists():
        print(f"ERROR: config file not found: {config_path}", file=sys.stderr)
        return 1

    try:
        return build_embeddings(config_path)
    except RuntimeError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1
    except (KeyError, ValueError) as exc:
        print(f"ERROR: invalid config or input data: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())

#!/usr/bin/env python3
"""Build a local vector index from chunk embeddings.

Index artifacts are written under rag/outputs/vector_index.
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime, timezone
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


def ensure_numpy() -> None:
    try:
        import numpy  # noqa: F401
    except Exception as exc:  # pragma: no cover - runtime guard
        raise RuntimeError(
            "Missing numpy dependency. Install with: "
            "python -m pip install -r rag/requirements-rag.txt"
        ) from exc


def build_index(config_path: Path) -> int:
    ensure_numpy()

    import numpy as np

    config = load_json(config_path)
    embedding_output = PROJECT_ROOT / str(config["pipeline_paths"]["embedding_output"])
    index_dir = PROJECT_ROOT / str(config["pipeline_paths"]["vector_index_dir"])
    normalize_vectors = bool(config["embedding_runtime"].get("normalize_vectors", True))

    matrix_name = str(config["vector_store"]["index_files"]["matrix"])
    metadata_name = str(config["vector_store"]["index_files"]["metadata"])
    meta_name = str(config["vector_store"]["index_files"]["meta"])

    if not embedding_output.exists():
        print(f"ERROR: embeddings file not found: {embedding_output}", file=sys.stderr)
        print("Run build_embeddings.py first.", file=sys.stderr)
        return 1

    rows: List[Dict[str, object]] = list(iter_jsonl(embedding_output))
    if not rows:
        print("ERROR: embeddings file is empty.", file=sys.stderr)
        return 1

    vectors: List[List[float]] = []
    metadata_rows: List[Dict[str, object]] = []
    for idx, row in enumerate(rows, start=1):
        if "embedding" not in row:
            print(f"ERROR: record #{idx} has no embedding field.", file=sys.stderr)
            return 1
        vector = row["embedding"]
        if not isinstance(vector, list) or not vector:
            print(f"ERROR: record #{idx} has invalid embedding payload.", file=sys.stderr)
            return 1

        vectors.append(vector)

        metadata = {k: v for k, v in row.items() if k != "embedding"}
        metadata["vector_row"] = idx - 1
        metadata_rows.append(metadata)

    matrix = np.asarray(vectors, dtype=np.float32)
    if matrix.ndim != 2:
        print("ERROR: embedding matrix is not 2-dimensional.", file=sys.stderr)
        return 1

    if normalize_vectors:
        norms = np.linalg.norm(matrix, axis=1, keepdims=True)
        norms[norms == 0.0] = 1.0
        matrix = matrix / norms

    index_dir.mkdir(parents=True, exist_ok=True)
    matrix_path = index_dir / matrix_name
    metadata_path = index_dir / metadata_name
    meta_path = index_dir / meta_name

    np.save(matrix_path, matrix)

    with metadata_path.open("w", encoding="utf-8") as handle:
        for item in metadata_rows:
            handle.write(json.dumps(item, ensure_ascii=False) + "\n")

    index_meta = {
        "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "vector_store": str(config["vector_store"]["primary"]),
        "embedding_model": str(config["embedding_model"]["primary"]),
        "normalize_vectors": normalize_vectors,
        "vector_count": int(matrix.shape[0]),
        "dimension": int(matrix.shape[1]),
        "matrix_file": matrix_name,
        "metadata_file": metadata_name,
    }
    meta_path.write_text(json.dumps(index_meta, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    print(f"Vector index directory: {index_dir}")
    print(f"Vectors indexed: {matrix.shape[0]}")
    print(f"Dimension: {matrix.shape[1]}")
    print(f"Metadata records: {len(metadata_rows)}")
    return 0


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Build local numpy vector index")
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
        return build_index(config_path)
    except RuntimeError as exc:
        print(f"ERROR: {exc}", file=sys.stderr)
        return 1
    except (KeyError, ValueError) as exc:
        print(f"ERROR: invalid config or embeddings format: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())

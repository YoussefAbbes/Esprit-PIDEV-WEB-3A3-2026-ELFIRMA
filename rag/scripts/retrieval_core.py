#!/usr/bin/env python3
"""Shared retrieval helpers for local RAG scripts."""

from __future__ import annotations

import json
from importlib import metadata as importlib_metadata
from functools import lru_cache
from pathlib import Path
from typing import Dict, Iterable, List, Optional, Sequence, Set, Tuple
import traceback

PROJECT_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_CONFIG_PATH = PROJECT_ROOT / "rag" / "configs" / "embedding_config.json"
POLICY_DOC_TYPES = {"assumptions_and_gaps", "business_rules", "faq"}
LOCKFILE_PATH = PROJECT_ROOT / "rag" / "requirements-lock.txt"
CORE_RUNTIME_PACKAGES = [
    ("numpy", "numpy"),
    ("sentence_transformers", "sentence-transformers"),
    ("transformers", "transformers"),
    ("torch", "torch"),
    ("huggingface_hub", "huggingface_hub"),
    ("tokenizers", "tokenizers"),
    ("safetensors", "safetensors"),
]


def load_json(path: Path) -> Dict[str, object]:
    return json.loads(path.read_text(encoding="utf-8"))


def rel_path(path: Path) -> str:
    try:
        return str(path.relative_to(PROJECT_ROOT)).replace("\\", "/")
    except ValueError:
        return str(path)


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


def _module_version(module_name: str, package_name: str) -> str:
    import importlib

    try:
        module = importlib.import_module(module_name)
    except Exception:
        return "unknown"

    version = getattr(module, "__version__", "")
    if isinstance(version, str) and version.strip():
        return version.strip()

    try:
        return importlib_metadata.version(package_name)
    except importlib_metadata.PackageNotFoundError:
        return "missing"


def _dist_version(package_name: str) -> str:
    try:
        return importlib_metadata.version(package_name)
    except importlib_metadata.PackageNotFoundError:
        return "missing"


def _runtime_versions_summary() -> str:
    parts = [f"{pkg}={_dist_version(pkg)}" for _module, pkg in CORE_RUNTIME_PACKAGES]
    return ", ".join(parts)


def _last_trace_frame(exc: BaseException) -> str:
    tb = traceback.extract_tb(exc.__traceback__) if exc.__traceback__ is not None else []
    if not tb:
        return ""

    last = tb[-1]
    return f"{last.filename}:{last.lineno} in {last.name}"


def ensure_dependencies() -> None:
    import importlib

    missing: List[str] = []
    broken: List[str] = []

    for module_name, package_name in CORE_RUNTIME_PACKAGES:
        top_module = module_name.split(".")[0]
        dist_ver = _dist_version(package_name)
        try:
            importlib.import_module(module_name)
        except ModuleNotFoundError as exc:
            missing_name = (exc.name or "").strip()
            missing_message = str(exc).strip()
            chained_detail = ""
            frame_detail = _last_trace_frame(exc)
            root_exc = exc.__cause__ if exc.__cause__ is not None else exc.__context__
            if root_exc is not None and root_exc is not exc:
                root_frame_detail = _last_trace_frame(root_exc)
                chained_detail = f" | root={root_exc.__class__.__name__}: {root_exc}"
                if root_frame_detail:
                    chained_detail += f" @ {root_frame_detail}"
            if missing_name == top_module and dist_ver == "missing":
                missing.append(f"- {package_name} (distribution not installed)")
            else:
                if not missing_name:
                    missing_name = "unknown"
                broken.append(
                    f"- {module_name} [{package_name}={dist_ver}] -> "
                    f"ModuleNotFoundError({missing_name}): {missing_message}"
                    f"{(' @ ' + frame_detail) if frame_detail else ''}{chained_detail}"
                )
        except Exception as exc:
            broken.append(
                f"- {module_name} [{package_name}={dist_ver}] -> {exc.__class__.__name__}: {exc}"
            )

    if missing or broken:
        lines = [
            "Dependency check failed for retrieval scripts.",
            f"Install target: python -m pip install -r {rel_path(LOCKFILE_PATH)}",
        ]
        if missing:
            lines.append("Missing packages:")
            lines.extend(missing)
        if broken:
            lines.append("Broken imports (installed but not loadable):")
            lines.extend(broken)
        lines.append(f"Runtime versions: {_runtime_versions_summary()}")
        raise RuntimeError(
            "\n".join(lines)
        )

    version_check = {
        "numpy": _module_version("numpy", "numpy"),
        "sentence_transformers": _module_version("sentence_transformers", "sentence-transformers"),
        "transformers": _module_version("transformers", "transformers"),
        "torch": _module_version("torch", "torch"),
    }
    if any(v in {"missing", "unknown"} for v in version_check.values()):
        raise RuntimeError(
            "Dependency versions are not fully discoverable in current interpreter. "
            f"Runtime versions: {_runtime_versions_summary()}"
        )


@lru_cache(maxsize=2)
def get_model(model_name: str):
    try:
        from sentence_transformers import SentenceTransformer
    except Exception as exc:
        raise RuntimeError(
            "Model load failure: sentence-transformers import failed in current runtime. "
            f"Runtime versions: {_runtime_versions_summary()}"
        ) from exc

    try:
        return SentenceTransformer(model_name)
    except Exception as exc:
        raise RuntimeError(
            "Model load failure for embedding model "
            f"'{model_name}'. This may be caused by network/cache access or package-version mismatch. "
            f"Runtime versions: {_runtime_versions_summary()}"
        ) from exc


def load_index(config: Dict[str, object]) -> Tuple["numpy.ndarray", List[Dict[str, object]], Dict[str, object]]:
    import numpy as np

    index_dir = PROJECT_ROOT / str(config["pipeline_paths"]["vector_index_dir"])
    matrix_name = str(config["vector_store"]["index_files"]["matrix"])
    metadata_name = str(config["vector_store"]["index_files"]["metadata"])
    meta_name = str(config["vector_store"]["index_files"]["meta"])

    matrix_path = index_dir / matrix_name
    metadata_path = index_dir / metadata_name
    meta_path = index_dir / meta_name

    missing = [
        p for p in (matrix_path, metadata_path, meta_path) if not p.exists()
    ]
    if missing:
        missing_str = ", ".join(rel_path(p) for p in missing)
        raise FileNotFoundError(
            "Missing vector artifacts (index build required). "
            f"Missing: {missing_str}. "
            "Run build_vector_index.py first."
        )

    matrix = np.load(matrix_path)
    metadata = list(iter_jsonl(metadata_path))
    index_meta = load_json(meta_path)

    if matrix.shape[0] != len(metadata):
        raise ValueError(
            f"Index inconsistency: matrix rows ({matrix.shape[0]}) != metadata rows ({len(metadata)})"
        )

    return matrix, metadata, index_meta


def compute_scores(
    query: str,
    model_name: str,
    matrix: "numpy.ndarray",
    normalize_vectors: bool,
) -> "numpy.ndarray":
    import numpy as np

    model = get_model(model_name)
    query_vector = model.encode(
        [query],
        show_progress_bar=False,
        convert_to_numpy=True,
        normalize_embeddings=normalize_vectors,
    )[0].astype(np.float32)

    if normalize_vectors:
        query_norm = np.linalg.norm(query_vector)
        if query_norm != 0.0:
            query_vector = query_vector / query_norm
        return matrix @ query_vector

    matrix_norm = np.linalg.norm(matrix, axis=1)
    query_norm = np.linalg.norm(query_vector)
    denom = matrix_norm * max(query_norm, 1e-12)
    denom[denom == 0.0] = 1e-12
    return (matrix @ query_vector) / denom


def parse_filter_values(raw_values: Optional[Sequence[str]]) -> Set[str]:
    values: Set[str] = set()
    if not raw_values:
        return values
    for raw in raw_values:
        for part in str(raw).split(","):
            item = part.strip()
            if item:
                values.add(item)
    return values


def build_metadata_filters(
    domain: Optional[Sequence[str]] = None,
    document_type: Optional[Sequence[str]] = None,
    confidence: Optional[Sequence[str]] = None,
    language: Optional[Sequence[str]] = None,
    evidence_scope: Optional[Sequence[str]] = None,
) -> Dict[str, Set[str]]:
    return {
        "domain": parse_filter_values(domain),
        "document_type": parse_filter_values(document_type),
        "confidence": parse_filter_values(confidence),
        "language": parse_filter_values(language),
        "evidence_scope": parse_filter_values(evidence_scope),
    }


def row_matches_filters(row: Dict[str, object], filters: Dict[str, Set[str]]) -> bool:
    for key, allowed in filters.items():
        if not allowed:
            continue
        row_value = row.get(key)
        if row_value is None:
            return False
        if str(row_value) not in allowed:
            return False
    return True


def active_filters(filters: Dict[str, Set[str]]) -> Dict[str, List[str]]:
    return {k: sorted(v) for k, v in filters.items() if v}


def rank_results(
    query: str,
    model_name: str,
    normalize_vectors: bool,
    matrix: "numpy.ndarray",
    metadata: List[Dict[str, object]],
    filters: Optional[Dict[str, Set[str]]] = None,
    top_k: int = 5,
    min_score: float = -1.0,
) -> Tuple[List[Dict[str, object]], int]:
    import numpy as np

    local_filters = filters or {}
    candidate_indices = [
        idx for idx, row in enumerate(metadata) if row_matches_filters(row, local_filters)
    ]

    if not candidate_indices:
        return [], 0

    candidate_matrix = matrix[candidate_indices]
    scores = compute_scores(query, model_name, candidate_matrix, normalize_vectors)
    ranked_local = np.argsort(-scores)

    results: List[Dict[str, object]] = []
    for local_rank_idx in ranked_local:
        score = float(scores[local_rank_idx])
        if score < float(min_score):
            continue

        global_idx = int(candidate_indices[int(local_rank_idx)])
        row = metadata[global_idx]
        results.append(
            {
                "index": global_idx,
                "score": score,
                "row": row,
            }
        )
        if len(results) >= max(1, int(top_k)):
            break

    for rank, item in enumerate(results, start=1):
        item["rank"] = rank

    return results, len(candidate_indices)


def snippet(text: str, limit: int = 220) -> str:
    compact = " ".join(text.split())
    if len(compact) <= limit:
        return compact
    return compact[: limit - 3] + "..."

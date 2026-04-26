#!/usr/bin/env python3
"""RAG runtime doctor: verify Python, package runtime, model load, and artifacts."""

from __future__ import annotations

import argparse
import importlib
import json
import os
import sys
from importlib import metadata as importlib_metadata
from pathlib import Path
from typing import Dict, List, Sequence, Tuple

PROJECT_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_CONFIG_PATH = PROJECT_ROOT / "rag" / "configs" / "embedding_config.json"
LOCKFILE_PATH = PROJECT_ROOT / "rag" / "requirements-lock.txt"
MIN_PYTHON = (3, 10)

REQUIRED_IMPORTS: Tuple[Tuple[str, str], ...] = (
    ("numpy", "numpy"),
    ("sentence_transformers", "sentence-transformers"),
    ("transformers", "transformers"),
    ("torch", "torch"),
    ("huggingface_hub", "huggingface_hub"),
    ("tokenizers", "tokenizers"),
    ("safetensors", "safetensors"),
)

RELATED_VERSION_PACKAGES: Tuple[str, ...] = (
    "sentence-transformers",
    "transformers",
    "torch",
    "tokenizers",
    "safetensors",
    "huggingface_hub",
    "numpy",
)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Check RAG runtime health")
    parser.add_argument(
        "--config",
        default=str(DEFAULT_CONFIG_PATH),
        help="Path to embedding config JSON file",
    )
    parser.add_argument(
        "--skip-model-load",
        action="store_true",
        help="Skip SentenceTransformer model loading check",
    )
    return parser.parse_args()


def rel_path(path: Path) -> str:
    try:
        return str(path.relative_to(PROJECT_ROOT)).replace("\\", "/")
    except ValueError:
        return str(path)


def dist_version(package_name: str) -> str:
    try:
        return importlib_metadata.version(package_name)
    except importlib_metadata.PackageNotFoundError:
        return "missing"


def module_version(module_name: str, package_name: str) -> str:
    try:
        module = importlib.import_module(module_name)
    except Exception:
        return dist_version(package_name)

    version = getattr(module, "__version__", "")
    if isinstance(version, str) and version.strip():
        return version.strip()

    try:
        return importlib_metadata.version(package_name)
    except importlib_metadata.PackageNotFoundError:
        return "missing"


def format_versions(package_names: Sequence[str]) -> str:
    pairs = [f"{name}={dist_version(name)}" for name in package_names]
    return ", ".join(pairs)


def record(counts: Dict[str, int], level: str, check: str, detail: str) -> None:
    counts[level] += 1
    print(f"[{level}] {check}: {detail}")


def expected_venv_python() -> Path:
    win_path = PROJECT_ROOT / ".venv" / "Scripts" / "python.exe"
    if win_path.exists():
        return win_path.resolve()
    posix_path = PROJECT_ROOT / ".venv" / "bin" / "python"
    return posix_path.resolve()


def same_path(left: Path, right: Path) -> bool:
    return str(left.resolve()).lower() == str(right.resolve()).lower()


def load_config(path: Path) -> Dict[str, object]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(payload, dict):
        raise ValueError("Config JSON root must be an object")
    return payload


def resolve_artifacts(config: Dict[str, object]) -> List[Tuple[str, Path, str]]:
    pipeline_paths = config.get("pipeline_paths", {})
    vector_store = config.get("vector_store", {})

    if not isinstance(pipeline_paths, dict):
        raise ValueError("pipeline_paths must be an object in embedding config")
    if not isinstance(vector_store, dict):
        raise ValueError("vector_store must be an object in embedding config")

    index_files = vector_store.get("index_files", {})
    if not isinstance(index_files, dict):
        raise ValueError("vector_store.index_files must be an object in embedding config")

    vector_index_dir = PROJECT_ROOT / str(pipeline_paths.get("vector_index_dir", "rag/outputs/vector_index"))
    chunks_path = PROJECT_ROOT / str(pipeline_paths.get("chunk_input", "rag/outputs/chunks.jsonl"))
    embeddings_path = PROJECT_ROOT / str(pipeline_paths.get("embedding_output", "rag/outputs/chunk_embeddings.jsonl"))

    matrix_path = vector_index_dir / str(index_files.get("matrix", "embeddings.npy"))
    metadata_path = vector_index_dir / str(index_files.get("metadata", "metadata.jsonl"))
    meta_path = vector_index_dir / str(index_files.get("meta", "index_meta.json"))

    return [
        ("chunks_jsonl", chunks_path, "WARN"),
        ("chunk_embeddings_jsonl", embeddings_path, "WARN"),
        ("vector_matrix", matrix_path, "FAIL"),
        ("vector_metadata", metadata_path, "FAIL"),
        ("vector_meta", meta_path, "FAIL"),
    ]


def build_hints(
    using_project_venv: bool,
    venv_env_set: bool,
    had_ml_import_failure: bool,
    had_unknown_module_failure: bool,
) -> List[str]:
    hints: List[str] = []
    if not using_project_venv:
        hints.append("Wrong interpreter detected. Activate .venv and run with 'python', not 'py'.")
    if not venv_env_set and not using_project_venv:
        hints.append("Shell appears not activated for project venv. Re-run Activate.ps1 in this terminal.")
    if had_ml_import_failure:
        hints.append(
            "Potential sentence-transformers/transformers/torch incompatibility. Reinstall lockfile in active interpreter."
        )
    if had_unknown_module_failure:
        hints.append(
            "ModuleNotFoundError without module name often indicates partial/broken environment state."
        )
    return hints


def main() -> int:
    args = parse_args()
    counts = {"PASS": 0, "WARN": 0, "FAIL": 0}

    print("RAG Runtime Doctor")
    print("==================")

    current_version = (sys.version_info.major, sys.version_info.minor, sys.version_info.micro)
    current_label = f"{current_version[0]}.{current_version[1]}.{current_version[2]}"
    if current_version >= MIN_PYTHON:
        record(counts, "PASS", "python_version", f"{current_label} (required >= {MIN_PYTHON[0]}.{MIN_PYTHON[1]})")
    else:
        record(counts, "FAIL", "python_version", f"{current_label} (required >= {MIN_PYTHON[0]}.{MIN_PYTHON[1]})")

    current_python = Path(sys.executable).resolve()
    expected_python = expected_venv_python()
    using_project_venv = False
    if expected_python.exists():
        if same_path(current_python, expected_python):
            using_project_venv = True
            record(counts, "PASS", "python_interpreter", f"using project venv: {current_python}")
        else:
            record(
                counts,
                "WARN",
                "python_interpreter",
                (
                    f"current interpreter is {current_python}, expected project venv is {expected_python}. "
                    "If using PowerShell, run .\\.venv\\Scripts\\Activate.ps1 then use 'python ...' (not 'py ...')."
                ),
            )
    else:
        record(
            counts,
            "WARN",
            "python_interpreter",
            f"project venv interpreter not found at expected path: {expected_python}",
        )

    venv_env = os.environ.get("VIRTUAL_ENV", "").strip()
    venv_env_set = bool(venv_env)
    if venv_env:
        record(counts, "PASS", "virtual_env", f"VIRTUAL_ENV={venv_env}")
    elif using_project_venv:
        record(counts, "PASS", "virtual_env", "VIRTUAL_ENV is not set, but interpreter path matches project venv")
    else:
        record(counts, "WARN", "virtual_env", "VIRTUAL_ENV is not set in current shell")

    if expected_python.exists() and not using_project_venv:
        install_hint = f"{expected_python} -m pip install -r {rel_path(LOCKFILE_PATH)}"
    else:
        install_hint = f"python -m pip install -r {rel_path(LOCKFILE_PATH)}"

    record(
        counts,
        "PASS",
        "core_package_versions",
        format_versions([dist for _module, dist in REQUIRED_IMPORTS]),
    )

    import_ok = True
    had_ml_import_failure = False
    had_unknown_module_failure = False
    for module_name, package_name in REQUIRED_IMPORTS:
        top_module = module_name.split(".")[0]
        dist_ver = dist_version(package_name)
        try:
            importlib.import_module(module_name)
            version = module_version(module_name, package_name)
            record(counts, "PASS", f"import:{module_name}", f"installed version={version}")
        except ModuleNotFoundError as exc:
            import_ok = False
            if module_name in {"sentence_transformers", "transformers", "torch"}:
                had_ml_import_failure = True
            missing_name = (exc.name or "").strip()
            if (exc.name or "") == top_module:
                if dist_ver == "missing":
                    record(
                        counts,
                        "FAIL",
                        f"import:{module_name}",
                        (
                            f"missing package '{package_name}'. "
                            f"Install with: {install_hint}"
                        ),
                    )
                else:
                    record(
                        counts,
                        "FAIL",
                        f"import:{module_name}",
                        (
                            f"broken import for installed package '{package_name}' ({dist_ver}); "
                            f"missing module '{top_module}'. "
                            f"Related versions: {format_versions(RELATED_VERSION_PACKAGES)}"
                        ),
                    )
            else:
                if not missing_name:
                    missing_name = "unknown"
                    had_unknown_module_failure = True
                    cause = "ModuleNotFoundError raised without module name"
                else:
                    cause = f"missing module '{missing_name}'"
                record(
                    counts,
                    "FAIL",
                    f"import:{module_name}",
                    (
                        f"broken import for package '{package_name}' ({dist_ver}); {cause}. "
                        f"Related versions: {format_versions(RELATED_VERSION_PACKAGES)}"
                    ),
                )
        except Exception as exc:
            import_ok = False
            if module_name in {"sentence_transformers", "transformers", "torch"}:
                had_ml_import_failure = True
            record(
                counts,
                "FAIL",
                f"import:{module_name}",
                (
                    f"broken import for package '{package_name}' ({dist_ver}): "
                    f"{exc.__class__.__name__}: {exc}. "
                    f"Related versions: {format_versions(RELATED_VERSION_PACKAGES)}"
                ),
            )

    config_path = Path(args.config)
    if not config_path.is_absolute():
        config_path = PROJECT_ROOT / config_path

    config: Dict[str, object] = {}
    if not config_path.exists():
        record(counts, "FAIL", "embedding_config", f"missing file {rel_path(config_path)}")
    else:
        try:
            config = load_config(config_path)
            record(counts, "PASS", "embedding_config", f"loaded {rel_path(config_path)}")
        except Exception as exc:
            record(counts, "FAIL", "embedding_config", f"{exc.__class__.__name__}: {exc}")

    model_name = ""
    if config:
        try:
            model_name = str(config["embedding_model"]["primary"])  # type: ignore[index]
        except Exception as exc:
            record(counts, "FAIL", "embedding_model", f"could not resolve model name: {exc}")

    if args.skip_model_load:
        record(counts, "WARN", "model_load", "skipped by --skip-model-load")
    elif not model_name:
        record(counts, "WARN", "model_load", "skipped because no model name was resolved")
    elif not import_ok:
        record(counts, "WARN", "model_load", "skipped because required imports failed")
    else:
        try:
            from sentence_transformers import SentenceTransformer

            SentenceTransformer(model_name)
            record(counts, "PASS", "model_load", f"loaded model '{model_name}'")
        except Exception as exc:
            had_ml_import_failure = True
            record(
                counts,
                "FAIL",
                "model_load",
                (
                    f"failed to load '{model_name}': {exc.__class__.__name__}: {exc}. "
                    "Check network/cache access and runtime package compatibility. "
                    f"Related versions: {format_versions(RELATED_VERSION_PACKAGES)}"
                ),
            )

    if config:
        try:
            artifacts = resolve_artifacts(config)
            for label, artifact_path, missing_level in artifacts:
                if artifact_path.exists():
                    size = artifact_path.stat().st_size
                    record(counts, "PASS", f"artifact:{label}", f"found {rel_path(artifact_path)} ({size} bytes)")
                else:
                    record(counts, missing_level, f"artifact:{label}", f"missing {rel_path(artifact_path)}")
        except Exception as exc:
            record(counts, "FAIL", "artifact_map", f"{exc.__class__.__name__}: {exc}")

    hints = build_hints(
        using_project_venv=using_project_venv,
        venv_env_set=venv_env_set,
        had_ml_import_failure=had_ml_import_failure,
        had_unknown_module_failure=had_unknown_module_failure,
    )
    if hints:
        print("\nHints")
        print("-----")
        for hint in hints:
            print(f"- {hint}")

    if counts["FAIL"] > 0:
        overall = "FAIL"
        exit_code = 1
    elif counts["WARN"] > 0:
        overall = "WARN"
        exit_code = 0
    else:
        overall = "PASS"
        exit_code = 0

    print("\nSummary")
    print("-------")
    print(f"PASS: {counts['PASS']}")
    print(f"WARN: {counts['WARN']}")
    print(f"FAIL: {counts['FAIL']}")
    print(f"Overall: {overall}")

    return exit_code


if __name__ == "__main__":
    raise SystemExit(main())

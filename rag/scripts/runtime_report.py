#!/usr/bin/env python3
"""Generate a runtime environment report for RAG reproducibility checks."""

from __future__ import annotations

import argparse
import json
import os
import platform
import subprocess
import sys
from datetime import datetime, timezone
from importlib import metadata as importlib_metadata
from pathlib import Path
from typing import Dict, List

PROJECT_ROOT = Path(__file__).resolve().parents[2]
DEFAULT_CONFIG_PATH = PROJECT_ROOT / "rag" / "configs" / "embedding_config.json"
DEFAULT_OUTPUT_PATH = PROJECT_ROOT / "rag" / "outputs" / "reports" / "runtime_report.json"
DOCTOR_PATH = PROJECT_ROOT / "rag" / "scripts" / "doctor.py"

CORE_PACKAGES = [
    "numpy",
    "sentence-transformers",
    "transformers",
    "torch",
    "huggingface_hub",
    "tokenizers",
    "safetensors",
]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Generate runtime report for RAG environment")
    parser.add_argument(
        "--config",
        default=str(DEFAULT_CONFIG_PATH),
        help="Path to embedding config JSON file",
    )
    parser.add_argument(
        "--skip-model-load",
        action="store_true",
        help="Pass --skip-model-load to doctor.py",
    )
    parser.add_argument(
        "--output-json",
        action="store_true",
        help="Write JSON report to rag/outputs/reports/runtime_report.json",
    )
    parser.add_argument(
        "--output-file",
        default="",
        help="Optional custom JSON output path",
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


def collect_package_versions() -> Dict[str, str]:
    return {name: dist_version(name) for name in CORE_PACKAGES}


def parse_doctor_summary(stdout: str) -> Dict[str, object]:
    summary: Dict[str, object] = {
        "PASS": None,
        "WARN": None,
        "FAIL": None,
        "Overall": "unknown",
    }

    for raw_line in stdout.splitlines():
        line = raw_line.strip()
        if line.startswith("PASS:"):
            try:
                summary["PASS"] = int(line.split(":", 1)[1].strip())
            except ValueError:
                summary["PASS"] = line.split(":", 1)[1].strip()
        elif line.startswith("WARN:"):
            try:
                summary["WARN"] = int(line.split(":", 1)[1].strip())
            except ValueError:
                summary["WARN"] = line.split(":", 1)[1].strip()
        elif line.startswith("FAIL:"):
            try:
                summary["FAIL"] = int(line.split(":", 1)[1].strip())
            except ValueError:
                summary["FAIL"] = line.split(":", 1)[1].strip()
        elif line.startswith("Overall:"):
            summary["Overall"] = line.split(":", 1)[1].strip()

    return summary


def run_doctor(config_path: Path, skip_model_load: bool) -> Dict[str, object]:
    command: List[str] = [
        sys.executable,
        str(DOCTOR_PATH),
        "--config",
        str(config_path),
    ]
    if skip_model_load:
        command.append("--skip-model-load")

    result = subprocess.run(
        command,
        cwd=str(PROJECT_ROOT),
        capture_output=True,
        text=True,
    )

    return {
        "command": command,
        "exit_code": result.returncode,
        "summary": parse_doctor_summary(result.stdout),
        "stdout": result.stdout,
        "stderr": result.stderr,
    }


def resolve_output_path(args: argparse.Namespace) -> Path | None:
    if args.output_file:
        output_path = Path(args.output_file)
        if not output_path.is_absolute():
            output_path = PROJECT_ROOT / output_path
        return output_path
    if args.output_json:
        return DEFAULT_OUTPUT_PATH
    return None


def main() -> int:
    args = parse_args()

    config_path = Path(args.config)
    if not config_path.is_absolute():
        config_path = PROJECT_ROOT / config_path

    package_versions = collect_package_versions()
    doctor = run_doctor(config_path=config_path, skip_model_load=bool(args.skip_model_load))

    report = {
        "generated_at_utc": datetime.now(timezone.utc).isoformat(),
        "workspace_root": str(PROJECT_ROOT),
        "python": {
            "version": sys.version.split()[0],
            "executable": str(Path(sys.executable).resolve()),
            "platform": platform.platform(),
            "virtual_env": os.environ.get("VIRTUAL_ENV", ""),
        },
        "core_package_versions": package_versions,
        "doctor": doctor,
        "runtime_ready": bool(doctor["summary"].get("Overall") == "PASS"),
    }

    print("Runtime Report")
    print("==============")
    print(f"Python version: {report['python']['version']}")
    print(f"Python executable: {report['python']['executable']}")
    print(f"VIRTUAL_ENV: {report['python']['virtual_env'] or '(not set)'}")
    print("Core package versions:")
    for package_name in CORE_PACKAGES:
        print(f"- {package_name}: {package_versions[package_name]}")

    summary = doctor["summary"]
    print("Doctor summary:")
    print(f"- exit_code: {doctor['exit_code']}")
    print(f"- PASS: {summary.get('PASS')}")
    print(f"- WARN: {summary.get('WARN')}")
    print(f"- FAIL: {summary.get('FAIL')}")
    print(f"- Overall: {summary.get('Overall')}")

    output_path = resolve_output_path(args)
    if output_path is not None:
        output_path.parent.mkdir(parents=True, exist_ok=True)
        output_path.write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
        print(f"JSON report written: {rel_path(output_path)}")

    return int(doctor["exit_code"])


if __name__ == "__main__":
    raise SystemExit(main())

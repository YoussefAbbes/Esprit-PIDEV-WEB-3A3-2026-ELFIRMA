#!/usr/bin/env python3
"""Train and export a crop recommendation classifier.

This script compares multiple tabular models on Crop_recommendation.csv,
selects the best one using validation macro F1 (tie-break: accuracy),
then exports:
- a serialized model (.joblib)
- rich metadata (JSON) used by the Symfony integration layer
"""

from __future__ import annotations

import argparse
import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List

import joblib
import numpy as np
import pandas as pd
from sklearn.ensemble import ExtraTreesClassifier, GradientBoostingClassifier, RandomForestClassifier
from sklearn.metrics import accuracy_score, classification_report, confusion_matrix, f1_score
from sklearn.model_selection import train_test_split

RANDOM_SEED = 42
FEATURES = ["N", "P", "K", "temperature", "humidity", "ph", "rainfall"]
TARGET = "label"


def _as_float(value: Any) -> float:
    return float(np.round(float(value), 6))


def _evaluate(model: Any, x_data: pd.DataFrame, y_data: pd.Series) -> Dict[str, Any]:
    predictions = model.predict(x_data)
    return {
        "accuracy": _as_float(accuracy_score(y_data, predictions)),
        "macro_f1": _as_float(f1_score(y_data, predictions, average="macro")),
    }

def _safe_model_name(model: Any) -> str:
    return model.__class__.__name__


def _maybe_add_xgboost(models: Dict[str, Any]) -> None:
    try:
        from xgboost import XGBClassifier  # type: ignore

        models["XGBoost"] = XGBClassifier(
            n_estimators=350,
            max_depth=6,
            learning_rate=0.08,
            subsample=0.9,
            colsample_bytree=0.9,
            objective="multi:softprob",
            eval_metric="mlogloss",
            random_state=RANDOM_SEED,
            n_jobs=-1,
        )
    except Exception:
        # Optional dependency: silently skip when unavailable.
        return


def _build_models() -> Dict[str, Any]:
    models: Dict[str, Any] = {
        "RandomForest": RandomForestClassifier(
            n_estimators=450,
            max_depth=None,
            min_samples_leaf=1,
            random_state=RANDOM_SEED,
            n_jobs=-1,
            class_weight="balanced",
        ),
        "GradientBoosting": GradientBoostingClassifier(
            n_estimators=300,
            learning_rate=0.08,
            max_depth=3,
            random_state=RANDOM_SEED,
        ),
        "ExtraTrees": ExtraTreesClassifier(
            n_estimators=500,
            max_depth=None,
            min_samples_leaf=1,
            random_state=RANDOM_SEED,
            n_jobs=-1,
            class_weight="balanced",
        ),
    }
    _maybe_add_xgboost(models)
    return models


def _feature_importance(model: Any, feature_names: List[str]) -> List[Dict[str, Any]]:
    if hasattr(model, "feature_importances_"):
        values = np.asarray(model.feature_importances_, dtype=float)
    elif hasattr(model, "coef_"):
        coef = np.asarray(model.coef_, dtype=float)
        values = np.mean(np.abs(coef), axis=0)
    else:
        values = np.ones(len(feature_names), dtype=float)

    values = values / max(values.sum(), 1e-9)
    ranked = sorted(
        [
            {
                "feature": feature,
                "importance": _as_float(score),
            }
            for feature, score in zip(feature_names, values)
        ],
        key=lambda item: item["importance"],
        reverse=True,
    )
    return ranked


def _class_profiles(df: pd.DataFrame, feature_names: List[str]) -> Dict[str, Any]:
    profiles: Dict[str, Any] = {}
    grouped = df.groupby(TARGET)

    for crop, group in grouped:
        means = {feature: _as_float(group[feature].mean()) for feature in feature_names}
        stds = {
            feature: _as_float(group[feature].std(ddof=0) if not np.isnan(group[feature].std(ddof=0)) else 0.0)
            for feature in feature_names
        }
        mins = {feature: _as_float(group[feature].min()) for feature in feature_names}
        maxs = {feature: _as_float(group[feature].max()) for feature in feature_names}

        profiles[str(crop)] = {
            "means": means,
            "stds": stds,
            "mins": mins,
            "maxs": maxs,
            "sample_count": int(group.shape[0]),
        }

    return profiles


def train_and_export(dataset_path: Path, output_model_path: Path, output_metadata_path: Path) -> Dict[str, Any]:
    df = pd.read_csv(dataset_path)

    missing = [column for column in FEATURES + [TARGET] if column not in df.columns]
    if missing:
        raise ValueError(f"Dataset is missing required columns: {missing}")

    # Ensure numeric feature columns.
    for feature in FEATURES:
        df[feature] = pd.to_numeric(df[feature], errors="coerce")
    df = df.dropna(subset=FEATURES + [TARGET]).reset_index(drop=True)

    x = df[FEATURES]
    y = df[TARGET].astype(str)

    x_train_val, x_test, y_train_val, y_test = train_test_split(
        x,
        y,
        test_size=0.20,
        random_state=RANDOM_SEED,
        stratify=y,
    )
    x_train, x_val, y_train, y_val = train_test_split(
        x_train_val,
        y_train_val,
        test_size=0.25,
        random_state=RANDOM_SEED,
        stratify=y_train_val,
    )

    models = _build_models()
    comparison: List[Dict[str, Any]] = []

    best_name = ""
    best_model: Any = None
    best_val_macro_f1 = -1.0
    best_val_accuracy = -1.0

    for name, model in models.items():
        model.fit(x_train, y_train)
        val_metrics = _evaluate(model, x_val, y_val)

        row = {
            "name": name,
            "model_class": _safe_model_name(model),
            "validation": val_metrics,
        }
        comparison.append(row)

        better_macro = val_metrics["macro_f1"] > best_val_macro_f1
        macro_tie_better_acc = (
            np.isclose(val_metrics["macro_f1"], best_val_macro_f1)
            and val_metrics["accuracy"] > best_val_accuracy
        )
        if better_macro or macro_tie_better_acc:
            best_name = name
            best_model = model
            best_val_macro_f1 = val_metrics["macro_f1"]
            best_val_accuracy = val_metrics["accuracy"]

    if best_model is None:
        raise RuntimeError("No model could be trained.")

    # Retrain selected model on train + validation for final test evaluation.
    best_model.fit(x_train_val, y_train_val)
    test_predictions = best_model.predict(x_test)

    test_accuracy = _as_float(accuracy_score(y_test, test_predictions))
    test_macro_f1 = _as_float(f1_score(y_test, test_predictions, average="macro"))

    labels = sorted(y.unique().tolist())
    cm = confusion_matrix(y_test, test_predictions, labels=labels)
    report = classification_report(
        y_test,
        test_predictions,
        labels=labels,
        output_dict=True,
        zero_division=0,
    )

    output_model_path.parent.mkdir(parents=True, exist_ok=True)
    output_metadata_path.parent.mkdir(parents=True, exist_ok=True)

    joblib.dump(best_model, output_model_path)

    metadata = {
        "created_at": datetime.now(timezone.utc).isoformat(),
        "dataset": str(dataset_path),
        "random_seed": RANDOM_SEED,
        "feature_names": FEATURES,
        "target_name": TARGET,
        "class_labels": labels,
        "split": {
            "train_size": int(x_train.shape[0]),
            "validation_size": int(x_val.shape[0]),
            "test_size": int(x_test.shape[0]),
        },
        "model_comparison": comparison,
        "selected_model": {
            "name": best_name,
            "model_class": _safe_model_name(best_model),
            "selection_rule": "Highest validation macro_f1, tie-break on validation accuracy",
        },
        "test_metrics": {
            "accuracy": test_accuracy,
            "macro_f1": test_macro_f1,
            "confusion_matrix": {
                "labels": labels,
                "matrix": cm.tolist(),
            },
            "classification_report": report,
        },
        "feature_importance": _feature_importance(best_model, FEATURES),
        "class_profiles": _class_profiles(df, FEATURES),
        "global_feature_stats": {
            "means": {feature: _as_float(df[feature].mean()) for feature in FEATURES},
            "stds": {
                feature: _as_float(df[feature].std(ddof=0) if not np.isnan(df[feature].std(ddof=0)) else 0.0)
                for feature in FEATURES
            },
            "mins": {feature: _as_float(df[feature].min()) for feature in FEATURES},
            "maxs": {feature: _as_float(df[feature].max()) for feature in FEATURES},
        },
    }

    with output_metadata_path.open("w", encoding="utf-8") as fp:
        json.dump(metadata, fp, indent=2)

    return metadata


def main() -> None:
    parser = argparse.ArgumentParser(description="Train crop recommendation model")
    parser.add_argument(
        "--dataset",
        type=Path,
        default=Path("Crop_recommendation.csv"),
        help="Path to CSV dataset",
    )
    parser.add_argument(
        "--model-output",
        type=Path,
        default=Path("ml/crop_recommendation/best_model.joblib"),
        help="Output path for serialized model",
    )
    parser.add_argument(
        "--metadata-output",
        type=Path,
        default=Path("ml/crop_recommendation/model_metadata.json"),
        help="Output path for JSON metadata",
    )
    args = parser.parse_args()

    metadata = train_and_export(
        dataset_path=args.dataset,
        output_model_path=args.model_output,
        output_metadata_path=args.metadata_output,
    )

    selected = metadata["selected_model"]
    test_metrics = metadata["test_metrics"]

    print("Training completed successfully")
    print(f"Selected model: {selected['name']} ({selected['model_class']})")
    print(f"Test accuracy: {test_metrics['accuracy']:.4f}")
    print(f"Test macro F1: {test_metrics['macro_f1']:.4f}")
    print(f"Model file: {args.model_output}")
    print(f"Metadata file: {args.metadata_output}")


if __name__ == "__main__":
    main()

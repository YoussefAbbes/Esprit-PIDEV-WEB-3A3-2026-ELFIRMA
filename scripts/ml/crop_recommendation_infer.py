#!/usr/bin/env python3
"""Run inference for the crop recommendation model.

Input and output are JSON to keep integration simple from Symfony.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any, Dict, List

import joblib
import numpy as np
import pandas as pd

FEATURES = ["N", "P", "K", "temperature", "humidity", "ph", "rainfall"]


def _to_float(value: Any) -> float:
    return float(np.round(float(value), 6))


def _load_json(path: Path) -> Dict[str, Any]:
    with path.open("r", encoding="utf-8") as fp:
        return json.load(fp)


def _validate_input(payload: Dict[str, Any]) -> Dict[str, float]:
    parsed: Dict[str, float] = {}
    missing: List[str] = []

    for feature in FEATURES:
        if feature not in payload:
            missing.append(feature)
            continue
        try:
            parsed[feature] = float(payload[feature])
        except Exception as exc:  # pragma: no cover - explicit message is enough
            raise ValueError(f"Invalid value for '{feature}': {payload[feature]}") from exc

    if missing:
        raise ValueError(f"Missing required features: {missing}")

    return parsed


def _top_predictions(probabilities: np.ndarray, classes: List[str], top_n: int = 3) -> List[Dict[str, Any]]:
    order = np.argsort(probabilities)[::-1][:top_n]
    result = []
    for idx in order:
        result.append({
            "crop": classes[int(idx)],
            "probability": _to_float(probabilities[int(idx)]),
        })
    return result


def _local_explanation(
    input_features: Dict[str, float],
    predicted_crop: str,
    metadata: Dict[str, Any],
) -> Dict[str, Any]:
    class_profiles = metadata.get("class_profiles", {})
    predicted_profile = class_profiles.get(predicted_crop, {})
    means = predicted_profile.get("means", {})
    stds = predicted_profile.get("stds", {})

    global_stds = metadata.get("global_feature_stats", {}).get("stds", {})
    importance = {
        row["feature"]: float(row["importance"])
        for row in metadata.get("feature_importance", [])
        if "feature" in row and "importance" in row
    }

    feature_scores: List[Dict[str, Any]] = []
    for feature in FEATURES:
        value = float(input_features[feature])
        class_mean = float(means.get(feature, value))
        class_std = float(stds.get(feature, 0.0))
        global_std = float(global_stds.get(feature, 1.0))
        scale = max(class_std, global_std * 0.5, 1e-6)

        z_distance = abs(value - class_mean) / scale
        alignment = max(0.0, 1.0 - min(z_distance, 3.0) / 3.0)
        weighted_alignment = alignment * float(importance.get(feature, 1.0 / len(FEATURES)))

        feature_scores.append(
            {
                "feature": feature,
                "value": _to_float(value),
                "class_mean": _to_float(class_mean),
                "alignment": _to_float(alignment),
                "z_distance": _to_float(z_distance),
                "weighted_alignment": _to_float(weighted_alignment),
            }
        )

    supporting = sorted(feature_scores, key=lambda row: row["weighted_alignment"], reverse=True)[:3]
    limiting = sorted(feature_scores, key=lambda row: row["z_distance"], reverse=True)[:2]

    support_lines = [
        f"{row['feature']}={row['value']} is close to typical {predicted_crop} conditions (mean {row['class_mean']})."
        for row in supporting
    ]

    limiting_lines = []
    for row in limiting:
        if row["z_distance"] >= 1.0:
            limiting_lines.append(
                f"{row['feature']}={row['value']} differs from the usual {predicted_crop} profile (mean {row['class_mean']}); monitoring is advised."
            )

    summary = (
        f"{predicted_crop} is recommended because the parcel conditions align with the learned profile, especially on "
        f"{supporting[0]['feature']}, {supporting[1]['feature']}, and {supporting[2]['feature']}."
    )

    return {
        "summary": summary,
        "supporting_factors": support_lines,
        "limiting_factors": limiting_lines,
        "feature_alignment": feature_scores,
    }


def _agronomic_advice(input_features: Dict[str, float], predicted_crop: str, metadata: Dict[str, Any]) -> List[str]:
    class_profile = metadata.get("class_profiles", {}).get(predicted_crop, {})
    means = class_profile.get("means", {})

    advice: List[str] = []

    rainfall_value = input_features["rainfall"]
    rainfall_target = float(means.get("rainfall", rainfall_value))
    if rainfall_value < rainfall_target * 0.8:
        advice.append("Rainfall is below the typical level for this crop; consider an irrigation plan.")
    elif rainfall_value > rainfall_target * 1.25:
        advice.append("Rainfall is above the typical level; monitor drainage and root oxygenation.")

    ph_value = input_features["ph"]
    ph_target = float(means.get("ph", ph_value))
    if abs(ph_value - ph_target) > 0.7:
        advice.append("Soil pH is outside the usual range for this crop profile; consider a pH correction strategy.")

    for nutrient in ["N", "P", "K"]:
        val = input_features[nutrient]
        target = float(means.get(nutrient, val))
        if val < target * 0.8:
            advice.append(f"{nutrient} is lower than the typical demand; adjust fertilization before planting.")

    if not advice:
        advice.append("Current agronomic conditions are close to the target crop profile; maintain standard monitoring.")

    return advice[:4]


def infer(model_path: Path, metadata_path: Path, input_payload: Dict[str, Any]) -> Dict[str, Any]:
    metadata = _load_json(metadata_path)
    parsed = _validate_input(input_payload)

    model = joblib.load(model_path)

    ordered_values = [parsed[feature] for feature in FEATURES]
    x = pd.DataFrame([ordered_values], columns=FEATURES)

    if hasattr(model, "predict_proba"):
        probabilities = model.predict_proba(x)[0]
        classes = [str(item) for item in model.classes_]
    else:
        prediction = str(model.predict(x)[0])
        classes = [str(item) for item in metadata.get("class_labels", [prediction])]
        probabilities = np.zeros(len(classes), dtype=float)
        if prediction in classes:
            probabilities[classes.index(prediction)] = 1.0
        else:
            classes = [prediction]
            probabilities = np.asarray([1.0], dtype=float)

    top_predictions = _top_predictions(probabilities, classes, top_n=3)
    recommended = top_predictions[0]["crop"]
    confidence = top_predictions[0]["probability"]

    explanation = _local_explanation(parsed, recommended, metadata)
    advice = _agronomic_advice(parsed, recommended, metadata)

    return {
        "recommended_crop": recommended,
        "confidence": confidence,
        "top_predictions": top_predictions,
        "explanation": explanation,
        "feature_importance": metadata.get("feature_importance", []),
        "agronomic_advice": advice,
        "model": {
            "selected_name": metadata.get("selected_model", {}).get("name"),
            "selected_class": metadata.get("selected_model", {}).get("model_class"),
            "test_accuracy": metadata.get("test_metrics", {}).get("accuracy"),
            "test_macro_f1": metadata.get("test_metrics", {}).get("macro_f1"),
        },
        "input": {feature: _to_float(value) for feature, value in parsed.items()},
    }


def main() -> None:
    import sys

    parser = argparse.ArgumentParser(description="Crop recommendation inference")
    parser.add_argument("--model", type=Path, required=True, help="Path to .joblib model")
    parser.add_argument("--metadata", type=Path, required=True, help="Path to metadata JSON")
    parser.add_argument("--input-json", type=str, required=True,
                        help="Input features as JSON object, or '-' to read from stdin")
    args = parser.parse_args()

    if args.input_json == "-":
        payload = json.loads(sys.stdin.read())
    else:
        payload = json.loads(args.input_json)

    result = infer(args.model, args.metadata, payload)
    print(json.dumps(result))


if __name__ == "__main__":
    main()

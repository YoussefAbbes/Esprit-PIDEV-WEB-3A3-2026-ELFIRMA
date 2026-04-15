# Crop Recommendation ML Scripts

This folder contains the machine learning pipeline used by the field management module.

## Files

- `train_crop_recommendation.py`
  - Trains and compares multiple classifiers on `Crop_recommendation.csv`
  - Uses train/validation/test split
  - Exports best model + metadata

- `crop_recommendation_infer.py`
  - Loads exported model and metadata
  - Runs one prediction from JSON input
  - Returns recommendation, confidence, top-3, explanation, and advice

## Training Command

```bash
py -3 scripts/ml/train_crop_recommendation.py --dataset Crop_recommendation.csv --model-output ml/crop_recommendation/best_model.joblib --metadata-output ml/crop_recommendation/model_metadata.json
```

## Inference Command Example

```bash
py -3 scripts/ml/crop_recommendation_infer.py --model ml/crop_recommendation/best_model.joblib --metadata ml/crop_recommendation/model_metadata.json --input-json "{\"N\":90,\"P\":42,\"K\":43,\"temperature\":21,\"humidity\":82,\"ph\":6.5,\"rainfall\":203}"
```

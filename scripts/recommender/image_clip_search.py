#!/usr/bin/env python
import argparse
import json
import os
from pathlib import Path


def _emit(payload, exit_code=0):
    print(json.dumps(payload, ensure_ascii=True))
    raise SystemExit(exit_code)


def _collect_image_files(images_dir: Path):
    exts = {".jpg", ".jpeg", ".png", ".webp"}
    files = []
    if not images_dir.exists():
        return files
    for p in images_dir.iterdir():
        if p.is_file() and p.suffix.lower() in exts:
            files.append(p)
    return sorted(files)


def main():
    parser = argparse.ArgumentParser(description="CLIP image similarity search for products")
    parser.add_argument("--query-image", required=True)
    parser.add_argument("--project-dir", required=True)
    parser.add_argument("--top-k", type=int, default=24)
    args = parser.parse_args()

    query_image = Path(args.query_image)
    project_dir = Path(args.project_dir)
    images_dir = project_dir / "public" / "uploads" / "produits"

    if not query_image.exists():
        _emit({"ok": False, "message": "Query image not found."}, 1)

    candidates = _collect_image_files(images_dir)
    if not candidates:
        _emit({"ok": True, "message": "No product images found.", "results": []}, 0)

    try:
        import torch
        from PIL import Image
        from transformers import CLIPModel, CLIPProcessor
    except Exception as exc:
        _emit({
            "ok": False,
            "message": "CLIP dependencies not installed. Install: pip install torch transformers pillow",
            "error": str(exc),
        }, 1)

    model_name = "openai/clip-vit-base-patch32"

    try:
        processor = CLIPProcessor.from_pretrained(model_name)
        model = CLIPModel.from_pretrained(model_name)
        model.eval()

        with Image.open(query_image) as q_img:
            q_img = q_img.convert("RGB")
            q_inputs = processor(images=q_img, return_tensors="pt")

        with torch.no_grad():
            q_features = model.get_image_features(**q_inputs)
            q_features = q_features / q_features.norm(dim=-1, keepdim=True)

        results = []
        top_k = max(1, min(100, int(args.top_k)))

        for image_path in candidates:
            try:
                with Image.open(image_path) as p_img:
                    p_img = p_img.convert("RGB")
                    p_inputs = processor(images=p_img, return_tensors="pt")

                with torch.no_grad():
                    p_features = model.get_image_features(**p_inputs)
                    p_features = p_features / p_features.norm(dim=-1, keepdim=True)
                    similarity = torch.sum(q_features * p_features, dim=-1).item()

                results.append({
                    "image": image_path.name,
                    "similarity": float(similarity),
                })
            except Exception:
                continue

        results.sort(key=lambda x: x["similarity"], reverse=True)
        results = results[:top_k]

        _emit({
            "ok": True,
            "engine": "clip",
            "model": model_name,
            "message": "CLIP similarity search completed.",
            "results": results,
        }, 0)
    except Exception as exc:
        _emit({"ok": False, "message": "CLIP inference failed.", "error": str(exc)}, 1)


if __name__ == "__main__":
    main()

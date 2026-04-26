#!/usr/bin/env python3
"""
Image similarity search using CLIP embeddings.
"""

import json
import sys
import os
from pathlib import Path

try:
    import numpy as np
    from PIL import Image
    import torch
    from transformers import CLIPProcessor, CLIPModel
except ImportError as e:
    print(json.dumps({
        'ok': False,
        'error': f'Missing required library: {str(e)}'
    }), file=sys.stderr)
    sys.exit(1)


def load_image(image_path: str) -> Image.Image | None:
    """Load an image from file."""
    try:
        img = Image.open(image_path)
        if img.mode != 'RGB':
            img = img.convert('RGB')
        return img
    except Exception as e:
        print(f'Error loading image {image_path}: {e}', file=sys.stderr)
        return None


def get_clip_embedding(image_path: str, model, processor) -> np.ndarray | None:
    """Extract CLIP embedding from image."""
    img = load_image(image_path)
    if img is None:
        return None

    try:
        inputs = processor(images=img, return_tensors='pt')
        with torch.no_grad():
            image_features = model.get_image_features(**inputs)
        embedding = image_features[0].cpu().numpy()
        embedding = embedding / (np.linalg.norm(embedding) + 1e-8)
        return embedding
    except Exception as e:
        print(f'Error extracting embedding from {image_path}: {e}', file=sys.stderr)
        return None


def compute_similarity(embedding1: np.ndarray, embedding2: np.ndarray) -> float:
    """Compute cosine similarity between two embeddings."""
    if embedding1 is None or embedding2 is None:
        return 0.0
    return float(np.dot(embedding1, embedding2))


def main():
    """Main function."""
    if len(sys.argv) < 2:
        print(json.dumps({
            'ok': False,
            'error': 'Usage: python image_clip_search.py <query_image> <product_images_json> OR --payload-file <path>'
        }), file=sys.stderr)
        sys.exit(1)

    query_image = ''
    products = []

    if sys.argv[1] == '--payload-file':
        if len(sys.argv) < 3:
            print(json.dumps({
                'ok': False,
                'error': 'Missing payload file path.'
            }), file=sys.stderr)
            sys.exit(1)

        payload_file = sys.argv[2]
        if not os.path.isfile(payload_file):
            print(json.dumps({
                'ok': False,
                'error': f'Payload file not found: {payload_file}'
            }), file=sys.stderr)
            sys.exit(1)

        try:
            with open(payload_file, 'r', encoding='utf-8') as fh:
                payload = json.load(fh)
        except Exception as e:
            print(json.dumps({
                'ok': False,
                'error': f'Invalid payload file: {str(e)}'
            }), file=sys.stderr)
            sys.exit(1)

        query_image = str(payload.get('query_image') or '')
        products = payload.get('products') if isinstance(payload.get('products'), list) else []
    else:
        if len(sys.argv) < 3:
            print(json.dumps({
                'ok': False,
                'error': 'Missing <product_images_json> argument.'
            }), file=sys.stderr)
            sys.exit(1)

        query_image = sys.argv[1]
        products_json = sys.argv[2]

        try:
            products = json.loads(products_json)
        except json.JSONDecodeError as e:
            print(json.dumps({
                'ok': False,
                'error': f'Invalid JSON: {str(e)}'
            }), file=sys.stderr)
            sys.exit(1)

    if not os.path.isfile(query_image):
        print(json.dumps({
            'ok': False,
            'error': f'Query image not found: {query_image}'
        }), file=sys.stderr)
        sys.exit(1)

    try:
        # Load CLIP model
        model = CLIPModel.from_pretrained('openai/clip-vit-base-patch32')
        processor = CLIPProcessor.from_pretrained('openai/clip-vit-base-patch32')

        # Get query embedding
        query_embedding = get_clip_embedding(query_image, model, processor)
        if query_embedding is None:
            print(json.dumps({
                'ok': False,
                'error': 'Failed to extract embedding from query image'
            }), file=sys.stderr)
            sys.exit(1)

        # Compute similarities
        results = []
        for product in products:
            product_id = product.get('id')
            product_name = product.get('name')
            image_path = product.get('image_path')

            if not image_path or not os.path.isfile(image_path):
                continue

            product_embedding = get_clip_embedding(image_path, model, processor)
            if product_embedding is None:
                continue

            similarity = compute_similarity(query_embedding, product_embedding)
            if similarity > 0.15:  # Only keep meaningful matches
                results.append({
                    'id': product_id,
                    'name': product_name,
                    'similarity': round(float(similarity), 4)
                })

        # Sort by similarity descending
        results.sort(key=lambda x: x['similarity'], reverse=True)

        print(json.dumps({
            'ok': True,
            'count': len(results),
            'results': results[:24]
        }))

    except Exception as e:
        print(json.dumps({
            'ok': False,
            'error': f'Error during CLIP inference: {str(e)}'
        }), file=sys.stderr)
        sys.exit(1)


if __name__ == '__main__':
    main()
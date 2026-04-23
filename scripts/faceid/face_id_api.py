import argparse
import base64
import glob
import json
import os
import time
import urllib.request
from typing import Dict, List, Optional, Tuple

import cv2
import numpy as np
from flask import Flask, jsonify, request


class FaceEngine:
    def __init__(self, storage_dir: str, models_dir: str, threshold: float) -> None:
        self.storage_dir = storage_dir
        self.models_dir = models_dir
        self.threshold = threshold

        self._known_cache: Dict[int, Dict] = {}
        self._last_scan = 0.0

        # Use pre-trained cascade classifier for face detection
        cascade_path = cv2.data.haarcascades + 'haarcascade_frontalface_default.xml'
        self.cascade_detector = cv2.CascadeClassifier(cascade_path)

        if self.cascade_detector.empty():
            raise RuntimeError("Failed to load cascade classifier")

        # Try to load ONNX models for better accuracy
        self.yunet_detector = None
        self.recognizer_onnx = None
        self.has_onnx = False
        try:
            models_dir = os.path.normpath(models_dir)
            detector_path = os.path.join(models_dir, "face_detection_yunet_2023mar.onnx")
            recognizer_path = os.path.join(models_dir, "face_recognition_sface_2021dec.onnx")

            _download_if_missing(
                detector_path,
                "https://github.com/opencv/opencv_zoo/raw/refs/heads/main/models/face_detection_yunet/face_detection_yunet_2023mar.onnx",
            )
            _download_if_missing(
                recognizer_path,
                "https://github.com/opencv/opencv_zoo/raw/refs/heads/main/models/face_recognition_sface/face_recognition_sface_2021dec.onnx",
            )

            self.yunet_detector = cv2.FaceDetectorYN.create(detector_path, "", (320, 320), 0.9, 0.3, 5000)
            self.recognizer_onnx = cv2.FaceRecognizerSF.create(recognizer_path, "")
            self.has_onnx = True
            print("ONNX models loaded successfully for better face recognition")
        except Exception as e:
            print(f"⚠️ ONNX models not available: {e}. Using Haar Cascade fallback.")
            self.has_onnx = False

    def detect(self, image_base64: str) -> Dict:
        """Detect faces in image"""
        try:
            img = _decode_image(image_base64)
            if img is None:
                return {"ok": False, "error": "Invalid image"}

            # Try ONNX first, fallback to Haar Cascade
            faces = []
            if self.has_onnx and self.yunet_detector:
                faces = self._detect_onnx(img)
            if not faces:
                faces = self._detect_cascade(img)

            if not faces:
                return {"ok": True, "faceFound": False}

            # Return first face
            x, y, w, h = faces[0]
            face_roi = img[int(y) : int(y + h), int(x) : int(x + w)]

            embedding = None
            if self.has_onnx and self.recognizer_onnx:
                embedding = self._extract_embedding_onnx(face_roi)
            if embedding is None:
                embedding = self._extract_embedding_hog(face_roi)

            return {
                "ok": True,
                "faceFound": True,
                "bbox": {"x": int(x), "y": int(y), "w": int(w), "h": int(h)},
                "embedding": embedding,
            }
        except Exception as e:
            return {"ok": False, "error": str(e)}

    def recognize(self, image_base64: str) -> Dict:
        """Recognize face in image"""
        try:
            img = _decode_image(image_base64)
            if img is None:
                return {"ok": False, "error": "Invalid image"}

            # Detect faces
            faces = []
            if self.has_onnx and self.yunet_detector:
                faces = self._detect_onnx(img)
            if not faces:
                faces = self._detect_cascade(img)

            if not faces:
                return {"ok": True, "faceFound": False}

            # Get first face
            x, y, w, h = faces[0]
            face_roi = img[int(y) : int(y + h), int(x) : int(x + w)]

            # Extract embedding
            embedding = None
            if self.has_onnx and self.recognizer_onnx:
                embedding = self._extract_embedding_onnx(face_roi)
            if embedding is None:
                embedding = self._extract_embedding_hog(face_roi)

            if embedding is None:
                return {"ok": True, "faceFound": True, "recognized": False}

            # Compare with known faces
            match = self._find_match(embedding)

            return {
                "ok": True,
                "faceFound": True,
                "recognized": match is not None,
                "match": match,
                "bbox": {"x": int(x), "y": int(y), "w": int(w), "h": int(h)},
            }
        except Exception as e:
            return {"ok": False, "error": str(e)}

    def _detect_onnx(self, img: np.ndarray) -> List[Tuple]:
        """Detect using ONNX YuNet"""
        if not self.yunet_detector:
            return []
        try:
            h, w = img.shape[:2]
            self.yunet_detector.setInputSize((w, h))
            _, faces = self.yunet_detector.detect(img)
            if faces is None or len(faces) == 0:
                return []
            return [(f[0], f[1], f[2], f[3]) for f in faces]
        except:
            return []

    def _detect_cascade(self, img: np.ndarray) -> List[Tuple]:
        """Detect using Haar Cascade fallback"""
        gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
        faces = self.cascade_detector.detectMultiScale(gray, 1.3, 5)
        return [(x, y, w, h) for x, y, w, h in faces]

    def _extract_embedding_onnx(self, face_roi: np.ndarray) -> Optional[List]:
        """Extract 128D embedding using ONNX model"""
        if not self.recognizer_onnx:
            return None
        try:
            embedding = self.recognizer_onnx.feature(face_roi)
            if embedding is None or len(embedding) == 0:
                return None
            return embedding[0].tolist()
        except:
            return None

    def _extract_embedding_hog(self, face_roi: np.ndarray) -> Optional[List]:
        """Extract HOG features as fallback"""
        try:
            face_roi = cv2.resize(face_roi, (64, 64))
            win_size = (64, 64)
            cell_size = (8, 8)
            block_size = (2, 2)
            nbins = 9
            hog = cv2.HOGDescriptor(win_size, (16, 16), (8, 8), (8, 8), 9)
            hist = hog.compute(face_roi)
            return hist.flatten().tolist() if hist is not None else None
        except:
            return None

    def _find_match(self, embedding: List) -> Optional[Dict]:
        """Find matching user for embedding"""
        self._load_known_faces()

        if not self._known_cache:
            return None

        best_match = None
        best_score = -1

        for user_id, user_data in self._known_cache.items():
            for known_emb in user_data.get("embeddings", []):
                score = self._compare_embeddings(embedding, known_emb)
                if score > best_score:
                    best_score = score
                    best_match = user_data
                    best_match["userId"] = user_id

        threshold = self.threshold if self.has_onnx else (self.threshold * 0.6)
        recognized = best_match is not None and best_score >= threshold

        return best_match if recognized else None

    def _compare_embeddings(self, emb1: List, emb2: List) -> float:
        """Compare two embeddings using cosine similarity"""
        emb1 = np.array(emb1)
        emb2 = np.array(emb2)
        norm1 = np.linalg.norm(emb1)
        norm2 = np.linalg.norm(emb2)
        if norm1 == 0 or norm2 == 0:
            return 0
        return float(np.dot(emb1, emb2) / (norm1 * norm2))

    def _load_known_faces(self) -> None:
        """Load known face embeddings from storage"""
        cache_time = time.time()
        if cache_time - self._last_scan < 5:
            return

        self._known_cache.clear()
        for file in glob.glob(os.path.join(self.storage_dir, "user_*.json")):
            try:
                with open(file, "r") as f:
                    data = json.load(f)
                    user_id = data.get("user_id")
                    if user_id:
                        self._known_cache[user_id] = data
            except:
                pass

        self._last_scan = cache_time


def _decode_image(base64_str: str) -> Optional[np.ndarray]:
    """Decode base64 image"""
    try:
        if "," in base64_str:
            base64_str = base64_str.split(",")[1]
        img_data = base64.b64decode(base64_str)
        nparr = np.frombuffer(img_data, np.uint8)
        return cv2.imdecode(nparr, cv2.IMREAD_COLOR)
    except:
        return None


def _download_if_missing(file_path: str, url: str) -> None:
    """Download file if missing"""
    if os.path.exists(file_path):
        return
    os.makedirs(os.path.dirname(file_path), exist_ok=True)
    print(f"Downloading {os.path.basename(file_path)}...")
    try:
        # Download with timeout
        urllib.request.urlretrieve(url, file_path)
        print(f"Downloaded to {file_path}")
    except Exception as e:
        print(f"⚠️ Failed to download {os.path.basename(file_path)}: {e}")
        print("Will use Haar Cascade fallback for face detection")


def create_app(storage_dir: str, models_dir: str, threshold: float) -> Flask:
    app = Flask(__name__)
    engine = FaceEngine(storage_dir=storage_dir, models_dir=models_dir, threshold=threshold)

    @app.route("/health", methods=["GET"])
    def health():
        engine._load_known_faces()
        return jsonify({"ok": True, "knownUsers": len(engine._known_cache)})

    @app.route("/detect", methods=["POST"])
    def detect():
        data = request.get_json() or {}
        image = data.get("image", "")
        return jsonify(engine.detect(image))

    @app.route("/recognize", methods=["POST"])
    def recognize():
        data = request.get_json() or {}
        image = data.get("image", "")
        return jsonify(engine.recognize(image))

    return app


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--host", type=str, default="127.0.0.1")
    parser.add_argument("--port", type=int, default=8765)
    parser.add_argument("--storage-dir", type=str, default="var/faceid/encodings")
    parser.add_argument("--models-dir", type=str, default="var/faceid/models")
    parser.add_argument("--threshold", type=float, default=0.28)

    args = parser.parse_args()

    app = create_app(args.storage_dir, args.models_dir, args.threshold)
    app.run(host=args.host, port=args.port, debug=False)
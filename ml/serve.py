"""Loopback-only inference service; uploaded images are decoded in memory, not retained."""
import argparse
import io
import json
import logging
import secrets
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import torch
from flask import Flask, jsonify, request
from PIL import Image, ImageOps, UnidentifiedImageError
from waitress import serve
from werkzeug.exceptions import RequestEntityTooLarge

from model import ROOT, decode_probabilities, load_classifier, preprocessing


def create_app():
    config_path = ROOT / "server" / "model-service.json"
    config = json.loads(config_path.read_text(encoding="utf-8"))
    token = config.get("token", "")
    if len(token) < 32:
        raise ValueError("A strong local service token is required. Run ml/activate.py first.")
    torch.set_num_threads(2)
    model, metadata = load_classifier()
    transform = preprocessing()
    with torch.inference_mode():
        model(torch.zeros(1, 3, 224, 224))
    app = Flask(__name__)
    app.config["MAX_CONTENT_LENGTH"] = 11 * 1024 * 1024

    @app.get("/health")
    def health():
        return jsonify(success=True, ready=True, version=metadata["version"], classes=metadata["classes"])

    @app.post("/predict")
    def predict():
        if not secrets.compare_digest(request.headers.get("Authorization", ""), "Bearer " + token):
            return jsonify(success=False, error="Authentication required."), 401
        image_file = request.files.get("image")
        if image_file is None:
            return jsonify(success=False, error="An image is required."), 400
        raw = image_file.stream.read(10 * 1024 * 1024 + 1)
        if len(raw) > 10 * 1024 * 1024:
            return jsonify(success=False, error="Image must be smaller than 10 MB."), 400
        try:
            with Image.open(io.BytesIO(raw)) as source:
                if source.format not in {"JPEG", "PNG", "WEBP"}:
                    raise ValueError("Unsupported image format")
                if min(source.size) < 64 or max(source.size) > 12000 or source.width * source.height > 40_000_000:
                    raise ValueError("Invalid image dimensions")
                image = ImageOps.exif_transpose(source).convert("RGB")
                image.load()
            image.thumbnail((384, 384), Image.Resampling.LANCZOS)
            tensor = transform(image).unsqueeze(0)
        except (UnidentifiedImageError, OSError, ValueError, Image.DecompressionBombError):
            return jsonify(success=False, error="Please upload a valid JPG, PNG, or WEBP image."), 400
        with torch.inference_mode():
            probabilities = torch.softmax(model(tensor) / metadata["temperature"], dim=1)[0].tolist()
        result = decode_probabilities(probabilities, metadata["classes"])
        return jsonify(success=True, **result, model_version=metadata["version"])

    @app.errorhandler(RequestEntityTooLarge)
    def too_large(error):
        return jsonify(success=False, error="Image request is too large."), 413

    @app.errorhandler(Exception)
    def failure(error):
        app.logger.exception("Inference failed")
        return jsonify(success=False, error="Prediction service is temporarily unavailable."), 503

    return app


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--port", type=int, default=8766)
    args = parser.parse_args()
    logging.basicConfig(level=logging.INFO)
    app = create_app()
    print(f"FruitScan classifier ready at http://127.0.0.1:{args.port}", flush=True)
    serve(app, host="127.0.0.1", port=args.port, threads=2, clear_untrusted_proxy_headers=True)

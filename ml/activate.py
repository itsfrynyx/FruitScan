"""Publish a locally evaluated model's configuration without changing account/scan data."""
import hashlib
import json
import secrets
import os
import shutil
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODEL_DIR = ROOT / "storage" / "ml" / "model"


def main():
    metrics = json.loads((MODEL_DIR / "metrics.json").read_text(encoding="utf-8"))
    if metrics["held_out_test"]["condition_balanced_accuracy"] < 0.70:
        raise ValueError("Model is below the initial demonstration activation threshold; review metrics before enabling it.")
    fruits = ["apple", "banana", "grape", "guava", "jujube", "orange", "pomegranate", "strawberry"]
    manifest = {"version": metrics["version"], "fruit_classes": fruits,
                "training_dataset": "bdd69gyhv8-1 original images", "evaluation": metrics["held_out_test"],
                "limitations": metrics["limitations"], "model_sha256": hashlib.sha256((MODEL_DIR / "classifier.pt").read_bytes()).hexdigest()}
    (ROOT / "server" / "model.json").write_text(json.dumps(manifest, indent=2), encoding="utf-8")
    config_path = ROOT / "server" / "model-service.json"
    config = json.loads(config_path.read_text(encoding="utf-8")) if config_path.exists() else {}
    config.update({"url": "http://127.0.0.1:8766/predict", "token": config.get("token") or secrets.token_urlsafe(48)})
    config_path.write_text(json.dumps(config, indent=2), encoding="utf-8")
    php = os.environ.get("PHP_BINARY") or shutil.which("php") or str(ROOT.parents[1] / "php" / "php.exe")
    subprocess.run([php, str(ROOT / "scripts" / "sync-model-classes.php")], check=True)
    print(f"Configured {metrics['version']} with {len(fruits)} fruit categories. Start scripts/start-model.ps1 to serve predictions.")


if __name__ == "__main__":
    main()

"""Train a regularized classifier on frozen ImageNet features, then evaluate once."""
import argparse
import hashlib
import json
import os
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(Path(__file__).resolve().parent))
os.environ.setdefault("TORCH_HOME", str(ROOT / "storage" / "ml" / "torch-cache"))
os.environ.setdefault("OMP_NUM_THREADS", "4")
os.environ.setdefault("OPENBLAS_NUM_THREADS", "4")

import numpy as np
import torch
from PIL import Image, ImageOps
from scipy.optimize import minimize_scalar
from scipy.special import softmax
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import accuracy_score, balanced_accuracy_score, classification_report, confusion_matrix, f1_score, log_loss
from sklearn.preprocessing import StandardScaler
from torch.utils.data import DataLoader, Dataset

from model import FruitClassifier, MODEL_DIR, decode_probabilities, preprocessing

DATA = ROOT / "storage" / "ml" / "data"


class FruitImages(Dataset):
    def __init__(self, records, mirror=False):
        self.records, self.mirror = records, mirror
        self.transform = preprocessing()

    def __len__(self):
        return len(self.records)

    def __getitem__(self, index):
        with Image.open(DATA / "images" / self.records[index]["image"]) as source:
            rgb = source.convert("RGB")
            if self.mirror:
                rgb = ImageOps.mirror(rgb)
            return self.transform(rgb)


def extract(model, records, mirror=False):
    parts = []
    loader = DataLoader(FruitImages(records, mirror), batch_size=24, shuffle=False, num_workers=0)
    with torch.inference_mode():
        for batch, images in enumerate(loader):
            parts.append(model.backbone(images).numpy())
            if batch % 15 == 0 or batch + 1 == len(loader):
                print(f"Features {'(training mirrors)' if mirror else '(originals)'}: {min((batch+1)*24, len(records))}/{len(records)}", flush=True)
    return np.concatenate(parts)


def wilson(correct, total):
    z = 1.96
    p = correct / total
    denominator = 1 + z * z / total
    center = (p + z * z / (2 * total)) / denominator
    half = z * np.sqrt(p * (1 - p) / total + z * z / (4 * total * total)) / denominator
    return [float(center - half), float(center + half)]


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--threads", type=int, default=4)
    args = parser.parse_args()
    torch.set_num_threads(args.threads)
    torch.manual_seed(42)
    np.random.seed(42)
    start = time.time()
    manifest_bytes = (DATA / "manifest.json").read_bytes()
    manifest = json.loads(manifest_bytes)
    records, classes = manifest["records"], manifest["classes"]
    manifest_hash = hashlib.sha256(manifest_bytes).hexdigest()
    MODEL_DIR.mkdir(parents=True, exist_ok=True)
    model = FruitClassifier(len(classes), pretrained=True).eval()
    indices = {name: np.array([i for i, r in enumerate(records) if r["split"] == name]) for name in ["train", "validation", "test"]}
    y = np.array([r["class_index"] for r in records])
    cache = DATA / "features.npz"
    if cache.exists():
        cached = np.load(cache, allow_pickle=False)
        if str(cached["manifest_hash"]) != manifest_hash:
            raise ValueError("Feature cache does not match the dataset manifest. Remove storage/ml/data/features.npz and retry.")
        features, mirrors = cached["features"], cached["mirrors"]
        print("Reusing verified feature cache.", flush=True)
    else:
        features = extract(model, records)
        mirrors = extract(model, [records[i] for i in indices["train"]], mirror=True)
        np.savez_compressed(cache, features=features, mirrors=mirrors, manifest_hash=manifest_hash)
    # Fit all learned preprocessing strictly on training data.
    train_x = np.concatenate([features[indices["train"]], mirrors])
    train_y = np.tile(y[indices["train"]], 2)
    scaler = StandardScaler().fit(train_x)
    train_x = scaler.transform(train_x)
    validation_x = scaler.transform(features[indices["validation"]])
    validation_y = y[indices["validation"]]
    candidates = []
    best, best_score = None, -1
    for c in [0.01, 0.1, 1.0, 10.0]:
        classifier = LogisticRegression(C=c, max_iter=2000, solver="lbfgs", random_state=42).fit(train_x, train_y)
        predicted = classifier.predict(validation_x)
        score = f1_score(validation_y, predicted, average="macro", zero_division=0)
        row = {"C": c, "validation_macro_f1": float(score), "validation_accuracy": float(accuracy_score(validation_y, predicted))}
        candidates.append(row)
        print(json.dumps(row), flush=True)
        if score > best_score:
            best, best_score = classifier, score
    logits = best.decision_function(validation_x)
    temperature_fit = minimize_scalar(lambda log_t: log_loss(validation_y, softmax(logits / np.exp(log_t), axis=1), labels=list(range(len(classes)))), bounds=(np.log(0.2), np.log(5.0)), method="bounded")
    temperature = float(np.exp(temperature_fit.x))
    # Fold StandardScaler into a linear layer to remove sklearn from inference.
    weights = best.coef_ / scaler.scale_[None, :]
    bias = best.intercept_ - weights @ scaler.mean_
    with torch.no_grad():
        model.head.weight.copy_(torch.from_numpy(weights.astype(np.float32)))
        model.head.bias.copy_(torch.from_numpy(bias.astype(np.float32)))
    version = "fruitscan-mobilenetv3-" + datetime.now(timezone.utc).strftime("%Y%m%d-%H%M%S")
    checkpoint = {"state_dict": model.state_dict(), "classes": classes, "temperature": temperature,
                  "version": version, "manifest_sha256": manifest_hash, "architecture": "mobilenet_v3_small + standardized multinomial logistic head", "input_size": 224}
    torch.save(checkpoint, MODEL_DIR / "classifier.pt")
    # Test fold is accessed only after hyperparameter selection and calibration are finalized.
    test_y = y[indices["test"]]
    test_features = features[indices["test"]]
    with torch.inference_mode():
        test_logits = model.head(torch.from_numpy(test_features)).numpy()
    test_probabilities = softmax(test_logits / temperature, axis=1)
    predicted = test_probabilities.argmax(axis=1)
    decisions = [decode_probabilities(p, classes) for p in test_probabilities]
    actual_conditions = ["fresh" if classes[i].startswith("Fresh") else "rotten" for i in test_y]
    actual_fruits = [classes[i].removeprefix("Fresh").removeprefix("Rotten").lower() for i in test_y]
    predicted_conditions = [d["prediction"] for d in decisions]
    predicted_fruits = [d["fruit_class"] for d in decisions]
    correct_condition = sum(a == b for a, b in zip(actual_conditions, predicted_conditions))
    audit = json.loads((DATA / "audit.json").read_text(encoding="utf-8"))
    report = {"version": version, "trained_at_utc": datetime.now(timezone.utc).isoformat(), "dataset": "User-supplied bdd69gyhv8-1.zip, original images only",
              "architecture": checkpoint["architecture"], "pretrained_weights": "torchvision MobileNet_V3_Small_Weights.IMAGENET1K_V1",
              "split_counts": audit["split_counts"], "split_method": audit["split_method"], "manifest_sha256": manifest_hash,
              "validation_candidates": candidates, "selected_C": float(best.C), "calibration_temperature": temperature,
              "held_out_test": {"images": len(test_y), "joint_16_class_accuracy": float(accuracy_score(test_y, predicted)),
                                "joint_macro_f1": float(f1_score(test_y, predicted, average="macro")),
                                "condition_accuracy": float(accuracy_score(actual_conditions, predicted_conditions)),
                                "condition_balanced_accuracy": float(balanced_accuracy_score(actual_conditions, predicted_conditions)),
                                "condition_accuracy_wilson_95_image_level": wilson(correct_condition, len(test_y)),
                                "fruit_accuracy": float(accuracy_score(actual_fruits, predicted_fruits)),
                                "condition_confusion_labels": ["fresh", "rotten"],
                                "condition_confusion_matrix": confusion_matrix(actual_conditions, predicted_conditions, labels=["fresh", "rotten"]).tolist(),
                                "condition_report": classification_report(actual_conditions, predicted_conditions, output_dict=True, zero_division=0),
                                "joint_confusion_labels": classes, "joint_confusion_matrix": confusion_matrix(test_y, predicted, labels=list(range(len(classes)))).tolist(),
                                "per_class_report": classification_report(test_y, predicted, target_names=classes, output_dict=True, zero_division=0),
                                "multiclass_log_loss": float(log_loss(test_y, test_probabilities, labels=list(range(len(classes)))))},
              "training_seconds": round(time.time() - start, 2),
              "limitations": audit["limitations"] + ["Not tested on an independently collected external dataset.", "Only eight fruit categories were trained; non-fruit and unsupported-fruit rejection is not reliable.", "Confidence is temperature-scaled model probability, not a guarantee of correctness or food safety.", "The confidence interval treats images as independent and may be optimistic for correlated fruit views."]}
    (MODEL_DIR / "metrics.json").write_text(json.dumps(report, indent=2), encoding="utf-8")
    prediction_rows = [{"source": records[int(i)]["source"], "true_label": classes[int(actual)], "predicted_label": classes[int(guess)], **decision}
                       for i, actual, guess, decision in zip(indices["test"], test_y, predicted, decisions)]
    (MODEL_DIR / "test-predictions.json").write_text(json.dumps(prediction_rows, indent=2), encoding="utf-8")
    print(json.dumps({"version": version, "test_images": len(test_y), "condition_accuracy": report["held_out_test"]["condition_accuracy"],
                      "fruit_accuracy": report["held_out_test"]["fruit_accuracy"], "joint_accuracy": report["held_out_test"]["joint_16_class_accuracy"],
                      "seconds": report["training_seconds"]}, indent=2), flush=True)


if __name__ == "__main__":
    main()

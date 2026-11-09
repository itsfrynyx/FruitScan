"""Focused checks for the trained artifact, data separation, and prediction API."""
import io
import json
import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import numpy as np
import torch
from PIL import Image

from model import ROOT, MODEL_DIR, decode_probabilities, load_classifier, preprocessing
from serve import create_app


class TrainedModelTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.manifest = json.loads((ROOT / "storage/ml/data/manifest.json").read_text(encoding="utf-8"))
        cls.metrics = json.loads((MODEL_DIR / "metrics.json").read_text(encoding="utf-8"))
        cls.app = create_app().test_client()
        cls.token = json.loads((ROOT / "server/model-service.json").read_text(encoding="utf-8"))["token"]

    def test_no_similarity_group_crosses_splits(self):
        groups = {}
        for row in self.manifest["records"]:
            if row["group"] in groups:
                self.assertEqual(groups[row["group"]], row["split"])
            groups[row["group"]] = row["split"]

    def test_all_classes_have_training_and_test_examples(self):
        for split in ["train", "validation", "test"]:
            actual = {r["label"] for r in self.manifest["records"] if r["split"] == split}
            self.assertEqual(actual, set(self.manifest["classes"]))

    def test_metrics_match_saved_test_predictions(self):
        rows = json.loads((MODEL_DIR / "test-predictions.json").read_text(encoding="utf-8"))
        correct = sum(r["true_label"].lower().startswith(r["prediction"]) for r in rows)
        self.assertEqual(len(rows), self.metrics["held_out_test"]["images"])
        self.assertAlmostEqual(correct / len(rows), self.metrics["held_out_test"]["condition_accuracy"])

    def test_service_requires_authentication(self):
        self.assertEqual(self.app.post("/predict").status_code, 401)

    def test_saturated_probabilities_stay_in_range(self):
        result = decode_probabilities([1.0, 2e-8, 1e-12],
                                      ["RottenGuava", "RottenApple", "FreshGuava"])
        self.assertLessEqual(result["confidence"], 1.0)
        self.assertLessEqual(result["fruit_confidence"], 1.0)
        self.assertEqual(sum(result["probabilities"].values()), 1.0)
        self.assertEqual(result["prediction"], "rotten")

    def test_service_rejects_invalid_image(self):
        response = self.app.post("/predict", headers={"Authorization": "Bearer " + self.token},
                                 data={"image": (io.BytesIO(b"not an image"), "fake.jpg")})
        self.assertEqual(response.status_code, 400)

    def test_service_matches_direct_inference(self):
        row = next(r for r in self.manifest["records"] if r["split"] == "test")
        image_path = ROOT / "storage/ml/data/images" / row["image"]
        response = self.app.post("/predict", headers={"Authorization": "Bearer " + self.token},
                                 data={"image": (io.BytesIO(image_path.read_bytes()), "fruit.jpg")})
        self.assertEqual(response.status_code, 200)
        result = response.get_json()
        model, metadata = load_classifier()
        with Image.open(image_path) as image, torch.inference_mode():
            probabilities = torch.softmax(model(preprocessing()(image.convert("RGB")).unsqueeze(0)) / metadata["temperature"], dim=1)[0].tolist()
        expected = decode_probabilities(probabilities, metadata["classes"])
        self.assertEqual(result["prediction"], expected["prediction"])
        self.assertEqual(result["fruit_class"], expected["fruit_class"])
        self.assertAlmostEqual(result["confidence"], expected["confidence"], places=5)
        self.assertAlmostEqual(sum(result["probabilities"].values()), 1.0, places=5)
        self.assertEqual(result["model_version"], self.metrics["version"])

    def test_network_export_matches_feature_classifier(self):
        cache = np.load(ROOT / "storage/ml/data/features.npz", allow_pickle=False)
        model, metadata = load_classifier()
        index = next(i for i, r in enumerate(self.manifest["records"]) if r["split"] == "test")
        row = self.manifest["records"][index]
        with Image.open(ROOT / "storage/ml/data/images" / row["image"]) as image, torch.inference_mode():
            actual = model(preprocessing()(image.convert("RGB")).unsqueeze(0))
            cached = model.head(torch.from_numpy(cache["features"][index:index+1]))
        torch.testing.assert_close(actual, cached, atol=0.001, rtol=0.001)


if __name__ == "__main__":
    unittest.main(verbosity=2)

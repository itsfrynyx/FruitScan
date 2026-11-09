"""Shared, identical preprocessing and architecture for training and inference."""
from pathlib import Path

import torch
from torch import nn
from torchvision.models import MobileNet_V3_Small_Weights, mobilenet_v3_small

ROOT = Path(__file__).resolve().parents[1]
MODEL_DIR = ROOT / "storage" / "ml" / "model"
WEIGHTS = MobileNet_V3_Small_Weights.IMAGENET1K_V1


def preprocessing():
    return WEIGHTS.transforms()


class FruitClassifier(nn.Module):
    def __init__(self, number_of_classes=16, pretrained=False):
        super().__init__()
        self.backbone = mobilenet_v3_small(weights=WEIGHTS if pretrained else None)
        self.backbone.classifier = nn.Identity()
        self.head = nn.Linear(576, number_of_classes)

    def forward(self, images):
        return self.head(self.backbone(images))


def load_classifier(checkpoint=MODEL_DIR / "classifier.pt"):
    data = torch.load(checkpoint, map_location="cpu", weights_only=True)
    model = FruitClassifier(len(data["classes"]))
    model.load_state_dict(data["state_dict"])
    model.eval()
    return model, data


def decode_probabilities(probabilities, classes):
    condition = {"fresh": 0.0, "rotten": 0.0}
    fruits = {}
    for label, probability in zip(classes, probabilities):
        status = "fresh" if label.startswith("Fresh") else "rotten"
        fruit = label.removeprefix("Fresh").removeprefix("Rotten").lower()
        condition[status] += float(probability)
        fruits[fruit] = fruits.get(fruit, 0.0) + float(probability)
    # Float32 softmax values can sum slightly above one after aggregation.
    total = sum(condition.values())
    fresh = min(1.0, max(0.0, condition["fresh"] / total))
    condition = {"fresh": fresh, "rotten": 1.0 - fresh}
    fruits = {fruit: min(1.0, value / total) for fruit, value in fruits.items()}
    prediction = max(condition, key=condition.get)
    fruit = max(fruits, key=fruits.get)
    return {"prediction": prediction, "confidence": condition[prediction], "fruit_class": fruit,
            "probabilities": condition, "fruit_confidence": fruits[fruit]}

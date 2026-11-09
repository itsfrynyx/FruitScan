"""Create a reviewable report using only recorded audit/evaluation results."""
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
metrics = json.loads((ROOT / "storage/ml/model/metrics.json").read_text(encoding="utf-8"))
audit = json.loads((ROOT / "storage/ml/data/audit.json").read_text(encoding="utf-8"))
test = metrics["held_out_test"]
counts = metrics["split_counts"]
matrix = test["condition_confusion_matrix"]
lines = ["# FruitScan model evaluation", "", f"Model: `{metrics['version']}`", "",
         f"Trained at {metrics['trained_at_utc']} (UTC). All scores below were measured on {test['images']} held-out original images.", "",
         "## Results", "", "| Measure | Held-out result |", "| --- | ---: |",
         f"| Fresh/rotten accuracy | {test['condition_accuracy']:.2%} |",
         f"| Fresh/rotten balanced accuracy | {test['condition_balanced_accuracy']:.2%} |",
         f"| Fruit-type accuracy (8 fruits) | {test['fruit_accuracy']:.2%} |",
         f"| Joint fruit + condition accuracy (16 classes) | {test['joint_16_class_accuracy']:.2%} |",
         f"| Joint macro F1 | {test['joint_macro_f1']:.4f} |", "",
         "These are internal dataset results, not a claim of accuracy on arbitrary phone photos or a guarantee of food safety.", "",
         "### Fresh/rotten errors", "", "Rows are supplied labels; columns are predictions.", "",
         "| Actual label | Predicted Fresh | Predicted Rotten |", "| --- | ---: | ---: |",
         f"| Fresh | {matrix[0][0]} | {matrix[0][1]} |", f"| Rotten | {matrix[1][0]} | {matrix[1][1]} |", "",
         "### Per-class results", "", "| Class | Precision | Recall | F1 | Test images |", "| --- | ---: | ---: | ---: | ---: |"]
for label in test["joint_confusion_labels"]:
    row = test["per_class_report"][label]
    lines.append(f"| {label} | {row['precision']:.3f} | {row['recall']:.3f} | {row['f1-score']:.3f} | {int(row['support'])} |")
lines += ["", "## Dataset and split", "",
          f"- Source: `{audit['source_archive']}`; original images only.",
          f"- {audit['valid_images']} valid images; {len(audit['excluded'])} exclusions; {audit['exact_duplicate_extra_images']} extra exact duplicates.",
          f"- {audit['similarity_groups']} similarity groups; largest group: {audit['largest_similarity_group']} images.",
          f"- Training: {counts['train']}; validation: {counts['validation']}; held-out test: {counts['test']}.",
          "- Same-group images stay in one split. Augmented ZIP images were not used.",
          f"- Split method: {audit['split_method']}.", "", "## Training", "",
          "Frozen ImageNet MobileNetV3-Small features plus a fitted standardized multinomial logistic classifier. The backbone was not fine-tuned. Mirrored copies are created for training images only.", "",
          f"Regularization C={metrics['selected_C']} was chosen by validation macro F1. Temperature {metrics['calibration_temperature']:.4f} was fitted using validation negative log likelihood. Test data did not select either value.", "",
          f"Dataset manifest SHA-256: `{metrics['manifest_sha256']}`.", "", "## Limitations", ""]
lines += ["- " + note for note in metrics["limitations"]]
lines += ["", "## Reproduce and inspect", "", "See [training instructions](README.md). Detailed private artifacts are `storage/ml/data/audit.json`, `storage/ml/data/manifest.json`, `storage/ml/model/metrics.json`, and `storage/ml/model/test-predictions.json`.", ""]
(ROOT / "ml/MODEL_CARD.md").write_text("\n".join(lines), encoding="utf-8")
print("Wrote ml/MODEL_CARD.md from measured results.")

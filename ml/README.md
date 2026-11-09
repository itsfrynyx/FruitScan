# Local fruit classifier

This pipeline trains a 16-class fruit/condition classifier from the supplied original images. It uses a frozen ImageNet MobileNetV3-Small backbone with a trained, regularized multinomial logistic classifier. The backbone is not fine-tuned. A single set of class probabilities is summed into fresh/rotten and fruit-type probabilities. Confidence is a validation-calibrated model score, not food-safety certainty.

All dataset processing, training, and prediction happen on this computer. Only Python packages and public pretrained weights are downloaded; the dataset is not uploaded to a cloud service.

## Reproduce

From the project root in PowerShell:

```powershell
.\scripts\setup-ml.ps1
.\storage\ml\python\python.exe -u .\ml\prepare.py 'C:\Users\angel\Downloads\bdd69gyhv8-1.zip'
.\storage\ml\python\python.exe -u .\ml\train.py
.\storage\ml\python\python.exe .\ml\activate.py
.\storage\ml\python\python.exe .\ml\test_model.py
.\scripts\start-model.ps1
```

Keep the final service running while using the detector at `http://localhost/FruitScanWeb/`. It binds only to `127.0.0.1:8766`; Apache calls it with a private bearer token. Images are processed in memory by this service. PHP continues to save signed-in scans according to the existing privacy policy.

Activation writes `server/model.json` and an ignored, private `server/model-service.json`; **never publish the latter**, which contains the service token. Apache reads that configuration without a restart. Existing users and scan records are retained. Supported fruit metadata is synchronized with the trained classes, while unrelated metadata is disabled. Existing demo scans retain their demo labels.

Environment variables take precedence: `FRUITSCAN_MODEL_URL` and `FRUITSCAN_MODEL_TOKEN` override local service configuration. An explicitly empty `FRUITSCAN_MODEL_URL` forces demo mode, which is also how the isolated website tests continue to run. If the real model is configured but offline, the detector shows an error and does not invent a demo result.

## Data validation and evaluation

- Only `Original Image.zip` is used. The supplied augmented archive is excluded because it has filename/folder inconsistencies and its parent-image relationships are not documented.
- Every image must decode, use an expected class, meet dimension limits, and pass an integrity check. Original-byte and decoded-pixel hashes are recorded.
- Exact decoded images with contradictory labels are excluded.
- Similar images are grouped using a 64-bit perceptual hash, including horizontal mirrors, with a Hamming distance threshold of 4. No group can cross data splits.
- Five stratified group folds with seed 42 allocate three folds to training, one to validation, and one to the held-out test. This approximates a 60/20/20 split, with actual counts in the audit.
- Only training images receive mirrored augmentation. Feature scaling uses training images only. Regularization and temperature calibration use validation only. The test split is evaluated after selection is finalized.
- Image caching creates JPEGs bounded to 384 pixels; training and inference then use the pretrained backbone's documented 224-pixel ImageNet preprocessing.

Generated files are private under `storage/ml/`:

| File | Purpose |
| --- | --- |
| `data/audit.json` | Validity, duplicate/group counts, split counts, limitations |
| `data/manifest.json` | Original filenames/hashes, image cache mapping, groups and fixed splits |
| `data/label-review.jpg` | Labeled contact sheet for visual spot-checks |
| `model/classifier.pt` | Backbone and trained classification weights |
| `model/metrics.json` | Validation choices and measured held-out metrics |
| `model/test-predictions.json` | Per-image predictions supporting the reported metrics |

If the dataset changes, remove the generated `data/features.npz` before retraining. Do not change data splits in response to test errors; use an independently collected test set for a new evaluation.

## Scope and limits

The eight fruits are apple, banana, grape, guava, jujube, orange, pomegranate, and strawberry. There is no trained non-fruit/unknown class. Other objects and fruits can receive confident but meaningless predictions. Freshness refers only to visible condition; photographs cannot establish internal condition or food safety.

Grouping reduces duplicate leakage but cannot guarantee that different views of the same physical fruit stay together: no fruit-instance or acquisition-session IDs were provided. Scores describe this internal dataset split, not real-world accuracy. Labels were provided by the dataset and have not been independently expert-verified. The supplied ZIP contained no license/attribution document; confirm provenance before publishing the dataset or derived samples.

## Technical references

- [Torchvision MobileNetV3-Small and its pretrained preprocessing](https://docs.pytorch.org/vision/stable/models/generated/torchvision.models.mobilenet_v3_small.html)
- [Scikit-learn grouped cross-validation](https://scikit-learn.org/stable/modules/cross_validation.html#cross-validation-iterators-for-grouped-data)

The Python runtime and ML libraries are project-local and ignored by Git. No system Python installation or GPU is required.

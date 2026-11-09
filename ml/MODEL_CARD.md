# FruitScan model evaluation

Model: `fruitscan-mobilenetv3-20261011-160610`

Trained at 2026-10-11T16:06:10.181719+00:00 (UTC). All scores below were measured on 644 held-out original images.

## Results

| Measure | Held-out result |
| --- | ---: |
| Fresh/rotten accuracy | 99.22% |
| Fresh/rotten balanced accuracy | 99.22% |
| Fruit-type accuracy (8 fruits) | 99.22% |
| Joint fruit + condition accuracy (16 classes) | 98.60% |
| Joint macro F1 | 0.9863 |

These are internal dataset results, not a claim of accuracy on arbitrary phone photos or a guarantee of food safety.

### Fresh/rotten errors

Rows are supplied labels; columns are predictions.

| Actual label | Predicted Fresh | Predicted Rotten |
| --- | ---: | ---: |
| Fresh | 319 | 2 |
| Rotten | 3 | 320 |

### Per-class results

| Class | Precision | Recall | F1 | Test images |
| --- | ---: | ---: | ---: | ---: |
| FreshApple | 0.974 | 0.974 | 0.974 | 39 |
| FreshBanana | 1.000 | 1.000 | 1.000 | 43 |
| FreshGrape | 0.976 | 1.000 | 0.988 | 41 |
| FreshGuava | 1.000 | 1.000 | 1.000 | 39 |
| FreshJujube | 0.976 | 1.000 | 0.988 | 40 |
| FreshOrange | 1.000 | 1.000 | 1.000 | 40 |
| FreshPomegranate | 0.974 | 0.950 | 0.962 | 40 |
| FreshStrawberry | 1.000 | 1.000 | 1.000 | 39 |
| RottenApple | 0.930 | 1.000 | 0.964 | 40 |
| RottenBanana | 1.000 | 1.000 | 1.000 | 40 |
| RottenGrape | 1.000 | 0.974 | 0.987 | 39 |
| RottenGuava | 1.000 | 1.000 | 1.000 | 39 |
| RottenJujube | 0.955 | 0.955 | 0.955 | 44 |
| RottenOrange | 1.000 | 0.976 | 0.988 | 41 |
| RottenPomegranate | 1.000 | 0.951 | 0.975 | 41 |
| RottenStrawberry | 1.000 | 1.000 | 1.000 | 39 |

## Dataset and split

- Source: `bdd69gyhv8-1.zip`; original images only.
- 3200 valid images; 0 exclusions; 7 extra exact duplicates.
- 3134 similarity groups; largest group: 9 images.
- Training: 1923; validation: 633; held-out test: 644.
- Same-group images stay in one split. Augmented ZIP images were not used.
- Split method: Seed 42, five stratified group folds: three train, one validation, one test; perceptual hash distance <=4 including horizontal mirrors.

## Training

Frozen ImageNet MobileNetV3-Small features plus a fitted standardized multinomial logistic classifier. The backbone was not fine-tuned. Mirrored copies are created for training images only.

Regularization C=0.1 was chosen by validation macro F1. Temperature 0.6448 was fitted using validation negative log likelihood. Test data did not select either value.

Dataset manifest SHA-256: `accccdbb7b3edd6d430878e9ea499c87a9d58d139dacb64f2b1781f4ed786f77`.

## Limitations

- No fruit-instance or acquisition-session identifiers were supplied. Different views of the same physical fruit may still cross splits.
- Perceptual grouping is heuristic; evaluation is an internal dataset estimate, not external validation.
- Original labels have not been independently expert-verified.
- No dataset license or attribution document was included in the ZIP.
- Not tested on an independently collected external dataset.
- Only eight fruit categories were trained; non-fruit and unsupported-fruit rejection is not reliable.
- Confidence is temperature-scaled model probability, not a guarantee of correctness or food safety.
- The confidence interval treats images as independent and may be optimistic for correlated fruit views.

## Reproduce and inspect

See [training instructions](README.md). Detailed private artifacts are `storage/ml/data/audit.json`, `storage/ml/data/manifest.json`, `storage/ml/model/metrics.json`, and `storage/ml/model/test-predictions.json`.

"""Validate originals, create a compact image cache, and split similar images together."""
import argparse
import hashlib
import io
import json
import shutil
import zipfile
from collections import Counter, defaultdict
from pathlib import Path

import numpy as np
from PIL import Image, ImageDraw, ImageOps
from scipy.fft import dctn
from sklearn.model_selection import StratifiedGroupKFold

ROOT = Path(__file__).resolve().parents[1]
DATA = ROOT / "storage" / "ml" / "data"
FRUITS = ["Apple", "Banana", "Grape", "Guava", "Jujube", "Orange", "Pomegranate", "Strawberry"]
CLASSES = [condition + fruit for condition in ["Fresh", "Rotten"] for fruit in FRUITS]


def phash(image):
    pixels = np.asarray(image.convert("L").resize((32, 32), Image.Resampling.LANCZOS), dtype=float)
    coefficients = dctn(pixels, type=2, norm="ortho")[:8, :8].ravel()
    bits = coefficients > np.median(coefficients[1:])
    return int.from_bytes(np.packbits(bits).tobytes(), "big")


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("archive", type=Path)
    args = parser.parse_args()
    DATA.mkdir(parents=True, exist_ok=True)
    image_dir = DATA / "images"
    image_dir.mkdir(exist_ok=True)
    nested_path = DATA / "original-images.zip"
    with zipfile.ZipFile(args.archive) as outer:
        candidates = [x for x in outer.infolist() if x.filename.endswith("/Original Image.zip")]
        if len(candidates) != 1:
            raise ValueError("Expected one Original Image.zip inside the supplied archive.")
        source = candidates[0]
        print(f"Reading original image archive ({source.file_size:,} bytes)…", flush=True)
        # Stream only the known nested archive; no archive-controlled paths are extracted.
        with outer.open(source) as incoming, nested_path.open("wb") as output:
            shutil.copyfileobj(incoming, output, length=1024 * 1024)
    records, rejected = [], []
    with zipfile.ZipFile(nested_path) as archive:
        images = sorted([x for x in archive.infolist() if x.filename.lower().endswith((".jpg", ".jpeg", ".png"))], key=lambda x: x.filename)
        for index, entry in enumerate(images):
            label = entry.filename.split("/")[-2]
            if label not in CLASSES:
                rejected.append({"source": entry.filename, "reason": "Unexpected class"})
                continue
            try:
                if entry.file_size > 30 * 1024 * 1024:
                    raise ValueError("Image exceeds validation size limit")
                raw = archive.read(entry)
                with Image.open(io.BytesIO(raw)) as original:
                    original.verify()
                with Image.open(io.BytesIO(raw)) as original:
                    rgb = ImageOps.exif_transpose(original).convert("RGB")
                    rgb.load()
                if min(rgb.size) < 64 or rgb.width * rgb.height > 40_000_000:
                    raise ValueError("Invalid image dimensions")
                digest = hashlib.sha256(raw).hexdigest()
                pixel_digest = hashlib.sha256(str(rgb.size).encode() + rgb.tobytes()).hexdigest()
                normal_hash = phash(rgb)
                mirror_hash = phash(ImageOps.mirror(rgb))
                # No training augmentation is created until after split assignment.
                rgb.thumbnail((384, 384), Image.Resampling.LANCZOS)
                filename = f"{index:05d}-{digest[:12]}.jpg"
                rgb.save(image_dir / filename, quality=94, optimize=True)
                records.append({"source": entry.filename, "image": filename, "label": label,
                                "class_index": CLASSES.index(label), "sha256": digest,
                                "pixel_sha256": pixel_digest, "phash": f"{normal_hash:016x}",
                                "mirror_phash": f"{mirror_hash:016x}"})
            except (OSError, ValueError, Image.DecompressionBombError) as error:
                rejected.append({"source": entry.filename, "reason": str(error)})
            if (index + 1) % 200 == 0:
                print(f"Validated {index + 1}/{len(images)} images", flush=True)
    # Exclude exact duplicates with contradictory labels, rather than trusting either label.
    exact = defaultdict(list)
    for index, record in enumerate(records):
        exact[record["pixel_sha256"]].append(index)
    conflicting = {i for members in exact.values() if len({records[j]["label"] for j in members}) > 1 for i in members}
    for index in sorted(conflicting):
        rejected.append({"source": records[index]["source"], "reason": "Identical decoded image has conflicting labels"})
    records = [r for i, r in enumerate(records) if i not in conflicting]
    parent = list(range(len(records)))

    def find(index):
        while parent[index] != index:
            parent[index] = parent[parent[index]]
            index = parent[index]
        return index

    def join(a, b):
        parent[find(b)] = find(a)

    hashes = [(int(r["phash"], 16), int(r["mirror_phash"], 16)) for r in records]
    print("Grouping duplicate and similar images before splitting…", flush=True)
    for i, (normal, mirror) in enumerate(hashes):
        for j in range(i):
            if min((normal ^ hashes[j][0]).bit_count(), (mirror ^ hashes[j][0]).bit_count()) <= 4:
                join(i, j)
    groups = np.array([find(i) for i in range(len(records))])
    labels = np.array([r["class_index"] for r in records])
    splitter = StratifiedGroupKFold(n_splits=5, shuffle=True, random_state=42)
    folds = list(splitter.split(np.zeros(len(records)), labels, groups))
    validation, test = set(folds[0][1]), set(folds[1][1])
    for i, record in enumerate(records):
        record["group"] = int(groups[i])
        record["split"] = "validation" if i in validation else "test" if i in test else "train"
    split_groups = {name: {r["group"] for r in records if r["split"] == name} for name in ["train", "validation", "test"]}
    assert not (split_groups["train"] & split_groups["validation"] or split_groups["train"] & split_groups["test"] or split_groups["test"] & split_groups["validation"])
    counts = {name: dict(Counter(r["label"] for r in records if r["split"] == name)) for name in split_groups}
    for name, by_class in counts.items():
        if any(by_class.get(label, 0) < 5 for label in CLASSES):
            raise ValueError(f"Insufficient class coverage in {name}; inspect similarity groups before training.")
    report = {"source_archive": args.archive.name, "original_entries": len(images), "valid_images": len(records),
              "excluded": rejected, "class_counts": dict(Counter(r["label"] for r in records)),
              "split_counts": {k: sum(v.values()) for k, v in counts.items()}, "split_class_counts": counts,
              "exact_duplicate_extra_images": sum(len(v) - 1 for v in exact.values()), "conflicting_images_excluded": len(conflicting),
              "similarity_groups": len(set(groups.tolist())), "largest_similarity_group": max(Counter(groups.tolist()).values()),
              "split_method": "Seed 42, five stratified group folds: three train, one validation, one test; perceptual hash distance <=4 including horizontal mirrors",
              "augmented_archive_used": False, "limitations": ["No fruit-instance or acquisition-session identifiers were supplied. Different views of the same physical fruit may still cross splits.", "Perceptual grouping is heuristic; evaluation is an internal dataset estimate, not external validation.", "Original labels have not been independently expert-verified.", "No dataset license or attribution document was included in the ZIP."]}
    (DATA / "manifest.json").write_text(json.dumps({"classes": CLASSES, "records": records}, indent=2), encoding="utf-8")
    (DATA / "audit.json").write_text(json.dumps(report, indent=2), encoding="utf-8")
    # Review three examples from each class with their supplied labels.
    sheet = Image.new("RGB", (3 * 156, len(CLASSES) * 150), "#faf9f4")
    draw = ImageDraw.Draw(sheet)
    for row, label in enumerate(CLASSES):
        examples = [r for r in records if r["label"] == label]
        for col, position in enumerate([0, len(examples) // 2, len(examples) - 1]):
            with Image.open(image_dir / examples[position]["image"]) as sample:
                tile = ImageOps.contain(sample, (146, 116))
                sheet.paste(tile, (col * 156 + (146 - tile.width) // 2, row * 150 + 23))
            draw.text((col * 156 + 4, row * 150 + 4), label, fill="#173e2a")
    sheet.save(DATA / "label-review.jpg", quality=88)
    # This file was generated by this run; retain the user's original archive unchanged.
    nested_path.unlink()
    print(json.dumps(report, indent=2), flush=True)


if __name__ == "__main__":
    main()

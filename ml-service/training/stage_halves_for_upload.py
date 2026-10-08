#!/usr/bin/env python3
"""
BIGKAS-AI: Stage split halves for gdrive upload
===============================================
Reads split_log.csv and copies the 44 a/b halves from audio_files/data/
into audio_files/to_upload/ so they can be uploaded to gdrive in one batch.

Usage (run from ml-service/training/):
  python stage_halves_for_upload.py

Idempotent: re-running overwrites the staged copies (same content).
"""

import csv
import os
import shutil
import sys

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
LOG_PATH = os.path.join(SCRIPT_DIR, "split_log.csv")
SRC_DIR = os.path.join(SCRIPT_DIR, "audio_files", "data")
DEST_DIR = os.path.join(SCRIPT_DIR, "audio_files", "to_upload")


def main() -> None:
    if not os.path.isfile(LOG_PATH):
        print(f"ERROR: {LOG_PATH} not found.")
        sys.exit(1)

    with open(LOG_PATH, newline="", encoding="utf-8") as f:
        rows = list(csv.DictReader(f))

    expected = []
    for r in rows:
        expected.append(r["a_file"])
        expected.append(r["b_file"])

    os.makedirs(DEST_DIR, exist_ok=True)

    copied, missing = 0, []
    for fname in expected:
        src = os.path.join(SRC_DIR, fname)
        if not os.path.isfile(src):
            missing.append(fname)
            continue
        shutil.copy2(src, os.path.join(DEST_DIR, fname))
        copied += 1

    # Remove anything staged that is no longer in the log (stale files)
    staged = set(os.listdir(DEST_DIR))
    for extra in sorted(staged - set(expected)):
        os.remove(os.path.join(DEST_DIR, extra))
        print(f"  removed stale staged file: {extra}")

    print(f"\nStaged {copied}/{len(expected)} halves into audio_files/to_upload/")
    if missing:
        print(f"MISSING source files ({len(missing)}):")
        for m in missing:
            print(f"  - {m}")
        sys.exit(1)
    print("All halves staged. Upload the folder contents to gdrive audio_chunks.")


if __name__ == "__main__":
    main()

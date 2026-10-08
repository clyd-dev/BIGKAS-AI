#!/usr/bin/env python3
"""
BIGKAS-AI Audio Splitter (NON-DESTRUCTIVE)
==========================================
Splits .wav files that are >= 30.0 seconds into two equal halves using
a/b suffixes. Untouched files keep their exact names.

  std010_g4_fil_chunk_16.wav (42.2s)
    -> std010_g4_fil_chunk_16a.wav (21.1s)
    -> std010_g4_fil_chunk_16b.wav (21.1s)

Safety rules:
  1. Files under the threshold are NEVER touched (no rename, no re-encode).
  2. Oversized originals are MOVED to _oversized_originals/ (never deleted),
     so the operation is fully reversible.
  3. Idempotent: if the a/b outputs already exist, the file is skipped.
  4. Only top-level *.wav in the target dir are scanned (subfolders ignored).

Output:
  split_log.csv in the target dir, one row per split file:
    original_file,a_file,a_dur_sec,b_file,b_dur_sec,orig_dur_sec
  Use this log to add the new rows to the gdrive metadata sheet
  (1 row -> 2 rows, transcript divided between halves by listening).

Usage (run from ml-service/training/):
  python "audio_files/data - Copy/split_oversized_audio.py"
  python "audio_files/data - Copy/split_oversized_audio.py" "audio_files/data - Copy" 28.0
"""

import csv
import glob
import os
import shutil
import sys

try:
    from pydub import AudioSegment
    from pydub.utils import which
except ImportError:
    print("Error: 'pydub' is not installed. Run: pip install pydub audioop-lts")
    sys.exit(1)

THRESHOLD_SEC = 30.0
BACKUP_SUBDIR = "_oversized_originals"
LOG_FILENAME = "split_log.csv"


def split_stem(filename: str) -> tuple:
    """Return (stem, ext) preserving the original extension case on output as .wav."""
    stem, ext = os.path.splitext(filename)
    return stem, ext


def process_audio_folder(target_dir: str, threshold_sec: float = THRESHOLD_SEC) -> None:
    if not os.path.isdir(target_dir):
        print(f"ERROR: Target directory '{target_dir}' does not exist.")
        sys.exit(1)

    if which("ffmpeg") is None:
        print("ERROR: ffmpeg not found on PATH. pydub needs it to export wav files.")
        sys.exit(1)

    wav_files = sorted(glob.glob(os.path.join(target_dir, "*.wav")))
    # Also catch uppercase .WAV (Windows glob is case-insensitive, but be explicit)
    seen = set(os.path.basename(p) for p in wav_files)
    for p in sorted(glob.glob(os.path.join(target_dir, "*.WAV"))):
        if os.path.basename(p) not in seen:
            wav_files.append(p)
    if not wav_files:
        print(f"No WAV files found in '{target_dir}'.")
        return

    print(f"Found {len(wav_files)} WAV files in '{target_dir}'. Threshold: {threshold_sec}s")
    print("Untouched files will keep their exact names. Nothing is deleted.\n")

    backup_dir = os.path.join(target_dir, BACKUP_SUBDIR)

    split_count = 0
    skipped_exists = 0
    log_rows = []

    for file_path in wav_files:
        # Never process our own outputs, backup dir, or log file
        if os.path.dirname(os.path.abspath(file_path)) != os.path.abspath(target_dir):
            continue
        fname = os.path.basename(file_path)

        try:
            audio = AudioSegment.from_wav(file_path)
        except Exception as e:
            print(f"  ERROR loading '{fname}': {e}")
            continue

        duration_sec = len(audio) / 1000.0
        if duration_sec < threshold_sec:
            continue  # leave completely alone

        stem, _ext = split_stem(fname)
        a_fname = f"{stem}a.wav"
        b_fname = f"{stem}b.wav"
        a_path = os.path.join(target_dir, a_fname)
        b_path = os.path.join(target_dir, b_fname)

        if os.path.exists(a_path) or os.path.exists(b_path):
            print(f"  SKIP '{fname}' ({duration_sec:.1f}s): outputs already exist.")
            skipped_exists += 1
            continue

        print(f"  SPLIT '{fname}' ({duration_sec:.1f}s) -> '{a_fname}' + '{b_fname}'")
        half_ms = len(audio) // 2
        part_a = audio[:half_ms]
        part_b = audio[half_ms:]

        a_dur = len(part_a) / 1000.0
        b_dur = len(part_b) / 1000.0
        if a_dur >= threshold_sec or b_dur >= threshold_sec:
            print(f"    WARNING: a half is still >= {threshold_sec}s "
                  f"(a={a_dur:.1f}s b={b_dur:.1f}s). Re-run the script to split it again.")

        part_a.export(a_path, format="wav")
        part_b.export(b_path, format="wav")

        # Move (not delete) the oversized original into the backup subfolder
        os.makedirs(backup_dir, exist_ok=True)
        shutil.move(file_path, os.path.join(backup_dir, fname))
        print(f"    original moved to {BACKUP_SUBDIR}/{fname} (recoverable)")

        log_rows.append({
            "original_file": fname,
            "a_file": a_fname,
            "a_dur_sec": round(a_dur, 1),
            "b_file": b_fname,
            "b_dur_sec": round(b_dur, 1),
            "orig_dur_sec": round(duration_sec, 1),
        })
        split_count += 1

    if log_rows:
        log_path = os.path.join(target_dir, LOG_FILENAME)
        with open(log_path, "w", newline="", encoding="utf-8") as f:
            writer = csv.DictWriter(f, fieldnames=list(log_rows[0].keys()))
            writer.writeheader()
            writer.writerows(log_rows)
        print(f"\nWrote {log_path} ({len(log_rows)} rows) - use it to patch the gdrive sheet.")

    print("\n=================================================================")
    print("Done.")
    print(f"  Files split: {split_count}")
    print(f"  Skipped (outputs already exist): {skipped_exists}")
    print(f"  Untouched files: renamed=0, deleted=0")
    print("=================================================================")


if __name__ == "__main__":
    TARGET_DIR = sys.argv[1] if len(sys.argv) > 1 else os.path.dirname(os.path.abspath(__file__))
    threshold = float(sys.argv[2]) if len(sys.argv) > 2 else THRESHOLD_SEC
    process_audio_folder(TARGET_DIR, threshold)

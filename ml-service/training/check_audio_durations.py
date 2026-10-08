#!/usr/bin/env python3
"""
BIGKAS-AI Audio Validation Tool - Check Audio Duration (< 30 sec)
Scans audio directories (.wav files) and confirms whether all files are under 30.0 seconds.
Flags any files >= 30.0s and outputs a detailed summary report.
"""

import os
import sys
import glob
import wave
import contextlib

def get_wav_duration_fast(file_path):
    """
    Returns WAV file duration in seconds using standard library wave module.
    Falls back to pydub/librosa if standard header parsing fails.
    """
    try:
        with contextlib.closing(wave.open(file_path, 'r')) as f:
            frames = f.getnframes()
            rate = f.getframerate()
            return frames / float(rate)
    except Exception:
        try:
            from pydub import AudioSegment
            audio = AudioSegment.from_wav(file_path)
            return len(audio) / 1000.0
        except Exception as e:
            print(f"⚠️ Error reading {file_path}: {e}")
            return None

def check_directory(target_dir, max_threshold=30.0):
    if not os.path.exists(target_dir):
        print(f"⚠️ Directory '{target_dir}' does not exist. Skipping.")
        return None

    wav_files = []
    for root, _, files in os.walk(target_dir):
        for f in files:
            if f.lower().endswith('.wav'):
                wav_files.append(os.path.join(root, f))

    if not wav_files:
        print(f"ℹ️ No WAV files found in '{target_dir}'.")
        return None

    print(f"\n🔍 Scanning {len(wav_files)} WAV files in '{target_dir}' (Threshold: < {max_threshold}s)...")
    
    over_limit = []
    valid_count = 0
    total_duration = 0.0
    max_duration_found = 0.0
    longest_file = None

    for file_path in sorted(wav_files):
        duration = get_wav_duration_fast(file_path)
        if duration is None:
            continue

        total_duration += duration
        if duration > max_duration_found:
            max_duration_found = duration
            longest_file = file_path

        rel_path = os.path.basename(file_path)
        
        if duration >= max_threshold:
            over_limit.append((rel_path, duration, file_path))
        else:
            valid_count += 1

    avg_duration = total_duration / len(wav_files) if wav_files else 0.0

    print(f"  📊 Summary for '{target_dir}':")
    print(f"     - Total Files Checked: {len(wav_files)}")
    print(f"     - Valid Files (< {max_threshold}s): {valid_count}")
    print(f"     - Over Threshold (>= {max_threshold}s): {len(over_limit)}")
    print(f"     - Average Duration: {avg_duration:.2f}s")
    if longest_file:
        print(f"     - Longest File: {os.path.basename(longest_file)} ({max_duration_found:.2f}s)")

    if over_limit:
        print(f"\n  ❌ FOUND {len(over_limit)} FILE(S) EXCEEDING {max_threshold} SECONDS:")
        for name, dur, _ in over_limit:
            print(f"     • {name} -> {dur:.2f}s")
    else:
        print(f"  ✅ ALL {len(wav_files)} files in '{target_dir}' are strictly under {max_threshold} seconds!")

    return {
        'total': len(wav_files),
        'valid': valid_count,
        'over': over_limit,
        'avg': avg_duration,
        'max': max_duration_found
    }

def main():
    default_dirs = [
        "./g4_processed_chunks",
        "./g5_processed_chunks",
        "./g6_processed_chunks",
        "./final_chopped_dataset"
    ]
    
    if len(sys.argv) > 1:
        target_dirs = sys.argv[1:]
    else:
        existing_defaults = [d for d in default_dirs if os.path.exists(d)]
        target_dirs = existing_defaults if existing_defaults else ["."]

    threshold = 30.0
    print("=" * 65)
    print(f"  BIGKAS-AI Audio Duration Verification Tool (Threshold: < {threshold}s)")
    print("=" * 65)

    all_over = []
    total_files_all = 0

    for d in target_dirs:
        res = check_directory(d, max_threshold=threshold)
        if res:
            total_files_all += res['total']
            all_over.extend(res['over'])

    print("\n" + "=" * 65)
    if total_files_all == 0:
        print("⚠️ No audio files were scanned. Please specify a valid folder path:")
        print("   python check_audio_durations.py /path/to/your/audio_folder")
    elif len(all_over) == 0:
        print(f"🎉 SUCCESS: All {total_files_all} audio chunks across all scanned folders are under {threshold} seconds!")
    else:
        print(f"⚠️ VERIFICATION FAILED: {len(all_over)} out of {total_files_all} files exceeded {threshold} seconds.")
        print("   Run your splitting script (e.g. g6_split_and_rename.py) to split long files.")
    print("=" * 65)

if __name__ == "__main__":
    main()

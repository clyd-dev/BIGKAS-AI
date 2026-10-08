"""
Audio quality measurement for a reading recording.

Whisper only tells us what words it heard. This module measures the recording
itself, so the teacher can see what else the microphone picked up: how much of
it was speech, how loud the background was, and where sounds occurred that were
not the child reading.

Everything here is computed from the waveform and a voice-activity detector —
no model output is trusted for it.
"""

import numpy as np

SAMPLE_RATE = 16000
FRAME_SECONDS = 0.03          # 30 ms analysis frames
SILENCE_FLOOR_DB = -80.0      # reported when a frame is digitally silent

# A non-speech stretch counts as a "background sound" when it is this much
# louder than the recording's own noise floor, and lasts at least this long.
EVENT_ABOVE_FLOOR_DB = 12.0
EVENT_MIN_DB = -45.0
EVENT_MIN_SECONDS = 0.25
MAX_EVENTS = 20


def _frame_db(audio):
    """RMS level of each 30 ms frame, in dBFS."""
    frame_len = int(SAMPLE_RATE * FRAME_SECONDS)
    n_frames = len(audio) // frame_len

    if n_frames == 0:
        return np.array([]), frame_len

    frames = audio[: n_frames * frame_len].reshape(n_frames, frame_len)
    rms = np.sqrt(np.mean(np.square(frames, dtype=np.float64), axis=1))

    with np.errstate(divide="ignore"):
        db = 20.0 * np.log10(np.maximum(rms, 1e-10))

    return np.maximum(db, SILENCE_FLOOR_DB), frame_len


def _speech_mask(n_frames, frame_len, speech_timestamps):
    """True for frames the voice-activity detector marked as speech."""
    mask = np.zeros(n_frames, dtype=bool)

    for ts in speech_timestamps:
        start = int(ts["start"] // frame_len)
        end = int(np.ceil(ts["end"] / frame_len))
        mask[max(0, start): min(n_frames, end)] = True

    return mask


def _background_events(db, mask, noise_db):
    """Stretches outside speech that are clearly louder than the noise floor."""
    threshold = max(noise_db + EVENT_ABOVE_FLOOR_DB, EVENT_MIN_DB)
    loud = (~mask) & (db > threshold)

    events = []
    start = None

    for i, is_loud in enumerate(np.append(loud, False)):
        if is_loud and start is None:
            start = i
        elif not is_loud and start is not None:
            duration = (i - start) * FRAME_SECONDS
            if duration >= EVENT_MIN_SECONDS:
                events.append({
                    "start": round(start * FRAME_SECONDS, 2),
                    "end": round(i * FRAME_SECONDS, 2),
                    "peak_db": round(float(db[start:i].max()), 1),
                })
            start = None

    # Loudest first, then cap, then back into time order for display.
    events.sort(key=lambda e: e["peak_db"], reverse=True)
    return sorted(events[:MAX_EVENTS], key=lambda e: e["start"])


def _rating(snr_db, speech_ratio):
    # Checked first: with no speech there is no signal level, so SNR is None too.
    if speech_ratio < 0.05:
        return "no_speech"
    if snr_db is None:
        return "unknown"
    if snr_db >= 20:
        return "clean"
    if snr_db >= 10:
        return "moderate_noise"
    return "noisy"


def measure(audio, speech_timestamps):
    """
    audio             -- mono float32 waveform at 16 kHz
    speech_timestamps -- [{'start': sample, 'end': sample}, ...] from the VAD

    Returns a JSON-serialisable dict; see the keys below.
    """
    total_seconds = len(audio) / SAMPLE_RATE
    db, frame_len = _frame_db(audio)

    if len(db) == 0:
        return {
            "measured": False,
            "reason": "Recording too short to measure.",
            "total_seconds": round(total_seconds, 2),
        }

    mask = _speech_mask(len(db), frame_len, speech_timestamps)
    speech_frames = db[mask]
    other_frames = db[~mask]

    speech_seconds = float(mask.sum()) * FRAME_SECONDS
    speech_ratio = speech_seconds / total_seconds if total_seconds > 0 else 0.0

    # Noise floor: the quiet half of whatever is not speech. If the child spoke
    # wall to wall there is nothing to measure it from, so fall back to the
    # quietest frames of the whole recording.
    if len(other_frames) >= 10:
        noise_db = float(np.percentile(other_frames, 50))
    else:
        noise_db = float(np.percentile(db, 5))

    speech_db = float(np.percentile(speech_frames, 75)) if len(speech_frames) else None
    snr_db = (speech_db - noise_db) if speech_db is not None else None

    first_speech = speech_timestamps[0]["start"] / SAMPLE_RATE if speech_timestamps else None
    last_speech = speech_timestamps[-1]["end"] / SAMPLE_RATE if speech_timestamps else None

    return {
        "measured": True,
        "total_seconds": round(total_seconds, 2),
        "speech_seconds": round(speech_seconds, 2),
        "non_speech_seconds": round(max(0.0, total_seconds - speech_seconds), 2),
        "speech_ratio": round(speech_ratio, 3),
        "speech_db": round(speech_db, 1) if speech_db is not None else None,
        "noise_floor_db": round(noise_db, 1),
        "snr_db": round(snr_db, 1) if snr_db is not None else None,
        "clipping_ratio": round(float(np.mean(np.abs(audio) >= 0.99)), 4),
        "leading_silence": round(first_speech, 2) if first_speech is not None else None,
        "trailing_silence": round(max(0.0, total_seconds - last_speech), 2) if last_speech is not None else None,
        "background_events": _background_events(db, mask, noise_db),
        "rating": _rating(snr_db, speech_ratio),
    }

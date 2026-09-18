#!/usr/bin/env python3
"""Read a licence plate out of one camera JPEG.

Prints "PLATE CONF" (confidence 0-100) or "NONE". PHP decides whether the
confidence is high enough to store. Nothing is logged: the plate is personal
data.
"""

import os
import re
import subprocess
import sys
import tempfile

import cv2

# Hikvision burns capture metadata (date, plate, colour) as an overlay along
# the bottom of scene images. Those big printed characters are much easier to
# OCR than the actual plate on the car, so tesseract steals the result. We
# strip that band off before anything else looks at the frame.
OVERLAY_STRIP_FRACTION = 0.2

# Character height tesseract really wants. A plate in a Hikvision crop is
# often 20-30 px tall; we upscale each candidate to several targets so the
# right one is in the batch.
OCR_HEIGHTS = (64, 96, 128, 160)


def main() -> None:
    if len(sys.argv) != 2:
        print("NONE")
        return

    image = cv2.imread(sys.argv[1])

    if image is None:
        print("NONE")
        return

    best = best_read(image)
    if best is None:
        print("NONE")
        return

    plate, confidence = best
    print(f"{plate} {confidence:.0f}")


def best_read(image):
    height, width = image.shape[:2]
    scene = width > 900

    if scene:
        keep = max(int(height * (1 - OVERLAY_STRIP_FRACTION)), 1)
        image = image[:keep]
        height, width = image.shape[:2]

    aspect = width / max(height, 1)
    best = None

    # A tight plate close-up: the whole image is the plate.
    if width <= 900 and 1.6 <= aspect <= 8:
        best = better(best, ocr(image))

    # A vehicle crop (small, roughly square). Try the whole thing plus each
    # rectangle that looks like a plate.
    if width <= 900 and aspect < 1.6:
        best = better(best, ocr(image))

    for crop in plate_regions(image):
        best = better(best, ocr(crop))

    return best


def better(current, candidate):
    if candidate is None:
        return current
    if current is None or candidate[1] > current[1]:
        return candidate
    return current


def plate_regions(image):
    """Find rectangles that look like plates and return them, largest first."""
    height, width = image.shape[:2]
    working = image
    # Scale small images up so the morphology kernel has room to work; scale
    # very large ones down so we do not spend seconds on background clutter.
    if width < 800:
        factor = 800 / max(width, 1)
        working = cv2.resize(image, (int(width * factor), int(height * factor)))
    elif width > 1280:
        factor = 1280 / max(width, 1)
        working = cv2.resize(image, (int(width * factor), int(height * factor)))

    gray = cv2.cvtColor(working, cv2.COLOR_BGR2GRAY)
    kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (17, 5))
    blackhat = cv2.morphologyEx(gray, cv2.MORPH_BLACKHAT, kernel)
    gradient = cv2.Sobel(blackhat, cv2.CV_32F, 1, 0, ksize=3)
    gradient = cv2.convertScaleAbs(gradient)
    gradient = cv2.morphologyEx(gradient, cv2.MORPH_CLOSE, kernel)
    _threshold, binary = cv2.threshold(gradient, 0, 255, cv2.THRESH_BINARY | cv2.THRESH_OTSU)
    binary = cv2.morphologyEx(
        binary,
        cv2.MORPH_CLOSE,
        cv2.getStructuringElement(cv2.MORPH_RECT, (25, 7)),
    )
    contours, _hierarchy = cv2.findContours(binary, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)

    regions = []
    frame_h, frame_w = working.shape[:2]
    for contour in contours:
        x, y, crop_w, crop_h = cv2.boundingRect(contour)
        if crop_h < 12 or crop_w < 40:
            continue
        ratio = crop_w / float(crop_h)
        if ratio < 1.8 or ratio > 7.5:
            continue
        pad_x = int(crop_w * 0.1)
        pad_y = int(crop_h * 0.35)
        x0 = max(x - pad_x, 0)
        y0 = max(y - pad_y, 0)
        x1 = min(x + crop_w + pad_x, frame_w)
        y1 = min(y + crop_h + pad_y, frame_h)
        regions.append((crop_w * crop_h, working[y0:y1, x0:x1]))

    regions.sort(key=lambda item: item[0], reverse=True)

    return [crop for _area, crop in regions[:8]]


def ocr(crop):
    """Read one candidate region. Tries several scales and PSM modes so a
    borderline character height is not the reason we miss the plate.
    """
    if crop is None or crop.size == 0:
        return None

    gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY) if len(crop.shape) == 3 else crop
    gray = cv2.normalize(gray, None, 0, 255, cv2.NORM_MINMAX)

    best = None
    for target_height in OCR_HEIGHTS:
        variant = _resize_to_height(gray, target_height)
        for psm in ("7", "8"):
            best = _better(best, _run_tesseract(variant, psm))
    return best


def _resize_to_height(gray, target_height):
    height, width = gray.shape[:2]
    if height == 0:
        return gray
    factor = target_height / height
    new_width = max(int(width * factor), 1)
    return cv2.resize(gray, (new_width, target_height), interpolation=cv2.INTER_CUBIC)


def _run_tesseract(gray, psm):
    handle, path = tempfile.mkstemp(suffix=".png")
    os.close(handle)
    try:
        if not cv2.imwrite(path, gray):
            return None
        completed = subprocess.run(
            [
                "tesseract",
                path,
                "stdout",
                "--psm",
                psm,
                "-c",
                "tessedit_char_whitelist=ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789",
                "tsv",
            ],
            capture_output=True,
            text=True,
            timeout=8,
            check=False,
        )
    except (OSError, subprocess.TimeoutExpired):
        return None
    finally:
        try:
            os.unlink(path)
        except OSError:
            pass

    return best_line(completed.stdout)


def _better(current, candidate):
    if candidate is None:
        return current
    if current is None or candidate[1] > current[1]:
        return candidate
    return current


def best_line(tsv: str):
    rows = tsv.splitlines()
    if len(rows) < 2:
        return None

    header = rows[0].split("\t")
    try:
        conf_at = header.index("conf")
        text_at = header.index("text")
        line_at = header.index("line_num")
        block_at = header.index("block_num")
        par_at = header.index("par_num")
    except ValueError:
        return None

    groups: dict[tuple[str, str, str], list[tuple[str, float]]] = {}
    needed = max(conf_at, text_at, line_at, block_at, par_at)
    for row in rows[1:]:
        cols = row.split("\t")
        if len(cols) <= needed:
            continue
        text = re.sub(r"[^A-Z0-9]", "", cols[text_at].upper())
        if text == "":
            continue
        try:
            confidence = float(cols[conf_at])
        except ValueError:
            continue
        if confidence < 0:
            continue
        key = (cols[block_at], cols[par_at], cols[line_at])
        groups.setdefault(key, []).append((text, confidence))

    best = None
    for parts in groups.values():
        plate = "".join(part[0] for part in parts)
        if re.fullmatch(r"[A-Z0-9]{5,10}", plate) is None:
            continue
        if re.search(r"[A-Z]", plate) is None or re.search(r"\d", plate) is None:
            continue
        confidence = sum(part[1] for part in parts) / len(parts)
        if best is None or confidence > best[1]:
            best = (plate, confidence)

    return best


if __name__ == "__main__":
    main()

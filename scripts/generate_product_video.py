#!/usr/bin/env python3
import json
import os
import shutil
import sys
import tempfile
import textwrap
import time
from datetime import datetime

from gtts import gTTS
try:
    from moviepy import AudioClip, AudioFileClip, CompositeVideoClip, ImageClip, vfx
except Exception:  # MoviePy v1 fallback
    from moviepy.editor import AudioClip, AudioFileClip, CompositeVideoClip, ImageClip, vfx
from PIL import Image, ImageDraw, ImageFont, ImageOps


def clip_with_duration(clip, duration):
    if hasattr(clip, "with_duration"):
        return clip.with_duration(duration)
    return clip.set_duration(duration)


def clip_with_position(clip, position):
    if hasattr(clip, "with_position"):
        return clip.with_position(position)
    return clip.set_position(position)


def clip_with_audio(clip, audio):
    if hasattr(clip, "with_audio"):
        return clip.with_audio(audio)
    return clip.set_audio(audio)


def clip_with_start(clip, start):
    if hasattr(clip, "with_start"):
        return clip.with_start(start)
    return clip.set_start(start)


def clip_fadein(clip, duration):
    if hasattr(clip, "fadein"):
        return clip.fadein(duration)

    if hasattr(clip, "with_effects") and hasattr(vfx, "FadeIn"):
        return clip.with_effects([vfx.FadeIn(duration)])

    return clip


def clip_zoom(clip, duration):
    zoom_fn = lambda t: 1.0 + (0.06 * (t / max(duration, 0.001)))

    if hasattr(clip, "resize"):
        return clip.resize(zoom_fn)

    if hasattr(clip, "with_effects") and hasattr(vfx, "Resize"):
        return clip.with_effects([vfx.Resize(zoom_fn)])

    return clip


def pick_font(size, bold=False):
    candidates = [
        "C:/Windows/Fonts/arialbd.ttf" if bold else "C:/Windows/Fonts/arial.ttf",
        "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf" if bold else "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
    ]
    for font_path in candidates:
        if os.path.isfile(font_path):
            return ImageFont.truetype(font_path, size=size)
    return ImageFont.load_default()


def clamp_wrapped_text(value, width_chars, max_lines):
    text = (value or "").strip()
    if text == "":
        return ""

    lines = []
    for raw_line in text.splitlines():
        wrapped = textwrap.wrap(raw_line.strip(), width=width_chars) or [""]
        lines.extend(wrapped)

    if len(lines) > max_lines:
        lines = lines[:max_lines]
        if lines[-1] != "":
            lines[-1] = (lines[-1][: max(0, width_chars - 1)]).rstrip() + "..."

    return "\n".join(lines)


def create_background(image_path, width, height, output_path):
    if image_path and os.path.isfile(image_path):
        base = Image.open(image_path).convert("RGB")
    else:
        base = Image.new("RGB", (width, height), "#113322")

    fitted = ImageOps.fit(base, (width, height), method=Image.Resampling.LANCZOS)

    overlay = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    draw = ImageDraw.Draw(overlay)
    draw.rectangle([(0, 0), (width, height)], fill=(10, 20, 12, 92))

    composed = Image.alpha_composite(fitted.convert("RGBA"), overlay)
    composed.convert("RGB").save(output_path, format="PNG")


def create_title_layer(width, height, name, quality, output_path):
    canvas = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    draw = ImageDraw.Draw(canvas)

    title_font = pick_font(64, bold=True)
    quality_font = pick_font(36, bold=False)

    panel_x = int(width * 0.06)
    panel_y = int(height * 0.08)
    panel_w = int(width * 0.88)
    panel_h = int(height * 0.26)

    draw.rounded_rectangle(
        [(panel_x, panel_y), (panel_x + panel_w, panel_y + panel_h)],
        radius=36,
        fill=(6, 22, 18, 188),
        outline=(45, 212, 191, 230),
        width=5,
    )

    title_text = clamp_wrapped_text(name or "Produit", width_chars=24, max_lines=2)
    title_text = title_text or "Produit"
    draw.multiline_text(
        (panel_x + 42, panel_y + 24),
        title_text,
        font=title_font,
        fill=(236, 254, 255, 255),
        spacing=8,
    )

    title_bbox = draw.multiline_textbbox((panel_x + 42, panel_y + 24), title_text, font=title_font, spacing=8)
    quality_y = title_bbox[3] + 10
    max_quality_y = panel_y + panel_h - 54
    if quality_y > max_quality_y:
        quality_y = max_quality_y

    draw.text((panel_x + 44, quality_y), f"Qualite: {quality}", font=quality_font, fill=(153, 246, 228, 255))

    canvas.save(output_path, format="PNG")


def create_details_layer(width, height, description, production_date, expiration_date, price, output_path):
    canvas = Image.new("RGBA", (width, height), (0, 0, 0, 0))
    draw = ImageDraw.Draw(canvas)

    body_font = pick_font(30, bold=False)
    meta_font = pick_font(28, bold=False)

    panel_x = int(width * 0.08)
    panel_y = int(height * 0.48)
    panel_w = int(width * 0.84)
    panel_h = int(height * 0.42)

    draw.rounded_rectangle(
        [(panel_x, panel_y), (panel_x + panel_w, panel_y + panel_h)],
        radius=30,
        fill=(8, 14, 25, 172),
        outline=(96, 165, 250, 220),
        width=4,
    )

    wrapped = clamp_wrapped_text(description or "Description non renseignee", width_chars=56, max_lines=3)
    text_x = panel_x + 40
    content_top = panel_y + 28

    prod_label = f"Date production: {production_date}"
    exp_label = f"Date expiration: {expiration_date}"
    price_label = f"Prix: {price} DT"

    # Adapt price font to available width so it never spills over text blocks.
    price_font_size = 54
    price_font = pick_font(price_font_size, bold=True)
    max_price_width = panel_w - 80
    while price_font_size > 34:
        pbox = draw.textbbox((0, 0), price_label, font=price_font)
        if (pbox[2] - pbox[0]) <= max_price_width:
            break
        price_font_size -= 4
        price_font = pick_font(price_font_size, bold=True)

    pbox = draw.textbbox((0, 0), price_label, font=price_font)
    price_h = pbox[3] - pbox[1]
    price_y = panel_y + panel_h - 24 - price_h

    # Ensure description + dates always fit above price by reducing desc lines when needed.
    chosen_desc = wrapped
    meta_gap = 40
    mbox = draw.textbbox((0, 0), prod_label, font=meta_font)
    meta_h = mbox[3] - mbox[1]

    for desc_lines in (3, 2, 1):
        candidate_desc = clamp_wrapped_text(description or "Description non renseignee", width_chars=56, max_lines=desc_lines)
        dbox = draw.multiline_textbbox((text_x, content_top), candidate_desc, font=body_font, spacing=10)
        desc_bottom = dbox[3]
        meta_y_candidate = desc_bottom + 12
        exp_bottom = meta_y_candidate + meta_h + meta_gap
        if exp_bottom <= price_y - 16:
            chosen_desc = candidate_desc
            break

    draw.multiline_text((text_x, content_top), chosen_desc, font=body_font, fill=(219, 234, 254, 255), spacing=10)
    dbox = draw.multiline_textbbox((text_x, content_top), chosen_desc, font=body_font, spacing=10)
    meta_y = dbox[3] + 12

    max_meta_y = price_y - 16 - (meta_h * 2 + (meta_gap - meta_h))
    if meta_y > max_meta_y:
        meta_y = max(content_top + 34, max_meta_y)

    draw.text((text_x, meta_y), prod_label, font=meta_font, fill=(191, 219, 254, 255))
    draw.text((text_x, meta_y + meta_gap), exp_label, font=meta_font, fill=(191, 219, 254, 255))

    draw.text((panel_x + 40, price_y), price_label, font=price_font, fill=(110, 231, 183, 255))

    canvas.save(output_path, format="PNG")


def safe_audio(tts_text, temp_dir):
    text = tts_text.strip() or "Produit disponible dans notre catalogue."
    mp3_path = os.path.join(temp_dir, "voice.mp3")
    try:
        tts = gTTS(text=text, lang="fr")
        tts.save(mp3_path)
        return AudioFileClip(mp3_path)
    except Exception:
        # Offline fallback when gTTS cannot reach service.
        return AudioClip(lambda t: 0.0, duration=8.0, fps=44100)


def load_payload_from_argv(argv):
    if len(argv) < 2:
        raise ValueError("Missing JSON payload argument.")

    if argv[1] == "--payload-file":
        if len(argv) < 3:
            raise ValueError("Missing payload file path.")

        payload_path = argv[2]
        if not os.path.isfile(payload_path):
            raise ValueError("Payload file not found.")

        with open(payload_path, "r", encoding="utf-8") as fh:
            raw = fh.read()

        return json.loads(raw)

    return json.loads(argv[1])


def main():
    try:
        payload = load_payload_from_argv(sys.argv)
    except (ValueError, json.JSONDecodeError):
        print(json.dumps({"ok": False, "message": "Invalid JSON payload."}))
        sys.exit(1)

    project_dir = payload.get("project_dir") or os.getcwd()
    product_id = int(payload.get("id") or 0)
    name = str(payload.get("name") or "Produit")
    description = str(payload.get("description") or "")
    price = str(payload.get("price") or "0.00")
    quality = str(payload.get("quality") or "Standard")
    production_date = str(payload.get("production_date") or "Non renseignee")
    expiration_date = str(payload.get("expiration_date") or "Non renseignee")
    tts_text = str(payload.get("tts_text") or "")
    image_path = str(payload.get("image_path") or "")

    public_videos_dir = os.path.join(project_dir, "public", "generated_videos")
    os.makedirs(public_videos_dir, exist_ok=True)

    temp_dir = tempfile.mkdtemp(prefix="video_gen_")
    audio_clip = None
    bg_clip = None
    text_clip = None
    final_clip = None

    try:
        width = 960
        height = 540

        bg_path = os.path.join(temp_dir, "bg.png")
        title_path = os.path.join(temp_dir, "title.png")
        details_path = os.path.join(temp_dir, "details.png")

        create_background(image_path, width, height, bg_path)
        create_title_layer(width, height, name, quality, title_path)
        create_details_layer(width, height, description, production_date, expiration_date, price, details_path)

        if tts_text.strip() == "":
            tts_text = (
                f"Produit {name}. Qualite {quality}. Date de production {production_date}. "
                f"Date d expiration {expiration_date}. Prix {price} dinars tunisiens."
            )

        audio_clip = safe_audio(tts_text, temp_dir)
        duration = max(5.5, min(14.0, float(audio_clip.duration)))

        bg_clip = clip_with_duration(ImageClip(bg_path), duration)
        bg_clip = clip_zoom(bg_clip, duration)
        bg_clip = clip_with_position(bg_clip, "center")

        title_clip = clip_with_duration(ImageClip(title_path), duration)
        title_clip = clip_with_position(title_clip, lambda t: ("center", 12 + int(min(24, t * 10))))
        title_clip = clip_fadein(title_clip, 0.55)

        details_clip = clip_with_duration(ImageClip(details_path), max(0.0, duration - 0.9))
        details_clip = clip_with_start(details_clip, 0.9)
        details_clip = clip_with_position(details_clip, lambda t: ("center", 22 + int(min(22, t * 8))))
        details_clip = clip_fadein(details_clip, 0.65)

        final_clip = CompositeVideoClip([bg_clip, title_clip, details_clip], size=(width, height))
        final_clip = clip_with_audio(final_clip, audio_clip)

        timestamp = datetime.utcnow().strftime("%Y%m%d%H%M%S")
        filename = f"product_{product_id}_{timestamp}.mp4"
        output_path = os.path.join(public_videos_dir, filename)

        final_clip.write_videofile(
            output_path,
            fps=20,
            codec="libx264",
            audio_codec="aac",
            preset="ultrafast",
            threads=2,
            logger=None,
        )
    finally:
        # Close clips explicitly to avoid Windows file locks on voice.mp3.
        for clip in (final_clip, details_clip, title_clip, bg_clip, audio_clip):
            try:
                if clip is not None:
                    clip.close()
            except Exception:
                pass

        # Retry temp cleanup briefly in case ffmpeg releases file handles with delay.
        for _ in range(4):
            try:
                shutil.rmtree(temp_dir, ignore_errors=False)
                break
            except Exception:
                time.sleep(0.2)
        else:
            shutil.rmtree(temp_dir, ignore_errors=True)

    print(
        json.dumps(
            {
                "ok": True,
                "message": "Video generated successfully.",
                "video_filename": filename,
                "video_web_path": f"/generated_videos/{filename}",
            },
            ensure_ascii=False,
        )
    )


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:
        print(json.dumps({"ok": False, "message": f"Video generation error: {exc}"}))
        sys.exit(1)

#!/usr/bin/env python3
"""重打 PixiJS 雪碧图：帧名 = 服务端协议码（前后端 1:1）。

  A3…A15 = 黑桃 3…2（A14=A，A15=2）
  B3…B15 / C3…C15 / D3…D15 同理
  E16 / E17 = 小王 / 大王
  F55 = 癞子
  BACK = 牌背

行内按斗地主权重从小到大：3→2。
美术导出请直接用上述文件名（如 A14.png），不要用 A_1.png。
"""
from __future__ import annotations

import json
from pathlib import Path

from PIL import Image

ROOT = Path("/www/wwwroot/qipai/cards")
OLD_JSON = Path("/tmp/old-cards.json")
OLD_PNG = ROOT / "src/assets/images/cards/cards.png"
OUT_SHEET = ROOT / "public/assets/sheets"
OUT_CARD = ROOT / "public/assets/images/card"
OUT_SRC_JSON = ROOT / "src/assets/images/cards/cards.json"

FW, FH = 116, 159
COLS, ROWS = 13, 5

# 旧 card/N.png → 协议帧名（目视核对）
OLD_TO_NEW = {
    "4": "BACK",
    "5": "E16",
    "6": "E17",
    "55": "F55",
    # 黑桃
    "18": "A3",
    "17": "A4",
    "16": "A5",
    "15": "A6",
    "14": "A7",
    "13": "A8",
    "12": "A9",
    "11": "A10",
    "9": "A11",
    "8": "A12",
    "7": "A13",
    "20": "A14",
    "19": "A15",
    # 红桃
    "33": "B3",
    "31": "B4",
    "30": "B5",
    "29": "B6",
    "28": "B7",
    "27": "B8",
    "26": "B9",
    "25": "B10",
    "24": "B11",
    "23": "B12",
    "22": "B13",
    "35": "B14",
    "34": "B15",
    # 梅花
    "47": "C3",
    "46": "C4",
    "45": "C5",
    "44": "C6",
    "42": "C7",
    "41": "C8",
    "40": "C9",
    "39": "C10",
    "38": "C11",
    "37": "C12",
    "36": "C13",
    "49": "C14",
    "48": "C15",
    # 方片
    "32": "D3",
    "21": "D4",
    "10": "D5",
    "3": "D6",
    "2": "D7",
    "1": "D8",
    "0": "D9",
    "53": "D10",
    "52": "D11",
    "51": "D12",
    "50": "D13",
    "54": "D14",
    "43": "D15",
}


def crop(src: Image.Image, frames: dict, key: str) -> Image.Image:
    fr = frames[key]["frame"]
    box = (fr["x"], fr["y"], fr["x"] + fr["w"], fr["y"] + fr["h"])
    return src.crop(box)


def frame_entry(x: int, y: int) -> dict:
    return {
        "frame": {"x": x, "y": y, "w": FW, "h": FH},
        "rotated": False,
        "trimmed": False,
        "spriteSourceSize": {"x": 0, "y": 0, "w": FW, "h": FH},
        "sourceSize": {"w": FW, "h": FH},
        "anchor": {"x": 0.5, "y": 0.5},
    }


def main() -> None:
    if not OLD_JSON.exists():
        raise SystemExit(f"missing {OLD_JSON}")

    old = json.loads(OLD_JSON.read_text(encoding="utf-8"))
    frames = old["frames"]
    src = Image.open(OLD_PNG).convert("RGBA")

    new_to_old = {v: f"card/{k}.png" for k, v in OLD_TO_NEW.items()}
    deal_order = list(range(3, 16))  # 3…15
    layout: list[tuple[str, str, int, int]] = []
    for row, suit in enumerate("ABCD"):
        for col, point in enumerate(deal_order):
            name = f"{suit}{point}"
            layout.append((name, new_to_old[name], row, col))
    layout.extend(
        [
            ("E16", new_to_old["E16"], 4, 0),
            ("E17", new_to_old["E17"], 4, 1),
            ("F55", new_to_old["F55"], 4, 2),
            ("BACK", new_to_old["BACK"], 4, 3),
        ]
    )
    missing = [n for n, old_key, _, _ in layout if old_key not in frames]
    if missing:
        raise SystemExit(f"missing old frames: {missing}")

    canvas = Image.new("RGBA", (COLS * FW, ROWS * FH), (0, 0, 0, 0))
    out_frames: dict[str, dict] = {}
    for name, old_key, row, col in layout:
        tile = crop(src, frames, old_key)
        x, y = col * FW, row * FH
        canvas.paste(tile, (x, y))
        out_frames[name] = frame_entry(x, y)

    sheet = {
        "frames": out_frames,
        "animations": {
            "deal": [f"{s}{p}" for s in "ABCD" for p in deal_order] + ["E16", "E17"],
        },
        "meta": {
            "app": "https://pixijs.com/",
            "version": "1.2",
            "image": "cards.png",
            "format": "RGBA8888",
            "size": {"w": COLS * FW, "h": ROWS * FH},
            "scale": 1,
            "smartupdate": "帧名=协议码 A14=黑桃A；勿用 A_1",
        },
    }
    text = json.dumps(sheet, ensure_ascii=False, indent=2)

    OUT_SHEET.mkdir(parents=True, exist_ok=True)
    OUT_CARD.mkdir(parents=True, exist_ok=True)
    canvas.save(OUT_SHEET / "cards.png")
    (OUT_SHEET / "cards.json").write_text(text + "\n", encoding="utf-8")

    card_json = json.loads(text)
    card_json["meta"]["image"] = "card.png"
    canvas.save(OUT_CARD / "card.png")
    (OUT_CARD / "card.json").write_text(json.dumps(card_json, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    OUT_SRC_JSON.parent.mkdir(parents=True, exist_ok=True)
    OUT_SRC_JSON.write_text(text + "\n", encoding="utf-8")

    print(f"frames={len(out_frames)} size={canvas.size}")
    print("A14=黑桃A  A15=黑桃2  E16=小王  F55=癞子")
    print("ok", OUT_SHEET / "cards.json")


if __name__ == "__main__":
    main()

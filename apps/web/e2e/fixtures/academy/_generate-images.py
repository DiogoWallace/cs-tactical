"""Gera os PNG do conteúdo E2E da Academy (sem dependências: zlib da biblioteca padrão).

Uso: python3 apps/web/e2e/fixtures/academy/_generate-images.py
"""

import struct
import zlib
from pathlib import Path

ROOT = Path(__file__).resolve().parent


def png(width: int, height: int, color: tuple[int, int, int], stripe: tuple[int, int, int]) -> bytes:
    rows = b""
    for y in range(height):
        row = b"\x00"
        for x in range(width):
            row += bytes(stripe if (x + y) % 32 < 6 else color)
        rows += row

    def chunk(kind: bytes, data: bytes) -> bytes:
        return struct.pack(">I", len(data)) + kind + data + struct.pack(">I", zlib.crc32(kind + data))

    header = struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0)
    return b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", header) + chunk(b"IDAT", zlib.compress(rows, 9)) + chunk(b"IEND", b"")


IMAGES = {
    "fundamentos-e2e/media/capa.png": png(320, 180, (30, 34, 44), (230, 140, 40)),
    "fundamentos-e2e/media/grafico.png": png(240, 135, (240, 240, 245), (40, 120, 220)),
    "fundamentos-e2e/media/decorativa.png": png(160, 90, (60, 66, 80), (90, 96, 110)),
}

for relative, data in IMAGES.items():
    path = ROOT / relative
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_bytes(data)
    print(f"{relative}: {len(data)} bytes")

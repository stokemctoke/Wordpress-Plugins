#!/usr/bin/env python3
"""Build the two textures bundled with the Sea Level Globe plugin.

Outputs (written to ../assets/):
  earth-color.jpg      4096x2048 NASA Blue Marble (public domain)
  earth-elevation.png  4096x2048 RGB PNG; elevation in metres encoded as
                       value = R*256 + G - 11000  (range -11000..+14535 m)

Elevation source: NOAA ETOPO1 global relief served by the CoastWatch ERDDAP
grid-subset service, so only a strided ~4300x2160 slice is downloaded rather
than the full-resolution GeoTIFF. Fetched as NetCDF-3 (readable by scipy).

Usage:  python3 build_textures.py
Deps:   pip install numpy pillow requests scipy
"""

import io
import os
import sys
import tempfile

import numpy as np
import requests
from PIL import Image
from scipy.io import netcdf_file

Image.MAX_IMAGE_PIXELS = None

HERE = os.path.dirname(os.path.abspath(__file__))
ASSETS = os.path.join(HERE, "..", "assets")

OUT_W, OUT_H = 4096, 2048
ELEV_OFFSET = 11000  # metres added before uint16 encoding

BLUE_MARBLE_URL = (
    "https://eoimages.gsfc.nasa.gov/images/imagerecords/73000/73909/"
    "world.topo.bathy.200412.3x5400x2700.jpg"
)

# ETOPO1 (1 arc-minute, ice surface) via ERDDAP griddap. Stride 5 yields a
# 2161x4321 grid (~5.5 km/pixel at the equator), plenty for a 4096x2048 map.
ERDDAP_SERVERS = [
    "https://coastwatch.pfeg.noaa.gov/erddap/griddap/etopo180",
    "https://upwell.pfeg.noaa.gov/erddap/griddap/etopo180",
]
ERDDAP_QUERY = ".nc?altitude%5B(-90):5:(90)%5D%5B(-180):5:(180)%5D"


def fetch(url, desc):
    print(f"Downloading {desc}\n  {url}")
    resp = requests.get(url, timeout=600)
    resp.raise_for_status()
    print(f"  {len(resp.content) / 1e6:.1f} MB received")
    return resp.content


def build_color():
    raw = fetch(BLUE_MARBLE_URL, "NASA Blue Marble imagery")
    img = Image.open(io.BytesIO(raw)).convert("RGB")
    img = img.resize((OUT_W, OUT_H), Image.LANCZOS)
    out = os.path.join(ASSETS, "earth-color.jpg")
    img.save(out, "JPEG", quality=85, optimize=True)
    print(f"Wrote {out} ({os.path.getsize(out) / 1e6:.1f} MB)")


def fetch_etopo_nc():
    last_err = None
    for server in ERDDAP_SERVERS:
        try:
            return fetch(server + ERDDAP_QUERY, "ETOPO1 elevation grid (ERDDAP NetCDF)")
        except Exception as e:  # try the mirror before giving up
            print(f"  failed: {e}")
            last_err = e
    raise last_err


def build_elevation():
    raw = fetch_etopo_nc()
    print("Parsing NetCDF grid…")
    with tempfile.NamedTemporaryFile(suffix=".nc") as tmp:
        tmp.write(raw)
        tmp.flush()
        with netcdf_file(tmp.name, "r", mmap=False) as nc:
            lats = nc.variables["latitude"][:].copy()
            grid = nc.variables["altitude"][:].astype(np.float64)
    # Image rows run north to south; flip if latitude is ascending.
    if lats[0] < lats[-1]:
        grid = np.flipud(grid)
    print(f"  grid {grid.shape[1]}x{grid.shape[0]}, "
          f"range {np.nanmin(grid):.0f}..{np.nanmax(grid):.0f} m")

    # Resample to output size via PIL (32-bit float mode).
    img = Image.fromarray(grid.astype(np.float32), mode="F")
    img = img.resize((OUT_W, OUT_H), Image.BILINEAR)
    elev = np.asarray(img)

    encoded = np.clip(elev + ELEV_OFFSET, 0, 65535).astype(np.uint16)
    rgb = np.zeros((OUT_H, OUT_W, 3), dtype=np.uint8)
    rgb[:, :, 0] = encoded >> 8
    rgb[:, :, 1] = encoded & 0xFF

    out = os.path.join(ASSETS, "earth-elevation.png")
    Image.fromarray(rgb, mode="RGB").save(out, "PNG", optimize=True)
    print(f"Wrote {out} ({os.path.getsize(out) / 1e6:.1f} MB)")


def main():
    os.makedirs(ASSETS, exist_ok=True)
    build_color()
    build_elevation()
    print("Done.")


if __name__ == "__main__":
    sys.exit(main())

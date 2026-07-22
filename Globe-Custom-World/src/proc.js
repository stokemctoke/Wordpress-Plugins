/**
 * Procedural planet elevation generator.
 *
 * Produces an equirectangular canvas encoding elevation the same way as the
 * bundled ETOPO texture (metres = R*256 + G - 11000). Noise is sampled in 3D
 * on the unit sphere, so there is no seam at the antimeridian and no pinching
 * at the poles.
 */

const W = 1024;
const H = 512;

/** Deterministic pseudo-random gradient table from a seed. */
function makeRng(seed) {
  let s = seed >>> 0;
  return function () {
    s = (s * 1664525 + 1013904223) >>> 0;
    return s / 4294967296;
  };
}

/** 3D value noise with a seeded permutation-free hash. */
function makeNoise(seed) {
  const rng = makeRng(seed);
  const ox = rng() * 256;
  const oy = rng() * 256;
  const oz = rng() * 256;

  function hash(x, y, z) {
    let h = Math.sin((x + ox) * 127.1 + (y + oy) * 311.7 + (z + oz) * 74.7) * 43758.5453;
    return h - Math.floor(h);
  }

  function smooth(t) {
    return t * t * (3 - 2 * t);
  }

  return function noise(x, y, z) {
    const xi = Math.floor(x), yi = Math.floor(y), zi = Math.floor(z);
    const xf = smooth(x - xi), yf = smooth(y - yi), zf = smooth(z - zi);

    const lerp = (a, b, t) => a + (b - a) * t;
    const c000 = hash(xi, yi, zi), c100 = hash(xi + 1, yi, zi);
    const c010 = hash(xi, yi + 1, zi), c110 = hash(xi + 1, yi + 1, zi);
    const c001 = hash(xi, yi, zi + 1), c101 = hash(xi + 1, yi, zi + 1);
    const c011 = hash(xi, yi + 1, zi + 1), c111 = hash(xi + 1, yi + 1, zi + 1);

    return lerp(
      lerp(lerp(c000, c100, xf), lerp(c010, c110, xf), yf),
      lerp(lerp(c001, c101, xf), lerp(c011, c111, xf), yf),
      zf
    );
  };
}

/**
 * Generate a planet heightfield onto a canvas.
 *
 * @param {number} seed Any integer.
 * @returns {HTMLCanvasElement}
 */
export function generatePlanetCanvas(seed) {
  const noise = makeNoise(seed);
  const canvas = document.createElement('canvas');
  canvas.width = W;
  canvas.height = H;
  const ctx = canvas.getContext('2d');
  const img = ctx.createImageData(W, H);
  const data = img.data;

  const OCTAVES = 5;

  for (let y = 0; y < H; y++) {
    const lat = ((y + 0.5) / H - 0.5) * Math.PI; // -pi/2 .. pi/2 (north up)
    const cosLat = Math.cos(lat);
    const sinLat = Math.sin(-lat);
    for (let x = 0; x < W; x++) {
      const lon = ((x + 0.5) / W - 0.5) * 2 * Math.PI;
      const px = cosLat * Math.cos(lon);
      const py = sinLat;
      const pz = cosLat * Math.sin(lon);

      // Continents: low-frequency fbm; detail: higher octaves.
      let v = 0;
      let amp = 0.5;
      let freq = 1.6;
      for (let o = 0; o < OCTAVES; o++) {
        v += amp * noise(px * freq + 7, py * freq + 7, pz * freq + 7);
        amp *= 0.5;
        freq *= 2.1;
      }
      // v in ~0..1 -> signed and amplified so continents are well defined,
      // with a slight downward bias to keep oceans in the majority.
      let e = (v - 0.5) * 3.0;
      e = Math.max(-1, Math.min(1, e));
      e = Math.sign(e) * Math.pow(Math.abs(e), 1.15);
      let metres = e * 8000 - 500;
      metres = Math.max(-10500, Math.min(8500, metres));

      const enc = Math.round(metres + 11000);
      const i = (y * W + x) * 4;
      data[i] = enc >> 8;
      data[i + 1] = enc & 0xff;
      data[i + 2] = 0;
      data[i + 3] = 255;
    }
  }

  ctx.putImageData(img, 0, 0);
  return canvas;
}

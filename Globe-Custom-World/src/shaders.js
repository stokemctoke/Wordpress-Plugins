/**
 * GLSL for the planet surface, cloud layer, and atmosphere rim.
 *
 * Elevation texture encoding (shared with the texture build script):
 *   metres = R * 256 + G - 11000
 */

export const PLANET_VERTEX = /* glsl */ `
  varying vec2 vUv;
  varying vec3 vNormalW;
  void main() {
    vUv = uv;
    vNormalW = normalize(mat3(modelMatrix) * normal);
    gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
  }
`;

export const PLANET_FRAGMENT = /* glsl */ `
  uniform sampler2D colorMap;
  uniform sampler2D elevMap;
  uniform float seaLevelM;    // metres relative to datum
  uniform float iceAmount;    // 0..1
  uniform float vegetation;   // 0..1 (0.5 = natural Earth)
  uniform float oceanHue;     // 0..1 (hue fraction)
  uniform vec3 sunDir;        // world space
  uniform float useProcColor; // 1.0 = derive land color from elevation (custom worlds)

  varying vec2 vUv;
  varying vec3 vNormalW;

  const float ELEV_OFFSET = 11000.0;

  float decodeElevation(vec2 uv) {
    vec2 rg = texture2D(elevMap, uv).rg;
    return (rg.r * 255.0 * 256.0 + rg.g * 255.0) - ELEV_OFFSET;
  }

  // https://stackoverflow.com/a/17897228 - branchless HSV to RGB
  vec3 hsv2rgb(vec3 c) {
    vec4 K = vec4(1.0, 2.0 / 3.0, 1.0 / 3.0, 3.0);
    vec3 p = abs(fract(c.xxx + K.xyz) * 6.0 - K.www);
    return c.z * mix(K.xxx, clamp(p - K.xxx, 0.0, 1.0), c.y);
  }

  float luma(vec3 c) {
    return dot(c, vec3(0.299, 0.587, 0.114));
  }

  void main() {
    float elev = decodeElevation(vUv);
    float lat = abs(vUv.y * 2.0 - 1.0);          // 0 equator .. 1 pole
    float relElev = elev - seaLevelM;            // height above current sea

    // "Coldness" drives ice: latitude dominates, altitude helps.
    float coldness = pow(lat, 2.2) + clamp(relElev / 9000.0, 0.0, 1.0) * 0.55;
    float iceThreshold = 1.05 - iceAmount * 1.15;

    vec3 color;

    if (elev <= seaLevelM) {
      // ---- Water ----
      float depth = seaLevelM - elev;
      float t = clamp(depth / 200.0, 0.0, 1.0);
      vec3 shallow = hsv2rgb(vec3(oceanHue, 0.62, 0.55));
      vec3 deep = hsv2rgb(vec3(oceanHue, 0.80, 0.22));
      color = mix(shallow, deep, t);
      // Tint newly flooded land so it reads as flooding, not ocean.
      if (elev > 0.0) {
        color = mix(color, hsv2rgb(vec3(oceanHue, 0.45, 0.70)), 0.30);
      }
      // Sea ice forms a bit later than land ice.
      if (coldness > iceThreshold + 0.07) {
        color = mix(color, vec3(0.90, 0.94, 0.97), 0.85);
      }
    } else {
      // ---- Land ----
      if (useProcColor > 0.5) {
        // Custom world: color from elevation ramp + vegetation.
        float h = clamp(relElev / 5000.0, 0.0, 1.0);
        vec3 lowVeg = mix(vec3(0.78, 0.68, 0.45), vec3(0.13, 0.38, 0.14), vegetation);
        vec3 highVeg = mix(vec3(0.62, 0.51, 0.36), vec3(0.16, 0.30, 0.13), vegetation * 0.7);
        vec3 rock = vec3(0.42, 0.38, 0.34);
        color = mix(lowVeg, highVeg, smoothstep(0.0, 0.35, h));
        color = mix(color, rock, smoothstep(0.35, 0.8, h));
        // Beach strip just above the waterline.
        color = mix(vec3(0.80, 0.72, 0.52), color, smoothstep(0.0, 60.0, relElev));
      } else {
        // Earth: photo texture restyled by the vegetation slider.
        color = texture2D(colorMap, vUv).rgb;
        float t = vegetation * 2.0 - 1.0;   // -1 desert .. 0 natural .. +1 lush
        if (t < 0.0) {
          vec3 desert = vec3(0.80, 0.66, 0.44) * (0.35 + luma(color) * 1.1);
          color = mix(color, desert, -t * 0.85);
        } else {
          vec3 lush = mix(color, vec3(0.10, 0.42, 0.12) * (0.4 + luma(color) * 1.5), 0.65);
          color = mix(color, lush, t * 0.75);
        }
        // Newly exposed seabed rendered as sand.
        if (elev < 0.0) {
          float shade = clamp(1.0 + elev / 8000.0, 0.35, 1.0);
          color = vec3(0.71, 0.62, 0.46) * shade;
        }
      }
      // Land ice / snow caps.
      float snow = smoothstep(iceThreshold, iceThreshold + 0.12, coldness);
      color = mix(color, vec3(0.93, 0.96, 0.99), snow * 0.95);
    }

    // ---- Sunlight with a soft day-night terminator ----
    float ndl = dot(vNormalW, sunDir);
    float day = smoothstep(-0.12, 0.18, ndl);
    float diffuse = max(ndl, 0.0);
    float lit = mix(0.10, 0.45 + diffuse * 0.65, day);
    color *= lit;

    gl_FragColor = vec4(color, 1.0);
  }
`;

export const CLOUD_VERTEX = /* glsl */ `
  varying vec3 vPos;
  varying vec3 vNormalW;
  void main() {
    vPos = position;
    vNormalW = normalize(mat3(modelMatrix) * normal);
    gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
  }
`;

export const CLOUD_FRAGMENT = /* glsl */ `
  uniform float coverage;  // 0..1
  uniform float seed;
  uniform vec3 sunDir;
  varying vec3 vPos;
  varying vec3 vNormalW;

  float hash(vec3 p) {
    p = fract(p * 0.3183099 + 0.1);
    p *= 17.0;
    return fract(p.x * p.y * p.z * (p.x + p.y + p.z));
  }

  float noise(vec3 x) {
    vec3 i = floor(x);
    vec3 f = fract(x);
    f = f * f * (3.0 - 2.0 * f);
    return mix(
      mix(mix(hash(i), hash(i + vec3(1, 0, 0)), f.x),
          mix(hash(i + vec3(0, 1, 0)), hash(i + vec3(1, 1, 0)), f.x), f.y),
      mix(mix(hash(i + vec3(0, 0, 1)), hash(i + vec3(1, 0, 1)), f.x),
          mix(hash(i + vec3(0, 1, 1)), hash(i + vec3(1, 1, 1)), f.x), f.y),
      f.z);
  }

  float fbm(vec3 p) {
    float v = 0.0;
    float a = 0.5;
    for (int i = 0; i < 5; i++) {
      v += a * noise(p);
      p *= 2.03;
      a *= 0.5;
    }
    return v;
  }

  void main() {
    if (coverage <= 0.001) discard;
    float n = fbm(vPos * 3.5 + vec3(seed * 91.7));
    // Higher coverage lowers the density threshold.
    float threshold = mix(0.72, 0.30, coverage);
    float alpha = smoothstep(threshold, threshold + 0.18, n);

    float ndl = dot(vNormalW, sunDir);
    float day = smoothstep(-0.12, 0.18, ndl);
    vec3 color = vec3(1.0) * mix(0.08, 0.45 + max(ndl, 0.0) * 0.6, day);

    gl_FragColor = vec4(color, alpha * 0.92);
  }
`;

export const ATMO_VERTEX = /* glsl */ `
  varying vec3 vNormalV;
  varying vec3 vPosV;
  void main() {
    vNormalV = normalize(normalMatrix * normal);
    vec4 mv = modelViewMatrix * vec4(position, 1.0);
    vPosV = mv.xyz;
    gl_Position = projectionMatrix * mv;
  }
`;

export const ATMO_FRAGMENT = /* glsl */ `
  uniform float strength;  // 0..1
  uniform vec3 tint;
  varying vec3 vNormalV;
  varying vec3 vPosV;

  void main() {
    // Rendered on the back side, so the rim is where the normal grazes the view ray.
    vec3 viewDir = normalize(-vPosV);
    float rim = pow(clamp(dot(viewDir, normalize(vNormalV)) + 1.0, 0.0, 1.0), 3.0);
    gl_FragColor = vec4(tint, rim * strength);
  }
`;

/**
 * Sea Level Globe – front-end app.
 *
 * Finds every .slg-globe container on the page and mounts an independent
 * Three.js globe in it. Sea level is applied in a fragment shader that
 * compares a 16-bit elevation texture against a uniform, so slider
 * changes recolor the planet instantly on the GPU.
 */
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { createControls } from './ui.js';

const FT_PER_M = 3.28084;

// Elevation texture encoding: value = (R * 256 + G) - 11000, in metres.
// See tools/build_textures.py.
const ELEV_OFFSET_M = 11000.0;

const VERTEX_SHADER = /* glsl */ `
  varying vec2 vUv;
  varying vec3 vNormal;
  void main() {
    vUv = uv;
    vNormal = normalize(normalMatrix * normal);
    gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
  }
`;

const FRAGMENT_SHADER = /* glsl */ `
  uniform sampler2D colorMap;
  uniform sampler2D elevMap;
  uniform float seaLevelM;   // metres relative to today's sea level
  varying vec2 vUv;
  varying vec3 vNormal;

  const float ELEV_OFFSET = ${ELEV_OFFSET_M.toFixed(1)};

  float decodeElevation(vec2 uv) {
    vec2 rg = texture2D(elevMap, uv).rg;
    return (rg.r * 255.0 * 256.0 + rg.g * 255.0) - ELEV_OFFSET;
  }

  void main() {
    float elev = decodeElevation(vUv);
    vec3 landColor = texture2D(colorMap, vUv).rgb;

    vec3 color;
    if (elev <= seaLevelM) {
      float depth = seaLevelM - elev;
      // Deep water is dark blue; shallow water near the new shoreline is lighter.
      vec3 deepWater = vec3(0.02, 0.09, 0.30);
      vec3 shallowWater = vec3(0.15, 0.42, 0.66);
      float t = clamp(depth / 200.0, 0.0, 1.0);
      color = mix(shallowWater, deepWater, t);
      // Newly flooded land (was above today's sea level) gets a subtle tint
      // so viewers can distinguish new flooding from existing ocean.
      if (elev > 0.0) {
        color = mix(color, vec3(0.25, 0.55, 0.75), 0.35);
      }
    } else {
      color = landColor;
      // Newly exposed seabed (below today's sea level) rendered as sandy tone.
      if (elev < 0.0) {
        float shade = clamp(1.0 + elev / 8000.0, 0.35, 1.0);
        color = vec3(0.71, 0.62, 0.46) * shade;
      }
    }

    // Simple hemispherical lighting so the sphere reads as 3D.
    vec3 lightDir = normalize(vec3(0.5, 0.3, 1.0));
    float diffuse = max(dot(vNormal, lightDir), 0.0);
    float ambient = 0.55;
    color *= ambient + diffuse * 0.55;

    gl_FragColor = vec4(color, 1.0);
  }
`;

function mountGlobe(container) {
  const cfg = {
    min: parseFloat(container.dataset.min) || -10000,
    max: parseFloat(container.dataset.max) || 10000,
    start: parseFloat(container.dataset.start) || 0,
    step: parseFloat(container.dataset.step) || 10,
    unit: container.dataset.unit === 'm' ? 'm' : 'ft',
    colorSrc: container.dataset.colorSrc,
    elevSrc: container.dataset.elevSrc,
  };

  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(45, 1, 0.1, 100);
  camera.position.z = 3;

  const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));

  const loader = new THREE.TextureLoader();
  const colorMap = loader.load(cfg.colorSrc, () => render());
  colorMap.colorSpace = THREE.SRGBColorSpace;
  const elevMap = loader.load(cfg.elevSrc, () => {
    container.querySelector('.slg-loading')?.remove();
    render();
  });
  // Elevation texture must not be filtered across the 16-bit channel split
  // in a way that invents values, but linear filtering of R and G separately
  // is acceptable at this resolution; disable mipmaps to avoid haloing.
  elevMap.generateMipmaps = false;
  elevMap.minFilter = THREE.LinearFilter;
  elevMap.magFilter = THREE.LinearFilter;

  const uniforms = {
    colorMap: { value: colorMap },
    elevMap: { value: elevMap },
    seaLevelM: { value: toMetres(cfg.start, cfg.unit) },
  };

  const globe = new THREE.Mesh(
    new THREE.SphereGeometry(1, 128, 64),
    new THREE.ShaderMaterial({
      uniforms,
      vertexShader: VERTEX_SHADER,
      fragmentShader: FRAGMENT_SHADER,
    })
  );
  scene.add(globe);

  // Faint starfield-free dark backdrop is handled by CSS; keep canvas transparent.

  const controls = new OrbitControls(camera, renderer.domElement);
  controls.enableDamping = true;
  controls.dampingFactor = 0.08;
  controls.rotateSpeed = 0.5;
  controls.minDistance = 1.15;
  controls.maxDistance = 6;
  controls.enablePan = false;

  const canvasWrap = document.createElement('div');
  canvasWrap.className = 'slg-canvas';
  canvasWrap.appendChild(renderer.domElement);
  container.appendChild(canvasWrap);

  let needsRender = true;
  function render() {
    needsRender = true;
  }

  createControls(container, cfg, (value, unit) => {
    uniforms.seaLevelM.value = toMetres(value, unit);
    render();
  });

  function animate() {
    requestAnimationFrame(animate);
    const moved = controls.update();
    if (moved || needsRender) {
      needsRender = false;
      renderer.render(scene, camera);
    }
  }

  function resize() {
    const w = canvasWrap.clientWidth;
    const h = canvasWrap.clientHeight;
    if (w === 0 || h === 0) return;
    renderer.setSize(w, h);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
    render();
  }

  new ResizeObserver(resize).observe(canvasWrap);
  resize();
  animate();
}

function toMetres(value, unit) {
  return unit === 'ft' ? value / FT_PER_M : value;
}

function init() {
  document.querySelectorAll('.slg-globe').forEach((el) => {
    if (!el.dataset.slgMounted) {
      el.dataset.slgMounted = '1';
      mountGlobe(el);
    }
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}

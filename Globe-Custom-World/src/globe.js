/**
 * World Builder Globe – front-end app.
 *
 * Mounts an independent Three.js planet in every .wbg-globe container.
 * All variables (sea level, ice, vegetation, clouds, atmosphere, ocean hue,
 * sun angle, spin) are shader uniforms or cheap mesh properties, so slider
 * changes apply instantly.
 */
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import {
  PLANET_VERTEX, PLANET_FRAGMENT,
  CLOUD_VERTEX, CLOUD_FRAGMENT,
  ATMO_VERTEX, ATMO_FRAGMENT,
} from './shaders.js';
import { generatePlanetCanvas } from './proc.js';
import { createControls } from './ui.js';

const FT_PER_M = 3.28084;

function readConfig(el) {
  const num = (key, fallback) => {
    const v = parseFloat(el.dataset[key]);
    return Number.isFinite(v) ? v : fallback;
  };
  return {
    mode: el.dataset.mode === 'custom' ? 'custom' : 'earth',
    sea: num('sea', 0),
    ice: num('ice', 15),
    vegetation: num('vegetation', 50),
    clouds: num('clouds', 30),
    atmosphere: num('atmosphere', 40),
    ocean: num('ocean', 210),
    sun: num('sun', 35),
    spin: num('spin', 0),
    colorSrc: el.dataset.colorSrc,
    elevSrc: el.dataset.elevSrc,
  };
}

function sunVector(deg) {
  const az = (deg * Math.PI) / 180;
  return new THREE.Vector3(Math.cos(az), 0.35, Math.sin(az)).normalize();
}

function mountGlobe(container) {
  const cfg = readConfig(container);

  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(45, 1, 0.1, 100);
  camera.position.z = 3.1;

  const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));

  let needsRender = true;
  const render = () => { needsRender = true; };

  // ---- Textures ----
  const loader = new THREE.TextureLoader();
  const colorMap = loader.load(cfg.colorSrc, render);
  colorMap.colorSpace = THREE.SRGBColorSpace;

  const earthElev = loader.load(cfg.elevSrc, () => {
    container.querySelector('.wbg-loading')?.remove();
    render();
  });
  earthElev.generateMipmaps = false;
  earthElev.minFilter = THREE.LinearFilter;
  earthElev.magFilter = THREE.LinearFilter;

  let customElev = null;
  let customSeed = Math.floor(Math.random() * 1e9);

  function buildCustomElev() {
    if (customElev) customElev.dispose();
    customElev = new THREE.CanvasTexture(generatePlanetCanvas(customSeed));
    customElev.generateMipmaps = false;
    customElev.minFilter = THREE.LinearFilter;
    customElev.magFilter = THREE.LinearFilter;
    return customElev;
  }

  // ---- Planet ----
  const uniforms = {
    colorMap: { value: colorMap },
    elevMap: { value: earthElev },
    seaLevelM: { value: cfg.sea / FT_PER_M },
    iceAmount: { value: cfg.ice / 100 },
    vegetation: { value: cfg.vegetation / 100 },
    oceanHue: { value: cfg.ocean / 360 },
    sunDir: { value: sunVector(cfg.sun) },
    useProcColor: { value: cfg.mode === 'custom' ? 1 : 0 },
  };

  const planet = new THREE.Mesh(
    new THREE.SphereGeometry(1, 128, 64),
    new THREE.ShaderMaterial({
      uniforms,
      vertexShader: PLANET_VERTEX,
      fragmentShader: PLANET_FRAGMENT,
    })
  );
  scene.add(planet);

  // ---- Clouds ----
  const cloudUniforms = {
    coverage: { value: cfg.clouds / 100 },
    seed: { value: Math.random() },
    sunDir: uniforms.sunDir,
  };
  const clouds = new THREE.Mesh(
    new THREE.SphereGeometry(1.018, 96, 48),
    new THREE.ShaderMaterial({
      uniforms: cloudUniforms,
      vertexShader: CLOUD_VERTEX,
      fragmentShader: CLOUD_FRAGMENT,
      transparent: true,
      depthWrite: false,
    })
  );
  scene.add(clouds);

  // ---- Atmosphere rim ----
  const atmoUniforms = {
    strength: { value: cfg.atmosphere / 100 },
    tint: { value: new THREE.Color(0.35, 0.55, 1.0) },
  };
  const atmo = new THREE.Mesh(
    new THREE.SphereGeometry(1, 64, 32),
    new THREE.ShaderMaterial({
      uniforms: atmoUniforms,
      vertexShader: ATMO_VERTEX,
      fragmentShader: ATMO_FRAGMENT,
      transparent: true,
      side: THREE.BackSide,
      depthWrite: false,
      blending: THREE.AdditiveBlending,
    })
  );
  atmo.scale.setScalar(1.06);
  scene.add(atmo);

  // ---- Controls / DOM ----
  const controls = new OrbitControls(camera, renderer.domElement);
  controls.enableDamping = true;
  controls.dampingFactor = 0.08;
  controls.rotateSpeed = 0.5;
  controls.minDistance = 1.175;
  controls.maxDistance = 7;
  controls.enablePan = false;

  const canvasWrap = document.createElement('div');
  canvasWrap.className = 'wbg-canvas';
  canvasWrap.appendChild(renderer.domElement);
  container.appendChild(canvasWrap);

  let spinDegPerSec = cfg.spin;

  const api = {
    setMode(mode) {
      uniforms.useProcColor.value = mode === 'custom' ? 1 : 0;
      uniforms.elevMap.value = mode === 'custom' ? (customElev || buildCustomElev()) : earthElev;
      render();
    },
    randomize() {
      customSeed = Math.floor(Math.random() * 1e9);
      uniforms.elevMap.value = buildCustomElev();
      cloudUniforms.seed.value = Math.random();
      render();
    },
    set(key, value) {
      switch (key) {
        case 'sea': uniforms.seaLevelM.value = value / FT_PER_M; break;
        case 'ice': uniforms.iceAmount.value = value / 100; break;
        case 'vegetation': uniforms.vegetation.value = value / 100; break;
        case 'clouds': cloudUniforms.coverage.value = value / 100; break;
        case 'atmosphere':
          atmoUniforms.strength.value = value / 100;
          atmo.scale.setScalar(1.03 + (value / 100) * 0.07);
          break;
        case 'ocean': uniforms.oceanHue.value = value / 360; break;
        case 'sun': uniforms.sunDir.value.copy(sunVector(value)); break;
        case 'spin': spinDegPerSec = value; break;
      }
      render();
    },
  };

  createControls(container, cfg, api);
  if (cfg.mode === 'custom') api.setMode('custom');

  // ---- Render loop (on demand, continuous only while spinning) ----
  let lastT = performance.now();
  function animate(t) {
    requestAnimationFrame(animate);
    const dt = Math.min((t - lastT) / 1000, 0.1);
    lastT = t;

    if (spinDegPerSec !== 0) {
      const step = (spinDegPerSec * Math.PI / 180) * dt;
      planet.rotation.y += step;
      clouds.rotation.y += step * 1.15; // clouds drift relative to the surface
      needsRender = true;
    }
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
  requestAnimationFrame(animate);
}

function init() {
  document.querySelectorAll('.wbg-globe').forEach((el) => {
    if (!el.dataset.wbgMounted) {
      el.dataset.wbgMounted = '1';
      mountGlobe(el);
    }
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}

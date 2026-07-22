/**
 * Control panel: mode toggle, one slider + editable number input per
 * variable, randomize (custom mode) and reset buttons.
 */

const SLIDERS = [
  { key: 'sea',        label: 'Sea level',   min: -10000, max: 10000, step: 10, unit: 'ft' },
  { key: 'ice',        label: 'Ice caps',    min: 0,      max: 100,   step: 1,  unit: '%' },
  { key: 'vegetation', label: 'Vegetation',  min: 0,      max: 100,   step: 1,  unit: '%' },
  { key: 'clouds',     label: 'Cloud cover', min: 0,      max: 100,   step: 1,  unit: '%' },
  { key: 'atmosphere', label: 'Atmosphere',  min: 0,      max: 100,   step: 1,  unit: '%' },
  { key: 'ocean',      label: 'Ocean hue',   min: 0,      max: 360,   step: 1,  unit: '°' },
  { key: 'sun',        label: 'Sun angle',   min: 0,      max: 360,   step: 1,  unit: '°' },
  { key: 'spin',       label: 'Spin',        min: -10,    max: 10,    step: 0.5, unit: '°/s' },
];

/**
 * @param {HTMLElement} container The .wbg-globe element.
 * @param {object} cfg Initial values (keys matching SLIDERS plus mode).
 * @param {{ set: Function, setMode: Function, randomize: Function }} api
 */
export function createControls(container, cfg, api) {
  const panel = document.createElement('div');
  panel.className = 'wbg-panel';

  // Mode row
  const modeRow = document.createElement('div');
  modeRow.className = 'wbg-mode-row';
  modeRow.innerHTML = `
    <div class="wbg-mode-group" role="group" aria-label="World mode">
      <button type="button" class="wbg-mode" data-mode="earth">Earth</button>
      <button type="button" class="wbg-mode" data-mode="custom">Custom world</button>
    </div>
    <button type="button" class="wbg-randomize" title="Generate new continents">Randomize</button>
    <button type="button" class="wbg-reset">Reset</button>
  `;
  panel.appendChild(modeRow);

  const grid = document.createElement('div');
  grid.className = 'wbg-grid';
  panel.appendChild(grid);

  const rows = {};
  for (const def of SLIDERS) {
    const row = document.createElement('div');
    row.className = 'wbg-row';
    row.innerHTML = `
      <label class="wbg-label">${def.label} <span class="wbg-unit">${def.unit}</span></label>
      <input type="range" class="wbg-slider" min="${def.min}" max="${def.max}" step="${def.step}" />
      <input type="number" class="wbg-number" min="${def.min}" max="${def.max}" step="any"
        aria-label="${def.label}" />
    `;
    grid.appendChild(row);

    const slider = row.querySelector('.wbg-slider');
    const number = row.querySelector('.wbg-number');

    const apply = (v, fromSlider) => {
      const clamped = Math.max(def.min, Math.min(def.max, v));
      if (!fromSlider) slider.value = clamped;
      number.value = clamped;
      api.set(def.key, clamped);
    };

    slider.addEventListener('input', () => apply(parseFloat(slider.value), true));
    number.addEventListener('change', () => {
      const v = parseFloat(number.value);
      if (Number.isFinite(v)) apply(v, false);
      else number.value = slider.value;
    });
    number.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        number.dispatchEvent(new Event('change'));
      }
    });

    rows[def.key] = { def, slider, number, apply };
  }

  container.appendChild(panel);

  // ---- Mode toggle ----
  const modeButtons = panel.querySelectorAll('.wbg-mode');
  const randomizeBtn = panel.querySelector('.wbg-randomize');

  function setMode(mode) {
    modeButtons.forEach((b) => b.classList.toggle('is-active', b.dataset.mode === mode));
    randomizeBtn.style.display = mode === 'custom' ? '' : 'none';
    api.setMode(mode);
  }

  modeButtons.forEach((b) =>
    b.addEventListener('click', () => setMode(b.dataset.mode))
  );
  randomizeBtn.addEventListener('click', () => api.randomize());

  // ---- Reset ----
  panel.querySelector('.wbg-reset').addEventListener('click', () => {
    for (const key of Object.keys(rows)) {
      rows[key].apply(cfg[key], false);
      rows[key].slider.value = cfg[key];
    }
    setMode(cfg.mode);
  });

  // ---- Initial values ----
  for (const key of Object.keys(rows)) {
    rows[key].slider.value = cfg[key];
    rows[key].number.value = cfg[key];
  }
  modeButtons.forEach((b) => b.classList.toggle('is-active', b.dataset.mode === cfg.mode));
  randomizeBtn.style.display = cfg.mode === 'custom' ? '' : 'none';
}

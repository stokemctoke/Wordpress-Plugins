/**
 * Slider + text entry + unit toggle + reset button for one globe instance.
 */

const FT_PER_M = 3.28084;

/**
 * @param {HTMLElement} container The .slg-globe element.
 * @param {object} cfg  { min, max, start, step, unit } in the configured unit.
 * @param {(value: number, unit: string) => void} onChange Called with the
 *   current value in the current unit whenever it changes.
 */
export function createControls(container, cfg, onChange) {
  let unit = cfg.unit;
  let value = cfg.start;

  const panel = document.createElement('div');
  panel.className = 'slg-panel';
  panel.innerHTML = `
    <label class="slg-label">Sea level
      <span class="slg-readout"></span>
    </label>
    <input type="range" class="slg-slider" />
    <div class="slg-row">
      <input type="number" class="slg-number" aria-label="Sea level value" />
      <button type="button" class="slg-unit" aria-label="Toggle units"></button>
      <button type="button" class="slg-reset">Reset to today</button>
    </div>
  `;
  container.appendChild(panel);

  const slider = panel.querySelector('.slg-slider');
  const number = panel.querySelector('.slg-number');
  const unitBtn = panel.querySelector('.slg-unit');
  const resetBtn = panel.querySelector('.slg-reset');
  const readout = panel.querySelector('.slg-readout');

  function bounds() {
    // min/max/step were provided in cfg.unit; convert if the unit was toggled.
    const factor = unit === cfg.unit ? 1 : unit === 'm' ? 1 / FT_PER_M : FT_PER_M;
    return {
      min: cfg.min * factor,
      max: cfg.max * factor,
      step: Math.max(cfg.step * factor, 1),
    };
  }

  function refresh() {
    const b = bounds();
    slider.min = b.min;
    slider.max = b.max;
    slider.step = b.step;
    slider.value = value;
    number.min = b.min;
    number.max = b.max;
    number.step = 'any';
    number.value = round(value);
    unitBtn.textContent = unit;
    const sign = value > 0 ? '+' : '';
    readout.textContent = `${sign}${round(value).toLocaleString()} ${unit}`;
    panel.classList.toggle('slg-raised', value > 0);
    panel.classList.toggle('slg-lowered', value < 0);
  }

  function setValue(v, fireChange = true) {
    const b = bounds();
    value = Math.max(b.min, Math.min(b.max, v));
    refresh();
    if (fireChange) onChange(value, unit);
  }

  slider.addEventListener('input', () => setValue(parseFloat(slider.value)));

  number.addEventListener('change', () => {
    const v = parseFloat(number.value);
    if (!Number.isNaN(v)) setValue(v);
    else refresh();
  });
  number.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      number.dispatchEvent(new Event('change'));
    }
  });

  unitBtn.addEventListener('click', () => {
    const next = unit === 'ft' ? 'm' : 'ft';
    value = next === 'm' ? value / FT_PER_M : value * FT_PER_M;
    unit = next;
    setValue(value);
  });

  resetBtn.addEventListener('click', () => setValue(0));

  setValue(cfg.start, true);
}

function round(v) {
  return Math.abs(v) >= 100 ? Math.round(v) : Math.round(v * 10) / 10;
}

function formatHours(value) {
  if (!value) return "0";
  const rounded = Math.round(value * 100) / 100;
  if (Math.abs(rounded - Math.round(rounded)) < 0.001) return String(Math.round(rounded));
  return String(rounded);
}

function recalcHours() {
  const sheet = document.getElementById("hour-sheet");
  if (!sheet) return;
  const inputs = [...sheet.querySelectorAll("input[data-day]")];
  const days = [...new Set(inputs.map((input) => input.dataset.day))];
  let grand = 0;
  days.forEach((day) => {
    const sum = inputs
      .filter((input) => input.dataset.day === day)
      .reduce((total, input) => total + (parseFloat(input.value) || 0), 0);
    grand += sum;
    const cell = sheet.querySelector(`[data-total="${day}"]`);
    if (cell) {
      cell.textContent = formatHours(sum);
      cell.classList.toggle("is-over", sum > 24);
    }
  });
  const projects = [...new Set(inputs.map((input) => input.dataset.project))];
  projects.forEach((project) => {
    const sum = inputs
      .filter((input) => input.dataset.project === project)
      .reduce((total, input) => total + (parseFloat(input.value) || 0), 0);
    const cell = sheet.querySelector(`[data-project-total="${project}"]`);
    if (cell) cell.textContent = formatHours(sum);
  });
  const week = document.getElementById("week-total");
  const grandCell = document.getElementById("sheet-grand");
  if (week) week.textContent = formatHours(grand);
  if (grandCell) grandCell.textContent = formatHours(grand);
}

function syncManagers() {
  const role = document.getElementById("role");
  const select = document.getElementById("reports_to");
  const field = document.getElementById("manager-field");
  if (!role || !select || !field) return;
  if (role.value === "admin") {
    field.hidden = true;
    select.required = false;
    select.value = "";
    return;
  }
  field.hidden = false;
  select.required = true;
  let keep = false;
  [...select.options].forEach((option) => {
    if (!option.value) {
      option.hidden = false;
      option.disabled = false;
      return;
    }
    const roles = (option.dataset.roles || "").split(" ").filter(Boolean);
    const allowed = roles.includes(role.value);
    option.hidden = !allowed;
    option.disabled = !allowed;
    if (allowed && option.value === select.value) keep = true;
  });
  if (!keep) select.value = "";
}

document.querySelectorAll(".demo-chip").forEach((chip) => {
  chip.addEventListener("click", () => {
    const form = chip.closest("form");
    form.email.value = chip.dataset.email;
    form.password.value = chip.dataset.password;
    form.requestSubmit();
  });
});

document.querySelectorAll("form[data-confirm]").forEach((form) => {
  form.addEventListener("submit", (event) => {
    if (!window.confirm(form.dataset.confirm)) event.preventDefault();
  });
});

document.querySelectorAll("#hour-sheet input").forEach((input) => {
  input.addEventListener("input", recalcHours);
});

const role = document.getElementById("role");
if (role) {
  role.addEventListener("change", syncManagers);
  syncManagers();
}

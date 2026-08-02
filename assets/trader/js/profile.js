"use strict";

(function initPasswordStrength() {
  const pwInput = document.getElementById("passwordInput");
  const bar = document.getElementById("pwStrengthBar");
  if (!pwInput || !bar) return;
  pwInput.addEventListener("input", function () {
    const val = this.value;
    let score = 0;
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const colors = ["#ef4444", "#f97316", "#eab308", "#22c55e"];
    const widths = ["25%", "50%", "75%", "100%"];
    bar.style.width = val.length === 0 ? "0%" : widths[Math.max(score - 1, 0)];
    bar.style.background = val.length === 0 ? "" : colors[Math.max(score - 1, 0)];
  });
})();

(function initConfirmPassword() {
  const pw = document.getElementById("passwordInput");
  const confirm = document.getElementById("confirmPassword");
  if (!pw || !confirm) return;
  confirm.addEventListener("input", function () {
    this.style.borderColor = this.value && this.value !== pw.value ? "#ef4444" : "";
  });
})();

(function initFormSubmitFeedback() {
  const form = document.getElementById("profileForm");
  const btn = document.getElementById("saveContinueBtn");
  if (!form || !btn) return;
  form.addEventListener("submit", function () {
    btn.textContent = "Saving…";
    btn.disabled = true;
  });
})();

(function initSearch() {
  const input = document.getElementById("globalSearch");
  if (!input) return;
  input.addEventListener("input", function () {
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll(".form-group, .summary-tile").forEach(function (el) {
      el.style.display = el.textContent.toLowerCase().includes(q) ? "" : "none";
    });
  });
})();

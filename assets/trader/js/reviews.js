"use strict";

(function initSearch() {
  const input = document.getElementById("globalSearch");
  if (!input) return;
  input.addEventListener("input", function () {
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll(".notif-item").forEach(function (item) {
      const text = item.dataset.search || item.textContent.toLowerCase();
      item.style.display = text.includes(q) ? "" : "none";
    });
  });
})();

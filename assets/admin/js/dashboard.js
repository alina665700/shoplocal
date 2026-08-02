document.addEventListener('DOMContentLoaded', function () {
  const tooltip = document.getElementById('admGrowthTooltip');
  const hoverDots = document.querySelectorAll('.adm-hover-dot');

  if (!tooltip || !hoverDots.length) return;

  function moveTooltip(event) {
    const offset = 16;
    const tooltipWidth = tooltip.offsetWidth || 180;
    const tooltipHeight = tooltip.offsetHeight || 80;

    let left = event.clientX + offset;
    let top = event.clientY + offset;

    if (left + tooltipWidth > window.innerWidth - 12) {
      left = event.clientX - tooltipWidth - offset;
    }

    if (top + tooltipHeight > window.innerHeight - 12) {
      top = event.clientY - tooltipHeight - offset;
    }

    tooltip.style.left = left + 'px';
    tooltip.style.top = top + 'px';
  }

  hoverDots.forEach(function (dot) {
    dot.addEventListener('mouseenter', function (event) {
      const targetDot = document.getElementById(dot.dataset.dotId || '');
      if (targetDot) targetDot.classList.add('is-active');

      tooltip.innerHTML =
        '<strong>' + dot.dataset.date + '</strong>' +
        '<span>Total customers: ' + dot.dataset.total + '</span>' +
        '<span>New customers: +' + dot.dataset.new + '</span>';

      tooltip.style.display = 'block';
      moveTooltip(event);
    });

    dot.addEventListener('mousemove', moveTooltip);

    dot.addEventListener('mouseleave', function () {
      const targetDot = document.getElementById(dot.dataset.dotId || '');
      if (targetDot) targetDot.classList.remove('is-active');
      tooltip.style.display = 'none';
    });
  });
});

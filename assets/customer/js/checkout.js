(function () {
  const pickupInput = document.getElementById('pickup_date');
  const slotCards = Array.from(document.querySelectorAll('.slot-card'));
  const slotNote = document.getElementById('slotNote');

  if (!pickupInput || !slotCards.length) return;

  const days = ['SUNDAY', 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY'];
  const allowedDays = ['WEDNESDAY', 'THURSDAY', 'FRIDAY'];

  function updateSlots() {
    const value = pickupInput.value;
    let selectedDay = '';
    let enabledCount = 0;
    const minimumTime = Date.now() + (24 * 60 * 60 * 1000);

    if (value) {
      const date = new Date(value + 'T00:00:00');
      selectedDay = days[date.getDay()];
    }

    slotCards.forEach((card) => {
      const input = card.querySelector('input[type="radio"]');
      const cardDay = card.dataset.day;
      const startHour = String(card.dataset.start || '0').padStart(2, '0');
      const slotStartTime = value ? new Date(value + 'T' + startHour + ':00:00').getTime() : 0;
      const shouldDisable = !value || cardDay !== selectedDay || slotStartTime < minimumTime;

      card.classList.toggle('disabled', shouldDisable);
      input.disabled = shouldDisable;

      if (shouldDisable && input.checked) {
        input.checked = false;
      }

      if (!shouldDisable) {
        enabledCount++;
      }
    });

    if (!value) {
      slotNote.textContent = 'Pick a Wednesday, Thursday, or Friday date to see valid slots.';
      return;
    }

    if (!allowedDays.includes(selectedDay)) {
      slotNote.textContent = 'Collection is only available on Wednesday, Thursday, and Friday.';
      return;
    }

    if (enabledCount === 0) {
      slotNote.textContent = 'No slot on this date is at least 24 hours away. Choose a later date.';
      return;
    }

    slotNote.textContent = enabledCount + ' valid pickup slot' + (enabledCount === 1 ? '' : 's') + ' available for ' + selectedDay.toLowerCase() + '.';
  }

  pickupInput.addEventListener('change', updateSlots);
  updateSlots();
})();

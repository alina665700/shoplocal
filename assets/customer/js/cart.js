document.querySelectorAll('.qty-form').forEach(form => {
    const input = form.querySelector('.qty-num');
    const max = parseInt(input.getAttribute('max') || '99', 10);

    form.querySelector('.plus-btn').addEventListener('click', () => {
        input.value = Math.min(max, parseInt(input.value || '1', 10) + 1);
        form.submit();
    });

    form.querySelector('.minus-btn').addEventListener('click', () => {
        input.value = Math.max(1, parseInt(input.value || '1', 10) - 1);
        form.submit();
    });

    input.addEventListener('change', () => {
        let value = parseInt(input.value || '1', 10);
        if (Number.isNaN(value)) value = 1;
        input.value = Math.max(1, Math.min(max, value));
        form.submit();
    });
});

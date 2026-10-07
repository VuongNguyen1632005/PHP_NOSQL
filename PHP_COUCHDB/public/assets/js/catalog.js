document.addEventListener('DOMContentLoaded', () => {
    const mainImage = document.getElementById('mainProductImage');
    document.querySelectorAll('.thumb-button').forEach((button) => {
        button.addEventListener('click', () => {
            if (!mainImage) return;
            mainImage.src = button.dataset.image || mainImage.src;
            document.querySelectorAll('.thumb-button').forEach((thumb) => thumb.classList.remove('active'));
            button.classList.add('active');
        });
    });

    document.querySelectorAll('[data-quantity-step]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = button.parentElement?.querySelector('input[name="quantity"]');
            if (!(input instanceof HTMLInputElement)) return;
            const step = Number(button.dataset.quantityStep || 0);
            const maximum = Number(input.max || 999);
            input.value = String(Math.min(maximum, Math.max(1, Number(input.value || 1) + step)));
        });
    });

    const variantSelect = document.getElementById('variant_id');
    const quantityInput = document.querySelector('.add-to-cart-form input[name="quantity"]');
    if (variantSelect instanceof HTMLSelectElement && quantityInput instanceof HTMLInputElement) {
        variantSelect.addEventListener('change', () => {
            const selectedOption = variantSelect.selectedOptions[0];
            const stock = Number(selectedOption?.dataset.stock || 1);
            quantityInput.max = String(stock);
            quantityInput.value = String(Math.min(stock, Math.max(1, Number(quantityInput.value || 1))));
        });
    }

});

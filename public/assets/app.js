(function () {
    const forms = document.querySelectorAll('.confirm-send');
    forms.forEach((form) => {
        form.addEventListener('submit', (event) => {
            const total = form.closest('.section')?.querySelector('strong')?.textContent || 'selected';
            if (!window.confirm(`Create sending job for ${total} outgoing messages?`)) {
                event.preventDefault();
            }
        });
    });
})();

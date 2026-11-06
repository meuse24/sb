// Keep the one-time token out of request URLs, referrers and server access logs.
const fragment = new URLSearchParams(window.location.hash.slice(1));
const token = fragment.get('token');
if (token !== null) {
    window.history.replaceState(null, '', window.location.pathname + window.location.search);
    if (/^[a-f0-9]{64}$/.test(token)) {
        document.getElementById('confirmation-token').value = token;
        document.getElementById('confirmation-start').requestSubmit();
    } else {
        const status = document.getElementById('confirmation-status');
        if (status) status.textContent = 'Der Bestätigungslink ist ungültig. Bitte einen neuen Link anfordern.';
    }
}

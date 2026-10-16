// Fragment tidak dikirim ke access log server atau referrer.
const code = window.location.hash.slice(1);
if (/^[a-f0-9]{48}$/.test(code)) document.getElementById('kode').value = code;
if (window.location.hash) history.replaceState(null, '', window.location.pathname);

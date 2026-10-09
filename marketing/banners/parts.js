/* Peças reutilizadas: logo zMusic, celular com tela real do app e ícones */
const LOGO_BARS = (s = 64) => `<svg width="${s}" height="${s}" viewBox="0 0 64 64" aria-hidden="true">
  <defs><linearGradient id="lg${s}" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#a78bfa"/><stop offset="1" stop-color="#22d3ee"/></linearGradient></defs>
  <rect width="64" height="64" rx="18" fill="#12121c"/><rect x=".5" y=".5" width="63" height="63" rx="17.5" fill="none" stroke="rgba(255,255,255,.12)"/>
  <g fill="url(#lg${s})"><rect x="12" y="26" width="6" height="12" rx="3"/><rect x="22" y="18" width="6" height="28" rx="3"/>
  <rect x="32" y="12" width="6" height="40" rx="3"/><rect x="42" y="20" width="6" height="24" rx="3"/><rect x="52" y="28" width="4" height="8" rx="2"/></g></svg>`;

const ICON = {
  noads: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M5.6 5.6l12.8 12.8"/></svg>',
  lock: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>',
  offline: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0l-5-5m5 5l5-5"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>',
  lyrics: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 6h16M4 12h10M4 18h13"/><circle cx="19" cy="15" r="2.4" fill="currentColor" stroke="none"/></svg>',
  playlist: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h12M3 12h12M3 18h7"/><path d="M17 10v8.5"/><circle cx="15" cy="18.5" r="2"/><path d="M17 10l4-1.5"/></svg>',
  users: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14.5a6.5 6.5 0 0 1 3.5 5.5"/></svg>',
  play: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.1v13.8c0 .8.9 1.3 1.5.8l10.3-6.9c.6-.4.6-1.3 0-1.7L9.5 4.3C8.9 3.9 8 4.3 8 5.1z"/></svg>',
  pause: '<svg viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="5" width="4" height="14" rx="1.2"/><rect x="14" y="5" width="4" height="14" rx="1.2"/></svg>',
  next: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 6.5v11c0 .8.9 1.3 1.6.8l7.4-5.5c.5-.4.5-1.2 0-1.6L7.6 5.7C6.9 5.2 6 5.7 6 6.5zM16 6h2v12h-2z"/></svg>',
  prev: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M18 6.5v11c0 .8-.9 1.3-1.6.8L9 12.8c-.5-.4-.5-1.2 0-1.6l7.4-5.5c.7-.5 1.6 0 1.6.8zM6 6h2v12H6z"/></svg>',
  arrow: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>',
  check: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>',
  android: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="6" y="2" width="12" height="20" rx="3"/><path d="M10.5 18.5h3"/></svg>',
  gift: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="8" width="18" height="5" rx="1"/><path d="M5 13v7a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-7M12 8v13M12 8S10.5 3 8 3.6C5.8 4.1 7 8 12 8zM12 8s1.5-5 4-4.4C18.2 4.1 17 8 12 8z"/></svg>',
};

function sb() {
  return `<div class="sb"><span>9:41</span><span>
    <svg width="18" height="12" viewBox="0 0 18 12" fill="#fff"><rect x="0" y="8" width="3" height="4" rx="1"/><rect x="5" y="5.5" width="3" height="6.5" rx="1"/><rect x="10" y="3" width="3" height="9" rx="1"/><rect x="15" y="0" width="3" height="12" rx="1"/></svg>
    <svg width="26" height="13" viewBox="0 0 26 13"><rect x=".5" y=".5" width="22" height="12" rx="3.5" fill="none" stroke="rgba(255,255,255,.5)"/><rect x="2.5" y="2.5" width="16" height="8" rx="2" fill="#fff"/><rect x="23.5" y="4" width="2" height="5" rx="1" fill="rgba(255,255,255,.5)"/></svg></span></div>`;
}

/* <div class="phone" data-shot="np.png" data-crop="844"> → celular com a tela real do app */
document.querySelectorAll('.phone[data-shot]').forEach((p) => {
  p.innerHTML = `<div class="island"></div><div class="screen">${sb()}<img src="shots/${p.dataset.shot}" alt=""><div class="glare"></div></div>`;
});
document.querySelectorAll('[data-logo]').forEach((el) => {
  const s = +el.dataset.logo || 64;
  el.innerHTML = `${LOGO_BARS(s)}<b style="font-size:${Math.round(s * 0.62)}px">z<i>Music</i></b>`;
  el.classList.add('logo');
});
document.querySelectorAll('[data-icon]').forEach((el) => { el.innerHTML = ICON[el.dataset.icon] || ''; });

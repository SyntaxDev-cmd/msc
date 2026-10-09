/* Sonora — player e biblioteca. Vanilla JS, sem build. */
(() => {
  'use strict';

  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const icon = (n, cls = '') => `<svg class="${cls}"><use href="#i-${n}"/></svg>`;
  const fmt = (s) => { s = Math.max(0, Math.floor(s || 0)); const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), x = String(s % 60).padStart(2, '0'); return h ? `${h}:${String(m).padStart(2, '0')}:${x}` : `${m}:${x}`; };
  const fmtSize = (b) => b > 1e9 ? (b / 1e9).toFixed(1) + ' GB' : (b / 1e6).toFixed(0) + ' MB';
  const store = {
    get(k, d) { try { const v = localStorage.getItem('sonora.' + k); return v ? JSON.parse(v) : d; } catch { return d; } },
    set(k, v) { try { localStorage.setItem('sonora.' + k, JSON.stringify(v)); } catch { /* sem storage */ } },
  };
  const hue = (s) => { let h = 0; for (const c of String(s)) h = (h * 31 + c.charCodeAt(0)) % 360; return h; };
  const gradient = (s) => { const h = hue(s); return `linear-gradient(135deg, hsl(${h} 70% 45%), hsl(${(h + 50) % 360} 75% 30%))`; };
  const coverUrl = (t) => (t && t.cover ? `stream.php?id=${t.id}&cover=1` : '');
  const IS_IOS = /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

  let CSRF = $('meta[name=csrf]').content;

  /* ======================= API ======================= */
  async function api(action, { params = {}, body = null } = {}) {
    const qs = new URLSearchParams({ action, ...params });
    const r = await fetch('api.php?' + qs, {
      method: body ? 'POST' : 'GET',
      headers: { 'X-CSRF': CSRF, 'Content-Type': 'application/json' },
      body: body ? JSON.stringify(body) : null,
      credentials: 'same-origin',
    });
    const data = await r.json().catch(() => ({ error: 'Resposta inválida do servidor' }));
    if (r.status === 401 && !['login', 'signup'].includes(action)) { document.body.classList.add('logged-out'); throw new Error('Faça login'); }
    if (r.status === 402) {
      if (S.me) { S.me.user.expired = true; renderBanners(); }
      if (currentRoute()[0] !== 'account') location.hash = '#/account';
      throw new Error(data.message || 'Seu plano venceu');
    }
    if (!r.ok || data.error) throw new Error(data.error || 'Erro ' + r.status);
    return data;
  }

  /* ======================= Estado ======================= */
  const S = {
    tracks: [], byId: new Map(), status: {}, jobs: [], pending: 0,
    search: { q: '', source: 'youtube', items: [], loading: false, error: '', token: 0, limit: 25, fallback: false },
    filters: store.get('filters', { live: false, cover: false, long: false, missing: false }),
    dlKind: store.get('dlKind', 'audio'),
    me: null, offlineMode: false,
  };
  const money = (v) => Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  const dateFmt = (ts) => (ts ? new Date(ts * 1000).toLocaleDateString('pt-BR') : '—');

  async function loadLibrary() {
    const { tracks } = await api('library');
    S.tracks = tracks;
    store.set('lib', tracks);
    S.byId = new Map(tracks.map((t) => [t.id, t]));
    renderSidebarGenres();
  }

  function genres() {
    const m = new Map();
    for (const t of S.tracks) {
      const g = m.get(t.genre) || { name: t.genre, count: 0, artists: new Set(), cover: null };
      g.count++; g.artists.add(t.artist.split(/,|&| feat\.?| ft\.?/i)[0].trim());
      if (!g.cover && t.cover) g.cover = t;
      m.set(t.genre, g);
    }
    return [...m.values()].sort((a, b) => b.count - a.count);
  }
  const mainArtist = (a) => a.split(/\s*(?:,|&|\bfeat\.?|\bft\.?|\bpart\.?|\bx\b|\bvs\.?)\s*/i)[0].trim() || a;

  function artistsOf(genre) {
    const m = new Map();
    for (const t of S.tracks) {
      if (genre && t.genre !== genre) continue;
      const a = mainArtist(t.artist);
      const x = m.get(a) || { name: a, count: 0, cover: null, genre: t.genre };
      x.count++; if (!x.cover && t.cover) x.cover = t;
      m.set(a, x);
    }
    return [...m.values()].sort((a, b) => a.name.localeCompare(b.name, 'pt'));
  }

  function renderSidebarGenres() {
    $('#side-genres').innerHTML = genres().map((g) => `<a href="#/genre/${encodeURIComponent(g.name)}"><span>${esc(g.name)}</span><small>${g.count}</small></a>`).join('')
      || '<span class="muted small" style="padding:0 12px">Nenhum ainda</span>';
  }

  /* ======================= Toasts / popover ======================= */
  function toast(msg, type = '', action = null) {
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.innerHTML = `<span>${esc(msg)}</span>`;
    if (action) {
      const b = document.createElement('button');
      b.className = 'btn sm primary'; b.textContent = action.label;
      b.onclick = () => { action.fn(); el.remove(); };
      el.append(b);
    }
    $('#toasts').append(el);
    setTimeout(() => { el.style.transition = 'opacity .4s'; el.style.opacity = '0'; setTimeout(() => el.remove(), 400); }, action ? 6000 : 3500);
  }

  const pop = $('#pop');
  function openPop(anchor, html, onClick) {
    pop.innerHTML = html; pop.hidden = false;
    const r = anchor.getBoundingClientRect(), pr = pop.getBoundingClientRect();
    let left = Math.min(window.innerWidth - pr.width - 10, Math.max(10, r.right - pr.width));
    let top = r.top - pr.height - 8;
    if (top < 10) top = r.bottom + 8;
    pop.style.left = left + 'px'; pop.style.top = Math.min(top, window.innerHeight - pr.height - 10) + 'px';
    pop.onclick = (e) => { const b = e.target.closest('[data-pop]'); if (b && onClick) { onClick(b.dataset.pop, b); } };
  }
  const closePop = () => { pop.hidden = true; pop.onclick = null; };
  document.addEventListener('pointerdown', (e) => { if (!pop.hidden && !pop.contains(e.target) && !e.target.closest('[data-action=eq],[data-action=sleep],[data-action=menu]')) closePop(); });

  /* ======================= Player ======================= */
  const audio = $('#audio');
  const video = $('#np-video');
  const P = {
    el: audio, queue: [], original: null, idx: -1, shuffle: false, repeat: 'off', counted: false,
    sleepAt: 0, sleepEnd: false, sleepTimer: null,
    get track() { return S.byId.get(this.queue[this.idx]); },

    playList(ids, start = 0) {
      this.queue = [...ids]; this.original = null; this.idx = start;
      if (this.shuffle) this.shuffleQueue();
      this.load(true);
    },
    playNow(id) { this.playList([id], 0); },
    playNext(id) { if (this.idx < 0) return this.playNow(id); this.queue.splice(this.idx + 1, 0, id); toast('Toca em seguida'); this.save(); renderQueue(); },
    enqueue(id) { if (this.idx < 0) return this.playNow(id); this.queue.push(id); toast('Adicionada à fila'); this.save(); renderQueue(); },

    load(autoplay, at = 0) {
      const t = this.track;
      if (!t) return;
      const target = t.kind === 'video' ? video : audio;
      for (const el of [audio, video]) if (el !== target) { el.pause(); el.removeAttribute('src'); el.load(); }
      this.el = target;
      target.src = `stream.php?id=${t.id}`;
      if (at) target.currentTime = at;
      target.volume = Vol.value; target.muted = Vol.muted;
      this.counted = false;
      $('#np').classList.toggle('video', t.kind === 'video');
      if (autoplay) this.play();
      updateNowPlaying();
      this.save();
    },
    play() {
      if (!this.track) { const all = S.tracks.map((t) => t.id); if (all.length) this.playList(all, 0); return; }
      Viz.ensure();
      this.el.play().catch((e) => { if (e.name !== 'AbortError') toast('Toque no play para iniciar', ''); });
    },
    pause() { this.el.pause(); },
    toggle() { this.el.paused ? this.play() : this.pause(); },
    next(auto = false) {
      if (!this.queue.length) return;
      if (auto && this.repeat === 'one') { this.el.currentTime = 0; return this.play(); }
      if (auto && this.sleepEnd) { this.sleepEnd = false; setSleep(0); this.pause(); return; }
      if (this.idx + 1 >= this.queue.length) {
        if (this.repeat === 'all' || !auto) { this.idx = 0; } else { this.pause(); this.el.currentTime = 0; return; }
      } else this.idx++;
      if (S.offlineMode && !Offline.has(this.queue[this.idx])) { if (this.queue.some((id) => Offline.has(id))) return this.next(auto); return; }
      this.load(true);
    },
    prev() {
      if (this.el.currentTime > 3 || this.idx <= 0) { this.el.currentTime = 0; return; }
      this.idx--; this.load(true);
    },
    seek(sec) { if (isFinite(sec)) this.el.currentTime = Math.max(0, Math.min(sec, this.el.duration || sec)); },
    shuffleQueue() {
      const cur = this.queue[this.idx];
      this.original = [...this.queue];
      const rest = this.queue.filter((_, i) => i !== this.idx);
      for (let i = rest.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [rest[i], rest[j]] = [rest[j], rest[i]]; }
      this.queue = cur !== undefined ? [cur, ...rest] : rest; this.idx = cur !== undefined ? 0 : -1;
    },
    toggleShuffle() {
      this.shuffle = !this.shuffle;
      if (this.shuffle) this.shuffleQueue();
      else if (this.original) { const cur = this.queue[this.idx]; this.queue = this.original; this.idx = Math.max(0, this.queue.indexOf(cur)); this.original = null; }
      document.body.classList.toggle('shuffle-on', this.shuffle);
      toast(this.shuffle ? 'Aleatório ligado' : 'Aleatório desligado'); this.save(); renderQueue();
    },
    cycleRepeat() {
      this.repeat = { off: 'all', all: 'one', one: 'off' }[this.repeat];
      applyRepeatClass();
      toast({ off: 'Repetir desligado', all: 'Repetindo a fila', one: 'Repetindo esta música' }[this.repeat]); this.save();
    },
    save() { store.set('player', { queue: this.queue, idx: this.idx, shuffle: this.shuffle, repeat: this.repeat, time: this.el.currentTime || 0 }); },
    restore() {
      const s = store.get('player', null);
      if (!s) return;
      this.queue = (s.queue || []).filter((id) => S.byId.has(id));
      this.idx = Math.min(s.idx ?? -1, this.queue.length - 1);
      this.shuffle = !!s.shuffle; this.repeat = s.repeat || 'off';
      document.body.classList.toggle('shuffle-on', this.shuffle); applyRepeatClass();
      if (this.track) this.load(false, s.time || 0);
    },
  };
  const applyRepeatClass = () => { document.body.classList.toggle('repeat-all', P.repeat === 'all'); document.body.classList.toggle('repeat-one', P.repeat === 'one'); };

  const Vol = {
    value: store.get('vol', 0.9), muted: false,
    set(v) { this.value = Math.max(0, Math.min(1, v)); P.el.volume = this.value; if (this.value > 0) this.setMuted(false); store.set('vol', this.value); this.ui(); },
    setMuted(m) { this.muted = m; audio.muted = video.muted = m; document.body.classList.toggle('muted-on', m); this.ui(); },
    ui() { const r = $('.vol'); r.value = Math.round(this.value * 100); r.style.setProperty('--p', (this.muted ? 0 : this.value * 100) + '%'); },
  };

  for (const el of [audio, video]) {
    el.addEventListener('play', () => { if (el === P.el) { document.body.classList.add('is-playing'); Viz.start(); setMSState(); } });
    el.addEventListener('pause', () => { if (el === P.el) { document.body.classList.remove('is-playing'); setMSState(); P.save(); } });
    el.addEventListener('ended', () => { if (el === P.el) P.next(true); });
    el.addEventListener('timeupdate', () => { if (el === P.el) onTime(); });
    el.addEventListener('loadedmetadata', () => { if (el === P.el) onTime(); });
    el.addEventListener('error', () => {
      if (el !== P.el || !el.getAttribute('src')) return;
      toast('Não foi possível tocar este arquivo — pulando', 'err');
      setTimeout(() => P.next(true), 800);
    });
  }

  let lastSave = 0;
  function onTime() {
    const el = P.el, cur = el.currentTime || 0, dur = el.duration || P.track?.duration || 0;
    const pct = dur ? (cur / dur) * 100 : 0;
    for (const s of $$('.seek')) if (!s.dragging) { s.value = Math.round(pct * 10); s.style.setProperty('--p', pct + '%'); }
    $$('[data-bind=cur]').forEach((e) => (e.textContent = fmt(cur)));
    $$('[data-bind=dur]').forEach((e) => (e.textContent = fmt(dur)));
    $('.pl-mini-progress i').style.width = pct + '%';
    Lyrics.sync(cur);
    if (!P.counted && (cur > 30 || (dur && cur > dur / 2))) {
      P.counted = true; const t = P.track;
      if (t) { t.plays++; t.last_played = Date.now() / 1000; api('played', { body: { id: t.id } }).catch(() => {}); }
    }
    if ('mediaSession' in navigator && navigator.mediaSession.setPositionState && dur && isFinite(dur)) {
      try { navigator.mediaSession.setPositionState({ duration: dur, position: Math.min(cur, dur), playbackRate: el.playbackRate }); } catch { /* ignore */ }
    }
    if (Date.now() - lastSave > 5000) { lastSave = Date.now(); P.save(); }
  }

  function updateNowPlaying() {
    const t = P.track;
    const title = t ? t.title : 'Nada tocando', artist = t ? t.artist : '';
    $$('[data-bind=title]').forEach((e) => (e.textContent = title));
    $$('[data-bind=artist]').forEach((e) => (e.textContent = artist));
    $$('img[data-bind=cover]').forEach((img) => { if (t && t.cover) { img.src = coverUrl(t); img.style.visibility = ''; } else { img.removeAttribute('src'); img.style.visibility = 'hidden'; } });
    $$('.pl-fav').forEach((b) => { b.classList.toggle('on', !!t?.favorite); b.querySelector('use').setAttribute('href', t?.favorite ? '#i-heart-fill' : '#i-heart'); });
    document.title = t ? `${t.title} · ${t.artist}` : APP.name;
    $$('.trk').forEach((r) => r.classList.toggle('playing', +r.dataset.id === t?.id));
    if (t) Accent.from(t);
    if ('mediaSession' in navigator && t) {
      navigator.mediaSession.metadata = new MediaMetadata({
        title: t.title, artist: t.artist, album: t.album || t.genre,
        artwork: t.cover ? [{ src: new URL(coverUrl(t), location.href).href, sizes: '600x600', type: 'image/jpeg' }] : [],
      });
    }
    Lyrics.load(t);
    renderQueue();
  }
  function setMSState() { if ('mediaSession' in navigator) navigator.mediaSession.playbackState = P.el.paused ? 'paused' : 'playing'; }
  if ('mediaSession' in navigator) {
    const ms = navigator.mediaSession;
    const h = { play: () => P.play(), pause: () => P.pause(), previoustrack: () => P.prev(), nexttrack: () => P.next(),
      seekto: (d) => P.seek(d.seekTime), seekbackward: (d) => P.seek(P.el.currentTime - (d.seekOffset || 10)), seekforward: (d) => P.seek(P.el.currentTime + (d.seekOffset || 10)) };
    for (const [k, fn] of Object.entries(h)) { try { ms.setActionHandler(k, fn); } catch { /* não suportado */ } }
  }

  /* Seek bars */
  for (const s of $$('.seek')) {
    s.addEventListener('input', () => { s.dragging = true; s.style.setProperty('--p', s.value / 10 + '%'); const d = P.el.duration || 0; $$('[data-bind=cur]').forEach((e) => (e.textContent = fmt((s.value / 1000) * d))); });
    s.addEventListener('change', () => { s.dragging = false; P.seek((s.value / 1000) * (P.el.duration || 0)); });
  }
  $('.vol').addEventListener('input', (e) => Vol.set(e.target.value / 100));

  /* ======================= Cor dinâmica (extraída da capa) ======================= */
  const Accent = {
    from(t) {
      if (!t.cover || !store.get('dynColor', true)) return this.set(S.me?.brand.color || `hsl(${hue(t.artist)} 80% 62%)`);
      const img = new Image();
      img.onload = () => {
        try {
          const c = document.createElement('canvas'); c.width = c.height = 24;
          const x = c.getContext('2d', { willReadFrequently: true }); x.drawImage(img, 0, 0, 24, 24);
          const d = x.getImageData(0, 0, 24, 24).data;
          let best = null, bestScore = -1;
          for (let i = 0; i < d.length; i += 4) {
            const [h, s, l] = rgb2hsl(d[i], d[i + 1], d[i + 2]);
            const score = s * (1 - Math.abs(l - 0.5) * 1.6);
            if (score > bestScore) { bestScore = score; best = [h, s, l]; }
          }
          if (best) this.set(`hsl(${Math.round(best[0])} ${Math.round(Math.max(55, best[1] * 100))}% ${Math.round(Math.min(68, Math.max(55, best[2] * 100)))}%)`);
        } catch { /* ignore */ }
      };
      img.src = coverUrl(t);
    },
    set(c) { document.documentElement.style.setProperty('--accent', c); },
  };
  function rgb2hsl(r, g, b) {
    r /= 255; g /= 255; b /= 255;
    const mx = Math.max(r, g, b), mn = Math.min(r, g, b); let h = 0, s = 0; const l = (mx + mn) / 2;
    if (mx !== mn) {
      const d = mx - mn; s = l > 0.5 ? d / (2 - mx - mn) : d / (mx + mn);
      h = mx === r ? (g - b) / d + (g < b ? 6 : 0) : mx === g ? (b - r) / d + 2 : (r - g) / d + 4; h *= 60;
    }
    return [h, s, l];
  }

  /* ======================= Web Audio: visualizador + equalizador ======================= */
  const EQ_PRESETS = { Normal: [0, 0, 0], Grave: [7, 1, 0], Vocal: [-2, 4, 2], Agudo: [0, 0, 6], Festa: [6, -1, 5] };
  const Viz = {
    ctx: null, analyser: null, data: null, filters: [], running: false, gains: store.get('eq', [0, 0, 0]),
    ensure() {
      if (this.ctx || IS_IOS) { if (this.ctx?.state === 'suspended') this.ctx.resume(); return; }
      try {
        const AC = window.AudioContext || window.webkitAudioContext; if (!AC) return;
        this.ctx = new AC();
        this.filters = [['lowshelf', 120], ['peaking', 1200], ['highshelf', 7000]].map(([type, f], i) => {
          const b = this.ctx.createBiquadFilter(); b.type = type; b.frequency.value = f; b.gain.value = this.gains[i]; if (type === 'peaking') b.Q.value = 0.9; return b;
        });
        this.analyser = this.ctx.createAnalyser(); this.analyser.fftSize = 256; this.analyser.smoothingTimeConstant = 0.8;
        this.data = new Uint8Array(this.analyser.frequencyBinCount);
        for (const el of [audio, video]) this.ctx.createMediaElementSource(el).connect(this.filters[0]);
        this.filters[0].connect(this.filters[1]); this.filters[1].connect(this.filters[2]); this.filters[2].connect(this.analyser);
        this.analyser.connect(this.ctx.destination);
      } catch (e) { console.warn('Web Audio indisponível', e); this.ctx = null; }
    },
    setGain(i, v) { this.gains[i] = v; if (this.filters[i]) this.filters[i].gain.value = v; store.set('eq', this.gains); },
    start() { if (this.running) return; this.running = true; requestAnimationFrame(() => this.frame()); },
    frame() {
      if (P.el.paused) { this.running = false; this.clear(); return; }
      requestAnimationFrame(() => this.frame());
      let levels;
      if (this.analyser) { this.analyser.getByteFrequencyData(this.data); levels = this.data; }
      else { const t = performance.now() / 1000; levels = Array.from({ length: 128 }, (_, i) => 90 + 70 * Math.sin(t * 3 + i * 0.4) * Math.sin(t * 1.3 + i * 0.13)); }
      const accent = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#8b5cf6';
      this.mini(levels, accent);
      if ($('#np').classList.contains('open')) this.radial(levels, accent);
    },
    mini(levels, accent) {
      const c = $('#mini-viz'), x = c.getContext('2d'), w = c.width, h = c.height, n = 7;
      x.clearRect(0, 0, w, h); x.fillStyle = 'rgba(0,0,0,.35)'; x.fillRect(0, 0, w, h); x.fillStyle = '#fff';
      const bw = w / (n * 1.6);
      for (let i = 0; i < n; i++) { const v = levels[2 + i * 5] / 255; const bh = Math.max(3, v * h * 0.8); x.fillRect(bw * 0.6 + i * bw * 1.6, (h - bh) / 2, bw, bh); }
    },
    radial(levels, accent) {
      const c = $('#np-viz'), dpr = Math.min(2, devicePixelRatio || 1);
      const W = c.clientWidth * dpr, H = c.clientHeight * dpr;
      if (c.width !== W || c.height !== H) { c.width = W; c.height = H; }
      const x = c.getContext('2d'); x.clearRect(0, 0, W, H);
      const cx = W / 2, cy = H / 2, r0 = Math.min(W, H) * 0.33, bars = 96;
      x.lineCap = 'round'; x.lineWidth = Math.max(2, (Math.PI * 2 * r0) / bars * 0.45); x.strokeStyle = accent; x.shadowColor = accent; x.shadowBlur = 18 * dpr;
      for (let i = 0; i < bars; i++) {
        const idx = Math.floor((i < bars / 2 ? i : bars - i) * (levels.length * 0.7) / (bars / 2));
        const v = (levels[idx] || 0) / 255, len = 6 * dpr + v * v * r0 * 0.55, a = (i / bars) * Math.PI * 2 - Math.PI / 2;
        x.globalAlpha = 0.35 + v * 0.65;
        x.beginPath(); x.moveTo(cx + Math.cos(a) * r0, cy + Math.sin(a) * r0); x.lineTo(cx + Math.cos(a) * (r0 + len), cy + Math.sin(a) * (r0 + len)); x.stroke();
      }
      x.globalAlpha = 1;
    },
    clear() { const c = $('#mini-viz'); c.getContext('2d').clearRect(0, 0, c.width, c.height); },
  };

  function openEq(anchor) {
    Viz.ensure();
    const labels = ['Grave', 'Médio', 'Agudo'];
    const html = `<h4>Equalizador</h4>${IS_IOS ? '<p class="muted small" style="margin:0 8px 8px">Indisponível no iPhone/iPad.</p>' : ''}
      <div class="eq">${labels.map((l, i) => `<label><span data-v="${i}">${Viz.gains[i] > 0 ? '+' : ''}${Viz.gains[i]} dB</span>
      <input type="range" class="vert" min="-12" max="12" step="1" value="${Viz.gains[i]}" data-band="${i}" style="--p:${((Viz.gains[i] + 12) / 24) * 100}%">${l}</label>`).join('')}</div>
      <div class="eq-presets">${Object.keys(EQ_PRESETS).map((p) => `<button class="chip" data-pop="${p}">${p}</button>`).join('')}</div>`;
    openPop(anchor, html, (preset) => { EQ_PRESETS[preset].forEach((v, i) => Viz.setGain(i, v)); openEq(anchor); });
    $$('[data-band]', pop).forEach((r) => r.addEventListener('input', () => {
      const i = +r.dataset.band, v = +r.value; Viz.setGain(i, v);
      r.style.setProperty('--p', ((v + 12) / 24) * 100 + '%'); $(`[data-v="${i}"]`, pop).textContent = `${v > 0 ? '+' : ''}${v} dB`;
    }));
  }

  /* ======================= Timer para dormir ======================= */
  function setSleep(min, endOfTrack = false) {
    clearTimeout(P.sleepTimer); P.sleepAt = 0; P.sleepEnd = endOfTrack;
    if (min > 0) {
      P.sleepAt = Date.now() + min * 60000;
      P.sleepTimer = setTimeout(() => { fadeOutAndPause(); P.sleepAt = 0; document.body.classList.remove('sleep-on'); }, min * 60000);
    }
    document.body.classList.toggle('sleep-on', min > 0 || endOfTrack);
  }
  function fadeOutAndPause() {
    const el = P.el, start = el.volume; let i = 0;
    const iv = setInterval(() => { i++; el.volume = Math.max(0, start * (1 - i / 20)); if (i >= 20) { clearInterval(iv); P.pause(); el.volume = Vol.value; toast('Boa noite 🌙'); } }, 150);
  }
  function openSleep(anchor) {
    const left = P.sleepAt ? Math.ceil((P.sleepAt - Date.now()) / 60000) : 0;
    const opts = [[15, '15 minutos'], [30, '30 minutos'], [45, '45 minutos'], [60, '1 hora'], ['end', 'Fim desta música'], [0, 'Desligar timer']];
    openPop(anchor, `<h4>Timer para dormir${left ? ` · ${left} min` : P.sleepEnd ? ' · fim da música' : ''}</h4>` +
      opts.map(([v, l]) => `<button class="mi" data-pop="${v}">${icon('moon')}${l}</button>`).join(''), (v) => {
      closePop();
      if (v === 'end') { setSleep(0, true); toast('Vai parar no fim desta música'); } else { setSleep(+v); toast(+v ? `Pausa em ${v} minutos` : 'Timer desligado'); }
    });
  }

  /* ======================= Letras sincronizadas (LRCLIB) ======================= */
  const Lyrics = {
    lines: [], cur: -1, forId: null, cache: new Map(),
    async load(t) {
      const box = $('#lyrics');
      if (!t) { box.innerHTML = ''; return; }
      if (this.forId === t.id) return;
      this.forId = t.id; this.lines = []; this.cur = -1;
      box.className = 'lyrics'; box.innerHTML = '<p class="none"><span class="spinner"></span></p>';
      try {
        let l = this.cache.get(t.id);
        if (!l) { l = await api('lyrics', { params: { id: t.id } }); this.cache.set(t.id, l); }
        if (this.forId !== t.id) return;
        if (l.synced) {
          this.lines = l.synced.split('\n').map((s) => { const m = s.match(/^\[(\d+):(\d+(?:\.\d+)?)\](.*)$/); return m ? { t: +m[1] * 60 + +m[2], text: m[3].trim() } : null; })
            .filter(Boolean).filter((x, i, a) => x.text || (a[i + 1] && a[i + 1].t - x.t > 4));
          box.innerHTML = this.lines.map((x, i) => `<p data-i="${i}">${x.text ? esc(x.text) : '♪'}</p>`).join('');
          this.sync(P.el.currentTime || 0);
        } else if (l.plain) {
          box.className = 'lyrics plain'; box.innerHTML = l.plain.split('\n').map((s) => `<p>${esc(s) || '&nbsp;'}</p>`).join('');
        } else box.innerHTML = '<p class="none">Letra não encontrada para esta música 🎶</p>';
      } catch { box.innerHTML = '<p class="none">Não foi possível carregar a letra</p>'; }
    },
    sync(time) {
      if (!this.lines.length) return;
      let i = this.lines.findIndex((l) => l.t > time + 0.25) - 1;
      if (i === -2) i = this.lines.length - 1;
      if (i === this.cur) return;
      this.cur = i;
      const box = $('#lyrics');
      $$('p', box).forEach((p, k) => { p.classList.toggle('active', k === i); p.classList.toggle('past', k < i); });
      const el = box.children[i];
      if (el && $('#np').classList.contains('open')) {
        const pane = box.parentElement;
        pane.scrollTo({ top: el.offsetTop - pane.clientHeight * 0.38, behavior: 'smooth' });
      }
    },
  };
  $('#lyrics').addEventListener('click', (e) => { const p = e.target.closest('p[data-i]'); if (p) { P.seek(Lyrics.lines[+p.dataset.i].t); P.play(); } });

  /* ======================= Now playing ======================= */
  function openNP(tab) {
    const np = $('#np'); np.classList.add('open'); np.setAttribute('aria-hidden', 'false');
    if (tab) setTab(tab);
    Lyrics.cur = -1; Lyrics.sync(P.el.currentTime || 0);
    if (!P.el.paused) Viz.start();
  }
  function closeNP() { const np = $('#np'); np.classList.remove('open'); np.setAttribute('aria-hidden', 'true'); }
  function setTab(tab) {
    $$('.np-tabs button').forEach((b) => b.classList.toggle('active', b.dataset.tab === tab));
    $$('.np-pane').forEach((p) => p.classList.toggle('active', p.dataset.pane === tab));
    if (tab === 'lyrics') { Lyrics.cur = -1; Lyrics.sync(P.el.currentTime || 0); }
  }
  $$('.np-tabs button').forEach((b) => b.addEventListener('click', () => setTab(b.dataset.tab)));

  function renderQueue() {
    const box = $('#queue-list');
    if (!P.queue.length) { box.innerHTML = '<div class="empty"><h3>Fila vazia</h3><p>Toque uma música para começar</p></div>'; return; }
    const cur = P.track;
    const next = P.queue.slice(P.idx + 1, P.idx + 101).map((id) => S.byId.get(id)).filter(Boolean);
    box.innerHTML = `<div class="q-h">Tocando agora</div>${cur ? trackRow(cur, 0, { queue: true, qpos: P.idx }) : ''}
      <div class="q-h">A seguir · ${P.queue.length - P.idx - 1}</div>
      ${next.map((t, i) => trackRow(t, i + 1, { queue: true, qpos: P.idx + 1 + i })).join('') || '<p class="muted" style="padding:0 8px">Nada na sequência</p>'}`;
  }
  $('#queue-list').addEventListener('click', (e) => {
    const row = e.target.closest('.trk'); if (!row) return;
    if (e.target.closest('[data-action=menu]')) return;
    P.idx = +row.dataset.qpos; P.load(true);
  });

  /* ======================= Linhas de faixas ======================= */
  function trackRow(t, n, opt = {}) {
    const cover = t.cover ? `<img src="${coverUrl(t)}" loading="lazy" alt="">` : icon(t.kind === 'video' ? 'video' : 'music');
    return `<div class="trk${P.track?.id === t.id ? ' playing' : ''}" data-id="${t.id}"${opt.qpos !== undefined ? ` data-qpos="${opt.qpos}"` : ''}>
      ${opt.queue ? '' : `<button class="trk-num" data-action="play-row"><span>${n}</span>${icon('play')}</button>`}
      ${opt.queue ? `<button class="trk-num" data-action="noop">${icon('play')}</button>` : ''}
      <div class="trk-cover">${cover}</div>
      <div class="trk-main"><b>${esc(t.title)}${t.kind === 'video' ? '<span class="tag">vídeo</span>' : ''}${Offline.has(t.id) ? `<span class="off-dot" title="Disponível offline">${icon('offline')}</span>` : ''}</b>
        <small><a href="#/artist/${encodeURIComponent(mainArtist(t.artist))}">${esc(t.artist)}</a></small></div>
      ${opt.queue ? '' : `<span class="trk-album"><a href="#/genre/${encodeURIComponent(t.genre)}">${esc(t.genre)}</a>${t.album ? ' · ' + esc(t.album) : ''}</span>
      <button class="icon-btn fav${t.favorite ? ' on' : ''}" data-action="fav" title="Favoritar">${icon(t.favorite ? 'heart-fill' : 'heart')}</button>`}
      <span class="trk-dur">${fmt(t.duration)}</span>
      <button class="icon-btn more" data-action="menu" title="Mais">${icon('more')}</button>
    </div>`;
  }
  function trackList(list) {
    if (!list.length) return '<div class="empty"><h3>Nada por aqui</h3></div>';
    return `<div class="tracks" data-ids="${list.map((t) => t.id).join(',')}">${list.map((t, i) => trackRow(t, i + 1)).join('')}</div>`;
  }

  function trackMenu(anchor, t) {
    openPop(anchor, `
      <button class="mi" data-pop="next">${icon('queue')}Tocar em seguida</button>
      <button class="mi" data-pop="enqueue">${icon('queue')}Adicionar à fila</button>
      <button class="mi" data-pop="artist">${icon('music')}Ir para ${esc(mainArtist(t.artist))}</button>
      <button class="mi" data-pop="fav">${icon(t.favorite ? 'heart-fill' : 'heart')}${t.favorite ? 'Remover dos favoritos' : 'Favoritar'}</button>
      ${Offline.allowed() ? `<button class="mi${Offline.has(t.id) ? ' on' : ''}" data-pop="offline">${icon('offline')}${Offline.has(t.id) ? 'Remover do offline' : 'Disponível offline'}</button>` : ''}
      <button class="mi" data-pop="file">${icon('download')}Baixar arquivo (${fmtSize(t.size)})</button>
      ${t.owners === null || !isAdmin() ? `<button class="mi danger" data-pop="remove">${icon('trash')}Remover da minha biblioteca</button>` : ''}
      ${isAdmin() ? `<button class="mi danger" data-pop="delete">${icon('trash')}Excluir do servidor${t.owners ? ` (${t.owners} usuário${t.owners > 1 ? 's' : ''})` : ''}</button>` : ''}`, async (a) => {
      closePop();
      if (a === 'next') P.playNext(t.id);
      if (a === 'enqueue') P.enqueue(t.id);
      if (a === 'artist') location.hash = '#/artist/' + encodeURIComponent(mainArtist(t.artist));
      if (a === 'fav') toggleFav(t);
      if (a === 'file') location.href = `stream.php?id=${t.id}&download=1`;
      if (a === 'offline') { if (Offline.has(t.id)) { await Offline.remove([t.id]); toast('Removida do offline'); route(); } else Offline.save([t.id]); }
      const removing = a === 'remove' && confirm(`Remover "${t.title}" da sua biblioteca?`);
      const deleting = a === 'delete' && confirm(`Excluir "${t.title}" do servidor? Some da biblioteca de todos.`);
      if (removing || deleting) {
        await api(deleting ? 'delete' : 'remove', { body: { id: t.id } });
        Offline.remove([t.id]);
        const wasCurrent = P.track?.id === t.id;
        P.queue = P.queue.filter((id) => id !== t.id);
        if (wasCurrent) { P.idx = Math.min(P.idx, P.queue.length - 1); P.pause(); if (P.track) P.load(false); else updateNowPlaying(); }
        else P.idx = P.queue.indexOf(P.track?.id);
        await loadLibrary(); route(); toast(deleting ? 'Excluída do servidor' : 'Removida da sua biblioteca');
      }
    });
  }

  async function toggleFav(t) {
    t.favorite = !t.favorite;
    await api('favorite', { body: { id: t.id, value: t.favorite } }).catch(() => { t.favorite = !t.favorite; });
    updateNowPlaying();
    $$(`.trk[data-id="${t.id}"] .fav`).forEach((b) => { b.classList.toggle('on', t.favorite); b.innerHTML = icon(t.favorite ? 'heart-fill' : 'heart'); });
    if (currentRoute()[0] === 'favorites') route();
  }

  /* ======================= Views ======================= */
  const view = $('#view');
  const currentRoute = () => location.hash.replace(/^#\/?/, '').split('?')[0].split('/').map(decodeURIComponent);

  function route() {
    let [name = 'home', a, b] = currentRoute();
    if (S.me?.user.expired && name !== 'account') { name = 'account'; history.replaceState(null, '', '#/account'); }
    if (S.offlineMode && !['offline', 'account'].includes(name)) name = 'offline';
    if (name === 'admin' && !S.me?.panel) name = 'home';
    const navKey = ['genre', 'artist', 'all'].includes(name) ? 'library' : name === 'admin' ? (a ? `admin/${a}` : 'admin') : name;
    $$('[data-nav]').forEach((n) => n.classList.toggle('active', n.dataset.nav === navKey));
    closePop();
    const views = { home: vHome, search: vSearch, library: vLibrary, genre: vGenre, artist: vArtist, favorites: vFavorites, downloads: vDownloads, all: vAll, account: vAccount, offline: vOffline };
    if (name === 'admin') Sonora.views.admin(a, b);
    else (views[name] || vHome)(a, b);
    $('#main').scrollTop = 0;
  }

  function vHome() {
    const h = new Date().getHours();
    const hi = h < 5 ? 'Boa madrugada' : h < 12 ? 'Bom dia' : h < 18 ? 'Boa tarde' : 'Boa noite';
    const recent = [...S.tracks].sort((a, b) => b.created_at - a.created_at).slice(0, 20);
    const top = [...S.tracks].filter((t) => t.plays > 0).sort((a, b) => b.plays - a.plays).slice(0, 10);
    const size = S.tracks.reduce((s, t) => s + t.size, 0);
    const gs = genres();
    view.innerHTML = `
      <h1 class="h1">${hi} 👋</h1>
      <p class="sub">Pesquise qualquer música ou artista — o ${esc(APP.name)} baixa, organiza por gênero e artista e deixa pronto para ouvir.</p>
      <form class="searchbar" data-form="home-search">${icon('search')}<input name="q" placeholder="Música, artista ou álbum…" autocomplete="off"><button class="btn primary">Buscar</button></form>
      ${S.tracks.length ? `
      <div class="stats">
        <div class="stat"><b>${S.tracks.length}</b><span>faixas</span></div>
        <div class="stat"><b>${artistsOf().length}</b><span>artistas</span></div>
        <div class="stat"><b>${gs.length}</b><span>gêneros</span></div>
        <div class="stat"><b>${fmtSize(size)}</b><span>no servidor</span></div>
      </div>
      <div class="row" style="margin-top:16px">
        <button class="btn primary" data-action="mix">${icon('sparkle')} Mix aleatório</button>
        <button class="btn" data-action="play-favs">${icon('heart')} Tocar favoritas</button>
      </div>
      <h2 class="h2">Adicionadas recentemente <a class="btn sm ghost" href="#/all">Ver tudo</a></h2>
      <div class="hscroll">${recent.map(cardTrack).join('')}</div>
      ${top.length ? `<h2 class="h2">Suas mais tocadas</h2>${trackList(top)}` : ''}
      <h2 class="h2">Seus gêneros</h2>
      <div class="grid">${gs.map(genreCard).join('')}</div>` : `
      <div class="empty" style="padding-top:80px">${icon('music')}<h3>Sua biblioteca está vazia</h3><p>Busque um artista acima e toque em “Baixar todas”. 🎧</p></div>`}`;
  }
  const cardTrack = (t) => `<div class="card" data-action="play-one" data-id="${t.id}">
      <div class="art">${t.cover ? `<img src="${coverUrl(t)}" loading="lazy" alt="">` : `<div class="ph" style="background:${gradient(t.artist)}">${esc(t.title[0] || '♪')}</div>`}</div>
      <button class="fab" tabindex="-1">${icon('play')}</button><b>${esc(t.title)}</b><small>${esc(t.artist)}</small></div>`;
  const genreCard = (g) => `<a class="genre-card" href="#/genre/${encodeURIComponent(g.name)}" style="background:${gradient(g.name)}"><span>${esc(g.name)}</span><small>${g.count} faixas · ${g.artists.size} artistas</small></a>`;

  function vLibrary() {
    const gs = genres();
    view.innerHTML = `<h1 class="h1">Biblioteca</h1>
      <p class="sub">Organizada como no servidor: <b>Gênero › Artista › Música</b></p>
      <div class="row"><a class="btn" href="#/all">${icon('music')} Todas as faixas (${S.tracks.length})</a></div>
      <h2 class="h2">Gêneros</h2>
      ${gs.length ? `<div class="grid">${gs.map(genreCard).join('')}</div>` : '<div class="empty"><h3>Nenhum gênero ainda</h3><p>Baixe músicas pela busca.</p></div>'}
      <h2 class="h2">Artistas</h2><div class="grid">${artistsOf().map(artistCard).join('')}</div>`;
  }
  const artistCard = (a) => `<a class="card" href="#/artist/${encodeURIComponent(a.name)}">
      <div class="art round">${a.cover ? `<img src="${coverUrl(a.cover)}" loading="lazy" alt="">` : `<div class="ph" style="background:${gradient(a.name)}">${esc(a.name[0] || '?')}</div>`}</div>
      <b>${esc(a.name)}</b><small>${a.count} faixa${a.count > 1 ? 's' : ''}</small></a>`;

  function vGenre(g) {
    const list = S.tracks.filter((t) => t.genre === g);
    view.innerHTML = `<div class="crumbs"><a href="#/library">Biblioteca</a> › <span>${esc(g)}</span></div>
      <div class="hero"><div class="art" style="background:${gradient(g)}"><div class="ph">${esc(g[0] || '?')}</div></div>
      <div class="meta"><div class="kicker">Gênero</div><h1 class="h1">${esc(g)}</h1><p class="sub">${list.length} faixas</p>
      <div class="row"><button class="btn primary" data-action="play-all">${icon('play')} Tocar</button><button class="btn" data-action="shuffle-all">${icon('shuffle')} Aleatório</button>${Offline.allowed() ? `<button class="btn" data-action="offline-list" title="Salvar no aparelho para ouvir sem internet">${icon('offline')} Offline</button>` : ''}</div></div></div>
      <h2 class="h2">Artistas</h2><div class="grid">${artistsOf(g).map(artistCard).join('')}</div>
      <h2 class="h2">Faixas</h2>${trackList(list)}`;
  }

  function vArtist(a) {
    const list = S.tracks.filter((t) => mainArtist(t.artist) === a).sort((x, y) => (x.album || '').localeCompare(y.album || '') || x.title.localeCompare(y.title));
    const cover = list.find((t) => t.cover);
    const g = list[0]?.genre || '';
    view.innerHTML = `<div class="crumbs"><a href="#/library">Biblioteca</a> › <a href="#/genre/${encodeURIComponent(g)}">${esc(g)}</a> › <span>${esc(a)}</span></div>
      <div class="hero"><div class="art" style="border-radius:50%">${cover ? `<img src="${coverUrl(cover)}" alt="">` : `<div class="ph" style="background:${gradient(a)}">${esc(a[0] || '?')}</div>`}</div>
      <div class="meta"><div class="kicker">Artista · ${esc(g)}</div><h1 class="h1">${esc(a)}</h1><p class="sub">${list.length} faixas na sua biblioteca</p>
      <div class="row"><button class="btn primary" data-action="play-all">${icon('play')} Tocar</button><button class="btn" data-action="shuffle-all">${icon('shuffle')} Aleatório</button>${Offline.allowed() ? `<button class="btn" data-action="offline-list" title="Salvar no aparelho para ouvir sem internet">${icon('offline')} Offline</button>` : ''}
      <a class="btn" href="#/search?q=${encodeURIComponent(a)}&s=artist">${icon('search')} Buscar mais músicas</a></div></div></div>
      ${trackList(list)}`;
  }

  function vAll() {
    const sort = store.get('sortAll', 'recent');
    view.innerHTML = `<div class="crumbs"><a href="#/library">Biblioteca</a> › <span>Todas as faixas</span></div>
      <h1 class="h1">Todas as faixas</h1>
      <div class="toolbar"><input class="filter-input" placeholder="Filtrar…" data-filter style="flex:1">
      <div class="seg" data-sort>${[['recent', 'Recentes'], ['title', 'A-Z'], ['artist', 'Artista'], ['plays', 'Mais tocadas']].map(([k, l]) => `<button data-k="${k}" class="${k === sort ? 'active' : ''}">${l}</button>`).join('')}</div>
      <button class="btn primary sm" data-action="play-all">${icon('play')} Tocar</button></div>
      <div data-list></div>`;
    const draw = () => {
      const q = $('[data-filter]', view).value.toLowerCase(), s = store.get('sortAll', 'recent');
      const list = S.tracks.filter((t) => !q || `${t.title} ${t.artist} ${t.album} ${t.genre}`.toLowerCase().includes(q)).sort({
        recent: (a, b) => b.created_at - a.created_at, title: (a, b) => a.title.localeCompare(b.title, 'pt'),
        artist: (a, b) => a.artist.localeCompare(b.artist, 'pt'), plays: (a, b) => b.plays - a.plays,
      }[s]);
      $('[data-list]', view).innerHTML = trackList(list);
    };
    $('[data-filter]', view).addEventListener('input', draw);
    $('[data-sort]', view).addEventListener('click', (e) => { const b = e.target.closest('button'); if (!b) return; store.set('sortAll', b.dataset.k); $$('[data-sort] button', view).forEach((x) => x.classList.toggle('active', x === b)); draw(); });
    draw();
  }

  function vFavorites() {
    const list = S.tracks.filter((t) => t.favorite);
    view.innerHTML = `<div class="hero"><div class="art" style="background:linear-gradient(135deg,var(--accent),#ec4899)"><div class="ph" style="color:#fff">${icon('heart-fill')}</div></div>
      <div class="meta"><div class="kicker">Playlist</div><h1 class="h1">Favoritas</h1><p class="sub">${list.length} faixas</p>
      ${list.length ? `<div class="row"><button class="btn primary" data-action="play-all">${icon('play')} Tocar</button><button class="btn" data-action="shuffle-all">${icon('shuffle')} Aleatório</button>${Offline.allowed() ? `<button class="btn" data-action="offline-list" title="Salvar no aparelho para ouvir sem internet">${icon('offline')} Offline</button>` : ''}</div>` : ''}</div></div>
      ${list.length ? trackList(list) : '<div class="empty"><h3>Nenhuma favorita ainda</h3><p>Toque no ♥ de uma música.</p></div>'}`;
  }

  /* ---------- Downloads ---------- */
  function vDownloads() {
    view.innerHTML = `<h1 class="h1">Downloads</h1>
      <p class="sub">A fila roda no servidor — pode fechar a página que continua baixando.</p>
      <div class="row" style="margin-bottom:16px"><button class="btn sm" data-action="jobs-refresh">${icon('refresh')} Atualizar</button><button class="btn sm ghost" data-action="jobs-clear">Limpar concluídos</button></div>
      <div id="jobs"></div>`;
    renderJobs();
    pollJobs(true);
  }
  function renderJobs() {
    const box = $('#jobs'); if (!box) return;
    if (!S.jobs.length) { box.innerHTML = `<div class="empty">${icon('download')}<h3>Nenhum download</h3><p>Busque uma música e toque em baixar.</p></div>`; return; }
    const label = { queued: 'Na fila', running: 'Baixando', done: 'Pronto', error: 'Erro' };
    box.innerHTML = S.jobs.map((j) => `<div class="job ${j.status}" data-job="${j.id}">
      ${j.thumb ? `<img src="${esc(j.thumb)}" alt="" loading="lazy" referrerpolicy="no-referrer">` : '<div class="ph-sm"></div>'}
      <div style="min-width:0"><b>${esc(j.title)}${j.kind === 'video' ? '<span class="tag">vídeo</span>' : ''}</b>
      <small>${esc(j.artist)} · ${j.status === 'running' ? `${esc(j.message || 'Baixando')} ${j.progress}%` : esc(j.message || label[j.status])}</small>
      ${j.status === 'running' || j.status === 'queued' ? `<div class="bar"><i style="width:${j.progress}%"></i></div>` : ''}</div>
      <div class="row">${j.status === 'done' && j.track_id ? `<button class="btn sm have" data-action="play-one" data-id="${j.track_id}">${icon('play')} Ouvir</button>` : ''}
      ${j.status === 'error' ? `<button class="btn sm" data-action="job-retry">${icon('refresh')} Tentar de novo</button>` : ''}
      ${j.status !== 'running' ? `<button class="icon-btn" data-action="job-cancel" title="Remover">${icon('close')}</button>` : ''}</div></div>`).join('');
  }

  let pollTimer = null, knownDone = new Set(), firstPoll = true;
  async function pollJobs(force = false) {
    clearTimeout(pollTimer);
    try {
      const r = await api('jobs');
      const newlyDone = r.jobs.filter((j) => j.status === 'done' && j.track_id && !knownDone.has(j.id));
      const newlyErr = r.jobs.filter((j) => j.status === 'error' && !knownDone.has(j.id));
      r.jobs.filter((j) => j.status === 'done' || j.status === 'error').forEach((j) => knownDone.add(j.id));
      S.jobs = r.jobs; S.pending = r.pending;
      const badge = $('#dl-badge'); badge.hidden = !r.pending; badge.textContent = r.pending;
      if (newlyDone.length && !firstPoll) {
        await loadLibrary();
        if (newlyDone.length === 1) { const j = newlyDone[0]; toast(`✔ ${j.title} pronta para ouvir`, 'ok', { label: 'Ouvir', fn: () => P.playNow(j.track_id) }); }
        else toast(`✔ ${newlyDone.length} músicas prontas`, 'ok');
        if (['home', 'library', 'genre', 'artist', 'all'].includes(currentRoute()[0] || 'home')) route();
      }
      if (newlyErr.length && !firstPoll) toast(`Falha: ${newlyErr[0].title} — ${newlyErr[0].message}`, 'err');
      firstPoll = false;
      syncResultsWithJobs();
      renderJobs();
      if (r.pending > 0 || force) pollTimer = setTimeout(() => pollJobs(), r.pending > 0 ? 2000 : 15000);
    } catch { pollTimer = setTimeout(() => pollJobs(), 8000); }
  }

  /* ---------- Busca ---------- */
  const SOURCES = [['youtube', 'YouTube (tudo)'], ['artist', 'Artista (catálogo completo)'], ['catalog', 'Catálogo oficial'], ['jamendo', 'Músicas livres']];
  function vSearch() {
    const params = new URLSearchParams(location.hash.split('?')[1] || '');
    const q = params.get('q') || S.search.q, src = params.get('s') || S.search.source;
    const srcs = SOURCES.filter(([k]) => k !== 'jamendo' || S.status.jamendo);
    view.innerHTML = `
      <form class="searchbar" data-form="search">${icon('search')}<input name="q" value="${esc(q)}" placeholder="O que você quer ouvir?" autocomplete="off" autofocus>
      <button class="btn primary">Buscar</button></form>
      <div class="tabs">${srcs.map(([k, l]) => `<button class="tab${k === src ? ' active' : ''}" data-src="${k}">${l}</button>`).join('')}</div>
      <div class="toolbar">
        <div class="seg" data-kind><button data-k="audio" class="${S.dlKind === 'audio' ? 'active' : ''}">${icon('music')} Áudio ${esc((S.status.audio_format || '').toUpperCase())}</button><button data-k="video" class="${S.dlKind === 'video' ? 'active' : ''}">${icon('video')} Vídeo</button></div>
        <div class="chips" data-filters>
          <button class="chip${S.filters.live ? ' on' : ''}" data-f="live">Sem “ao vivo”</button>
          <button class="chip${S.filters.cover ? ' on' : ''}" data-f="cover">Sem covers/karaokê</button>
          <button class="chip${S.filters.long ? ' on' : ''}" data-f="long">Até 10 min</button>
          <button class="chip${S.filters.missing ? ' on' : ''}" data-f="missing">Só o que não tenho</button>
        </div>
        <span class="spacer"></span>
        <button class="btn primary sm" data-action="download-all" disabled>${icon('download')} Baixar todas</button>
      </div>
      <div id="local-hits"></div>
      <div id="results"></div>`;
    $('[data-kind]', view).addEventListener('click', (e) => { const b = e.target.closest('button'); if (!b) return; S.dlKind = b.dataset.k; store.set('dlKind', S.dlKind); $$('[data-kind] button', view).forEach((x) => x.classList.toggle('active', x === b)); renderResults(); });
    $('[data-filters]', view).addEventListener('click', (e) => { const b = e.target.closest('[data-f]'); if (!b) return; S.filters[b.dataset.f] = !S.filters[b.dataset.f]; store.set('filters', S.filters); b.classList.toggle('on'); renderResults(); });
    $$('[data-src]', view).forEach((b) => b.addEventListener('click', () => { S.search.source = b.dataset.src; $$('[data-src]', view).forEach((x) => x.classList.toggle('active', x === b)); doSearch($('input[name=q]', view).value); }));
    S.search.source = src;
    if (q) doSearch(q); else renderResults();
  }

  async function doSearch(q, more = false) {
    q = q.trim(); S.search.q = q;
    S.search.limit = more ? 50 : 25;
    history.replaceState(null, '', `#/search?q=${encodeURIComponent(q)}&s=${S.search.source}`);
    const local = q.length > 1 ? S.tracks.filter((t) => `${t.title} ${t.artist} ${t.album}`.toLowerCase().includes(q.toLowerCase())).slice(0, 8) : [];
    const lh = $('#local-hits');
    if (lh) lh.innerHTML = local.length ? `<h2 class="h2" style="margin-top:6px">Já na sua biblioteca</h2>${trackList(local)}<h2 class="h2">Resultados da busca</h2>` : '';
    if (q.length < 2) { S.search.items = []; renderResults(); return; }
    const token = ++S.search.token;
    S.search.loading = true; S.search.error = ''; renderResults();
    try {
      const r = await api('search', { params: { q, source: S.search.source, limit: S.search.limit } });
      if (token !== S.search.token) return;
      S.search.items = r.items; S.search.fallback = r.fallback; S.search.artist = r.artist || null;
    } catch (e) {
      if (token !== S.search.token) return;
      S.search.items = []; S.search.error = e.message; S.search.artist = null;
    }
    S.search.loading = false; renderResults();
  }

  const RX_LIVE = /\b(ao vivo|live|en vivo|concert|in concert)\b/i;
  const RX_COVER = /\b(cover|karaok[eê]|instrumental|playback|tutorial|aula|reaction|react|8d|slowed|sped up|nightcore)\b/i;
  function visibleItems() {
    const f = S.filters, k = S.dlKind;
    return S.search.items.filter((it) => {
      const text = `${it.raw_title || ''} ${it.title} ${it.album || ''}`;
      if (f.live && RX_LIVE.test(text)) return false;
      if (f.cover && RX_COVER.test(text)) return false;
      if (f.long && it.duration > 600) return false;
      if (f.missing && it.library?.[k]) return false;
      if (!it.kinds.includes(k)) return false;
      return true;
    });
  }

  function renderResults() {
    const box = $('#results'); if (!box) return;
    const btnAll = $('[data-action=download-all]', view);
    if (S.search.loading) { box.innerHTML = Array.from({ length: 6 }, () => '<div class="skeleton" style="margin-bottom:6px"></div>').join(''); if (btnAll) btnAll.disabled = true; return; }
    if (S.search.error) { box.innerHTML = `<div class="empty"><h3>Ops!</h3><p>${esc(S.search.error)}</p>${/yt-dlp|install/i.test(S.search.error) ? '<a class="btn primary" href="install.php">Abrir instalação</a>' : ''}</div>`; if (btnAll) btnAll.disabled = true; return; }
    if (!S.search.q) { box.innerHTML = `<div class="empty">${icon('search')}<h3>Busque por música ou artista</h3><p>Qualquer música do YouTube pode ser baixada. Em <b>Artista (catálogo completo)</b> aparecem todas as músicas e clipes do artista no YouTube — baixe tudo com um clique.</p></div>`; return; }
    const items = visibleItems(), k = S.dlKind;
    if (!items.length) { box.innerHTML = '<div class="empty"><h3>Nada encontrado</h3><p>Tente outra busca, outra fonte ou desligue os filtros.</p></div>'; if (btnAll) btnAll.disabled = true; return; }
    const todo = items.filter((it) => !it.library?.[k] && !it.job?.[k]);
    if (btnAll) { btnAll.disabled = !todo.length; btnAll.innerHTML = `${icon('download')} Baixar todas (${todo.length})`; }
    const ar = S.search.source === 'artist' ? S.search.artist : null;
    box.innerHTML = `${ar ? `<div class="artist-card">
        ${ar.thumb ? `<img src="${esc(ar.thumb)}" alt="" referrerpolicy="no-referrer">` : `<div class="ph" style="background:${gradient(ar.name)}">${esc(ar.name[0] || '?')}</div>`}
        <div><small>Artista no YouTube</small><b>${esc(ar.name)}</b><span>${[ar.subscribers, `${ar.count} músicas e vídeos encontrados`].filter(Boolean).map(esc).join(' · ')}</span></div>
        <button class="btn primary" data-action="download-all" ${todo.length ? '' : 'disabled'}>${icon('download')} Baixar discografia (${todo.length})</button></div>` : ''}
      <div class="results">${items.map((it) => {
      const i = S.search.items.indexOf(it);
      const meta = [it.artist, it.album, it.year].filter(Boolean).map(esc).join(' · ');
      const chips = [it.genre && `<span class="chip">${esc(it.genre)}</span>`, it.source === 'youtube' && `<span class="chip">${esc(it.channel)}</span>`,
        it.views && `<span class="chip">${Intl.NumberFormat('pt-BR', { notation: 'compact' }).format(it.views)} views</span>`,
        it.license && '<span class="chip">Creative Commons</span>'].filter(Boolean).join('');
      return `<div class="res" data-idx="${i}">
        <div class="res-thumb${it.source === 'youtube' ? ' wide' : ''}">${it.thumb ? `<img src="${esc(it.thumb)}" loading="lazy" alt="" referrerpolicy="no-referrer" onerror="this.remove()">` : `<div class="ph" style="background:${gradient(it.artist)};font-size:22px">${esc((it.title || '?')[0])}</div>`}
          ${it.preview ? `<button data-action="preview" title="Prévia de 30s">${icon('play')}</button>` : ''}</div>
        <div class="res-main"><b>${esc(it.title)}</b><small>${meta}</small>${chips ? `<div class="chips">${chips}</div>` : ''}</div>
        <span class="res-dur">${it.duration ? fmt(it.duration) : ''}</span>
        <div class="res-act">${resAction(it, k)}</div></div>`;
    }).join('')}</div>
    ${S.search.fallback ? '<p class="muted small" style="text-align:center">Não estava no catálogo oficial — mostrando resultados do YouTube.</p>' : ''}
    ${S.search.source !== 'artist' && (S.search.limit < 50 && S.search.items.length >= S.search.limit && ['youtube', 'jamendo'].includes(S.search.source) || (S.search.fallback && S.search.limit < 50)) ? `<div style="text-align:center;margin:18px 0"><button class="btn" data-action="more">${icon('down')} Carregar mais resultados</button></div>` : ''}`;
  }
  function resAction(it, k) {
    if (it.library?.[k]) return `<button class="btn sm have" data-action="play-one" data-id="${it.library[k]}">${icon('play')} ${k === 'video' ? 'Assistir' : 'Ouvir'}</button>`;
    const j = it.job?.[k];
    if (j && j.status === 'error') return `<span class="pill err" title="${esc(j.message)}"><span>Erro</span></span><button class="btn sm" data-action="dl">${icon('refresh')}</button>`;
    if (j) return `<span class="pill"><i style="width:${j.progress || 0}%"></i><span>${j.status === 'running' ? `${esc(j.message || 'Baixando')} ${Math.round(j.progress || 0)}%` : 'Na fila…'}</span></span>`;
    if (it.server?.[k]) return `<button class="btn sm" data-action="dl" title="Já está no servidor: entra na hora, sem gastar download">${icon('bolt')} Adicionar</button>`;
    return `<button class="btn sm" data-action="dl">${icon('download')} Baixar</button>`;
  }
  function syncResultsWithJobs() {
    if (!S.search.items.length) return;
    let changed = false;
    const byId = new Map(S.jobs.map((j) => [j.id, j]));
    for (const it of S.search.items) for (const k of ['audio', 'video']) {
      const j = it.job?.[k]; if (!j) continue;
      const nj = byId.get(j.id); if (!nj) continue;
      if (nj.status === 'done' && nj.track_id) { it.library[k] = nj.track_id; it.job[k] = null; changed = true; }
      else if (nj.status !== j.status || nj.progress !== j.progress) { it.job[k] = nj; changed = true; }
    }
    if (changed && currentRoute()[0] === 'search') renderResults();
  }

  async function download(items) {
    const k = S.dlKind;
    try {
      const r = await api('download', { body: { kind: k, items } });
      r.results.forEach((res, i) => {
        const it = items[i];
        if (res.status === 'exists' || res.status === 'added') it.library[k] = res.track_id;
        if (res.status === 'queued') it.job[k] = { id: res.job_id, status: 'queued', progress: 0 };
      });
      S.jobs = r.jobs || S.jobs;
      renderResults();
      const parts = [];
      if (r.queued) parts.push(`${r.queued} na fila`);
      if (r.added) parts.push(`⚡ ${r.added} adicionada${r.added > 1 ? 's' : ''} na hora`);
      if (r.exists) parts.push(`${r.exists} já estavam na biblioteca`);
      if (r.errors) parts.push(r.errors === 1 && r.error_msg ? r.error_msg : `${r.errors} com erro: ${r.error_msg}`);
      toast(parts.join(' · ') || 'Nada a fazer', r.errors ? 'err' : 'ok');
      if (r.exists || r.added) await loadLibrary();
      if (r.added || r.queued) refreshMe().catch(() => {});
      setTimeout(() => pollJobs(), 1500);
    } catch (e) { toast(e.message, 'err'); }
  }

  const preview = new Audio();
  let previewBtn = null;
  preview.addEventListener('ended', () => previewBtn?.classList.remove('on'));
  function togglePreview(btn, url) {
    if (previewBtn === btn && !preview.paused) { preview.pause(); btn.classList.remove('on'); btn.innerHTML = icon('play'); return; }
    previewBtn?.classList.remove('on'); if (previewBtn) previewBtn.innerHTML = icon('play');
    previewBtn = btn; btn.classList.add('on'); btn.innerHTML = icon('pause');
    if (!P.el.paused) P.pause();
    preview.src = url; preview.volume = Vol.value; preview.play().catch(() => {});
  }


  /* ======================= Plataforma: conta, marca, pagamentos, offline ======================= */
  const isAdmin = () => S.me?.user.role === 'admin';

  async function refreshMe() {
    S.me = await api('me');
    CSRF = S.me.csrf || CSRF;
    store.set('me', S.me);
    applyBrand(S.me.brand);
    document.body.classList.toggle('is-panel', !!S.me.panel);
    document.body.classList.toggle('is-admin', isAdmin());
    document.body.classList.toggle('no-offline', !Offline.allowed());
    renderBanners();
    return S.me;
  }

  function applyBrand(b) {
    if (!b) return;
    const root = document.documentElement.style;
    root.setProperty('--brand', b.color); root.setProperty('--accent-2', b.color2);
    if (!P.track || !store.get('dynColor', true)) root.setProperty('--accent', b.color);
    APP.name = b.name;
    $$('.brand-name').forEach((e) => (e.textContent = b.name));
    $$('img.brand-logo').forEach((i) => (i.src = b.logo || 'assets/icon.svg'));
    if (!P.track) document.title = b.name;
  }

  function renderBanners() {
    const box = $('#banners'); if (!box || !S.me) return;
    const u = S.me.user, out = [];
    if (S.offlineMode) out.push(['info', `📴 Você está offline — tocando as ${S.tracks.length} músicas salvas no aparelho.`, '']);
    if (S.me.impersonating) out.push(['warn', `👤 Você está acessando como <b>${esc(u.username)}</b>.`, `<button class="btn sm" data-action="stop-impersonate">Voltar à minha conta</button>`]);
    if (u.expired) out.push(['err', '⛔ Seu plano venceu. Renove para voltar a ouvir e baixar.', S.me.plans.length ? `<button class="btn sm primary" data-action="renew">Renovar agora</button>` : '']);
    else if (u.is_trial && u.days_left !== null) out.push(['info', `✨ Teste grátis — ${u.days_left < 1 ? 'termina hoje' : `faltam ${u.days_left} dia${u.days_left > 1 ? 's' : ''}`}. Gostou? Assine e não perca suas músicas.`, S.me.plans.length ? `<button class="btn sm primary" data-action="renew">Ver planos</button>` : '']);
    else if (u.days_left !== null && u.days_left <= 5) out.push(['warn', `⏳ Seu plano vence ${u.days_left < 1 ? 'hoje' : `em ${u.days_left} dia${u.days_left > 1 ? 's' : ''}`} (${dateFmt(u.expires_at)}).`, S.me.plans.length ? `<button class="btn sm primary" data-action="renew">Renovar</button>` : '']);
    if (S.me.panel && !isAdmin() && u.credits < 0) out.push(['err', `Seu saldo de créditos está negativo (${u.credits}). Compre créditos para regularizar.`, `<a class="btn sm" href="#/account">Comprar</a>`]);
    box.innerHTML = out.map(([t, msg, act]) => `<div class="banner ${t}"><span>${msg}</span>${act}</div>`).join('');
  }

  /* ---------- Modal ---------- */
  function formToObj(form) {
    const o = Object.fromEntries(new FormData(form));
    $$('input[type=checkbox]', form).forEach((c) => { if (c.name) o[c.name] = c.checked ? 1 : 0; });
    return o;
  }
  function modal(html, { onSubmit, wide = false, onClose } = {}) {
    const wrap = $('#modal');
    wrap.innerHTML = `<div class="modal${wide ? ' wide' : ''}" role="dialog" aria-modal="true">${html}</div>`;
    wrap.hidden = false;
    const close = () => { wrap.hidden = true; wrap.innerHTML = ''; wrap.onclick = null; onClose?.(); };
    wrap.onclick = (e) => { if (e.target === wrap || e.target.closest('[data-close]')) close(); };
    const form = $('form', wrap);
    if (form && onSubmit) form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = form.querySelector('button[type=submit]') || form.querySelector('button.primary');
      if (btn) btn.disabled = true;
      try { await onSubmit(formToObj(form), close, form); } catch (ex) { toast(ex.message, 'err'); } finally { if (btn) btn.disabled = false; }
    });
    setTimeout(() => wrap.querySelector('[autofocus], input:not([type=hidden]):not([type=checkbox]), select')?.focus(), 60);
    return { el: wrap.firstElementChild, close };
  }
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !$('#modal').hidden) { $('#modal').hidden = true; $('#modal').innerHTML = ''; } });

  /* ---------- Confete 🎉 ---------- */
  function confetti() {
    const c = $('#confetti'), x = c.getContext('2d');
    c.width = innerWidth; c.height = innerHeight; c.style.display = 'block';
    const colors = [getComputedStyle(document.documentElement).getPropertyValue('--accent'), '#22d3ee', '#fbbf24', '#f472b6', '#34d399'];
    const ps = Array.from({ length: 160 }, () => ({ x: Math.random() * c.width, y: -20 - Math.random() * c.height * 0.5, r: 4 + Math.random() * 5, vx: -2 + Math.random() * 4, vy: 2 + Math.random() * 4, a: Math.random() * 6, va: -0.2 + Math.random() * 0.4, c: colors[Math.floor(Math.random() * colors.length)] }));
    const t0 = performance.now();
    (function frame(t) {
      x.clearRect(0, 0, c.width, c.height);
      for (const p of ps) { p.x += p.vx; p.y += p.vy; p.vy += 0.05; p.a += p.va; x.save(); x.translate(p.x, p.y); x.rotate(p.a); x.fillStyle = p.c; x.fillRect(-p.r, -p.r / 2, p.r * 2, p.r); x.restore(); }
      if (t - t0 < 3200) requestAnimationFrame(frame); else { x.clearRect(0, 0, c.width, c.height); c.style.display = 'none'; }
    })(t0);
  }

  /* ---------- Pagamento (Pix com QR dentro do app, ou cartão/boleto) ---------- */
  function openPay(opt) {
    const u = S.me.user;
    const m = modal(`<form class="pay">
        <h3>${esc(opt.title)}</h3>
        <div class="pay-amount">${money(opt.amount)}</div>
        <p class="muted small">${esc(opt.desc || '')}</p>
        <label class="fld"><span>E-mail para o comprovante</span><input type="email" name="email" required value="${esc(u.email || '')}" placeholder="voce@email.com"></label>
        <div class="row" style="justify-content:center;margin-top:6px">
          <button class="btn primary" name="method" value="pix" type="submit">${icon('bolt')} Pagar com Pix</button>
          <button class="btn" type="button" data-pay-checkout>${icon('card')} Cartão ou boleto</button>
        </div>
        <p class="muted small" style="text-align:center;margin-top:12px">Pagamento processado pelo Mercado Pago. Liberação automática.</p>
        <button type="button" class="icon-btn modal-x" data-close>${icon('close')}</button></form>`, {
      onSubmit: async (d) => start('pix', d.email),
    });
    $('[data-pay-checkout]', m.el).addEventListener('click', () => {
      const email = $('input[name=email]', m.el);
      if (!email.reportValidity()) return;
      const win = window.open('', '_blank'); // abre já no clique (evita bloqueio de pop-up)
      start('checkout', email.value, win).catch((e) => { win?.close(); toast(e.message, 'err'); });
    });
    let timer = null, stopped = false;
    const stop = () => { stopped = true; clearTimeout(timer); };
    async function start(method, email, win) {
      const { payment } = await api('pay_create', { body: { kind: opt.kind, plan_id: opt.plan_id, package: opt.package, method, email } });
      if (method === 'checkout') {
        if (win) win.location = payment.init_point; else location.href = payment.init_point;
        waitScreen(payment, `<p>Finalize o pagamento na aba do Mercado Pago.</p><p class="muted small">Esta tela atualiza sozinha quando o pagamento for aprovado.</p>
          <a class="btn" href="${esc(payment.init_point)}" target="_blank" rel="noopener">Abrir pagamento de novo</a>`);
      } else {
        waitScreen(payment, `<img class="qr" src="data:image/png;base64,${payment.qr_base64}" alt="QR Code Pix">
          <p class="muted small">Abra o app do seu banco › Pix › Ler QR Code, ou copie o código:</p>
          <div class="copy-row"><input readonly value="${esc(payment.qr_code)}"><button class="btn sm primary" type="button" data-copy>${icon('copy')} Copiar</button></div>`);
        $('[data-copy]', m.el)?.addEventListener('click', async (e) => {
          try { await navigator.clipboard.writeText(payment.qr_code); } catch { $('.copy-row input', m.el).select(); document.execCommand('copy'); }
          e.target.closest('button').innerHTML = `${icon('check')} Copiado!`;
        });
      }
    }
    function waitScreen(payment, inner) {
      m.el.innerHTML = `<div class="pay"><h3>${esc(opt.title)} · ${money(payment.amount)}</h3>${inner}
        <div class="pay-wait"><span class="spinner"></span> Aguardando confirmação do pagamento…</div>
        <button type="button" class="icon-btn modal-x" data-close>${icon('close')}</button></div>`;
      $('#modal').onclick = (e) => { if (e.target.closest('[data-close]') || e.target === $('#modal')) { stop(); $('#modal').hidden = true; $('#modal').innerHTML = ''; } };
      const started = Date.now();
      const poll = async () => {
        if (stopped) return;
        try {
          const { payment: p } = await api('pay_status', { params: { id: payment.id } });
          if (p.status === 'approved') return success(p);
          if (p.status === 'failed' || p.status === 'expired') { $('.pay-wait', m.el).innerHTML = '❌ Pagamento não aprovado. Tente novamente.'; return; }
        } catch { /* rede instável: tenta de novo */ }
        if (Date.now() - started < 60 * 60 * 1000) timer = setTimeout(poll, 4000);
      };
      timer = setTimeout(poll, 4000);
    }
    async function success(p) {
      stop();
      m.el.innerHTML = `<div class="pay"><div class="pay-ok">${icon('check')}</div><h3>Pagamento aprovado!</h3>
        <p>${p.kind === 'credits' ? `+${p.qty} créditos adicionados à sua conta.` : 'Seu plano foi renovado. Boa música! 🎶'}</p>
        <button class="btn primary" data-close>Continuar</button></div>`;
      confetti();
      await refreshMe();
      if (p.kind !== 'credits' && S.tracks.length === 0) await loadLibrary().catch(() => {});
      route();
    }
  }

  /* ---------- Offline: músicas guardadas no aparelho (Cache Storage + service worker) ---------- */
  const MEDIA_CACHE = 'sonora-media-v1';
  const Offline = {
    ids: new Set(store.get('offline', [])),
    supported: 'caches' in window && window.isSecureContext,
    allowed() { return this.supported && S.me?.user.allow_offline !== false; },
    url(id, cover) { return new URL(`stream.php?id=${id}${cover ? '&cover=1' : ''}`, location.href).href; },
    has(id) { return this.ids.has(id); },
    persist() { store.set('offline', [...this.ids]); },
    async save(ids) {
      if (!this.allowed()) return toast(this.supported ? 'Seu plano não inclui o modo offline' : 'O modo offline precisa de HTTPS', 'err');
      try { await navigator.storage?.persist?.(); } catch { /* opcional */ }
      const todo = ids.filter((id) => !this.ids.has(id) && S.byId.get(id));
      if (!todo.length) return toast('Já está tudo disponível offline ✔');
      const cache = await caches.open(MEDIA_CACHE);
      let ok = 0, fail = 0;
      toast(`Salvando ${todo.length} música${todo.length > 1 ? 's' : ''} no aparelho…`);
      for (const id of todo) {
        try {
          const r = await fetch(this.url(id), { credentials: 'same-origin' });
          if (!r.ok) throw new Error();
          await cache.put(this.url(id), r);
          if (S.byId.get(id).cover) { const rc = await fetch(this.url(id, true)); if (rc.ok) await cache.put(this.url(id, true), rc); }
          this.ids.add(id); this.persist(); ok++;
          $$(`.trk[data-id="${id}"] .trk-main b`).forEach((b) => { if (!b.querySelector('.off-dot')) b.insertAdjacentHTML('beforeend', `<span class="off-dot">${icon('offline')}</span>`); });
        } catch { fail++; }
      }
      toast(`✔ ${ok} disponíve${ok > 1 ? 'is' : 'l'} offline${fail ? ` · ${fail} falharam (espaço?)` : ''}`, fail ? 'err' : 'ok');
      if (currentRoute()[0] === 'offline') route();
    },
    async remove(ids) {
      if (!this.supported) return;
      const cache = await caches.open(MEDIA_CACHE);
      for (const id of ids) { await cache.delete(this.url(id)); await cache.delete(this.url(id, true)); this.ids.delete(id); }
      this.persist();
    },
    async clear() { if (this.supported) await caches.delete(MEDIA_CACHE); this.ids.clear(); this.persist(); },
  };

  async function vOffline() {
    const list = S.tracks.filter((t) => Offline.has(t.id));
    const size = list.reduce((s, t) => s + t.size, 0);
    let est = null;
    try { est = await navigator.storage?.estimate?.(); } catch { /* sem suporte */ }
    view.innerHTML = `<div class="hero"><div class="art" style="background:linear-gradient(135deg,var(--accent),var(--accent-2))"><div class="ph" style="color:#fff">${icon('offline')}</div></div>
      <div class="meta"><div class="kicker">No seu aparelho</div><h1 class="h1">Offline</h1>
      <p class="sub">${list.length} músicas · ${fmtSize(size)}${est?.quota ? ` · espaço livre ~${fmtSize(est.quota - est.usage)}` : ''}</p>
      <div class="row">${list.length ? `<button class="btn primary" data-action="play-all">${icon('play')} Tocar</button><button class="btn" data-action="shuffle-all">${icon('shuffle')} Aleatório</button>` : ''}
      ${!S.offlineMode && S.tracks.length ? `<button class="btn" id="off-favs">${icon('heart')} Salvar favoritas</button><button class="btn" id="off-all">${icon('offline')} Salvar tudo</button>` : ''}
      ${list.length && !S.offlineMode ? `<button class="btn ghost" id="off-clear">${icon('trash')} Limpar</button>` : ''}</div></div></div>
      ${!Offline.supported ? '<div class="banner warn"><span>O modo offline precisa que o site esteja em HTTPS (a Hostinger oferece SSL grátis).</span></div>' : ''}
      ${list.length ? trackList(list) : `<div class="empty">${icon('offline')}<h3>Nada salvo ainda</h3><p>Em qualquer artista, gênero ou playlist toque em <b>Offline</b> — ou use o menu ⋯ de uma música.<br>Depois é só ouvir no avião, no metrô ou sem plano de dados. ✈️</p></div>`}`;
    $('#off-favs')?.addEventListener('click', () => Offline.save(S.tracks.filter((t) => t.favorite).map((t) => t.id)));
    $('#off-all')?.addEventListener('click', () => { const ids = S.tracks.map((t) => t.id); if (ids.length < 40 || confirm(`Salvar ${ids.length} músicas (${fmtSize(S.tracks.reduce((s, t) => s + t.size, 0))}) no aparelho?`)) Offline.save(ids); });
    $('#off-clear')?.addEventListener('click', async () => { if (confirm('Remover todas as músicas salvas neste aparelho?')) { await Offline.clear(); route(); } });
  }

  /* ---------- Minha conta ---------- */
  async function vAccount() {
    if (!S.me) return;
    const { user: u, usage, plans, packages, brand } = S.me;
    const params = new URLSearchParams(location.hash.split('?')[1] || '');
    const bar = (v, max) => `<div class="meter"><i style="width:${max ? Math.min(100, (v / max) * 100) : 0}%"></i></div>`;
    const planDays = plans.find((p) => p.id === u.plan_id)?.days || 30;
    view.innerHTML = `
      <div class="hero"><div class="art avatar" style="background:${gradient(u.username)}"><div class="ph" style="color:#fff">${esc((u.name || u.username)[0].toUpperCase())}</div></div>
        <div class="meta"><div class="kicker">${esc(u.role_label)}${u.is_trial ? ' · teste grátis' : ''}</div><h1 class="h1">${esc(u.name || u.username)}</h1>
        <p class="sub">@${esc(u.username)}${u.email ? ' · ' + esc(u.email) : ''}</p>
        <div class="row">${brand.support_url ? `<a class="btn" href="${esc(brand.support_url)}" target="_blank" rel="noopener">💬 Suporte</a>` : ''}
        <button class="btn ghost" data-action="logout">${icon('logout')} Sair</button></div></div></div>
      <div class="stats">
        <div class="stat ${u.expired ? 'bad' : u.days_left !== null && u.days_left <= 5 ? 'warn' : ''}"><span>${u.expires_at ? (u.expired ? 'Venceu em' : 'Vence em') : 'Validade'}</span>
          <b>${u.expires_at ? dateFmt(u.expires_at) : 'Sem vencimento'}</b>
          ${u.expires_at && !u.expired ? `<span>${u.days_left < 1 ? 'vence hoje' : `faltam ${u.days_left} dias`}</span>${bar(Math.max(0, u.days_left), planDays)}` : ''}</div>
        <div class="stat"><span>Plano</span><b>${esc(u.is_trial ? 'Teste grátis' : S.me.user.plan_name || '—')}</b><span>${u.allow_video ? '🎬 vídeos' : 'só áudio'} · ${u.allow_offline ? '📴 offline' : 'sem offline'}</span></div>
        <div class="stat"><span>Downloads hoje</span><b>${usage.downloads_today}${u.dl_per_day ? ` / ${u.dl_per_day}` : ''}</b>${u.dl_per_day ? bar(usage.downloads_today, u.dl_per_day) : '<span>ilimitado</span>'}</div>
        <div class="stat"><span>Minha biblioteca</span><b>${usage.tracks}${u.max_tracks ? ` / ${u.max_tracks}` : ''}</b>${u.max_tracks ? bar(usage.tracks, u.max_tracks) : '<span>ilimitada</span>'}</div>
        ${S.me.panel && !isAdmin() ? `<div class="stat ${u.credits < 0 ? 'bad' : ''}"><span>Créditos</span><b>${u.credits}</b><span>1 crédito ≈ 1 mês de cliente</span></div>` : ''}
      </div>
      ${S.me.invite_url ? `<div class="toolbar"><span>🔗 <b>Seu link de convite</b> — quem se cadastrar ganha teste grátis e fica na sua conta:</span>
        <input class="filter-input" readonly value="${esc(S.me.invite_url)}" style="flex:1;min-width:200px" id="invite"><button class="btn sm primary" id="invite-copy">${icon('copy')} Copiar</button></div>` : ''}
      ${plans.length && !isAdmin() ? `<h2 class="h2">${u.expired ? 'Renove seu plano' : 'Renovar / mudar de plano'}</h2>
        ${S.me.mp_enabled ? '' : '<p class="muted">Pagamento online indisponível no momento — fale com o suporte para renovar.</p>'}
        <div class="plans" id="plans-grid">${plans.map((p) => `<div class="plan${p.highlight ? ' hot' : ''}">${p.highlight ? '<span class="plan-badge">Mais popular</span>' : ''}
          <h3>${esc(p.name)}</h3><div class="price">${money(p.price)}</div><p class="muted small">${p.days} dias · ${money(p.price / Math.max(1, p.days / 30))}/mês</p>
          <ul><li>${p.dl_per_day ? `${p.dl_per_day} downloads por dia` : 'Downloads ilimitados'}</li><li>${p.max_tracks ? `Até ${p.max_tracks.toLocaleString('pt-BR')} músicas` : 'Biblioteca ilimitada'}</li>
          <li class="${p.allow_video ? '' : 'no'}">Vídeos</li><li class="${p.allow_offline ? '' : 'no'}">Ouvir offline</li><li>Letras, equalizador e mais</li></ul>
          <button class="btn ${p.highlight ? 'primary' : ''}" data-plan="${p.id}" ${S.me.mp_enabled ? '' : 'disabled'}>${u.expired ? 'Renovar' : 'Assinar'}</button></div>`).join('')}</div>` : ''}
      ${packages.length ? `<h2 class="h2">Comprar créditos</h2><div class="plans">${packages.map((k, i) => `<div class="plan"><h3>${k.qty} créditos</h3>
          <div class="price">${money(k.price)}</div><p class="muted small">${money(k.price / k.qty)} por crédito</p><button class="btn" data-pkg="${i}">Comprar</button></div>`).join('')}</div>` : ''}
      <h2 class="h2">Meus dados</h2>
      <form class="card-form" id="profile">
        <div class="grid2"><label class="fld"><span>Nome</span><input name="name" value="${esc(u.name)}" maxlength="80"></label>
        <label class="fld"><span>E-mail</span><input type="email" name="email" value="${esc(u.email)}"></label>
        <label class="fld"><span>WhatsApp</span><input name="phone" value="${esc(u.phone)}"></label></div>
        <div class="grid2"><label class="fld"><span>Senha atual</span><input type="password" name="password_current" autocomplete="current-password"></label>
        <label class="fld"><span>Nova senha</span><input type="password" name="password_new" minlength="6" autocomplete="new-password"></label></div>
        <div class="row"><label class="switch"><input type="checkbox" id="dyn" ${store.get('dynColor', true) ? 'checked' : ''}><i></i> Cor do app acompanha a capa da música</label>
        <span class="spacer"></span><button class="btn primary">Salvar</button></div>
      </form>
      <h2 class="h2">Pagamentos</h2><div id="my-pays"><div class="skeleton"></div></div>`;
    $$('[data-plan]', view).forEach((b) => b.addEventListener('click', () => {
      const p = plans.find((x) => x.id === +b.dataset.plan);
      openPay({ kind: 'renew', plan_id: p.id, amount: p.price, title: `Plano ${p.name}`, desc: `${p.days} dias${u.expires_at && !u.expired ? ', somados ao tempo que você ainda tem' : ''}.` });
    }));
    $$('[data-pkg]', view).forEach((b) => b.addEventListener('click', () => {
      const k = packages[+b.dataset.pkg];
      openPay({ kind: 'credits', package: +b.dataset.pkg, amount: k.price, title: `${k.qty} créditos`, desc: 'Use para criar e renovar clientes.' });
    }));
    $('#invite-copy')?.addEventListener('click', async () => { try { await navigator.clipboard.writeText(S.me.invite_url); } catch { $('#invite').select(); document.execCommand('copy'); } toast('Link copiado!', 'ok'); });
    $('#dyn').addEventListener('change', (e) => { store.set('dynColor', e.target.checked); if (P.track) Accent.from(P.track); else applyBrand(S.me.brand); });
    $('#profile').addEventListener('submit', async (e) => {
      e.preventDefault();
      try { await api('profile_save', { body: formToObj(e.target) }); toast('Dados salvos', 'ok'); await refreshMe(); } catch (ex) { toast(ex.message, 'err'); }
    });
    try {
      const { payments } = await api('payments');
      const label = { pending: 'Aguardando', approved: 'Aprovado', failed: 'Recusado', expired: 'Expirado' };
      $('#my-pays').innerHTML = payments.filter((p) => p.account_id === u.id).length ? `<div class="table">${payments.filter((p) => p.account_id === u.id).map((p) => `<div class="tr">
        <span>#${p.id}</span><span>${p.kind === 'credits' ? `${p.qty} créditos` : `Plano ${esc(p.plan_name || '')}`}</span><span>${money(p.amount)}</span>
        <span class="pill-s ${p.status}">${label[p.status] || p.status}</span><span class="muted">${dateFmt(p.created_at)}</span></div>`).join('')}</div>` : '<p class="muted">Nenhum pagamento ainda.</p>';
    } catch { $('#my-pays').innerHTML = ''; }
    if (params.get('pay')) {
      const { payment } = await api('pay_status', { params: { id: params.get('pay') } }).catch(() => ({}));
      if (payment?.status === 'approved') { confetti(); toast('Pagamento aprovado! 🎉', 'ok'); await refreshMe(); history.replaceState(null, '', '#/account'); vAccount(); }
      else if (payment?.status === 'pending') toast('Pagamento em processamento — liberamos assim que o Mercado Pago confirmar.');
    }
  }

  window.Sonora = { api, $, $$, esc, icon, toast, modal, formToObj, money, dateFmt, fmtSize, gradient, S, route, refreshMe, confetti, openPay, isAdmin, views: {} };

  /* ======================= Eventos globais ======================= */
  document.addEventListener('click', (e) => {
    const a = e.target.closest('[data-action]'); if (!a) return;
    const act = a.dataset.action;
    const row = a.closest('.trk'), t = row ? S.byId.get(+row.dataset.id) : null;
    const listIds = () => (a.closest('[data-ids]') || $('[data-ids]', view))?.dataset.ids.split(',').map(Number) || [];
    const actions = {
      toggle: () => P.toggle(), next: () => P.next(), prev: () => P.prev(),
      shuffle: () => P.toggleShuffle(), repeat: () => P.cycleRepeat(),
      mute: () => Vol.setMuted(!Vol.muted),
      'open-np': () => { if (!e.target.closest('[data-action=fav-current]')) openNP(); },
      'close-np': closeNP,
      lyrics: () => openNP('lyrics'), queue: () => openNP('queue'),
      eq: () => openEq(a), sleep: () => openSleep(a),
      'fav-current': () => { e.stopPropagation(); if (P.track) toggleFav(P.track); },
      fav: () => t && toggleFav(t),
      menu: () => t && trackMenu(a, t),
      'play-row': () => { const ids = listIds(); P.playList(ids, Math.max(0, ids.indexOf(t.id))); },
      'play-one': () => { const id = +a.dataset.id; if (S.byId.has(id)) { P.playNow(id); if (S.byId.get(id).kind === 'video') openNP(); } },
      'play-all': () => { const ids = listIds(); if (ids.length) P.playList(ids, 0); },
      'shuffle-all': () => { const ids = listIds(); if (!ids.length) return; if (!P.shuffle) P.toggleShuffle(); P.playList(ids, Math.floor(Math.random() * ids.length)); },
      mix: () => { if (!P.shuffle) P.toggleShuffle(); const ids = S.tracks.filter((x) => x.kind === 'audio').map((x) => x.id); P.playList(ids, Math.floor(Math.random() * ids.length)); },
      'play-favs': () => { const ids = S.tracks.filter((x) => x.favorite).map((x) => x.id); ids.length ? P.playList(ids, 0) : toast('Nenhuma favorita ainda'); },
      dl: () => { const it = S.search.items[+a.closest('.res').dataset.idx]; if (it) download([it]); },
      'download-all': () => {
        const k = S.dlKind, todo = visibleItems().filter((it) => !it.library?.[k] && !it.job?.[k]);
        const u = S.me?.user, left = u && u.dl_per_day && !isAdmin() ? u.dl_per_day - S.me.usage.downloads_today : Infinity;
        const fresh = todo.filter((it) => !it.server?.[k]).length;
        if (fresh > left && !confirm(`Seu plano permite mais ${Math.max(0, left)} downloads hoje. As que já estão no servidor entram na hora; as demais vão parar no limite. Continuar?`)) return;
        if (todo.length > 15 && !confirm(`Baixar ${todo.length} ${k === 'video' ? 'vídeos' : 'músicas'}?`)) return;
        download(todo);
      },
      preview: () => { const it = S.search.items[+a.closest('.res').dataset.idx]; if (it?.preview) togglePreview(a, it.preview); },
      more: () => doSearch(S.search.q, true),
      'jobs-refresh': () => pollJobs(true),
      'jobs-clear': async () => { await api('jobs_clear', { body: {} }); pollJobs(true); },
      'job-retry': async () => { await api('job_retry', { body: { id: +a.closest('[data-job]').dataset.job } }); pollJobs(true); },
      'job-cancel': async () => { await api('job_cancel', { body: { id: +a.closest('[data-job]').dataset.job } }); pollJobs(true); },
      logout: async () => { await api('logout', { body: {} }).catch(() => {}); await Offline.clear().catch(() => {}); store.set('lib', []); store.set('player', null); location.replace('./'); },
      'stop-impersonate': async () => { await api('stop_impersonate', { body: {} }); location.replace('./#/admin/accounts'); location.reload(); },
      renew: () => { location.hash = '#/account'; setTimeout(() => $('#plans-grid')?.scrollIntoView({ behavior: 'smooth' }), 120); },
      'offline-list': () => Offline.save(listIds()),
    };
    if (actions[act]) { e.preventDefault(); actions[act](); }
  });
  view.addEventListener('dblclick', (e) => { const row = e.target.closest('.trk'); if (row && !e.target.closest('button,a')) row.querySelector('[data-action=play-row]')?.click(); });
  document.addEventListener('submit', (e) => {
    const f = e.target.closest('[data-form]'); if (!f) return;
    e.preventDefault();
    const q = f.q.value.trim();
    if (f.dataset.form === 'home-search') { location.hash = `#/search?q=${encodeURIComponent(q)}&s=youtube`; return; }
    doSearch(q);
  });

  document.addEventListener('keydown', (e) => {
    if (e.target.closest('input, textarea, select') || e.ctrlKey || e.metaKey || e.altKey) { if (e.key === 'Escape') e.target.blur?.(); return; }
    const k = e.key.toLowerCase();
    const map = {
      ' ': () => P.toggle(), arrowright: () => P.seek(P.el.currentTime + 5), arrowleft: () => P.seek(P.el.currentTime - 5),
      arrowup: () => Vol.set(Vol.value + 0.05), arrowdown: () => Vol.set(Vol.value - 0.05),
      n: () => P.next(), p: () => P.prev(), s: () => P.toggleShuffle(), r: () => P.cycleRepeat(), m: () => Vol.setMuted(!Vol.muted),
      l: () => ($('#np').classList.contains('open') ? closeNP() : openNP('lyrics')), q: () => openNP('queue'),
      f: () => P.track && toggleFav(P.track), '/': () => { location.hash = '#/search'; setTimeout(() => $('input[name=q]')?.focus(), 50); },
      escape: () => { closePop(); closeNP(); },
    };
    if (map[k]) { e.preventDefault(); map[k](); }
  });

  /* Gesto: arrastar para baixo fecha a tela "Tocando agora" no celular */
  (() => {
    const np = $('#np'); let y0 = null;
    np.addEventListener('touchstart', (e) => { y0 = e.target.closest('.np-right, input') ? null : e.touches[0].clientY; }, { passive: true });
    np.addEventListener('touchend', (e) => { if (y0 !== null && e.changedTouches[0].clientY - y0 > 90) closeNP(); y0 = null; });
  })();

  window.addEventListener('hashchange', route);
  window.addEventListener('beforeunload', () => P.save());

  /* ======================= Login / boot ======================= */
  $('#login-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target, err = $('.login-err', f); err.textContent = '';
    f.querySelector('button').disabled = true;
    try {
      await api('login', { body: { username: f.username.value, password: f.password.value } });
      location.replace('./' + location.hash); location.reload();
    } catch (ex) { err.textContent = ex.message; f.querySelector('button').disabled = false; }
  });
  $('#signup-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const f = e.target, err = $('.login-err', f); err.textContent = '';
    f.querySelector('button').disabled = true;
    try {
      await api('signup', { body: Object.fromEntries(new FormData(f)) });
      store.set('welcome', 1);
      location.replace('./#/search');
    } catch (ex) { err.textContent = ex.message; f.querySelector('button').disabled = false; }
  });
  $$('[data-login-toggle]').forEach((b) => b.addEventListener('click', () => { $('#login-form').hidden = !$('#login-form').hidden; $('#signup-form').hidden = !$('#signup-form').hidden; }));

  async function boot() {
    try {
      await refreshMe();
      S.status = await api('status');
      if (!S.me.user.expired) await loadLibrary();
    } catch (e) {
      if (document.body.classList.contains('logged-out')) return;
      if (e instanceof TypeError && store.get('lib', []).length) return bootOffline();
      toast(e.message, 'err'); return;
    }
    Vol.ui(); Vol.set(Vol.value);
    P.restore(); updateNowPlaying();
    route();
    if (!S.me.user.expired) pollJobs();
    if (store.get('welcome', 0)) { store.set('welcome', 0); confetti(); toast(`Bem-vindo(a)! Seu teste grátis começou 🎉`, 'ok'); }
    if (isAdmin() && !S.status.download) toast('yt-dlp não instalado — abra Ferramentas para instalar', 'err', { label: 'Abrir', fn: () => (location.href = 'install.php') });
  }

  /* Sem internet: abre com a biblioteca salva e toca o que está offline */
  function bootOffline() {
    S.offlineMode = true;
    S.me = store.get('me', null);
    S.tracks = store.get('lib', []).filter((t) => Offline.has(t.id));
    S.byId = new Map(S.tracks.map((t) => [t.id, t]));
    document.body.classList.add('offline');
    if (S.me) applyBrand(S.me.brand);
    renderBanners(); renderSidebarGenres();
    Vol.ui(); Vol.set(Vol.value); P.restore(); updateNowPlaying();
    route();
  }
  window.addEventListener('online', () => { if (S.offlineMode) location.reload(); });

  if ('serviceWorker' in navigator && window.isSecureContext) navigator.serviceWorker.register('sw.js').catch(() => {});
  document.addEventListener('DOMContentLoaded', () => { if (APP.logged) boot(); });
})();

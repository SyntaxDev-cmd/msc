/* Painel de administração e revendas */
(() => {
  'use strict';
  const { api, $, $$, esc, icon, toast, modal, money, dateFmt, fmtSize, gradient, S, refreshMe, confetti, isAdmin } = window.Sonora;
  const view = $('#view');
  const ROLE_ICON = { admin: '👑', master: '💎', reseller: '🏪', user: '🎧' };
  let plansCache = null;

  const plans = async () => (plansCache ||= (await api('plans')).plans);
  const me = () => S.me.user;
  const loginUrl = () => location.origin + location.pathname + (isAdmin() ? '' : `?r=${encodeURIComponent(me().username)}`);
  const randPass = () => Array.from(crypto.getRandomValues(new Uint32Array(2)), (n) => n.toString(36)).join('').slice(0, 8);
  const statusOf = (a) => a.status === 'blocked' ? ['blocked', 'Bloqueado'] : a.expired ? ['expired', 'Vencido'] : a.is_trial ? ['trial', 'Teste'] : a.days_left !== null && a.days_left <= 5 ? ['soon', `Vence em ${Math.max(0, a.days_left)}d`] : ['active', 'Ativo'];
  const header = (title, sub, actions = '') => `<div class="ph-head"><div><h1 class="h1">${title}</h1>${sub ? `<p class="sub">${sub}</p>` : ''}</div><div class="row">${actions}</div></div>`;
  const creditsChip = () => (isAdmin() ? '' : `<span class="chip on">💰 ${me().credits} créditos</span>`);

  /* ======================= Visão geral ======================= */
  async function vDashboard() {
    view.innerHTML = header(`Olá, ${esc(me().name || me().username)} ${ROLE_ICON[me().role]}`, `${esc(me().role_label)} · painel de gestão`, creditsChip()) + '<div class="skeleton" style="height:300px"></div>';
    const d = await api('dashboard');
    const maxRev = Math.max(1, ...d.series.map((x) => x.revenue)), maxSig = Math.max(1, ...d.series.map((x) => x.signups));
    view.innerHTML = header(`Olá, ${esc(me().name || me().username)} ${ROLE_ICON[me().role]}`, `${esc(me().role_label)} · painel de gestão`, `${creditsChip()}
        <button class="btn" data-quick-trial>${icon('sparkle')} Teste grátis rápido</button>
        <button class="btn primary" data-new>${icon('plus')} Nova conta</button>`) + `
      <div class="stats">
        <div class="stat"><span>Clientes ativos</span><b>${d.active}</b><span>de ${d.users} clientes</span></div>
        <div class="stat warn"><span>Vencendo em 7 dias</span><b>${d.expiring}</b><span>renove antes de perder</span></div>
        <div class="stat bad"><span>Vencidos</span><b>${d.expired}</b><span>oportunidade de renovação</span></div>
        <div class="stat"><span>Em teste grátis</span><b>${d.trials}</b><span>converta em assinantes</span></div>
        <div class="stat ok"><span>Recebido no mês</span><b>${money(d.revenue_month)}</b><span>via Mercado Pago</span></div>
        ${d.credits !== null ? `<div class="stat ${d.credits < 0 ? 'bad' : ''}"><span>Seus créditos</span><b>${d.credits}</b><span>${d.credits_out} com suas revendas</span></div>` : `<div class="stat"><span>Revendas</span><b>${d.masters + d.resellers}</b><span>${d.masters} master · ${d.resellers} revenda</span></div>`}
        ${d.tracks !== null ? `<div class="stat"><span>Acervo do servidor</span><b>${d.tracks}</b><span>${fmtSize(d.storage)} usados${d.disk_free ? ` · ${fmtSize(d.disk_free)} livres` : ''}</span></div>` : ''}
      </div>
      ${d.tools && !d.tools.ytdlp ? '<div class="banner err"><span>yt-dlp não instalado: ninguém consegue baixar.</span><a class="btn sm" href="install.php">Instalar</a></div>' : ''}
      <div class="dash-grid">
        <div class="panel-card"><h3>Últimos 14 dias</h3>
          <div class="chart" role="img" aria-label="Receita e novos cadastros por dia">${d.series.map((x) => `<div class="col" title="${x.day}: ${money(x.revenue)} · ${x.signups} cadastro(s)">
            <i class="rev" style="height:${(x.revenue / maxRev) * 100}%"></i><i class="sig" style="height:${(x.signups / maxSig) * 100}%"></i><small>${x.day.slice(0, 2)}</small></div>`).join('')}</div>
          <div class="legend"><span><i class="rev"></i> Receita</span><span><i class="sig"></i> Novos cadastros</span></div></div>
        <div class="panel-card"><h3>Vencendo nos próximos 7 dias</h3>
          ${d.expiring_list.length ? d.expiring_list.map((a) => `<div class="mini-acc"><span class="av" style="background:${gradient(a.username)}">${esc(a.username[0].toUpperCase())}</span>
            <div><b>${esc(a.name || a.username)}</b><small>@${esc(a.username)} · ${a.days_left < 1 ? 'hoje' : `em ${a.days_left} dia${a.days_left > 1 ? 's' : ''}`}</small></div>
            <button class="btn sm" data-renew="${a.id}">Renovar</button></div>`).join('') : '<p class="muted">Ninguém vencendo. 👌</p>'}</div>
      </div>`;
    $('[data-new]', view).onclick = () => openCreate();
    $('[data-quick-trial]', view).onclick = quickTrial;
    $$('[data-renew]', view).forEach((b) => (b.onclick = () => openRenew(d.expiring_list.find((a) => a.id === +b.dataset.renew), vDashboard)));
  }

  async function quickTrial() {
    const username = 'teste' + Math.floor(1000 + Math.random() * 9000), password = randPass();
    try {
      await api('account_save', { body: { role: 'user', trial: 1, username, password, name: 'Teste grátis' } });
      const { accounts } = await api('accounts', { params: { q: username } });
      showCredentials(accounts[0], password);
    } catch (e) { toast(e.message, 'err'); }
  }

  /* ======================= Contas ======================= */
  const F = { q: '', role: '', status: '', parent: '' };
  let parentLabel = '';
  async function vAccounts() {
    view.innerHTML = header('Contas', 'Clientes, revendas e masters abaixo de você', `${creditsChip()}<button class="btn primary" data-new>${icon('plus')} Nova conta</button>`) + `
      <div class="toolbar">
        <input class="filter-input" placeholder="Buscar por usuário, nome, e-mail, WhatsApp…" value="${esc(F.q)}" data-f="q" style="flex:1;min-width:180px">
        <select class="filter-input" data-f="role"><option value="">Todos os tipos</option>${Object.entries(S.me.roles).filter(([k]) => k !== 'admin').map(([k, l]) => `<option value="${k}" ${F.role === k ? 'selected' : ''}>${l}</option>`).join('')}</select>
        <div class="seg" data-status>${[['', 'Todos'], ['active', 'Ativos'], ['expiring', 'Vencendo'], ['expired', 'Vencidos'], ['trial', 'Teste'], ['blocked', 'Bloqueados']].map(([k, l]) => `<button data-k="${k}" class="${F.status === k ? 'active' : ''}">${l}</button>`).join('')}</div>
      </div>${F.parent ? `<div class="banner info"><span>Mostrando contas abaixo de <b>${esc(parentLabel)}</b></span><button class="btn sm" data-clear-parent>Ver todas</button></div>` : ''}<div id="acc-list"><div class="skeleton"></div></div>`;
    $('[data-clear-parent]', view)?.addEventListener('click', () => { F.parent = ''; vAccounts(); });
    $('[data-new]', view).onclick = () => openCreate();
    let t = null;
    $('[data-f=q]', view).addEventListener('input', (e) => { F.q = e.target.value; clearTimeout(t); t = setTimeout(load, 250); });
    $('[data-f=role]', view).addEventListener('change', (e) => { F.role = e.target.value; load(); });
    $('[data-status]', view).addEventListener('click', (e) => { const b = e.target.closest('button'); if (!b) return; F.status = b.dataset.k; $$('[data-status] button', view).forEach((x) => x.classList.toggle('active', x === b)); load(); });
    load();
  }
  let accCache = [];
  async function load() {
    const box = $('#acc-list'); if (!box) return;
    const { accounts } = await api('accounts', { params: F });
    accCache = accounts;
    if (!accounts.length) { box.innerHTML = `<div class="empty">${icon('users')}<h3>Nenhuma conta encontrada</h3><p>Crie sua primeira conta em “Nova conta”.</p></div>`; return; }
    box.innerHTML = `<div class="acc-list">${accounts.map((a) => {
      const [st, stl] = statusOf(a);
      return `<div class="acc" data-id="${a.id}">
        <span class="av" style="background:${gradient(a.username)}">${esc((a.name || a.username)[0].toUpperCase())}</span>
        <div class="acc-main"><b>${esc(a.name || a.username)} <span class="role-tag ${a.role}">${ROLE_ICON[a.role]} ${esc(a.role_label)}</span></b>
          <small>@${esc(a.username)}${a.phone ? ' · ' + esc(a.phone) : ''}${a.parent_username && a.parent_id !== me().id ? ` · via ${esc(a.parent_username)}` : ''}</small></div>
        <span class="pill-s ${st}">${stl}</span>
        <span class="acc-exp"><small>Vence</small>${a.expires_at ? dateFmt(a.expires_at) : '∞'}</span>
        <span class="acc-plan">${a.role === 'user' ? `<small>Plano</small>${esc(a.is_trial ? 'Teste' : a.plan_name || '—')}` : `<small>Créditos</small><b class="${a.credits < 0 ? 'neg' : ''}">${a.credits}</b>${a.children ? ` · ${a.children} conta${a.children > 1 ? 's' : ''}` : ''}`}</span>
        <div class="acc-act">
          <button class="btn sm" data-a="renew">${icon('refresh')} Renovar</button>
          ${a.role !== 'user' ? `<button class="btn sm" data-a="credits">💰 Créditos</button>` : ''}
          <button class="icon-btn" data-a="menu" title="Mais">${icon('more')}</button>
        </div></div>`;
    }).join('')}</div><p class="muted small" style="text-align:center">${accounts.length} conta${accounts.length > 1 ? 's' : ''}</p>`;
    box.onclick = (e) => {
      const b = e.target.closest('[data-a]'); if (!b) return;
      const a = accCache.find((x) => x.id === +b.closest('.acc').dataset.id);
      if (b.dataset.a === 'renew') openRenew(a, load);
      if (b.dataset.a === 'credits') openCredits(a);
      if (b.dataset.a === 'menu') accMenu(b, a);
    };
  }

  function accMenu(anchor, a) {
    const pop = $('#pop');
    pop.innerHTML = `<button class="mi" data-m="edit">${icon('gear')}Editar e limites</button>
      <button class="mi" data-m="share">${icon('copy')}Enviar acesso (WhatsApp)</button>
      <button class="mi" data-m="login">${icon('user')}Entrar como ${esc(a.username)}</button>
      ${a.role !== 'user' ? `<button class="mi" data-m="children">${icon('users')}Ver contas abaixo</button>` : ''}
      <button class="mi" data-m="block">${icon(a.status === 'blocked' ? 'check' : 'close')}${a.status === 'blocked' ? 'Desbloquear' : 'Bloquear acesso'}</button>
      <button class="mi danger" data-m="delete">${icon('trash')}Excluir</button>`;
    pop.hidden = false;
    const r = anchor.getBoundingClientRect(), pr = pop.getBoundingClientRect();
    pop.style.left = Math.max(10, r.right - pr.width) + 'px';
    pop.style.top = (r.bottom + pr.height + 10 > innerHeight ? r.top - pr.height - 8 : r.bottom + 8) + 'px';
    pop.onclick = async (e) => {
      const m = e.target.closest('[data-m]')?.dataset.m; if (!m) return;
      pop.hidden = true;
      try {
        if (m === 'edit') openEdit(a);
        if (m === 'share') showCredentials(a, null);
        if (m === 'children') { Object.assign(F, { q: '', role: '', status: '', parent: a.id }); parentLabel = a.username; vAccounts(); }
        if (m === 'login' && confirm(`Entrar na conta de ${a.username}? (você pode voltar depois)`)) { await api('impersonate', { body: { id: a.id } }); location.replace('./#/home'); location.reload(); }
        if (m === 'block') { await api('account_save', { body: { id: a.id, status: a.status === 'blocked' ? 'active' : 'blocked' } }); toast(a.status === 'blocked' ? 'Desbloqueado' : 'Bloqueado', 'ok'); load(); }
        if (m === 'delete' && confirm(`Excluir ${a.username}? Esta ação não pode ser desfeita.`)) { await api('account_delete', { body: { id: a.id } }); toast('Conta excluída', 'ok'); load(); }
      } catch (ex) { toast(ex.message, 'err'); }
    };
  }

  async function openCreate(defaults = {}) {
    const can = S.me.can_create;
    const ps = await plans();
    let parents = [];
    if (isAdmin()) parents = (await api('accounts', { params: { status: '' } })).accounts.filter((a) => a.role === 'master' || a.role === 'reseller');
    const role0 = defaults.role || (can.includes('user') ? 'user' : can[0]);
    const m = modal(`<form class="form">
      <h3>Nova conta</h3>
      <div class="seg role-seg">${can.map((r) => `<button type="button" data-role="${r}" class="${r === role0 ? 'active' : ''}">${ROLE_ICON[r]} ${esc(S.me.roles[r])}</button>`).join('')}</div>
      <input type="hidden" name="role" value="${role0}">
      ${parents.length ? `<label class="fld"><span>Criar abaixo de</span><select name="parent_id"><option value="">Mim (administrador)</option>${parents.map((p) => `<option value="${p.id}">${ROLE_ICON[p.role]} ${esc(p.username)}</option>`).join('')}</select></label>` : ''}
      <div class="grid2">
        <label class="fld"><span>Nome</span><input name="name" maxlength="80" autofocus></label>
        <label class="fld"><span>WhatsApp</span><input name="phone" maxlength="30" placeholder="5511999999999"></label>
        <label class="fld"><span>Usuário *</span><input name="username" required pattern="[A-Za-z0-9._\\-]{3,32}" autocapitalize="none"></label>
        <label class="fld"><span>Senha *</span><div class="in-btn"><input name="password" required minlength="6" value="${randPass()}"><button type="button" class="icon-btn" data-gen title="Gerar">${icon('refresh')}</button></div></label>
      </div>
      <label class="fld"><span>E-mail</span><input type="email" name="email"></label>
      <div data-for="user">
        <label class="switch"><input type="checkbox" name="trial"><i></i> Teste grátis (não consome créditos)</label>
        <label class="fld" data-plan-fld><span>Plano</span><select name="plan_id">${ps.filter((p) => p.active).map((p) => `<option value="${p.id}">${esc(p.name)} — ${p.days} dias${isAdmin() ? '' : ` · ${p.credits} crédito${p.credits > 1 ? 's' : ''}`}</option>`).join('')}</select></label>
      </div>
      <div data-for="master reseller">
        <div class="grid2">
          <label class="fld"><span>Créditos iniciais ${isAdmin() ? '' : `(saem dos seus ${me().credits})`}</span><input type="number" name="credits" min="0" value="0"></label>
          <label class="fld"><span>Validade (dias, 0 = sem vencimento)</span><input type="number" name="days" min="0" value="0"></label>
          <label class="fld"><span>Máx. de clientes (0 = ilimitado)</span><input type="number" name="max_users" min="0" value="0"></label>
          <label class="fld" data-for="master"><span>Máx. de revendas (0 = ilimitado)</span><input type="number" name="max_resellers" min="0" value="0"></label>
        </div>
        ${isAdmin() || me().can_brand ? '<label class="switch"><input type="checkbox" name="can_brand" checked><i></i> Pode usar marca própria (white-label)</label>' : ''}
      </div>
      <label class="fld"><span>Observações</span><input name="notes" maxlength="500"></label>
      <div class="row end"><button type="button" class="btn ghost" data-close>Cancelar</button><button class="btn primary" type="submit">Criar conta</button></div>
      <button type="button" class="icon-btn modal-x" data-close>${icon('close')}</button></form>`, {
      wide: true,
      onSubmit: async (d, close) => {
        const r = await api('account_save', { body: d });
        close();
        const { accounts } = await api('accounts', { params: { q: d.username } });
        await refreshMe();
        showCredentials(accounts.find((a) => a.id === r.id) || { username: d.username, phone: d.phone, role: d.role }, d.password);
        if (location.hash.startsWith('#/admin')) Sonora.route();
      },
    });
    const f = $('form', m.el);
    const sync = () => {
      const role = f.role.value;
      $$('[data-for]', f).forEach((el) => (el.hidden = !el.dataset.for.split(' ').includes(role)));
      $('[data-plan-fld]', f).hidden = f.trial.checked;
    };
    $$('[data-role]', f).forEach((b) => b.addEventListener('click', () => { f.role.value = b.dataset.role; $$('[data-role]', f).forEach((x) => x.classList.toggle('active', x === b)); sync(); }));
    f.trial.addEventListener('change', sync);
    $('[data-gen]', f).onclick = () => (f.password.value = randPass());
    sync();
  }

  /** Cartão de acesso pronto para mandar ao cliente */
  function showCredentials(a, password) {
    const exp = a.expires_at ? dateFmt(a.expires_at) : null;
    const text = `🎧 *${S.me.brand.name}*\n\nSeu acesso está pronto!\n🌐 ${loginUrl()}\n👤 Usuário: ${a.username}\n${password ? `🔑 Senha: ${password}\n` : ''}${exp ? `📅 Válido até: ${exp}\n` : ''}\nDica: no celular, toque em “Adicionar à tela inicial” para usar como app. 🎶`;
    const phone = String(a.phone || '').replace(/\D/g, '');
    const m = modal(`<div class="form"><div class="pay-ok">${icon('check')}</div><h3 style="text-align:center">Acesso de ${esc(a.username)}</h3>
      <pre class="cred">${esc(text)}</pre>
      <div class="row end"><button class="btn" data-copy>${icon('copy')} Copiar</button>
      <a class="btn primary" target="_blank" rel="noopener" href="https://wa.me/${phone}?text=${encodeURIComponent(text)}">Enviar no WhatsApp</a></div>
      <button type="button" class="icon-btn modal-x" data-close>${icon('close')}</button></div>`);
    $('[data-copy]', m.el).onclick = async (e) => { try { await navigator.clipboard.writeText(text); e.target.closest('button').innerHTML = `${icon('check')} Copiado!`; } catch { toast('Selecione e copie o texto', 'err'); } };
  }

  async function openRenew(a, after) {
    const ps = (await plans()).filter((p) => p.active);
    const base = Math.max(Date.now() / 1000, a.expires_at || 0);
    const m = modal(`<form class="form"><h3>Renovar ${esc(a.username)}</h3>
      <p class="muted">Vencimento atual: <b>${a.expires_at ? dateFmt(a.expires_at) : 'sem vencimento'}</b>${a.expired ? ' (vencido)' : ''}</p>
      <div class="grid2"><label class="fld"><span>Plano</span><select name="plan_id">${ps.map((p) => `<option value="${p.id}" ${p.id === a.plan_id ? 'selected' : ''}>${esc(p.name)} — ${p.days} dias</option>`).join('')}</select></label>
      <label class="fld"><span>Quantidade</span><select name="periods">${[1, 2, 3, 6, 12].map((n) => `<option value="${n}">${n}x</option>`).join('')}</select></label></div>
      <div class="renew-sum" data-sum></div>
      <div class="row end"><button type="button" class="btn ghost" data-close>Cancelar</button><button class="btn primary" type="submit">Confirmar renovação</button></div>
      <button type="button" class="icon-btn modal-x" data-close>${icon('close')}</button></form>`, {
      onSubmit: async (d, close) => {
        await api('account_renew', { body: { id: a.id, plan_id: d.plan_id, periods: d.periods } });
        close(); toast(`✔ ${a.username} renovado`, 'ok'); await refreshMe(); after?.();
      },
    });
    const f = $('form', m.el);
    const sum = () => {
      const p = ps.find((x) => x.id === +f.plan_id.value), n = +f.periods.value;
      const cost = isAdmin() ? 0 : p.credits * n;
      $('[data-sum]', f).innerHTML = `Novo vencimento: <b>${dateFmt(base + p.days * n * 86400)}</b>${isAdmin() ? '' : ` · custo: <b>${cost} crédito${cost !== 1 ? 's' : ''}</b> (você tem ${me().credits})`}`;
      $('button[type=submit]', f).disabled = !isAdmin() && cost > me().credits;
    };
    f.addEventListener('change', sum); sum();
  }

  function openCredits(a) {
    modal(`<form class="form"><h3>Créditos de ${esc(a.username)}</h3>
      <p class="muted">Saldo dele: <b>${a.credits}</b>${isAdmin() ? '' : ` · seu saldo: <b>${me().credits}</b>`}</p>
      <div class="seg" data-dir><button type="button" class="active" data-k="1">➕ Enviar</button><button type="button" data-k="-1">➖ Recolher</button></div>
      <input type="hidden" name="dir" value="1">
      <label class="fld"><span>Quantidade</span><input type="number" name="amount" min="1" value="10" required autofocus></label>
      <div class="row end"><button type="button" class="btn ghost" data-close>Cancelar</button><button class="btn primary" type="submit">Confirmar</button></div>
      <button type="button" class="icon-btn modal-x" data-close>${icon('close')}</button></form>`, {
      onSubmit: async (d, close) => {
        await api('account_credits', { body: { id: a.id, amount: +d.amount * +d.dir } });
        close(); toast('Créditos atualizados', 'ok'); await refreshMe(); load();
      },
    });
    $$('[data-dir] button').forEach((b) => b.addEventListener('click', () => { $('input[name=dir]').value = b.dataset.k; $$('[data-dir] button').forEach((x) => x.classList.toggle('active', x === b)); }));
  }

  function openEdit(a) {
    const iso = a.expires_at ? new Date(a.expires_at * 1000).toISOString().slice(0, 10) : '';
    modal(`<form class="form"><h3>${ROLE_ICON[a.role]} ${esc(a.username)}</h3>
      <div class="grid2">
        <label class="fld"><span>Nome</span><input name="name" value="${esc(a.name)}"></label>
        <label class="fld"><span>WhatsApp</span><input name="phone" value="${esc(a.phone)}"></label>
        <label class="fld"><span>E-mail</span><input type="email" name="email" value="${esc(a.email)}"></label>
        <label class="fld"><span>Nova senha (opcional)</span><input name="password" minlength="6" placeholder="manter a atual"></label>
        ${isAdmin() ? `<label class="fld"><span>Vencimento</span><input type="date" name="expires_at" value="${iso}"></label>` : ''}
        <label class="fld"><span>Situação</span><select name="status"><option value="active">Ativo</option><option value="blocked" ${a.status === 'blocked' ? 'selected' : ''}>Bloqueado</option></select></label>
      </div>
      <h4>Limites de uso</h4>
      <div class="grid2">
        <label class="fld"><span>Downloads por dia (0 = ilimitado)</span><input type="number" min="0" name="dl_per_day" value="${a.dl_per_day}"></label>
        <label class="fld"><span>Máx. músicas na biblioteca (0 = ilimitado)</span><input type="number" min="0" name="max_tracks" value="${a.max_tracks}"></label>
      </div>
      <div class="row"><label class="switch"><input type="checkbox" name="allow_video" ${a.allow_video ? 'checked' : ''}><i></i> Vídeos</label>
      <label class="switch"><input type="checkbox" name="allow_offline" ${a.allow_offline ? 'checked' : ''}><i></i> Ouvir offline</label></div>
      ${a.role !== 'user' ? `<h4>Revenda</h4><div class="grid2">
        <label class="fld"><span>Máx. de clientes (0 = ilimitado)</span><input type="number" min="0" name="max_users" value="${a.max_users}"></label>
        ${a.role === 'master' ? `<label class="fld"><span>Máx. de revendas</span><input type="number" min="0" name="max_resellers" value="${a.max_resellers}"></label>` : ''}</div>
        ${isAdmin() || me().can_brand ? `<label class="switch"><input type="checkbox" name="can_brand" ${a.can_brand ? 'checked' : ''}><i></i> Marca própria (white-label)</label>` : ''}` : ''}
      <label class="fld"><span>Observações</span><input name="notes" value="${esc(a.notes)}"></label>
      <p class="muted small">Criado em ${dateFmt(a.created_at)} · último acesso ${a.last_login ? dateFmt(a.last_login) : 'nunca'}</p>
      <div class="row end"><button type="button" class="btn ghost" data-close>Cancelar</button><button class="btn primary" type="submit">Salvar</button></div>
      <button type="button" class="icon-btn modal-x" data-close>${icon('close')}</button></form>`, {
      wide: true,
      onSubmit: async (d, close) => {
        if (!d.password) delete d.password;
        await api('account_save', { body: { ...d, id: a.id } });
        close(); toast('Conta atualizada', 'ok'); load();
      },
    });
  }

  /* ======================= Planos (admin) ======================= */
  async function vPlans() {
    plansCache = null;
    const ps = await plans();
    view.innerHTML = header('Planos', 'O que seus clientes compram. O custo em créditos vale para as revendas.', `<button class="btn primary" data-new>${icon('plus')} Novo plano</button>`) + `
      <div class="plans">${ps.map((p) => `<div class="plan${p.highlight ? ' hot' : ''}${p.active ? '' : ' off'}">${p.highlight ? '<span class="plan-badge">Destaque</span>' : ''}
        <h3>${esc(p.name)} ${p.active ? '' : '<small class="muted">(inativo)</small>'}</h3><div class="price">${money(p.price)}</div>
        <p class="muted small">${p.days} dias · ${p.credits} crédito${p.credits !== 1 ? 's' : ''} para revendas</p>
        <ul><li>${p.dl_per_day || '∞'} downloads/dia</li><li>${p.max_tracks || '∞'} músicas</li><li class="${p.allow_video ? '' : 'no'}">Vídeos</li><li class="${p.allow_offline ? '' : 'no'}">Offline</li></ul>
        <div class="row"><button class="btn sm" data-edit="${p.id}">Editar</button><button class="btn sm ghost" data-del="${p.id}">${icon('trash')}</button></div></div>`).join('')}</div>`;
    $('[data-new]', view).onclick = () => planForm({ days: 30, price: 19.9, credits: 1, dl_per_day: 50, max_tracks: 2000, allow_video: true, allow_offline: true, active: true });
    $$('[data-edit]', view).forEach((b) => (b.onclick = () => planForm(ps.find((p) => p.id === +b.dataset.edit))));
    $$('[data-del]', view).forEach((b) => (b.onclick = async () => { if (!confirm('Excluir plano? Se estiver em uso, ele só será desativado.')) return; await api('plan_delete', { body: { id: +b.dataset.del } }); vPlans(); }));
  }
  function planForm(p) {
    modal(`<form class="form"><h3>${p.id ? 'Editar plano' : 'Novo plano'}</h3><input type="hidden" name="id" value="${p.id || ''}">
      <div class="grid2">
        <label class="fld"><span>Nome</span><input name="name" required value="${esc(p.name || '')}" autofocus></label>
        <label class="fld"><span>Duração (dias)</span><input type="number" name="days" min="1" value="${p.days}"></label>
        <label class="fld"><span>Preço ao cliente (R$)</span><input type="number" name="price" min="0" step="0.01" value="${p.base_price ?? p.price}"></label>
        <label class="fld"><span>Custo para revendas (créditos)</span><input type="number" name="credits" min="0" value="${p.credits}"></label>
        <label class="fld"><span>Downloads por dia (0 = ∞)</span><input type="number" name="dl_per_day" min="0" value="${p.dl_per_day}"></label>
        <label class="fld"><span>Máx. músicas (0 = ∞)</span><input type="number" name="max_tracks" min="0" value="${p.max_tracks}"></label>
        <label class="fld"><span>Ordem</span><input type="number" name="sort" value="${p.sort || 0}"></label>
      </div>
      <div class="row"><label class="switch"><input type="checkbox" name="allow_video" ${p.allow_video ? 'checked' : ''}><i></i> Vídeos</label>
      <label class="switch"><input type="checkbox" name="allow_offline" ${p.allow_offline ? 'checked' : ''}><i></i> Offline</label>
      <label class="switch"><input type="checkbox" name="highlight" ${p.highlight ? 'checked' : ''}><i></i> Destaque</label>
      <label class="switch"><input type="checkbox" name="active" ${p.active ? 'checked' : ''}><i></i> Ativo</label></div>
      <div class="row end"><button type="button" class="btn ghost" data-close>Cancelar</button><button class="btn primary" type="submit">Salvar</button></div>
      <button type="button" class="icon-btn modal-x" data-close>${icon('close')}</button></form>`, {
      wide: true,
      onSubmit: async (d, close) => { await api('plan_save', { body: d }); close(); toast('Plano salvo', 'ok'); vPlans(); },
    });
  }

  /* ======================= Pagamentos ======================= */
  async function vPayments() {
    view.innerHTML = header('Pagamentos', 'Cobranças via Mercado Pago (Pix, cartão e boleto)') + '<div class="skeleton"></div>';
    const { payments } = await api('payments');
    const label = { pending: 'Aguardando', approved: 'Aprovado', failed: 'Recusado', expired: 'Expirado' };
    const appr = payments.filter((p) => p.status === 'approved' && (isAdmin() ? p.receiver_id === 0 : p.receiver_id === me().id));
    view.innerHTML = header('Pagamentos', 'Cobranças via Mercado Pago (Pix, cartão e boleto)') + `
      <div class="stats"><div class="stat ok"><span>Recebido por você</span><b>${money(appr.reduce((s, p) => s + p.amount, 0))}</b><span>${appr.length} pagamentos aprovados</span></div>
      <div class="stat"><span>Aguardando</span><b>${payments.filter((p) => p.status === 'pending').length}</b><span>Pix gerados ainda não pagos</span></div></div>
      ${payments.length ? `<div class="table">${payments.map((p) => `<div class="tr">
        <span>#${p.id}</span><span><b>${esc(p.username || '?')}</b></span><span>${p.kind === 'credits' ? `💰 ${p.qty} créditos` : `🎧 ${esc(p.plan_name || 'Plano')}`}</span>
        <span>${money(p.amount)}</span><span class="muted">${p.method === 'pix' ? 'Pix' : 'Checkout'}${p.receiver_id && p.receiver_id !== me().id ? ' · revenda' : ''}</span>
        <span class="pill-s ${p.status}" title="${esc(p.note || p.mp_status)}">${label[p.status] || p.status}</span><span class="muted">${dateFmt(p.created_at)}</span></div>`).join('')}</div>`
        : `<div class="empty">${icon('card')}<h3>Nenhum pagamento ainda</h3><p>Configure o Mercado Pago em “Marca e config.” e seus clientes poderão renovar sozinhos.</p></div>`}`;
  }

  /* ======================= Marca e configurações ======================= */
  async function vSettings() {
    const d = await api('settings');
    const ps = await plans();
    const g = d.global, mine = d.mine;
    const brandFields = (b, prefix) => `
      <div class="grid2">
        <label class="fld"><span>Nome do app</span><input name="${prefix}name" value="${esc(b.name || '')}" maxlength="40" data-prev="name"></label>
        <label class="fld"><span>Slogan</span><input name="${prefix}tagline" value="${esc(b.tagline || '')}" maxlength="80" data-prev="tagline"></label>
        <label class="fld"><span>Cor principal</span><input type="color" name="${prefix}color" value="${esc(b.color || '#8b5cf6')}" data-prev="color"></label>
        <label class="fld"><span>Cor secundária</span><input type="color" name="${prefix}color2" value="${esc(b.color2 || '#22d3ee')}" data-prev="color2"></label>
      </div>
      <label class="fld"><span>Link de suporte (WhatsApp, Telegram…)</span><input name="${prefix}support_url" value="${esc(b.support_url || '')}" placeholder="https://wa.me/5511999999999"></label>`;
    const preview = (b, logo) => `<div class="brand-prev" data-preview style="--pc:${esc(b.color || '#8b5cf6')};--pc2:${esc(b.color2 || '#22d3ee')}">
        <img src="${esc(logo || 'assets/icon.svg')}" alt=""><div><b data-pv="name">${esc(b.name || 'Seu app')}</b><small data-pv="tagline">${esc(b.tagline || '')}</small></div><span class="btn sm">Entrar</span></div>`;
    const logoBox = (target, url) => `<div class="row"><label class="btn sm">${icon('plus')} Enviar logo<input type="file" accept="image/png,image/jpeg,image/webp" hidden data-logo="${target}"></label>
        ${url ? `<button type="button" class="btn sm ghost" data-logo-rm="${target}">Remover logo</button>` : ''}<span class="muted small">PNG/JPG/WEBP quadrado, até 1 MB</span></div>`;
    const webhook = `<label class="fld"><span>URL de notificação (webhook) — já é enviada automaticamente em cada cobrança</span><div class="in-btn"><input readonly value="${esc(d.webhook)}"><button type="button" class="icon-btn" data-copy-wh>${icon('copy')}</button></div></label>`;

    view.innerHTML = header('Marca e configurações', isAdmin() ? 'Personalize o app, receba pagamentos e defina as regras' : 'Deixe o app com a sua cara e receba direto na sua conta') + (isAdmin() ? `
      <form class="card-form" id="f-brand"><h3>🎨 Marca</h3>
        ${preview({ name: g.brand_name, tagline: g.brand_tagline, color: g.brand_color, color2: g.brand_color2 }, g.logo_url)}
        ${brandFields({ name: g.brand_name, tagline: g.brand_tagline, color: g.brand_color, color2: g.brand_color2, support_url: g.support_url }, 'brand_')}
        ${logoBox('global', g.logo_url)}
        <div class="row end"><button class="btn primary">Salvar marca</button></div></form>
      <form class="card-form" id="f-mp"><h3>💳 Mercado Pago</h3>
        <p class="muted small">Pegue as credenciais em <a href="https://www.mercadopago.com.br/developers/panel/app" target="_blank" rel="noopener">mercadopago.com.br/developers</a> › Suas integrações › Credenciais de produção.</p>
        <div class="grid2"><label class="fld"><span>Access Token</span><input name="mp_access_token" value="${esc(g.mp_access_token)}" placeholder="APP_USR-..." autocomplete="off"></label>
        <label class="fld"><span>Public Key (opcional)</span><input name="mp_public_key" value="${esc(g.mp_public_key)}"></label></div>
        ${webhook}
        <label class="switch"><input type="checkbox" name="reseller_mp" ${g.reseller_mp === '1' ? 'checked' : ''}><i></i> Revendas podem receber no Mercado Pago delas (os clientes delas pagam direto pra elas e consomem os créditos delas)</label>
        <div class="row end"><button type="button" class="btn" data-mp-test="global">Testar conexão</button><button class="btn primary">Salvar</button></div></form>
      <form class="card-form" id="f-rules"><h3>📋 Vendas, créditos e cadastro</h3>
        <label class="fld"><span>Pacotes de créditos para revendas (um por linha: quantidade=preço)</span><textarea name="credit_packages" rows="4">${esc(g.credit_packages)}</textarea></label>
        <label class="switch"><input type="checkbox" name="signup_enabled" ${g.signup_enabled === '1' ? 'checked' : ''}><i></i> Permitir cadastro pelo site (com teste grátis) — também vale para os links de convite das revendas</label>
        <div class="grid2"><label class="fld"><span>Dias de teste grátis</span><input type="number" min="1" name="trial_days" value="${esc(g.trial_days)}"></label>
        <label class="fld"><span>Testes grátis por revenda por dia</span><input type="number" min="0" name="trials_per_day" value="${esc(g.trials_per_day)}"></label>
        <label class="fld"><span>Limites do teste grátis iguais ao plano</span><select name="default_plan_id">${ps.map((p) => `<option value="${p.id}" ${String(p.id) === g.default_plan_id ? 'selected' : ''}>${esc(p.name)}</option>`).join('')}</select></label>
        <label class="fld"><span>URL pública do site (opcional)</span><input name="public_url" value="${esc(g.public_url)}" placeholder="https://musica.seusite.com"></label></div>
        <h4>🎁 Indique e ganhe</h4>
        <label class="switch"><input type="checkbox" name="referral_enabled" ${g.referral_enabled === '1' ? 'checked' : ''}><i></i> Ativar programa de indicação (cada cliente ganha um link pessoal)</label>
        <div class="grid2"><label class="fld"><span>Desconto do indicado na 1ª assinatura (%)</span><input type="number" min="0" max="100" name="referral_new_pct" value="${esc(g.referral_new_pct)}"></label>
        <label class="fld"><span>Desconto para quem indicou, por indicado que assinar (%)</span><input type="number" min="0" max="100" name="referral_reward_pct" value="${esc(g.referral_reward_pct)}"></label>
        <label class="fld"><span>Desconto máximo acumulado (%) — 100 = pode ganhar mês grátis</span><input type="number" min="0" max="100" name="referral_max_pct" value="${esc(g.referral_max_pct)}"></label></div>
        <div class="row end"><button class="btn primary">Salvar</button></div></form>
      <form class="card-form" id="f-dl"><h3>⬇️ Download do YouTube</h3>
        <p class="muted small">O YouTube costuma bloquear IPs de hospedagem ("confirme que não é um robô"). Quando isso acontece, o sistema baixa automaticamente por servidores alternativos open source (Invidious, Piped, Cobalt). Use <a href="install.php">Testar YouTube</a> para ver o que está funcionando no seu servidor.</p>
        <label class="switch"><input type="checkbox" name="mirrors_enabled" ${g.mirrors_enabled === '1' ? 'checked' : ''}><i></i> Usar servidores alternativos quando o YouTube bloquear</label>
        <div class="grid2">
          <label class="fld"><span>Instâncias Invidious extras (uma por linha, opcional)</span><textarea name="mirror_invidious" rows="3" placeholder="https://invidious.exemplo.com">${esc(g.mirror_invidious)}</textarea></label>
          <label class="fld"><span>Instâncias Piped extras (API, opcional)</span><textarea name="mirror_piped" rows="3" placeholder="https://pipedapi.exemplo.com">${esc(g.mirror_piped)}</textarea></label>
          <label class="fld"><span>Servidor Cobalt (opcional)</span><input name="cobalt_url" value="${esc(g.cobalt_url)}" placeholder="https://cobalt.seudominio.com"></label>
          <label class="fld"><span>Chave da API Cobalt (se exigir)</span><input name="cobalt_key" value="${esc(g.cobalt_key)}" autocomplete="off"></label>
        </div>
        <label class="fld"><span>Proxy para o yt-dlp (opcional — ex.: proxy residencial)</span><input name="yt_proxy" value="${esc(g.yt_proxy)}" placeholder="http://usuario:senha@ip:porta  ou  socks5://ip:porta"></label>
        <div class="row end"><a class="btn" href="install.php">Testar YouTube</a><button class="btn primary">Salvar</button></div></form>
      <div class="card-form"><h3>🛠️ Servidor</h3><p class="muted">Instalar/atualizar yt-dlp, Deno e ffmpeg, e ver a saúde do servidor.</p><div class="row"><a class="btn" href="install.php">Abrir ferramentas</a></div></div>` : `
      ${mine.can_brand ? `<form class="card-form" id="f-mybrand"><h3>🎨 Minha marca (white-label)</h3>
        <p class="muted small">Seus clientes (e suas revendas) verão o app com o seu nome, logo e cores. Mande para eles o seu link: <b>${esc(loginUrl())}</b></p>
        ${preview(mine.brand, mine.brand.logo_url)}
        ${brandFields(mine.brand, 'b_')}
        ${logoBox('mine', mine.brand.logo_url)}
        <div class="row end"><button class="btn primary">Salvar marca</button></div></form>` : '<div class="banner info"><span>Marca própria não liberada para sua conta. Fale com quem te vendeu o acesso.</span></div>'}
      <form class="card-form" id="f-mymp"><h3>💳 Receber dos meus clientes</h3>
        ${mine.reseller_mp ? `<p class="muted small">Coloque o Access Token do <b>seu</b> Mercado Pago: quando seu cliente renovar pelo app, o dinheiro cai na sua conta e o sistema desconta os créditos do plano do seu saldo automaticamente. Sem token, os pagamentos vão para o administrador.</p>
        <label class="fld"><span>Access Token</span><input name="mp_token" value="${esc(mine.mp_token)}" placeholder="APP_USR-..." autocomplete="off"></label>${webhook}
        <h4>Meus preços</h4><div class="grid2">${ps.map((p) => `<label class="fld"><span>${esc(p.name)} (${p.days} dias)</span><input type="number" step="0.01" min="0" name="price_${p.id}" value="${esc(mine.prices[p.id] ?? '')}" placeholder="padrão ${money(p.price)}"></label>`).join('')}</div>` : '<p class="muted">O administrador não liberou recebimento direto para revendas.</p>'}
        <label class="switch"><input type="checkbox" name="signup" ${mine.signup ? 'checked' : ''}><i></i> Permitir cadastro com teste grátis pelo meu link</label>
        <div class="row end">${mine.reseller_mp ? '<button type="button" class="btn" data-mp-test="mine">Testar conexão</button>' : ''}<button class="btn primary">Salvar</button></div></form>`);

    // pré-visualização ao vivo da marca
    $$('form', view).forEach((f) => f.addEventListener('input', (e) => {
      const pv = $('[data-preview]', f); const k = e.target.dataset.prev; if (!pv || !k) return;
      if (k === 'color') pv.style.setProperty('--pc', e.target.value);
      else if (k === 'color2') pv.style.setProperty('--pc2', e.target.value);
      else $(`[data-pv=${k}]`, pv).textContent = e.target.value;
    }));
    const save = async (body, msg = 'Salvo!') => { await api(isAdmin() ? 'settings_save' : 'my_settings_save', { body }); toast(msg, 'ok'); await refreshMe(); };
    const onSubmit = (id, fn) => $(id)?.addEventListener('submit', async (e) => { e.preventDefault(); try { await fn(Sonora.formToObj(e.target)); } catch (ex) { toast(ex.message, 'err'); } });
    onSubmit('#f-brand', (o) => { o.support_url = o.brand_support_url; delete o.brand_support_url; return save({ global: o }, 'Marca atualizada 🎨'); });
    onSubmit('#f-mp', (o) => save({ global: { ...o, reseller_mp: o.reseller_mp ? '1' : '0' } }));
    onSubmit('#f-dl', (o) => save({ global: { ...o, mirrors_enabled: o.mirrors_enabled ? '1' : '0' } }));
    onSubmit('#f-rules', (o) => save({ global: { ...o, signup_enabled: o.signup_enabled ? '1' : '0', referral_enabled: o.referral_enabled ? '1' : '0' } }));
    onSubmit('#f-mybrand', (o) => save({ mine: { brand: { name: o.b_name, tagline: o.b_tagline, color: o.b_color, color2: o.b_color2, support_url: o.b_support_url } } }, 'Marca atualizada 🎨'));
    onSubmit('#f-mymp', (o) => {
      const prices = {}; Object.keys(o).filter((k) => k.startsWith('price_')).forEach((k) => (prices[k.slice(6)] = o[k]));
      return save({ mine: { mp_token: o.mp_token, prices, signup: !!o.signup } });
    });
    $$('[data-mp-test]', view).forEach((b) => (b.onclick = async () => {
      const inp = b.closest('form').querySelector('input[name=mp_access_token], input[name=mp_token]');
      b.disabled = true;
      try { const r = await api('mp_test', { body: { token: inp?.value || '', target: b.dataset.mpTest } }); toast(`✔ Conectado: ${r.account.nickname || r.account.email}`, 'ok'); } catch (e) { toast(e.message, 'err'); }
      b.disabled = false;
    }));
    $$('[data-copy-wh]', view).forEach((b) => (b.onclick = async () => { try { await navigator.clipboard.writeText(d.webhook); toast('Copiado', 'ok'); } catch { /* ignore */ } }));
    $$('[data-logo]', view).forEach((inp) => inp.addEventListener('change', async () => {
      const fd = new FormData(); fd.append('logo', inp.files[0]); fd.append('target', inp.dataset.logo);
      const r = await fetch('api.php?action=brand_upload', { method: 'POST', body: fd, headers: { 'X-CSRF': S.me.csrf }, credentials: 'same-origin' });
      const j = await r.json().catch(() => ({}));
      if (!r.ok) return toast(j.error || 'Falha no envio', 'err');
      toast('Logo atualizado', 'ok'); await refreshMe(); vSettings();
    }));
    $$('[data-logo-rm]', view).forEach((b) => (b.onclick = async () => { await api('brand_logo_remove', { body: { target: b.dataset.logoRm } }); await refreshMe(); vSettings(); }));
  }

  /* ======================= Atividades ======================= */
  const ACT = {
    'account.create': ['➕', 'criou'], 'account.update': ['✏️', 'editou'], 'account.renew': ['🔄', 'renovou'], 'account.delete': ['🗑️', 'excluiu'],
    'account.impersonate': ['👤', 'entrou como'], 'credits.transfer': ['💰', 'créditos'], 'payment.create': ['🧾', 'gerou cobrança'],
    'payment.approved': ['✅', 'pagamento aprovado'], 'referral.signup': ['🎁', 'indicou'], 'referral.reward': ['🏆', 'ganhou desconto por indicação'], 'settings.save': ['⚙️', 'alterou configurações'], 'settings.mine': ['🎨', 'alterou a própria marca'], 'track.delete': ['🎵', 'excluiu faixa'],
  };
  async function vLogs() {
    const { logs } = await api('logs');
    view.innerHTML = header('Atividades', 'Tudo o que acontece na sua rede, com data e autor') + (logs.length ? `<div class="timeline">${logs.map((l) => {
      const [ic, verb] = ACT[l.action] || ['•', l.action];
      return `<div class="tl"><span class="tl-ic">${ic}</span><div><b>${esc(l.actor || 'sistema')}</b> ${verb} ${l.target && l.target !== l.actor ? `<b>${esc(l.target)}</b>` : ''}
        <small>${esc(l.info)}</small></div><span class="muted small">${new Date(l.created_at * 1000).toLocaleString('pt-BR')}${l.ip ? ' · ' + esc(l.ip) : ''}</span></div>`;
    }).join('')}</div>` : '<div class="empty"><h3>Nada ainda</h3></div>');
  }

  /* ======================= Roteamento do painel ======================= */
  Sonora.views.admin = (sub) => {
    const fn = { accounts: vAccounts, plans: isAdmin() ? vPlans : null, payments: vPayments, settings: vSettings, logs: vLogs }[sub] || vDashboard;
    fn().catch?.((e) => toast(e.message, 'err'));
  };
})();

'use strict';
(() => {
  const form = document.getElementById('setup-form');
  const error = document.getElementById('form-error');
  const back = document.getElementById('back');
  const next = document.getElementById('next');
  const install = document.getElementById('install');
  let step = 1;
  let csrf = '';
  let managedAvailable = false;
  const field = name => form.elements.namedItem(name);

  function database() { return field('database').value; }
  function mysqlMode() { return field('mysql_mode').value; }
  function showStep(value) {
    step = value;
    document.querySelectorAll('[data-step]').forEach(item => item.classList.toggle('hidden', Number(item.dataset.step) !== step));
    document.querySelectorAll('[data-step-indicator]').forEach(item => {
      const number = Number(item.dataset.stepIndicator);
      item.classList.toggle('active', number === step);
      item.classList.toggle('done', number < step);
    });
    back.classList.toggle('hidden', step === 1 || step === 4);
    next.classList.toggle('hidden', step >= 3);
    install.classList.toggle('hidden', step !== 3);
    document.getElementById('actions').classList.toggle('hidden', step === 4);
    error.textContent = '';
  }
  function updateDatabaseFields() {
    const mysql = database() === 'mysql';
    document.getElementById('mysql-options').classList.toggle('hidden', !mysql);
    const managed = mysqlMode() === 'managed';
    document.getElementById('managed-fields').classList.toggle('hidden', !managed);
    document.getElementById('existing-fields').classList.toggle('hidden', managed);
    const managedInput = form.querySelector('input[name="mysql_mode"][value="managed"]');
    managedInput.disabled = !managedAvailable;
    if (!managedAvailable && managedInput.checked) form.querySelector('input[name="mysql_mode"][value="existing"]').checked = true;
    document.getElementById('managed-note').textContent = managedAvailable ? '自动创建 MySQL 8.4 容器、私有数据卷和随机数据库密码。' : '当前启动方式未启用自动创建组件，请连接已有 MySQL。';
  }
  function validateCurrent() {
    if (step === 1 && database() === 'mysql' && mysqlMode() === 'existing') {
      for (const name of ['mysql_host', 'mysql_database', 'mysql_user', 'mysql_password']) if (!String(field(name).value).trim()) throw Error('请完整填写 MySQL 连接信息');
    }
    if (step === 2) {
      const path = String(field('admin_path').value).replace(/^\/+/, '');
      if (!/^[A-Za-z0-9._~-]+(?:\/[A-Za-z0-9._~-]+)*$/.test(path)) throw Error('后台路径格式错误');
      if (!String(field('username').value).trim()) throw Error('请填写管理员用户名');
      if (!field('password').value) throw Error('请填写管理员密码');
      if (field('password').value !== field('password_confirm').value) throw Error('两次输入的管理员密码不一致');
    }
  }
  function payload() {
    const value = {
      database: database(), admin_path: '/' + String(field('admin_path').value).replace(/^\/+/, ''),
      username: String(field('username').value).trim(), password: field('password').value,
      password_confirm: field('password_confirm').value,
    };
    if (value.database === 'mysql') {
      value.mysql_mode = mysqlMode();
      if (value.mysql_mode === 'managed') value.project_name = String(field('project_name').value).trim();
      else Object.assign(value, { mysql_host: String(field('mysql_host').value).trim(), mysql_port: Number(field('mysql_port').value), mysql_database: String(field('mysql_database').value).trim(), mysql_user: String(field('mysql_user').value).trim(), mysql_password: field('mysql_password').value });
    }
    return value;
  }
  function renderSummary() {
    const value = payload();
    const rows = [
      ['数据库', value.database === 'sqlite' ? 'SQLite' : value.mysql_mode === 'managed' ? 'MySQL 8.4（自动创建）' : 'MySQL（已有实例）'],
      ['后台路径', value.admin_path], ['管理员', value.username],
    ];
    if (value.database === 'mysql' && value.mysql_mode === 'existing') rows.push(['MySQL 地址', value.mysql_host + ':' + value.mysql_port], ['数据库名称', value.mysql_database]);
    const summary = document.getElementById('summary'); summary.replaceChildren();
    rows.forEach(([name, content]) => { const row = document.createElement('div'); const dt = document.createElement('dt'); const dd = document.createElement('dd'); dt.textContent = name; dd.textContent = content; row.append(dt, dd); summary.append(row); });
  }
  async function initialize() {
    if (!document.getElementById('confirm-install').checked) throw Error('请确认开始初始化');
    showStep(4);
    const response = await fetch('/setup/api/install', { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify(payload()) });
    const data = await response.json().catch(() => ({ error: '初始化响应无效' }));
    if (!response.ok) throw Error(data.error || '初始化失败');
    const link = document.getElementById('console-link'); link.href = data.admin_path;
    document.getElementById('installing').classList.add('hidden'); document.getElementById('complete').classList.remove('hidden');
    window.setTimeout(() => { window.location.assign(data.admin_path); }, 1200);
  }
  form.addEventListener('change', event => { if (event.target.name === 'database' || event.target.name === 'mysql_mode') updateDatabaseFields(); });
  next.addEventListener('click', () => { try { validateCurrent(); if (step === 2) renderSummary(); showStep(step + 1); } catch (reason) { error.textContent = reason.message; } });
  back.addEventListener('click', () => showStep(Math.max(1, step - 1)));
  form.addEventListener('submit', async event => { event.preventDefault(); error.textContent = ''; try { await initialize(); } catch (reason) { showStep(3); error.textContent = reason.message; } });
  fetch('/setup/api/status', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(response => response.json()).then(data => { csrf = data.csrf || ''; managedAvailable = Boolean(data.managed_mysql_available); const defaults = data.defaults || {}; Object.entries(defaults).forEach(([name, value]) => { const input = field(name === 'admin_path' ? 'admin_path' : name); if (input && name !== 'database') input.value = name === 'admin_path' ? String(value).replace(/^\/+/, '') : value; }); updateDatabaseFields(); }).catch(() => { error.textContent = '无法读取初始化状态'; });
  showStep(1);
})();

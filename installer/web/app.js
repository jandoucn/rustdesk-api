(() => {
  'use strict';
  const form = document.getElementById('command-form');
  const result = document.getElementById('result');
  const command = document.getElementById('command');
  const error = document.getElementById('form-error');
  const warning = document.getElementById('history-warning');
  const scriptInput = form.elements.script_url;
  const installerSha256 = '519b2181c5088b3c732d1acc75859cef141153d2c027a6d6628e7dda4be31bae';
  scriptInput.value = 'https://ghfast.top/https://raw.githubusercontent.com/jandoucn/rustdesk-api/main/installer/install.sh';

  function shellQuote(value) {
    return `'${String(value).replaceAll("'", `'"'"'`)}'`;
  }

  form.addEventListener('submit', event => {
    event.preventDefault();
    error.textContent = '';
    const username = form.elements.username.value.trim();
    const token = form.elements.token.value.trim();
    const scriptUrl = scriptInput.value.trim();
    const port = Number(form.elements.port.value);
    const mode = form.elements.mode.value;
    if (!/^[A-Za-z0-9-]{1,39}$/.test(username)) return void (error.textContent = 'GitHub 用户名格式错误');
    if (!token) return void (error.textContent = '请输入 classic PAT');
    try { const url = new URL(scriptUrl); if (url.protocol !== 'https:') throw Error(); } catch (_) { return void (error.textContent = 'install.sh 必须使用有效的 HTTPS 地址'); }
    if (!Number.isInteger(port) || port < 1 || port > 65535) return void (error.textContent = '外部端口必须是 1-65535 的整数');

    const download = `f=$(mktemp) && curl -fsSL ${shellQuote(scriptUrl)} -o "$f" && echo ${shellQuote(`${installerSha256}  $f`)} | sha256sum -c -`;
    const execute = mode === 'embedded'
      ? `sudo env GHCR_USERNAME=${shellQuote(username)} GHCR_TOKEN=${shellQuote(token)} RUSTDESK_PORT=${port} bash "$f"`
      : `sudo env GHCR_USERNAME=${shellQuote(username)} RUSTDESK_PORT=${port} bash "$f"`;
    command.textContent = `${download} && ${execute}; rc=$?; unlink "$f"; exit $rc`;
    warning.hidden = mode !== 'embedded';
    result.hidden = false;
    result.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });

  document.getElementById('copy').addEventListener('click', async event => {
    await navigator.clipboard.writeText(command.textContent);
    event.currentTarget.textContent = '已复制';
    window.setTimeout(() => { event.currentTarget.textContent = '复制'; }, 1200);
  });
})();

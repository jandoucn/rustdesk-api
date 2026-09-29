(() => {
  'use strict';
  const form = document.getElementById('command-form');
  const result = document.getElementById('result');
  const command = document.getElementById('command');
  const error = document.getElementById('form-error');
  const warning = document.getElementById('history-warning');
  const scriptInput = form.elements.script_url;
  const installerSha256 = 'c0ebd62ca5025dff90f1cf86f3a50c03b1678306d1232d53367be585421d64a2';
  scriptInput.value = 'https://shell.olii.cc/rustdesk-install.sh';

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
    if (!/^\S{1,128}$/.test(username)) return void (error.textContent = 'ACR 用户名格式错误');
    if (!token) return void (error.textContent = '请输入 ACR 固定密码');
    try { const url = new URL(scriptUrl); if (url.protocol !== 'https:') throw Error(); } catch (_) { return void (error.textContent = 'install.sh 必须使用有效的 HTTPS 地址'); }
    if (!Number.isInteger(port) || port < 1 || port > 65535) return void (error.textContent = '外部端口必须是 1-65535 的整数');

    const download = `f=$(mktemp) && curl -fsSL ${shellQuote(scriptUrl)} -o "$f" && echo ${shellQuote(`${installerSha256}  $f`)} | sha256sum -c -`;
    const execute = mode === 'embedded'
      ? `sudo env REGISTRY_USERNAME=${shellQuote(username)} REGISTRY_PASSWORD=${shellQuote(token)} RUSTDESK_PORT=${port} bash "$f"`
      : `sudo env REGISTRY_USERNAME=${shellQuote(username)} RUSTDESK_PORT=${port} bash "$f"`;
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

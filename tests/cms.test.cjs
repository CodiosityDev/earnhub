const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const http = require('node:http');
const vm = require('node:vm');
const { spawn, execFileSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const tempRoot = path.resolve(root, '..', '.tmp');

test('CMS login, publishing, validation, conflicts, and password rotation', async t => {
  fs.mkdirSync(tempRoot, { recursive: true });
  const privateDirectory = fs.mkdtempSync(path.join(tempRoot, 'cms-test-'));
  const env = { ...process.env, SPAWNVAULT_CMS_DIR: privateDirectory };
  const password = ' test-password-with-spaces ';
  const setup = ['scripts/setup-cms.php', '--username=test_admin', '--password-stdin'];
  execFileSync('php', setup, { cwd: root, env, input: password + '\n' });
  const port = await new Promise(resolve => {
    const probe = net.createServer();
    probe.listen(0, '127.0.0.1', () => {
      const port = probe.address().port;
      probe.close(() => resolve(port));
    });
  });
  const server = spawn('php', ['-d', 'display_errors=0', '-S', `127.0.0.1:${port}`, '-t', root], { cwd: root, env, stdio: ['ignore', 'ignore', 'pipe'] });
  let logs = '';
  server.stderr.on('data', data => { logs += data; });
  const base = `http://127.0.0.1:${port}`;
  let cookie = '';
  async function request(url, fields, sendCookie = true) {
    const headers = { Connection: 'close' };
    if (sendCookie && cookie) headers.Cookie = cookie;
    let body = '';
    if (fields) {
      const params = new URLSearchParams();
      for (const [key, value] of Object.entries(fields)) {
        if (Array.isArray(value)) value.forEach(item => params.append(key + '[]', item));
        else params.append(key, value);
      }
      body = params.toString();
      headers['Content-Type'] = 'application/x-www-form-urlencoded';
      headers['Content-Length'] = Buffer.byteLength(body);
    }
    return new Promise((resolve, reject) => {
      const req = http.request(base + url, { method: fields ? 'POST' : 'GET', headers, agent: false }, response => {
        let text = '';
        response.setEncoding('utf8');
        response.on('data', chunk => { text += chunk; });
        response.on('end', () => {
          const setCookie = response.headers['set-cookie'] || [];
          if (sendCookie && setCookie.length) cookie = setCookie[0].split(';')[0];
          const responseHeaders = new Headers();
          for (const [name, values] of Object.entries(response.headers)) {
            for (const value of Array.isArray(values) ? values : [values]) responseHeaders.append(name, value);
          }
          resolve({ status: response.statusCode, headers: responseHeaders, text });
        });
        response.on('error', reject);
      });
      req.on('error', reject);
      req.end(body);
    });
  }
  function csrf(html) { return html.match(/name="csrf" value="([a-f0-9]+)"/)[1]; }
  function revision(html) { return html.match(/name="revision" value="([a-f0-9]+)"/)[1]; }
  function capture(name, html) {
    if (process.env.CMS_CAPTURE_HTML !== '1') return;
    const directory = path.join(root, '.tmp', 'cms-design');
    fs.mkdirSync(directory, { recursive: true });
    const css = fs.readFileSync(path.join(root, 'admin', 'cms.css'), 'utf8');
    fs.writeFileSync(path.join(directory, name + '.html'), html.replace('<link rel="stylesheet" href="cms.css">', '<style>' + css + '</style>'));
  }
  try {
    for (let attempt = 0; attempt < 50; attempt++) {
      try { await request('/api/content.php', null, false); break; }
      catch (_) { if (attempt === 49) throw new Error(logs); await new Promise(resolve => setTimeout(resolve, 100)); }
    }
    let token;
    await t.test('public content exposes no account data and writes require authentication', async () => {
      const publicContent = await request('/api/content.php', null, false);
      assert.equal(publicContent.status, 200);
      assert.equal(JSON.parse(publicContent.text).proof.url, 'https://vt.tiktok.com/ZSpKY6vhf/');
      assert.ok(!publicContent.text.includes('password_hash'));
      assert.ok(!publicContent.text.includes('test_admin'));
      const login = await request('/admin/');
      capture('login', login.text);
      token = csrf(login.text);
      assert.match(login.headers.get('set-cookie'), /HttpOnly/i);
      assert.match(login.headers.get('set-cookie'), /SameSite=Strict/i);
      assert.match(login.headers.get('content-security-policy'), /frame-ancestors 'none'/);
      assert.equal((await request('/admin/', { action: 'save-proof', csrf: token })).status, 401);
      assert.equal((await request('/admin/', { action: 'create-card', csrf: token })).status, 401);
      assert.equal((await request('/admin/', { action: 'delete-card', csrf: token })).status, 401);
      assert.equal((await request('/admin/', { action: 'save-proof', csrf: 'forged' })).status, 403);
      assert.equal((await request('/.spawnvault-cms/config.json', null, false)).status, 404);
      assert.equal((await request('/scripts/setup-cms.php', null, false)).status, 404);
    });
    await t.test('successful login rotates the session and publishes persisted content', async () => {
      const oldCookie = cookie;
      assert.equal((await request('/admin/', { action: 'login', csrf: token, username: 'test_admin', password })).status, 303);
      assert.notEqual(cookie, oldCookie);
      const page = await request('/admin/?view=proof');
      capture('proof', page.text);
      capture('platform', (await request('/admin/?key=platform-1')).text);
      token = csrf(page.text);
      assert.notEqual(token, csrf((await request('/admin/', null, false)).text));
      const saved = await request('/admin/?view=proof', {
        action: 'save-proof', csrf: token, revision: revision(page.text),
        url: 'https://www.tiktok.com/@test/video/123', label_id: 'Prove baru', label_en: 'Proof',
        note_id: 'Bukti pembayaran terbaru', note_en: 'Latest payment proof',
      });
      assert.equal(saved.status, 303, saved.text);
      const content = JSON.parse((await request('/api/content.php', null, false)).text);
      assert.equal(content.proof.label.id, 'Prove baru');
      assert.equal(content.proof.url, 'https://www.tiktok.com/@test/video/123');
      assert.equal(JSON.parse(fs.readFileSync(path.join(privateDirectory, 'content.json'))).proof.label.id, 'Prove baru');
      assert.equal((await request('/admin/', { action: 'save-proof', csrf: token, revision: revision(page.text) })).status, 409);
    });
    await t.test('unsafe links and executable markup are rejected without changing saved data', async () => {
      let page = await request('/admin/?view=proof');
      const before = fs.readFileSync(path.join(privateDirectory, 'content.json'), 'utf8');
      const response = await request('/admin/?view=proof', { action: 'save-proof', csrf: token, revision: revision(page.text), url: 'javascript:alert(1)' });
      assert.equal(response.status, 422);
      page = await request('/admin/?view=texts&key=hero-h1');
      assert.equal((await request('/admin/?view=texts&key=hero-h1', { action: 'save-text', csrf: token, revision: revision(page.text), key: 'hero-h1', text_id: '<img src=x onerror=alert(1)>', text_en: 'Hello' })).status, 422);
      assert.equal(fs.readFileSync(path.join(privateDirectory, 'content.json'), 'utf8'), before);
      const saved = await request('/admin/?view=texts&key=hero-h1', { action: 'save-text', csrf: token, revision: revision(page.text), key: 'hero-h1', text_id: 'Judul <strong>baru</strong>', text_en: 'New <em>title</em>' });
      assert.equal(saved.status, 303);
      assert.equal(JSON.parse((await request('/api/content.php', null, false)).text).translations.id['hero-h1'], 'Judul <strong>baru</strong>');
    });
    await t.test('platform names, descriptions, referral codes, and filters persist together', async () => {
      const page = await request('/admin/?key=platform-1');
      const saved = await request('/admin/?key=platform-1', {
        action: 'save-card', key: 'platform-1', section: 'ai-trainer', csrf: token, revision: revision(page.text),
        name_id: 'Mercor <aman>', name_en: 'Mercor updated', description_id: 'Deskripsi baru', description_en: 'New description',
        payout: 'PayPal / USD', code: "CODE'123", effort: 'low', payouts: ['paypal', 'project'],
        link_url_0: 'https://example.com/referral', link_label_0_id: 'Daftar sekarang', link_label_0_en: 'Apply now',
      });
      assert.equal(saved.status, 303, saved.text);
      const content = JSON.parse((await request('/api/content.php', null, false)).text);
      assert.equal(content.cards['platform-1'].name.id, 'Mercor <aman>');
      assert.equal(content.cards['platform-1'].code, "CODE'123");
      assert.equal(content.cards['platform-1'].payouts, 'paypal project');
      assert.equal(content.cards['platform-1'].effortLabel, 'effort-low');
      assert.equal(content.translations.id['card1-desc'], 'Deskripsi baru');
      const rendered = await request('/admin/?key=platform-1');
      assert.match(rendered.text, /Mercor &lt;aman&gt;/);
      assert.ok(!rendered.text.includes('value="Mercor <aman>"'));
    });
    await t.test('validation errors preserve effort and an empty payout selection', async () => {
      const page = await request('/admin/?key=platform-1');
      const response = await request('/admin/?key=platform-1', {
        action: 'save-card', key: 'platform-1', section: 'games', csrf: token, revision: revision(page.text),
        name_id: 'Mercor', name_en: 'Mercor', description_id: 'Deskripsi', description_en: 'Description',
        payout: 'PayPal', code: '', effort: 'very-low', link_url_0: 'javascript:alert(1)',
      });
      assert.equal(response.status, 422);
      assert.match(response.text, /value="very-low" selected/);
      assert.match(response.text, /value="games" selected/);
      assert.ok(!/name="payouts\[\]"[^>]* checked/.test(response.text));
    });
    await t.test('each category card has an independent description', async () => {
      for (const id of ['platform-9', 'platform-19']) {
        const content = JSON.parse((await request('/api/content.php', null, false)).text);
        const card = content.cards[id];
        const page = await request('/admin/?key=' + id);
        const fields = {
          action: 'save-card', key: id, section: card.section, csrf: token, revision: revision(page.text),
          name_id: card.name.id, name_en: card.name.en, description_id: 'Deskripsi ' + id, description_en: 'Description ' + id,
          payout: card.payout, code: card.code, effort: card.effort, payouts: card.payouts.split(' '),
        };
        card.links.forEach((link, index) => {
          fields['link_url_' + index] = link.url;
          fields['link_label_' + index + '_id'] = link.label.id;
          fields['link_label_' + index + '_en'] = link.label.en;
        });
        assert.equal((await request('/admin/?key=' + id, fields)).status, 303);
      }
      const content = JSON.parse((await request('/api/content.php', null, false)).text);
      const keys = Object.values(content.cards).map(card => card.description);
      assert.equal(new Set(keys).size, keys.length);
      for (const id of ['platform-9', 'platform-19']) {
        assert.equal(content.translations.id[content.cards[id].description], 'Deskripsi ' + id);
        assert.equal(content.translations.en[content.cards[id].description], 'Description ' + id);
      }
    });
    await t.test('cards can be created, moved, confirmed for deletion, and kept deleted', async () => {
      const add = await request('/admin/?view=new');
      capture('new-card', add.text);
      assert.equal(add.status, 200);
      const id = add.text.match(/name="key" value="(custom-[a-f0-9]{16})"/)[1];
      const fields = {
        action: 'create-card', key: id, section: 'games', csrf: token, revision: revision(add.text),
        name_id: 'Platform baru', name_en: 'New platform', description_id: 'Deskripsi baru', description_en: 'New description',
        payout: 'PayPal', code: '', effort: 'low', payouts: ['paypal'],
        link_url_0: 'https://example.com/new', link_label_0_id: 'Daftar', link_label_0_en: 'Join',
      };
      const before = fs.readFileSync(path.join(privateDirectory, 'content.json'), 'utf8');
      assert.equal((await request('/admin/?view=new', { ...fields, section: 'unknown-category' })).status, 422);
      assert.equal(fs.readFileSync(path.join(privateDirectory, 'content.json'), 'utf8'), before);
      const created = await request('/admin/?view=new', fields);
      assert.equal(created.status, 303, created.text);
      assert.equal(created.headers.get('location'), './?key=' + id);
      let content = JSON.parse((await request('/api/content.php', null, false)).text);
      assert.equal(Object.keys(content.cards).length, 33);
      assert.equal(content.cards[id].section, 'games');
      assert.equal(content.translations.id[content.cards[id].description], 'Deskripsi baru');
      const edit = await request('/admin/?key=' + id);
      assert.match(edit.text, /value="games" selected/);
      assert.equal((await request('/admin/?view=new', { ...fields, revision: revision(edit.text) })).status, 422);
      assert.equal((await request('/admin/?key=' + id, { ...fields, action: 'save-card', section: 'ai-trainer', revision: revision(edit.text) })).status, 303);
      content = JSON.parse((await request('/api/content.php', null, false)).text);
      assert.equal(content.cards[id].section, 'ai-trainer');
      assert.equal(Object.keys(content.cards).length, 33);
      const confirm = await request('/admin/?view=delete&key=' + id);
      capture('delete-card', confirm.text);
      const persisted = fs.readFileSync(path.join(privateDirectory, 'content.json'), 'utf8');
      assert.match(confirm.text, /Hapus kartu\?/);
      assert.equal(fs.readFileSync(path.join(privateDirectory, 'content.json'), 'utf8'), persisted);
      assert.equal((await request('/admin/?view=delete&key=' + id, { action: 'delete-card', key: id, csrf: token, revision: revision(confirm.text) })).status, 422);
      assert.equal(fs.readFileSync(path.join(privateDirectory, 'content.json'), 'utf8'), persisted);
      assert.equal((await request('/admin/?view=delete&key=' + id, { action: 'delete-card', key: id, confirm: 'yes', csrf: 'forged', revision: revision(confirm.text) })).status, 403);
      assert.equal((await request('/admin/?view=delete&key=' + id, { action: 'delete-card', key: id, confirm: 'yes', csrf: token, revision: revision(confirm.text) })).status, 303);
      content = JSON.parse((await request('/api/content.php', null, false)).text);
      assert.equal(content.cards[id], undefined);
      assert.equal(content.deletedCards[id], true);
      const existing = await request('/admin/?view=delete&key=platform-2');
      assert.equal((await request('/admin/?view=delete&key=platform-2', { action: 'delete-card', key: 'platform-2', confirm: 'yes', csrf: token, revision: revision(existing.text) })).status, 303);
      for (let read = 0; read < 2; read++) {
        const persisted = JSON.parse((await request('/api/content.php', null, false)).text);
        assert.equal(persisted.cards['platform-2'], undefined);
        assert.equal(persisted.deletedCards['platform-2'], true);
      }
      assert.match((await request('/admin/?view=delete&key=missing')).text, /Kartu tidak ditemukan/);
    });
    await t.test('deleting all cards leaves a working empty editor and permits new cards', async () => {
      const cards = JSON.parse((await request('/api/content.php', null, false)).text).cards;
      for (const id of Object.keys(cards)) {
        const page = await request('/admin/?view=delete&key=' + id);
        assert.equal((await request('/admin/?view=delete&key=' + id, { action: 'delete-card', key: id, confirm: 'yes', csrf: token, revision: revision(page.text) })).status, 303);
      }
      const empty = await request('/admin/');
      capture('empty', empty.text);
      assert.equal(empty.status, 200);
      assert.match(empty.text, /Belum ada kartu/);
      assert.equal(Object.keys(JSON.parse((await request('/api/content.php', null, false)).text).cards).length, 0);
      const add = await request('/admin/?view=new');
      const id = add.text.match(/name="key" value="(custom-[a-f0-9]{16})"/)[1];
      assert.equal((await request('/admin/?view=new', {
        action: 'create-card', key: id, section: 'passive', csrf: token, revision: revision(add.text),
        name_id: 'Kartu pertama', name_en: 'First card', description_id: 'Deskripsi', description_en: 'Description',
        payout: 'PayPal', code: '', effort: 'low', payouts: ['paypal'],
        link_url_0: 'https://example.com/first', link_label_0_id: 'Daftar', link_label_0_en: 'Join',
      })).status, 303);
      assert.match((await request('/admin/')).text, /Kartu pertama/);
      assert.equal(Object.keys(JSON.parse((await request('/api/content.php', null, false)).text).cards).length, 1);
    });
    await t.test('password rotation invalidates old sessions and logout blocks writes', async () => {
      const page = await request('/admin/?view=password');
      const oldCookie = cookie;
      const newPassword = 'new-test-password-123456';
      assert.equal((await request('/admin/?view=password', { action: 'password', csrf: token, current_password: password, new_password: newPassword, confirm_password: newPassword })).status, 303);
      cookie = oldCookie;
      const expired = await request('/admin/');
      assert.match(expired.text, /Masuk ke CMS/);
      token = csrf(expired.text);
      assert.equal((await request('/admin/', { action: 'login', csrf: token, username: 'test_admin', password: newPassword })).status, 303);
      const loggedIn = await request('/admin/');
      token = csrf(loggedIn.text);
      assert.equal((await request('/admin/', { action: 'logout', csrf: token })).status, 303);
      assert.equal((await request('/admin/', { action: 'save-proof', csrf: token })).status, 403);
      assert.match((await request('/admin/')).text, /Masuk ke CMS/);
      assert.ok(!fs.readFileSync(path.join(privateDirectory, 'config.json'), 'utf8').includes(newPassword));
    });
    await t.test('login throttling survives fresh browser sessions', async () => {
      for (let attempt = 0; attempt < 5; attempt++) {
        cookie = '';
        const login = await request('/admin/');
        const result = await request('/admin/', { action: 'login', csrf: csrf(login.text), username: 'test_admin', password: 'wrong-password' });
        assert.equal(result.status, 401);
      }
      cookie = '';
      const login = await request('/admin/');
      assert.equal((await request('/admin/', { action: 'login', csrf: csrf(login.text), username: 'test_admin', password: 'wrong-password' })).status, 429);
    });
    assert.ok(!/PHP (?:Fatal error|Warning|Parse error)/.test(logs), logs);
  } finally {
    server.kill();
    await new Promise(resolve => server.once('close', resolve));
    assert.equal(path.dirname(privateDirectory), tempRoot);
    assert.ok(path.basename(privateDirectory).startsWith('cms-test-'));
    fs.rmSync(privateDirectory, { recursive: true, force: true });
  }
});

test('published HTML, translations, and CMS records stay aligned', () => {
  const html = fs.readFileSync(path.join(root, 'index.html'), 'utf8');
  const content = JSON.parse(fs.readFileSync(path.join(root, 'data', 'content.default.json'), 'utf8'));
  const ids = [...html.matchAll(/data-cms-card="([^"]+)"/g)].map(match => match[1]);
  assert.deepEqual(ids, Object.keys(content.cards));
  for (const card of Object.values(content.cards)) {
    assert.ok(html.includes('data-i18n="' + card.description + '"'), card.description);
  }
  for (const match of html.matchAll(/data-i18n="([^"]+)"/g)) {
    for (const lang of ['id', 'en']) assert.equal(typeof content.translations[lang][match[1]], 'string', lang + ': ' + match[1]);
  }
  for (const match of html.matchAll(/<script([^>]*)>([\s\S]*?)<\/script>/g)) {
    if (match[1].includes('application/ld+json')) JSON.parse(match[2]);
    else new vm.Script(match[2]);
  }
});

test('public rendering adds, moves, and removes cards without duplicates across languages', async () => {
  class Element {
    constructor(tag = 'div', className = '') {
      this.tagName = tag.toUpperCase(); this.className = className;
      this.dataset = {}; this.children = []; this.parentElement = null; this._text = '';
      this.listeners = {};
    }
    get classList() {
      return {
        contains: value => this.className.split(' ').includes(value),
        remove: value => { this.className = this.className.split(' ').filter(item => item !== value).join(' '); },
        toggle: (value, force) => {
          const classes = new Set(this.className.split(' ').filter(Boolean));
          if (force) classes.add(value); else classes.delete(value);
          this.className = [...classes].join(' ');
        },
      };
    }
    append(...nodes) {
      for (let node of nodes) {
        if (typeof node === 'string') { const text = new Element('#text'); text._text = node; node = text; }
        node.remove(); node.parentElement = this; this.children.push(node);
      }
    }
    prepend(node) { node.remove(); node.parentElement = this; this.children.unshift(node); }
    remove() {
      if (this.parentElement) this.parentElement.children = this.parentElement.children.filter(child => child !== this);
      this.parentElement = null;
    }
    before(node) { const parent = this.parentElement; node.remove(); node.parentElement = parent; parent.children.splice(parent.children.indexOf(this), 0, node); }
    replaceChildren(...nodes) { this.children.forEach(node => { node.parentElement = null; }); this.children = []; this._text = ''; this.append(...nodes); }
    set textContent(value) { this.replaceChildren(String(value)); }
    get textContent() { return this._text + this.children.map(child => child.textContent).join(''); }
    set innerHTML(value) { this.content = new Element('#fragment'); this.content._text = value; }
    addEventListener(name, callback) { this.listeners[name] = callback; }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    querySelectorAll(selector) {
      if (selector.includes(' ')) {
        const [parent, ...rest] = selector.split(' ');
        return this.querySelectorAll(parent).flatMap(element => element.querySelectorAll(rest.join(' ')));
      }
      const descendants = this.children.flatMap(child => [child, ...child.querySelectorAll('*')]);
      return descendants.filter(node => {
        if (selector === '*') return !node.tagName.startsWith('#');
        if (selector === '[data-cms-card]') return node.dataset.cmsCard !== undefined;
        if (selector === '[data-i18n]') return node.dataset.i18n !== undefined;
        if (selector.startsWith('.')) return selector.slice(1).split('.').every(value => node.classList.contains(value));
        return node.tagName === selector.toUpperCase();
      });
    }
  }
  const rootElement = new Element('body');
  const sections = {};
  for (const id of ['ai-trainer', 'games']) {
    const section = new Element('section');
    section.id = id;
    const cards = new Element('div', 'cards');
    section.append(cards); rootElement.append(section); sections[id] = section;
  }
  const existing = new Element('div', 'card'); existing.dataset.cmsCard = 'existing';
  const top = new Element('div', 'card-top');
  top.append(new Element('div', 'card-name'), new Element('span', 'card-payout'));
  const description = new Element('div', 'card-desc'); description.dataset.i18n = 'existing-desc';
  const meta = new Element('div', 'card-meta'); meta.append(new Element('span', 'tag effort'));
  const oldCode = new Element('div', 'card-code');
  const links = new Element('div', 'card-links'); links.append(new Element('a', 'btn btn-primary'));
  existing.append(top, description, meta, oldCode, links);
  const deleted = new Element('div', 'card'); deleted.dataset.cmsCard = 'deleted';
  sections.games.querySelector('.cards').append(existing, deleted);
  const filter = new Element('button');
  let copied = '', filtered;
  const content = {
    translations: {
      id: { 'effort-low': 'Usaha Rendah', 'existing-desc': 'Deskripsi lama', 'new-desc': 'Deskripsi baru' },
      en: { 'effort-low': 'Low Effort', 'existing-desc': 'Existing description', 'new-desc': 'New description' },
    },
    cards: {
      existing: { section: 'ai-trainer', name: { id: 'Platform uji', en: 'Test platform' }, description: 'existing-desc', payout: 'PayPal', effort: 'low', effortLabel: 'effort-low', payouts: 'paypal', code: '', links: [{ url: 'https://example.com/existing', label: { id: 'Daftar', en: 'Join' } }] },
      new: { section: 'games', name: { id: 'Baru <aman>', en: 'New platform' }, description: 'new-desc', payout: 'Crypto', effort: 'low', effortLabel: 'effort-low', payouts: 'crypto-pay', code: 'NEW-CODE', links: [{ url: 'https://example.com/new', label: { id: 'Gabung', en: 'Visit' } }] },
    },
    proof: {},
  };
  const listeners = {};
  const document = {
    documentElement: { lang: 'id' },
    querySelectorAll: selector => rootElement.querySelectorAll(selector),
    querySelector: selector => rootElement.querySelector(selector),
    getElementById: id => id === 'filterAll' ? filter : sections[id],
    createElement: tag => new Element(tag),
  };
  vm.runInNewContext(fs.readFileSync(path.join(root, 'content.js'), 'utf8'), {
    document, window: { addEventListener(name, callback) { listeners[name] = callback; } },
    fetch: async () => ({ ok: true, json: async () => content }),
    copyCode: (button, code) => { copied = code; },
    currentFilter: 'cat-games', setFilter: (button, value) => { filtered = value; },
  });
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(deleted.parentElement, null);
  assert.equal(oldCode.parentElement, null);
  assert.equal(existing.parentElement, sections['ai-trainer'].querySelector('.cards'));
  assert.ok(existing.querySelector('.card-body'));
  assert.equal(existing.querySelector('.card-name').textContent, 'Platform uji');
  assert.equal(existing.querySelector('.tag.effort').textContent, 'Usaha Rendah');
  const added = rootElement.querySelectorAll('[data-cms-card]').find(element => element.dataset.cmsCard === 'new');
  assert.equal(added.parentElement, sections.games.querySelector('.cards'));
  assert.equal(added.querySelector('.card-name').textContent, 'Baru <aman>');
  assert.equal(added.querySelector('.card-desc').textContent, 'Deskripsi baru');
  assert.equal(added.querySelector('.card-links a').href, 'https://example.com/new');
  added.querySelector('.copy-btn').listeners.click();
  assert.equal(copied, 'NEW-CODE');
  assert.equal(filtered, 'cat-games');
  document.documentElement.lang = 'en';
  listeners['spawnvault:language']();
  assert.equal(rootElement.querySelectorAll('[data-cms-card]').length, 2);
  assert.equal(existing.querySelector('.card-name').textContent, 'Test platform');
  assert.equal(added.querySelector('.card-desc').textContent, 'New description');
  assert.equal(added.querySelector('.tag.effort').textContent, 'Low Effort');
  assert.equal(added.querySelector('.card-links a').textContent, 'Visit');
});

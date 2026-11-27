(() => {
  let content;

  function richText(element, value) {
    const template = document.createElement('template');
    template.innerHTML = value;
    template.content.querySelectorAll('*').forEach(node => {
      if (!['STRONG', 'EM', 'B', 'BR'].includes(node.tagName)) {
        node.replaceWith(document.createTextNode(node.textContent));
      } else {
        Array.from(node.attributes).forEach(attribute => node.removeAttribute(attribute.name));
      }
    });
    element.replaceChildren(template.content);
  }

  function createCard(key, card) {
    const element = document.createElement('div');
    element.className = 'card';
    element.dataset.cmsCard = key;
    const body = document.createElement('div');
    body.className = 'card-body';
    const top = document.createElement('div');
    top.className = 'card-top';
    const name = document.createElement('div');
    name.className = 'card-name';
    const payout = document.createElement('span');
    payout.className = 'card-payout';
    top.append(name, payout);
    const description = document.createElement('div');
    description.className = 'card-desc';
    description.dataset.i18n = card.description;
    const meta = document.createElement('div');
    meta.className = 'card-meta';
    const effort = document.createElement('span');
    effort.className = 'tag effort';
    meta.append(effort);
    body.append(top, description, meta);
    const links = document.createElement('div');
    links.className = 'card-links';
    card.links.forEach(() => {
      const link = document.createElement('a');
      link.className = 'btn btn-primary';
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      links.append(link);
    });
    element.append(body, links);
    return element;
  }

  function applyContent() {
    if (!content) return;
    const lang = document.documentElement.lang === 'id' ? 'id' : 'en';
    document.querySelectorAll('[data-i18n]').forEach(element => {
      const value = content.translations[lang][element.dataset.i18n];
      if (typeof value === 'string') richText(element, value);
    });
    const elements = new Map();
    document.querySelectorAll('[data-cms-card]').forEach(element => {
      if (!content.cards[element.dataset.cmsCard]) element.remove();
      else elements.set(element.dataset.cmsCard, element);
    });
    Object.entries(content.cards).forEach(([key, card]) => {
      const target = document.getElementById(card.section)?.querySelector('.cards');
      if (!target) return;
      const element = elements.get(key) || createCard(key, card);
      if (element.parentElement !== target) target.append(element);
      element.classList.toggle('ai-card', card.section === 'ai-trainer');
      let body = element.querySelector('.card-body');
      if (card.section === 'ai-trainer' && !body) {
        body = document.createElement('div');
        body.className = 'card-body';
        Array.from(element.children).forEach(child => {
          if (!child.classList.contains('card-links')) body.append(child);
        });
        element.prepend(body);
      }
      element.querySelector('.card-name').textContent = card.name[lang];
      element.querySelector('.card-payout').textContent = card.payout;
      richText(element.querySelector('.card-desc'), content.translations[lang][card.description]);
      element.dataset.effort = card.effort;
      element.dataset.payout = card.payouts;
      if (card.effortLabel) {
        const effort = element.querySelector('.tag.effort');
        if (effort) effort.textContent = content.translations[lang][card.effortLabel];
      }
      element.querySelectorAll('.card-links a').forEach((link, index) => {
        const item = card.links[index];
        if (!item || !/^https:\/\//i.test(item.url)) return;
        link.href = item.url;
        link.textContent = item.label[lang];
      });
      let code = element.querySelector('.card-code');
      if (code && !card.code) {
        code.remove();
        code = null;
      }
      if (card.code && !code) {
        code = document.createElement('div');
        code.className = 'card-code';
        if (body) body.append(code);
        else element.querySelector('.card-links').before(code);
      }
      if (code) {
        if (body && code.parentElement !== body) body.append(code);
        const value = document.createElement('span');
        value.className = 'code-val';
        value.textContent = card.code;
        const button = document.createElement('button');
        button.className = 'copy-btn';
        button.type = 'button';
        button.textContent = lang === 'id' ? 'Salin' : 'Copy';
        button.addEventListener('click', () => copyCode(button, card.code));
        code.replaceChildren(lang === 'id' ? 'Kode: ' : 'Code: ', value, button);
      }
    });
    const proof = document.querySelector('[data-cms-proof]');
    if (proof && /^https:\/\//i.test(content.proof.url)) {
      proof.href = content.proof.url;
      proof.textContent = content.proof.label[lang];
      document.querySelector('[data-cms-proof-note]').textContent = content.proof.note[lang];
    }
    // Reapply the current filter after its metadata changes, without toggling it off.
    if (typeof setFilter === 'function' && typeof currentFilter !== 'undefined') {
      const active = document.querySelector('.filter-btn.active');
      if (active) active.classList.remove('active');
      setFilter(active || document.getElementById('filterAll'), currentFilter);
    }
  }

  window.addEventListener('spawnvault:language', applyContent);
  async function loadContent() {
    for (const url of ['api/content.php', 'data/content.default.json']) {
      try {
        const response = await fetch(url, { cache: 'no-store', credentials: 'omit' });
        if (!response.ok) continue;
        const data = await response.json();
        if (!data.translations?.id || !data.translations?.en || !data.cards || !data.proof) continue;
        content = data;
        applyContent();
        return;
      } catch (_) { /* Keep the published HTML usable when CMS is unavailable. */ }
    }
  }
  loadContent();
})();

// Real browser test using Chrome DevTools and Node 22+, without third-party packages.
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';

const accounts = JSON.parse(execFileSync('docker', ['compose', '-f', 'compose.test.yml', 'exec', '-T', 'backend', 'cat', '/tmp/test-accounts.json'], { encoding: 'utf8' }));
const profileDir = await mkdtemp(join(tmpdir(), 'calendriersp-browser-'));
const chrome = spawn(process.env.CHROME_BIN || 'google-chrome', ['--headless', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--no-first-run', '--remote-debugging-address=127.0.0.1', '--remote-debugging-port=19222', `--user-data-dir=${profileDir}`, 'about:blank'], { stdio: 'ignore' });
let socket;
try {
  let tabs;
  for (let i = 0; i < 100; i++) {
    try { tabs = await (await fetch('http://127.0.0.1:19222/json')).json(); break; } catch { await delay(100); }
  }
  assert(tabs, 'Chrome did not start');
  socket = new WebSocket(tabs.find(t => t.type === 'page').webSocketDebuggerUrl);
  await new Promise((resolve, reject) => { socket.onopen = resolve; socket.onerror = reject; });
  let nextId = 0;
  const pending = new Map();
  const exceptions = [];
  const networkFailures = [];
  let pageLoads = 0;
  socket.onmessage = event => {
    const message = JSON.parse(event.data);
    if (message.method === 'Page.loadEventFired') pageLoads++;
    if (message.method === 'Runtime.exceptionThrown') exceptions.push(message.params.exceptionDetails);
    if (message.method === 'Network.loadingFailed' && ['Fetch', 'XHR'].includes(message.params.type)) networkFailures.push(message.params);
    if (message.id) {
      const callback = pending.get(message.id);
      pending.delete(message.id);
      if (message.error) callback.reject(new Error(JSON.stringify(message.error))); else callback.resolve(message.result);
    }
  };
  const command = (method, params = {}) => new Promise((resolve, reject) => {
    const id = ++nextId;
    pending.set(id, { resolve, reject });
    socket.send(JSON.stringify({ id, method, params }));
  });
  const reloadPage = async () => {
    const previous = pageLoads;
    await command('Page.reload');
    for (let i = 0; i < 300; i++) {
      if (pageLoads > previous) return;
      await delay(100);
    }
    throw new Error('Page reload did not finish');
  };
  const evaluate = async expression => {
    const result = await command('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (result.exceptionDetails) throw new Error(JSON.stringify(result.exceptionDetails));
    return result.result.value;
  };
  const wait = async expression => {
    for (let i = 0; i < 100; i++) {
      if (await evaluate(expression)) return;
      await delay(100);
    }
    throw new Error(`Timed out: ${expression}\n${await evaluate('document.body.innerText')}\nNetwork failures: ${JSON.stringify(networkFailures)}`);
  };
  const ensureFilters = async () => {
    await evaluate("document.querySelector('.passages-panel button[aria-expanded=false]')?.click()");
    await wait("document.querySelector('[name=filtreRue]')?.offsetParent !== null && !!document.querySelector('[name=filtreRue]')");
  };
  const fill = async (name, value) => {
    if (name === 'rechercheRecu') await ensureFilters();
    return evaluate(`(() => {
    const el = document.querySelector('[name=${JSON.stringify(name)}]') || document.querySelector('[aria-label=${JSON.stringify(name)}]');
    if (!el) throw new Error('Field missing: ' + ${JSON.stringify(name)});
    const prototype = el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
    Object.getOwnPropertyDescriptor(prototype, 'value').set.call(el, ${JSON.stringify(value)});
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  })()`);
  };
  const click = async label => {
    if (label === 'Réinitialiser les filtres et le tri') await ensureFilters();
    await wait(`!![...document.querySelectorAll('button')].find(e => e.textContent.trim() === ${JSON.stringify(label)} && !e.disabled)`);
    return evaluate(`(() => {
    const el = [...document.querySelectorAll('button')].find(e => e.textContent.trim() === ${JSON.stringify(label)});
    if (!el) throw new Error('Button missing: ' + ${JSON.stringify(label)});
    el.click();
  })()`);
  };
  const select = async (name, label) => {
    if (['filtreRue', 'filtreStatut', 'filtreCarnet', 'triPassages', 'ordrePassages'].includes(name)) await ensureFilters();
    return evaluate(`(() => {
    const el = document.querySelector('dialog select[name=${JSON.stringify(name)}]') || document.querySelector('select[name=${JSON.stringify(name)}]');
    el.value = [...el.options].find(o => o.textContent === ${JSON.stringify(label)}).value;
    el.dispatchEvent(new Event('change', { bubbles: true }));
  })()`);
  };
  const submitDialog = async () => { await evaluate("document.querySelector('dialog form').requestSubmit()"); await wait("!document.querySelector('dialog')"); };
  const waitActivity = async (scope, calendars, stock, remaining, addresses) => {
    const ratio = (value, total) => `${value.toLocaleString('fr-FR')} / ${total.toLocaleString('fr-FR')}`;
    const expected = [ratio(calendars, stock), ratio(remaining, addresses)];
    await wait(`JSON.stringify([...document.querySelectorAll(${JSON.stringify(`${scope} .metric-count`)})].map(e => e.textContent)) === ${JSON.stringify(JSON.stringify(expected))}`);
  };
  await command('Runtime.enable');
  await command('Network.enable');
  await command('Page.enable');
  await command('Emulation.setDeviceMetricsOverride', { width: 1280, height: 1000, deviceScaleFactor: 1, mobile: false });
  await command('Page.navigate', { url: 'http://127.0.0.1:18081' });
  await wait("!!document.querySelector('[name=amicaleId]')");
  assert.deepEqual(await evaluate("(() => { const el = document.querySelector('[name=amicaleId]'); return [el.minLength, el.maxLength, el.pattern]; })()"), [4, 20, '[A-Za-z0-9]{4,20}']);
  for (const [field, value] of Object.entries(accounts.B)) await fill(field, field === 'amicaleId' ? value.toUpperCase() : value);
  await click('Se connecter');
  await wait("!!document.querySelector('nav') && document.body.innerText.includes('Suivi des tournées')");
  await wait("document.querySelectorAll('.stats .metric-count').length === 2");
  const [calendarBaseline, addressBaseline] = await evaluate("[...document.querySelectorAll('.stats .metric-count')].map(e => e.textContent.split('/').map(n => Number(n.replace(/\\s/g, ''))))");

  const suffix = Date.now();
  await click('Paramètres');
  await wait("!!document.querySelector('[name=identifiant]')");
  assert.equal(await evaluate("document.querySelector('[name=identifiant]').value"), accounts.B.amicaleId);
  const changedIdentifier = `web${suffix}`;
  await fill('identifiant', changedIdentifier.toUpperCase());
  await evaluate("document.querySelector('section.panel form').requestSubmit()");
  await wait(`document.querySelector('[name=identifiant]')?.value === ${JSON.stringify(changedIdentifier)}`);
  await reloadPage();
  await wait("!!document.querySelector('nav')");
  await click('Paramètres');
  await wait(`document.querySelector('[name=identifiant]')?.value === ${JSON.stringify(changedIdentifier)}`);
  await fill('identifiant', accounts.B.amicaleId);
  await evaluate("document.querySelector('section.panel form').requestSubmit()");
  await wait(`document.querySelector('[name=identifiant]')?.value === ${JSON.stringify(accounts.B.amicaleId)}`);
  const sector = `Secteur navigateur ${suffix}`;
  const tour = `Tournée navigateur ${suffix}`;
  await click('Secteurs');
  await click('＋ Ajouter');
  await fill('nom', sector);
  await fill('Rechercher un lieu', 'rue blanqui, troyes');
  await click('Rechercher');
  await wait("document.querySelector('dialog .place-results')?.innerText.includes('Boulevard Blanqui 10000 Troyes')");
  assert.equal(await evaluate("document.querySelector('dialog [name=nom]').value"), sector);
  await click('Boulevard Blanqui 10000 Troyes');
  await wait("!document.querySelector('dialog .place-results')");
  assert.equal(await evaluate("!!document.querySelector('dialog .leaflet-container')"), true);
  await submitDialog();
  await wait(`document.body.innerText.includes(${JSON.stringify(sector)})`);

  await click('Adresses');
  await click('＋ Ajouter');
  await select('secteurId', sector);
  await fill('ligne1', '3 rue du navigateur');
  await fill('codePostal', '75001');
  await fill('ville', 'Paris');
  await submitDialog();
  await wait("document.body.innerText.includes('3 rue du navigateur')");
  await click('＋ Ajouter');
  await select('secteurId', sector);
  await fill('ligne1', '4 rue du navigateur');
  await fill('codePostal', '75001');
  await fill('ville', 'Paris');
  await submitDialog();
  await wait("document.body.innerText.includes('4 rue du navigateur')");
  await click('＋ Ajouter');
  await select('secteurId', sector);
  await fill('ligne1', '20 avenue des Tests');
  await fill('codePostal', '75001');
  await fill('ville', 'Paris');
  await submitDialog();
  await wait("document.body.innerText.includes('20 avenue des Tests')");

  const teamEmails = [`equipe-1-${suffix}@example.test`, `equipe-2-${suffix}@example.test`];
  const teamNames = [`Élodie Martin ${suffix}`, `Thomas Durand ${suffix}`];
  await click('Sapeurs');
  for (const [index, email] of teamEmails.entries()) {
    await click('＋ Ajouter');
    await fill('nom', teamNames[index]);
    await fill('email', email);
    await fill('password', 'Browser-team-123!');
    await submitDialog();
    await wait(`document.body.innerText.includes(${JSON.stringify(email)})`);
  }

  await click('Tournées');
  await click('＋ Ajouter');
  await select('secteurId', sector);
  await fill('nom', tour);
  await fill('dateDebut', '2026-11-10');
  await fill('stockCalendriers', '20');
  await submitDialog();
  await wait(`document.body.innerText.includes(${JSON.stringify(tour)})`);
  await click(tour);
  await wait("document.body.innerText.includes('Passages aux adresses')");
  assert.equal(await evaluate("[...document.querySelectorAll('button')].find(e => e.textContent === 'Ajouter à l’équipe').disabled"), true);
  assert.equal(await evaluate("document.querySelector('[name=filtreRue]').offsetParent === null"), true);
  for (const name of teamNames) {
    await evaluate(`[...document.querySelectorAll('.team-option')].find(label => label.textContent === ${JSON.stringify(name)}).querySelector('input').click()`);
  }
  await wait("document.body.innerText.includes('2 sapeurs sélectionnés')");
  await click('Ajouter à l’équipe');
  await wait("document.querySelectorAll('.team-list li').length === 2");
  for (const name of teamNames) {
    assert.equal(await evaluate(`document.querySelector('.team-list').innerText.includes(${JSON.stringify(name)})`), true);
    assert.equal(await evaluate(`[...document.querySelectorAll('.team-option')].some(label => label.textContent === ${JSON.stringify(name)})`), false);
  }
  // Removing one member preserves the rest of the team and makes that member available again.
  await evaluate(`[...document.querySelectorAll('.team-list li')].find(li => li.textContent.includes(${JSON.stringify(teamNames[0])})).querySelector('button').click()`);
  await wait("document.body.innerText.includes('Supprimer définitivement')");
  await click('Supprimer définitivement');
  await wait("!document.querySelector('dialog') && document.querySelectorAll('.team-list li').length === 1");
  assert.equal(await evaluate(`document.querySelector('.team-list').innerText.includes(${JSON.stringify(teamNames[1])})`), true);
  await evaluate(`[...document.querySelectorAll('.team-option')].find(label => label.textContent === ${JSON.stringify(teamNames[0])}).querySelector('input').click()`);
  await click('Ajouter à l’équipe');
  await wait("document.querySelectorAll('.team-list li').length === 2");
  const clickAddress = async (line, selector) => evaluate(`(() => {
    const row = [...document.querySelectorAll('table[aria-label="Résultats des passages"] tbody tr')].find(row => row.cells[0].textContent.startsWith(${JSON.stringify(line)}));
    row.querySelector(${JSON.stringify(selector)}).click();
  })()`);
  await clickAddress('3 rue du navigateur', 'button[aria-label^="Saisir le passage"]');
  assert.equal(await evaluate("document.querySelector('dialog button[type=submit]').disabled"), true);
  assert.equal(await evaluate("document.querySelector('dialog [name=statut]').disabled"), false);
  await select('statut', 'Absent');
  assert.equal(await evaluate("document.querySelector('dialog button[type=submit]').disabled"), false);
  await evaluate("document.querySelector('dialog [aria-label=Fermer]').click()");
  await click('＋ Ajouter un carnet');
  const bookNumber = `Web-${suffix}`;
  const firstReceipt = 100000000 + suffix % 1000000;
  await fill('numero', bookNumber);
  await fill('premierRecu', String(firstReceipt));
  assert.equal(await evaluate("document.querySelector('dialog [name=nombreRecus]').value"), '25');
  await submitDialog();
  await wait(`document.body.innerText.includes(${JSON.stringify(bookNumber)})`);
  await clickAddress('3 rue du navigateur', 'button[aria-label^="Saisir le passage"]');
  await select('carnetId', `Carnet ${bookNumber}`);
  await select('recuId', `N° ${firstReceipt}`);
  await fill('nbCalendriers', '2');
  await fill('montant', '12.30');
  await select('moyenPaiement', 'CB');
  await fill('batiment', 'A');
  await fill('etage', '1');
  await fill('appartement', '101');
  await fill('porte', 'Gauche');
  await submitDialog();
  await waitActivity('.tour-stats', 2, 20, 2, 3);
  assert.match(await evaluate('document.body.innerText'), /12,30/);

  // A second passage cannot select the receipt already used by the first one.
  await clickAddress('4 rue du navigateur', 'button[aria-label^="Saisir le passage"]');
  await select('statut', 'Don');
  await select('carnetId', `Carnet ${bookNumber}`);
  assert.equal(await evaluate(`[...document.querySelector('dialog [name=recuId]').options].some(o => o.textContent === 'N° ${firstReceipt}')`), false);
  await select('recuId', `N° ${firstReceipt + 1}`);
  await fill('commentaire', 'Don avec reçu distinct.');
  await submitDialog();
  await wait(`document.body.innerText.includes('n° ${firstReceipt + 1}')`);
  await waitActivity('.tour-stats', 3, 20, 1, 3);
  await click('← Retour aux tournées');
  await wait(`document.body.innerText.includes(${JSON.stringify(tour)})`);
  const assertTourRatios = async () => {
    const ratios = await evaluate(`[...[...document.querySelectorAll('tr')].find(row => row.querySelector('button')?.textContent === ${JSON.stringify(tour)}).querySelectorAll('.metric-count')].map(e => e.textContent)`);
    assert.deepEqual(ratios, ['3 / 20', '1 / 3']);
  };
  await assertTourRatios();
  await click('Tableau de bord');
  await waitActivity('.stats', calendarBaseline[0] + 3, calendarBaseline[1] + 20, addressBaseline[0] + 1, addressBaseline[1] + 3);
  await assertTourRatios();
  await evaluate('window.scrollTo(0, 0)');
  const activityDesktop = await command('Page.captureScreenshot', { format: 'png' });
  await writeFile('/tmp/calendriersp-activity-desktop.png', Buffer.from(activityDesktop.data, 'base64'));
  await command('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  assert.equal(await evaluate('document.documentElement.scrollWidth <= window.innerWidth'), true);
  const activityMobile = await command('Page.captureScreenshot', { format: 'png' });
  await writeFile('/tmp/calendriersp-activity-mobile.png', Buffer.from(activityMobile.data, 'base64'));
  await command('Emulation.setDeviceMetricsOverride', { width: 1280, height: 1000, deviceScaleFactor: 1, mobile: false });
  await click('Campagne');
  await waitActivity('.stats', calendarBaseline[0] + 3, calendarBaseline[1] + 20, addressBaseline[0] + 1, addressBaseline[1] + 3);
  await click('Tournées');
  await click(tour);
  await waitActivity('.tour-stats', 3, 20, 1, 3);

  await click('Modifier');
  await select('statut', 'Don');
  assert.equal(await evaluate("!!document.querySelector('dialog [name=nbCalendriers], dialog [name=montant], dialog [name=moyenPaiement]')"), false);
  await fill('commentaire', 'Don signalé sans montant.');
  await submitDialog();
  await waitActivity('.tour-stats', 2, 20, 1, 3);

  await reloadPage();
  await wait("!!document.querySelector('nav') && document.body.innerText.includes('Suivi des tournées')");
  await click('Tournées');
  await wait(`document.body.innerText.includes(${JSON.stringify(tour)})`);
  await click(tour);
  await waitActivity('.tour-stats', 2, 20, 1, 3);
  assert.equal(await evaluate("document.querySelectorAll('.team-list li').length"), 2);
  await click('Modifier');
  assert.equal(await evaluate("document.querySelector('dialog [name=statut]').value"), 'DON');
  assert.equal(await evaluate("document.querySelector('dialog [name=commentaire]').value"), 'Don signalé sans montant.');
  assert.equal(await evaluate("document.querySelector('dialog [name=recuId]').selectedOptions[0].textContent"), `N° ${firstReceipt} (reçu actuel)`);
  assert.equal(await evaluate("!!document.querySelector('dialog [name=montant], dialog [name=moyenPaiement]')"), false);
  await select('statut', 'Vente');
  await fill('nbCalendriers', '2');
  await fill('montant', '12.30');
  await submitDialog();
  await waitActivity('.tour-stats', 3, 20, 1, 3);
  // Create additional apartments directly from the first address, each with a separate result.
  const passageTable = 'table[aria-label="Résultats des passages"]';
  await wait(`!!document.querySelector('${passageTable}')`);
  for (const [apartment, status] of [['102', 'Refus'], ['103', 'Absent'], ['104', 'À revoir'], ['105', 'Don']]) {
    await clickAddress('3 rue du navigateur', 'button[aria-label^="Ajouter un appartement"]');
    await wait("!!document.querySelector('dialog [name=appartement]')");
    for (const field of ['batiment', 'etage', 'appartement', 'porte']) {
      assert.equal(await evaluate(`document.querySelector('dialog [name=${field}]').value`), '');
    }
    if (apartment !== '102') {
      await fill('batiment', 'A');
      await fill('etage', '1');
      await fill('porte', 'Gauche');
    }
    assert.equal(await evaluate("document.querySelector('dialog [name=carnetId]').value"), '');
    assert.equal(await evaluate("document.querySelector('dialog [name=commentaire]').value"), '');
    await fill('appartement', apartment);
    await select('statut', status);
    if (status === 'Don') {
      await select('carnetId', `Carnet ${bookNumber}`);
      assert.equal(await evaluate(`[...document.querySelector('dialog [name=recuId]').options].some(o => o.textContent === 'N° ${firstReceipt}')`), false);
      await select('recuId', `N° ${firstReceipt + 2}`);
    }
    await submitDialog();
    await wait(`document.querySelector('${passageTable}')?.innerText.includes('Appartement ${apartment}')`);
    if (apartment === '102') assert.equal(await evaluate(`[...document.querySelectorAll('${passageTable} .housing-cell')].find(cell => cell.textContent.includes('Appartement 102')).textContent`), 'Appartement 102');
  }
  assert.equal(await evaluate(`document.querySelectorAll('${passageTable} tbody tr').length`), 7);
  assert.match(await evaluate(`[...document.querySelectorAll('${passageTable} .housing-cell')].find(cell => cell.textContent.includes('Appartement 101')).innerText`), /Bâtiment A · Étage 1 · Appartement 101 · Porte Gauche/);
  for (const [status, color] of [['vente', 'rgb(22, 101, 52)'], ['don', 'rgb(22, 101, 52)'], ['refus', 'rgb(153, 27, 27)'], ['absent', 'rgb(154, 52, 18)'], ['a_revoir', 'rgb(154, 52, 18)']]) {
    assert.equal(await evaluate(`getComputedStyle(document.querySelector('.status-${status}')).color`), color);
  }
  await select('filtreRue', 'rue du navigateur');
  await wait(`document.querySelectorAll('${passageTable} tbody tr').length === 6`);
  await select('filtreStatut', 'Don');
  await wait(`document.querySelectorAll('${passageTable} tbody tr').length === 2`);
  await select('filtreCarnet', `Carnet ${bookNumber}`);
  await fill('rechercheRecu', String(firstReceipt));
  await wait("document.body.innerText.includes('Aucune adresse ne correspond aux filtres')");
  await select('filtreStatut', 'Tous les résultats');
  await wait(`document.querySelectorAll('${passageTable} tbody tr').length === 1`);
  await click('Modifier');
  for (const [field, expected] of [['batiment', 'A'], ['etage', '1'], ['appartement', '101'], ['porte', 'Gauche']]) {
    assert.equal(await evaluate(`document.querySelector('dialog [name=${field}]').value`), expected);
  }
  await select('moyenPaiement', 'CB');
  await submitDialog();
  await wait(`document.querySelectorAll('${passageTable} tbody tr').length === 1`);
  assert.equal(await evaluate("document.querySelector('[name=rechercheRecu]').value"), String(firstReceipt));
  await fill('rechercheRecu', '999999999');
  await wait("document.body.innerText.includes('Aucune adresse ne correspond aux filtres')");
  await click('Réinitialiser les filtres et le tri');
  await wait(`document.querySelectorAll('${passageTable} tbody tr').length === 7`);
  await select('filtreCarnet', 'Sans carnet');
  await wait(`document.querySelectorAll('${passageTable} tbody tr').length === 4`);
  await click('Réinitialiser les filtres et le tri');
  await select('triPassages', 'Montant');
  await select('ordrePassages', 'Décroissant');
  assert.equal(await evaluate(`document.querySelector('${passageTable} tbody tr .passage-status').textContent`), 'Vente');
  await select('ordrePassages', 'Croissant');
  assert.equal(await evaluate(`[...document.querySelectorAll('${passageTable} tbody tr')].at(-1).querySelector('.passage-status').textContent`), 'Vente');
  await select('triPassages', 'Résultat');
  assert.equal(await evaluate(`[...document.querySelectorAll('${passageTable} tbody tr')].at(-1).querySelector('.passage-status').textContent`), 'Vente');
  await click('Réinitialiser les filtres et le tri');
  assert.match(await evaluate(`document.querySelector('${passageTable} tbody tr td').textContent`), /^20 avenue/);
  assert.match(await evaluate(`[...document.querySelectorAll('${passageTable} tbody tr')].at(-1).cells[0].textContent`), /^4 rue/);
  await evaluate(`document.querySelector('${passageTable} th button').click()`);
  await wait(`document.querySelector('${passageTable} th').getAttribute('aria-sort') === 'descending'`);
  assert.match(await evaluate(`document.querySelector('${passageTable} tbody tr td').textContent`), /^4 rue/);
  await click('Réinitialiser les filtres et le tri');
  await reloadPage();
  await wait("!!document.querySelector('nav') && document.body.innerText.includes('Suivi des tournées')");
  await click('Tournées');
  await click(tour);
  await wait(`document.querySelectorAll('${passageTable} tbody tr').length === 7`);
  assert.equal(await evaluate(`document.querySelectorAll('${passageTable} .housing-cell').length`), 7);
  await evaluate("document.querySelector('.passages-panel').scrollIntoView({block:'start'})");
  const passageScreenshot = await command('Page.captureScreenshot', { format: 'png' });
  await writeFile('/tmp/calendriersp-passages.png', Buffer.from(passageScreenshot.data, 'base64'));
  await command('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  assert.equal(await evaluate('document.documentElement.scrollWidth <= window.innerWidth'), true);
  const mobilePassages = await command('Page.captureScreenshot', { format: 'png' });
  await writeFile('/tmp/calendriersp-passages-mobile.png', Buffer.from(mobilePassages.data, 'base64'));
  await command('Emulation.setDeviceMetricsOverride', { width: 1280, height: 1000, deviceScaleFactor: 1, mobile: false });
  await select('statut', 'Terminée');
  await click('Modifier le statut');
  await wait("document.body.innerText.includes('Valider et verrouiller la tournée')");
  await click('Valider et verrouiller la tournée');
  await submitDialog();
  await wait("document.body.innerText.includes('Les saisies sont verrouillées')");
  assert.equal(await evaluate("!!document.querySelector('.team-options')"), false);
  assert.equal(await evaluate("[...document.querySelectorAll('button')].find(e => e.textContent === 'Modifier').disabled"), true);
  await click('Tableau de bord');
  await wait("document.body.innerText.includes('Suivi des tournées')");
  assert.equal(await evaluate(`document.body.innerText.includes('Suivi des carnets') && document.body.innerText.includes(${JSON.stringify(bookNumber)})`), true);
  const bookRow = await evaluate(`[...document.querySelectorAll('tr')].find(r => r.innerText.includes(${JSON.stringify(bookNumber)})).innerText`);
  assert.match(bookRow, /12,30/);

  // Draw a real polygon, save it, preview the upstream addresses and import them.
  const geographicSector = `Secteur carte ${suffix}`;
  await click('Secteurs');
  await click('＋ Ajouter');
  await fill('nom', geographicSector);
  await submitDialog();
  const openSector = async () => {
    await wait(`document.body.innerText.includes(${JSON.stringify(geographicSector)})`);
    await evaluate(`[...document.querySelectorAll('tr')].find(r => r.innerText.includes(${JSON.stringify(geographicSector)})).querySelector('button').click()`);
    await wait("!!document.querySelector('dialog .leaflet-container')");
  };
  await openSector();
  await fill('Rechercher un lieu', 'Paris');
  await click('Rechercher');
  await wait("document.body.innerText.includes('Paris, France (test)')");
  await click('Paris, France (test)');
  await delay(500);
  await click('Dessiner un polygone');
  assert.equal(await evaluate("document.querySelector('dialog button[type=submit]').disabled"), true);
  await evaluate("document.querySelector('dialog .sector-map').scrollIntoView({block:'center'})");
  const mapBox = await evaluate("(() => { const r = document.querySelector('dialog .sector-map').getBoundingClientRect(); return {x:r.x+r.width/2,y:r.y+r.height/2}; })()");
  for (const [dx, dy] of [[-90,-80],[90,-80],[90,80],[-90,80]]) {
    await command('Input.dispatchMouseEvent', { type: 'mousePressed', x: mapBox.x + dx, y: mapBox.y + dy, button: 'left', clickCount: 1 });
    await command('Input.dispatchMouseEvent', { type: 'mouseReleased', x: mapBox.x + dx, y: mapBox.y + dy, button: 'left', clickCount: 1 });
    await delay(50);
  }
  await wait("document.body.innerText.includes('4 sommets')");
  await click('Terminer le polygone');
  await click('Enregistrer');
  await wait("document.body.innerText.includes('Secteur et polygone enregistrés.')");
  await click('Extraire les adresses');
  assert.equal(await evaluate("document.querySelector('dialog [name=source]').value"), 'BAN');
  await click('Rechercher les adresses du polygone');
  await wait("document.body.innerText.includes('Aperçu de l’extraction')");
  assert.equal(await evaluate("document.querySelector('.import-preview').innerText.includes('Base Adresse Nationale (BAN)')"), true);
  await click('Importer 4 adresses');
  await wait("document.body.innerText.includes('4 adresses importées, 1 doublons ignorés.')");
  await click('Rechercher les adresses du polygone');
  await wait("document.body.innerText.includes('Importer 0 adresses')");
  await select('source', 'OpenStreetMap');
  assert.equal(await evaluate("!!document.querySelector('.import-preview')"), false);
  await fill('codePostal', '75004');
  await fill('ville', 'Paris');
  await click('Rechercher les adresses du polygone');
  await wait("document.body.innerText.includes('Aperçu de l’extraction')");
  assert.match(await evaluate("document.querySelector('.import-preview').innerText"), /5 adresses dans le polygone : 2 nouvelles, 3 doublons ignorés/);
  await writeFile('/tmp/calendriersp-secteur-carte.png', Buffer.from((await command('Page.captureScreenshot', { format: 'png' })).data, 'base64'));
  await click('Importer 2 adresses');
  await wait("document.body.innerText.includes('2 adresses importées, 3 doublons ignorés.')");
  await click('Rechercher les adresses du polygone');
  await wait("document.body.innerText.includes('Importer 0 adresses')");
  assert.equal(await evaluate("[...document.querySelectorAll('dialog button')].find(b => b.textContent === 'Importer 0 adresses').disabled"), true);
  await evaluate("document.querySelector('dialog [aria-label=Fermer]').click()");
  await wait(`!![...document.querySelectorAll('tr')].find(r => r.innerText.includes(${JSON.stringify(geographicSector)}) && r.innerText.includes('Polygone défini') && r.cells[3].textContent === '6')`);
  await reloadPage();
  await wait("!!document.querySelector('nav')");
  await click('Secteurs');
  await openSector();
  assert.equal(await evaluate("document.body.innerText.includes('Modifier le tracé')"), true);
  await evaluate("document.querySelector('dialog [aria-label=Fermer]').click()");
  await click('Adresses');
  await wait("document.body.innerText.includes('1 rue du Polygone') && document.body.innerText.includes('6 rue du Polygone')");
  await click('Tableau de bord');
  await wait("document.body.innerText.includes('Suivi des tournées')");
  // Dependency checks are shown before confirmation, including a used sector.
  await click('Secteurs');
  const removeRow = async name => {
    await wait(`!![...document.querySelectorAll('tr')].find(r => r.innerText.includes(${JSON.stringify(name)}))`);
    await evaluate(`[...document.querySelectorAll('tr')].find(r => r.innerText.includes(${JSON.stringify(name)})).querySelector('button.danger').click()`);
    await wait("!!document.querySelector('dialog') && !document.body.innerText.includes('Vérification des liens…')");
  };
  await removeRow(geographicSector);
  await wait("document.body.innerText.includes('Suppression bloquée')");
  assert.equal(await evaluate("!!document.querySelector('dialog button[type=submit]')"), false);
  assert.match(await evaluate("document.querySelector('dialog').innerText"), /6 adresses du secteur/);
  await evaluate("document.querySelector('dialog [aria-label=Fermer]').click()");
  const disposableSector = `Secteur suppression ${suffix}`;
  await click('＋ Ajouter');
  await fill('nom', disposableSector);
  await submitDialog();
  await removeRow(disposableSector);
  await wait("document.body.innerText.includes('Supprimer définitivement')");
  // Opening the confirmation has no destructive effect.
  await evaluate("document.querySelector('dialog [aria-label=Fermer]').click()");
  assert.equal(await evaluate(`document.body.innerText.includes(${JSON.stringify(disposableSector)})`), true);
  await removeRow(disposableSector);
  await click('Supprimer définitivement');
  await wait(`!document.querySelector('dialog') && !document.body.innerText.includes(${JSON.stringify(disposableSector)})`);
  await click('Adresses');
  await removeRow('7 rue du Polygone');
  await wait("document.body.innerText.includes('Supprimer définitivement')");
  await click('Supprimer définitivement');
  await wait("!document.querySelector('dialog') && !document.body.innerText.includes('7 rue du Polygone')");

  await click('Sapeurs');
  await click('＋ Ajouter');
  const disposableEmail = `suppression-${suffix}@example.test`;
  await fill('nom', `Sapeur temporaire ${suffix}`);
  await fill('email', disposableEmail);
  await fill('password', 'Browser-deletion-123!');
  await submitDialog();
  await removeRow(disposableEmail);
  await wait("document.body.innerText.includes('Supprimer définitivement')");
  await click('Supprimer définitivement');
  await wait(`!document.querySelector('dialog') && !document.body.innerText.includes(${JSON.stringify(disposableEmail)})`);

  await click('Tournées');
  await click('＋ Ajouter');
  const disposableTour = `Tournée suppression ${suffix}`;
  await select('secteurId', geographicSector);
  await fill('nom', disposableTour);
  await fill('dateDebut', '2026-11-10');
  await submitDialog();
  await removeRow(disposableTour);
  await wait("document.body.innerText.includes('Supprimer définitivement')");
  await click('Supprimer définitivement');
  await wait(`!document.querySelector('dialog') && !document.body.innerText.includes(${JSON.stringify(disposableTour)})`);

  await click('Campagne');
  await click('＋ Ajouter une campagne');
  await fill('annee', '2088');
  await submitDialog();
  await waitActivity('.stats', 0, 0, 0, 0);
  await removeRow('2088');
  await wait("document.body.innerText.includes('Supprimer définitivement')");
  await click('Supprimer définitivement');
  await wait("!document.querySelector('dialog') && ![...document.querySelectorAll('tr')].some(r => r.cells[0]?.textContent === '2088')");
  await click('Tableau de bord');
  await wait("document.body.innerText.includes('Suivi des tournées')");
  // New street addresses can be entered directly on an empty tour, with the sale in one form.
  const exceptionalSector = `Secteur exceptionnel ${suffix}`;
  const exceptionalTour = `Tournée exceptionnelle ${suffix}`;
  await click('Secteurs');
  await click('＋ Ajouter');
  await fill('nom', exceptionalSector);
  await submitDialog();
  await wait(`document.body.innerText.includes(${JSON.stringify(exceptionalSector)})`);
  await click('Tournées');
  await click('＋ Ajouter');
  await select('secteurId', exceptionalSector);
  await fill('nom', exceptionalTour);
  await fill('dateDebut', '2026-11-12');
  await submitDialog();
  await click(exceptionalTour);
  await wait("document.body.innerText.includes('Aucune adresse dans ce secteur')");
  await click('＋ Nouvelle adresse');
  assert.equal(await evaluate("document.querySelector('dialog [name=ligne1]').value"), '');
  for (const field of ['batiment', 'etage', 'appartement', 'porte']) {
    assert.equal(await evaluate(`document.querySelector('dialog [name=${field}]').value`), '');
  }
  await evaluate("document.querySelector('dialog [aria-label=Fermer]').click()");
  await click('＋ Ajouter un carnet');
  const exceptionalBook = `Exception-${suffix}`;
  await fill('numero', exceptionalBook);
  await fill('premierRecu', String(firstReceipt + 100));
  await submitDialog();
  const exceptionalAddresses = ['10 Rue Traversière', '12 T Rue Traversière', '14bis Rue Traversière', '16 B Rue Traversière', '18B Rue Traversière', '20 avenue Nouvelle'];
  for (const [index, streetAddress] of exceptionalAddresses.entries()) {
    if (index > 0) await select('filtreRue', 'Rue Traversière');
    await click('＋ Nouvelle adresse');
    await fill('ligne1', streetAddress);
    await fill('codePostal', '10000');
    await fill('ville', 'Troyes');
    await select('carnetId', `Carnet ${exceptionalBook}`);
    await select('recuId', `N° ${firstReceipt + 100 + index}`);
    await fill('montant', '5.00');
    await submitDialog();
    await wait(`document.querySelector('${passageTable}')?.innerText.includes(${JSON.stringify(streetAddress)})`);
    assert.equal(await evaluate("document.querySelector('[name=filtreRue]').value"), '');
    assert.equal(await evaluate(`document.querySelectorAll('${passageTable} tbody tr').length`), index + 1);
  }
  const streetOptions = await evaluate("[...document.querySelector('[name=filtreRue]').options].map(o => o.textContent)");
  await waitActivity('.tour-stats', 6, 0, 0, 6);
  assert.equal(await evaluate("document.querySelector('.tour-stats').innerText.includes('Stock à renseigner')"), true);
  assert.deepEqual(streetOptions, ['Toutes les rues', 'avenue Nouvelle', 'Rue Traversière']);
  await select('filtreRue', 'Rue Traversière');
  await wait(`document.querySelectorAll('${passageTable} tbody tr').length === 5`);
  await reloadPage();
  await wait("!!document.querySelector('nav') && document.body.innerText.includes('Suivi des tournées')");
  await click('Tournées');
  await click(exceptionalTour);
  await wait(`document.querySelectorAll('${passageTable} tbody tr').length === 6`);
  await select('filtreRue', 'Rue Traversière');
  await wait(`document.querySelectorAll('${passageTable} tbody tr').length === 5`);
  await click('Tableau de bord');
  await wait("document.body.innerText.includes('Suivi des tournées')");
  await command('Network.enable');
  await command('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
  await click('Actualiser');
  await wait("document.body.innerText.includes('Impossible de joindre le serveur')");
  assert.equal(await evaluate("document.body.innerText.includes('Suivi des tournées')"), false);
  await command('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
  await click('Réessayer');
  await wait("document.body.innerText.includes('Suivi des tournées')");
  await command('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  const screenshot = await command('Page.captureScreenshot', { format: 'png' });
  await writeFile('/tmp/calendriersp-mobile-web.png', Buffer.from(screenshot.data, 'base64'));
  await click('Déconnexion');
  await wait("!!document.querySelector('[name=amicaleId]')");
  assert.equal(exceptions.length, 0, JSON.stringify(exceptions));
  console.log('OK: separate calendar and address ratios in tour cards, tour lists, dashboard and campaigns; donation counting, corrections and zero stock; multiple team selection, persistence, individual removal and validation lock; CB payments, apartment creation and persistence, result colors, cumulative street/status/book filters, exact receipt search, sorting and filter retention; browser deletion confirmations and dependency blocking for sectors, addresses, users, tours and campaigns; polygon drawing, address import and persistence; receipts, sales/donations, dashboard, validation, offline retry and logout.');
} finally {
  socket?.close();
  chrome.kill('SIGTERM');
  await delay(300);
  await rm(profileDir, { recursive: true, force: true, maxRetries: 3 });
}

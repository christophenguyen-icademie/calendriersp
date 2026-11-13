// End-to-end test of the actual exported MobileSP web app against compose.test.yml.
// First export MobileSP with EXPO_PUBLIC_CALENDRIERS_API_URL=http://127.0.0.1:18080/api.
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { mkdtemp, rm, writeFile, readFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { tmpdir } from 'node:os';
import { join, resolve, extname } from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
const exportDir = resolve(process.env.MOBILESP_WEB_EXPORT || '/tmp/calendriersp-mobilesp-web');
await readFile(join(exportDir, 'index.html'));
const server = createServer(async (req, res) => {
  try {
    let file = resolve(exportDir, '.' + decodeURIComponent(new URL(req.url, 'http://localhost').pathname));
    if (!file.startsWith(exportDir + '/')) file = join(exportDir, 'index.html');
    let content;
    try { content = await readFile(file); } catch { file = join(exportDir, 'index.html'); content = await readFile(file); }
    res.setHeader('Content-Type', ({ '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.json': 'application/json', '.png': 'image/png', '.woff': 'font/woff', '.ttf': 'font/ttf' })[extname(file)] || 'application/octet-stream');
    res.end(content);
  } catch { res.statusCode = 500; res.end('Export unavailable'); }
});
await new Promise((ok, fail) => { server.once('error', fail); server.listen(18082, '127.0.0.1', ok); });
const accounts = JSON.parse(execFileSync('docker', ['compose', '-f', 'compose.test.yml', 'exec', '-T', 'backend', 'cat', '/tmp/test-accounts.json'], { encoding: 'utf8' }));
const profileDir = await mkdtemp(join(tmpdir(), 'mobilesp-browser-'));
const chrome = spawn(process.env.CHROME_BIN || 'google-chrome', ['--headless', '--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage', '--no-first-run', '--remote-debugging-address=127.0.0.1', '--remote-debugging-port=19223', `--user-data-dir=${profileDir}`, 'about:blank'], { stdio: 'ignore' });
let socket;
try {
  let tabs;
  for (let i = 0; i < 100; i++) {
    try { tabs = await (await fetch('http://127.0.0.1:19223/json')).json(); break; } catch { await delay(100); }
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
    await evaluate("document.querySelector('[role=button][aria-label^=\"Afficher les filtres et le tri\"]')?.click()");
    await wait("!!document.querySelector('select[aria-label=Rue]')");
  };
  const fill = async (name, value) => {
    if (name === 'Rechercher un reçu') await ensureFilters();
    return evaluate(`(() => {
    const el = document.querySelector('[aria-label=${JSON.stringify(name)}]');
    if (!el) throw new Error('Field missing: ' + ${JSON.stringify(name)});
    const prototype = el.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype;
    Object.getOwnPropertyDescriptor(prototype, 'value').set.call(el, ${JSON.stringify(value)});
    el.dispatchEvent(new Event('input', { bubbles: true }));
    el.dispatchEvent(new Event('change', { bubbles: true }));
  })()`);
  };
  const click = async label => {
    if (label === 'Réinitialiser les filtres') await ensureFilters();
    await wait(`!![...document.querySelectorAll('[role=button],button')].find(e => e.textContent.trim() === ${JSON.stringify(label)} && !e.disabled && e.getAttribute('aria-disabled') !== 'true')`);
    return evaluate(`(() => {
    const el = [...document.querySelectorAll('[role=button],button')].find(e => e.textContent.trim() === ${JSON.stringify(label)});
    if (!el) throw new Error('Button missing: ' + ${JSON.stringify(label)});
    el.click();
  })()`);
  };
  const clickAddress = async (action, line) => {
    const prefix = `${action}${action.startsWith('Ajouter ') ? '' : ' le passage'} · ${line}`;
    await wait(`[...document.querySelectorAll('[role=button]')].some(e => e.getAttribute('aria-label')?.startsWith(${JSON.stringify(prefix)}) && e.getAttribute('aria-disabled') !== 'true')`);
    await evaluate(`[...document.querySelectorAll('[role=button]')].find(e => e.getAttribute('aria-label')?.startsWith(${JSON.stringify(prefix)})).click()`);
  };
  const select = async (name, label) => {
    if (['Rue', 'Filtrer par résultat', 'Filtrer par carnet', 'Trier par', 'Ordre'].includes(name)) await ensureFilters();
    await wait(`(() => {
      const el = document.querySelector('select[aria-label=${JSON.stringify(name)}]');
      return !!el && [...el.options].some(o => o.textContent === ${JSON.stringify(label)} || o.textContent.startsWith(${JSON.stringify(label)}));
    })()`);
    return evaluate(`(() => {
    const el = document.querySelector('select[aria-label=${JSON.stringify(name)}]');
    if (!el) throw new Error('Select missing: ' + ${JSON.stringify(name)});
    const option = [...el.options].find(o => o.textContent === ${JSON.stringify(label)} || o.textContent.startsWith(${JSON.stringify(label)}));
    if (!option) throw new Error('Option missing: ' + ${JSON.stringify(label)});
    el.value = option.value;
    el.dispatchEvent(new Event('change', { bubbles: true }));
  })()`);
  };
  const suffix = Date.now();
  // This setup can only target the isolated test API and its seeded tenants.
  let cookie = '', csrf = '';
  const api = async (path, method = 'GET', body) => {
    const response = await fetch('http://127.0.0.1:18080/api' + path, { method,
      headers: { Cookie: cookie, 'X-CSRF-Token': csrf, ...(body ? { 'Content-Type': 'application/json' } : {}) },
      ...(body ? { body: JSON.stringify(body) } : {}),
    });
    const setCookie = response.headers.getSetCookie();
    if (setCookie.length) cookie = setCookie.map(c => c.split(';')[0]).join('; ');
    const data = await response.json();
    assert(response.ok, `${method} ${path}: ${JSON.stringify(data)}`);
    return data;
  };
  csrf = (await api('/session')).csrfToken;
  await api('/login', 'POST', accounts.B);
  csrf = (await api('/session')).csrfToken;
  const sector = await api('/secteurs', 'POST', { nom: `Secteur MobileSP ${suffix}` });
  const source = await api('/adresses', 'POST', { secteurId: sector.id, ligne1: '12 T Rue Traversière', codePostal: '10000', ville: 'Troyes', batiment: 'A', etage: '2', appartement: '4', porte: 'Gauche' });
  const tour = await api('/tournees', 'POST', { nom: `Tournée MobileSP ${suffix}`, secteurId: sector.id, dateDebut: '2026-11-10', stockCalendriers: 50 });
  const privateTour = await api('/tournees', 'POST', { nom: `Tournée privée ${suffix}`, secteurId: sector.id, dateDebut: '2026-11-10' });
  const bookName = `Mobile-${suffix}`, receipt = 500000000 + suffix % 1000000;
  const connected = () => wait("document.body.innerText.includes('Mes tournées') && !document.querySelector('[aria-label=\"Mot de passe\"]')");
  const saved = () => wait("document.body.innerText.includes('Passage enregistré sur le serveur.') && !document.querySelector('[aria-label=\"Date du passage\"]')");
  const login = async account => {
    await fill('Identifiant de l’amicale', account.amicaleId.toUpperCase());
    await fill('E-mail', account.email); await fill('Mot de passe', account.password);
    await click('Se connecter'); await connected();
    await select('Campagne', '2026');
  };
  const openTour = async () => {
    await click(`Ouvrir ${tour.nom}`);
    await wait("document.body.innerText.includes('adresses affichées')");
    assert.equal(await evaluate("!!document.querySelector('select[aria-label=Rue]')"), false);
  };
  const memoryKey = 'mobilesp.calendriers.connexion.v1';
  const memorySwitch = '[role=switch][aria-label="Mémoriser mes informations de connexion"]';
  const toggleMemory = async () => {
    await wait(`!!document.querySelector(${JSON.stringify(memorySwitch)}) && !document.querySelector(${JSON.stringify(memorySwitch)}).disabled`);
    await evaluate(`document.querySelector(${JSON.stringify(memorySwitch)}).click()`);
  };
  const counter = (distributed, sold, donated, total = 50) => wait(`(() => {
    const text = document.querySelector('[data-testid="bilan-calendriers"]')?.textContent || '';
    return text.includes(${JSON.stringify(`${distributed} / ${total}`)}) && text.includes(${JSON.stringify(`${sold} vendus · ${donated} donnés`)});
  })()`);
  await command('Runtime.enable'); await command('Network.enable'); await command('Page.enable');
  await command('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 1, mobile: true });
  await command('Page.navigate', { url: 'http://127.0.0.1:18082/calendrier' });
  await wait("!!document.querySelector('[aria-label=\"Identifiant de l’amicale\"]')");
  assert.equal(await evaluate("document.querySelector('[aria-label=\"Adresse de l’API\"]').value"), 'http://127.0.0.1:18080/api');
  // No application storage without consent, including after a successful login.
  assert.equal(await evaluate(`document.querySelector(${JSON.stringify(memorySwitch)}).checked`), false);
  await login(accounts.B);
  assert.equal(await evaluate(`localStorage.getItem(${JSON.stringify(memoryKey)})`), null);
  await click('Déconnexion'); await wait("!!document.querySelector('[aria-label=\"Mot de passe\"]')");

  // Opt-in writes only authenticated credentials, then restores all three fields.
  await toggleMemory();
  await fill('Identifiant de l’amicale', accounts.B.amicaleId);
  await fill('E-mail', accounts.B.email); await fill('Mot de passe', 'Incorrect-test-password');
  await click('Se connecter'); await wait("!!document.querySelector('[role=alert]')");
  assert.equal(await evaluate(`localStorage.getItem(${JSON.stringify(memoryKey)})`), null);
  await login(accounts.B);
  assert.equal(await evaluate(`JSON.parse(localStorage.getItem(${JSON.stringify(memoryKey)})).amicale`), accounts.B.amicaleId);
  await click('Déconnexion'); await wait("!!document.querySelector('[aria-label=\"Mot de passe\"]')");
  await reloadPage();
  await wait(`document.querySelector(${JSON.stringify(memorySwitch)})?.checked === true && !document.querySelector(${JSON.stringify(memorySwitch)}).disabled`);
  for (const [label, expected] of [['Identifiant de l’amicale', accounts.B.amicaleId], ['E-mail', accounts.B.email], ['Mot de passe', accounts.B.password]]) {
    assert.equal(await evaluate(`document.querySelector('[aria-label=${JSON.stringify(label)}]').value`), expected);
  }
  assert.equal(await evaluate("document.querySelector('[aria-label=\"Mot de passe\"]').type"), 'password');

  // Disabling the choice deletes the data immediately, without another login.
  await toggleMemory();
  await wait(`localStorage.getItem(${JSON.stringify(memoryKey)}) === null && !document.querySelector(${JSON.stringify(memorySwitch)}).checked`);
  await reloadPage();
  await wait(`!!document.querySelector(${JSON.stringify(memorySwitch)}) && !document.querySelector(${JSON.stringify(memorySwitch)}).disabled`);
  for (const label of ['Identifiant de l’amicale', 'E-mail', 'Mot de passe']) {
    assert.equal(await evaluate(`document.querySelector('[aria-label=${JSON.stringify(label)}]').value`), '');
  }
  await toggleMemory(); await login(accounts.B);
  await click('Oublier mes informations de connexion');
  await wait("document.body.innerText.includes('Informations de connexion effacées de cet appareil.')");
  assert.equal(await evaluate(`localStorage.getItem(${JSON.stringify(memoryKey)})`), null);
  await openTour(); await counter(0, 0, 0);
  await click('Ajouter un carnet');
  assert.equal(await evaluate("document.querySelector('[aria-label=\"Nombre de reçus\"]').value"), '25');
  await fill('Numéro du carnet', bookName); await fill('Premier reçu', String(receipt));
  await click('Créer le carnet');
  await wait("document.body.innerText.includes('Carnet créé sur le serveur.') && document.body.innerText.includes('adresses affichées')");
  await clickAddress('Saisir', source.ligne1);
  assert.equal(await evaluate("document.querySelector('[aria-label=Porte]').value"), 'Gauche');
  await fill('Date du passage', '10/11/2026'); await fill('Nombre de calendriers', '2'); await fill('Montant total encaissé (€)', '23,45');
  await select('Moyen de paiement', 'CB'); await select('Carnet de reçus', bookName); await select('Numéro du reçu', `N° ${receipt}`);
  await fill('Commentaire', 'Vente CB depuis MobileSP'); await click('Enregistrer le passage'); await saved();
  await counter(2, 2, 0);
  await clickAddress('Modifier', source.ligne1); await fill('Nombre de calendriers', '3');
  await click('Enregistrer le passage'); await saved(); await counter(3, 3, 0);
  await clickAddress('Modifier', source.ligne1); await fill('Nombre de calendriers', '2');
  await click('Enregistrer le passage'); await saved(); await counter(2, 2, 0);
  let detail = await api(`/tournees/${tour.id}`);
  assert.equal(detail.montant, '23.45'); assert.equal(detail.listeAdresses[0].passage.moyenPaiement, 'CB');
  assert.equal(detail.listeAdresses[0].passage.datePassage, '2026-11-10');
  await clickAddress('Ajouter un appartement', source.ligne1);
  for (const name of ['Bâtiment', 'Étage', 'Appartement', 'Porte']) assert.equal(await evaluate(`document.querySelector('[aria-label=${JSON.stringify(name)}]').value`), '');
  await fill('Bâtiment', 'B'); await fill('Étage', '3'); await fill('Appartement', '12'); await fill('Porte', 'Droite');
  await select('Résultat du passage', 'Don');
  assert.equal(await evaluate("!!document.querySelector('[aria-label=\"Montant total encaissé (€)\"]') || !!document.querySelector('select[aria-label=\"Moyen de paiement\"]') || !!document.querySelector('[aria-label=\"Nombre de calendriers\"]')"), false);
  await select('Carnet de reçus', bookName); await select('Numéro du reçu', `N° ${receipt + 1}`);
  assert.equal(await evaluate(`!![...document.querySelector('select[aria-label="Numéro du reçu"]').options].find(o => o.textContent === 'N° ${receipt}')`), false);
  await fill('Commentaire', 'Don appartement'); await click('Enregistrer le passage'); await saved();
  await counter(3, 2, 1);
  await clickAddress('Ajouter un appartement', source.ligne1);
  for (const name of ['Bâtiment', 'Étage', 'Appartement', 'Porte']) assert.equal(await evaluate(`document.querySelector('[aria-label=${JSON.stringify(name)}]').value`), '');
  await click('Retour aux adresses');
  const createAddress = async (line, result, amount, recu) => {
    await click('Nouvelle adresse'); await fill('Numéro et voie', line); await fill('Code postal', '10000'); await fill('Ville', 'Troyes');
    await select('Résultat du passage', result);
    if (amount) { await fill('Montant total encaissé (€)', amount); await select('Carnet de reçus', bookName); await select('Numéro du reçu', `N° ${recu}`); }
    await click('Enregistrer le passage'); await saved();
  };
  await createAddress('20 avenue Nouvelle', 'Vente', '7,55', receipt + 2);
  await createAddress('14bis Rue Traversière', 'Refus');
  await createAddress('18B Rue Traversière', 'Absent');
  detail = await api(`/tournees/${tour.id}`);
  assert.equal(detail.montant, '31.00'); assert.equal(detail.listeAdresses.length, 5);
  assert.equal(detail.carnets[0].recusUtilises, 3); assert.equal(detail.carnets[0].dons, 1);
  await counter(4, 3, 1);
  await ensureFilters();
  assert.deepEqual(await evaluate("[...document.querySelector('select[aria-label=Rue]').options].map(o => o.textContent)"), ['Toutes les rues', 'avenue Nouvelle', 'Rue Traversière']);
  await select('Rue', 'Rue Traversière'); await select('Filtrer par résultat', 'Don'); await select('Filtrer par carnet', bookName);
  await fill('Rechercher un reçu', String(receipt + 1));
  await wait("document.body.innerText.includes('1 / 5 adresses affichées')");
  await click('Masquer les filtres (4 actifs)');
  assert.equal(await evaluate("!!document.querySelector('select[aria-label=Rue]')"), false);
  assert.equal(await evaluate("document.body.innerText.includes('1 / 5 adresses affichées')"), true);
  await ensureFilters();
  assert.equal(await evaluate("document.querySelector('[aria-label=\"Rechercher un reçu\"]').value"), String(receipt + 1));
  await counter(4, 3, 1);
  await fill('Rechercher un reçu', String(receipt)); await wait("document.body.innerText.includes('0 / 5 adresses affichées')");
  await click('Réinitialiser les filtres'); await wait("document.body.innerText.includes('5 / 5 adresses affichées')");
  await reloadPage(); await connected(); await select('Campagne', '2026'); await openTour();
  await wait("document.body.innerText.includes('Appartement 12') && document.body.innerText.includes('Porte Droite') && document.body.innerText.includes('Passage du 10/11/2026')");

  // A concurrent receipt consumption must keep the form and create no orphan address.
  await click('Nouvelle adresse'); await fill('Numéro et voie', '22 rue Conflit'); await fill('Code postal', '10000'); await fill('Ville', 'Troyes');
  await fill('Montant total encaissé (€)', '2'); await select('Carnet de reçus', bookName); await select('Numéro du reçu', `N° ${receipt + 3}`);
  const book = detail.carnets[0], target = detail.listeAdresses.find(a => a.ligne1.startsWith('18B'));
  await api(`/tournees/${tour.id}/passages/${target.id}`, 'PUT', { statut: 'DON', carnetId: book.id, recuId: book.recus[3].id });
  await click('Enregistrer le passage');
  await wait("!!document.querySelector('[role=alert]') && !!document.querySelector('[aria-label=\"Numéro et voie\"]') && ![...document.querySelector('select[aria-label=\"Numéro du reçu\"]').options].some(o => o.textContent === 'N° " + (receipt + 3) + "')");
  assert.equal(await evaluate("document.querySelector('[aria-label=\"Numéro et voie\"]').value"), '22 rue Conflit');
  assert(!(await api(`/tournees/${tour.id}`)).listeAdresses.some(a => a.ligne1 === '22 rue Conflit'));
  await select('Numéro du reçu', `N° ${receipt + 4}`); await click('Enregistrer le passage'); await saved();

  // No success or automatic retry when the write response is unavailable.
  await clickAddress('Modifier', source.ligne1); await fill('Commentaire', 'Saisie conservée hors connexion');
  await command('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
  await click('Enregistrer le passage'); await wait("document.body.innerText.includes('La confirmation du serveur n’a pas été reçue')");
  assert.equal(await evaluate("document.querySelector('[aria-label=Commentaire]').value"), 'Saisie conservée hors connexion');
  await command('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
  await click('Actualiser');
  assert.equal((await api(`/tournees/${tour.id}`)).listeAdresses.find(a => a.id === source.id).passage.commentaire, 'Vente CB depuis MobileSP');
  await click('Retour aux adresses');
  await api(`/tournees/${tour.id}/statut`, 'PATCH', { statut: 'TERMINEE' }); await api(`/tournees/${tour.id}/valider`, 'POST', {});
  await click('Actualiser'); await wait("document.body.innerText.includes('Tournée validée : consultation uniquement.')");
  await counter(6, 4, 2);
  assert.equal(await evaluate("[...document.querySelectorAll('[role=button],button')].some(e => e.textContent.trim() === 'Nouvelle adresse' || /^(Ajouter un appartement|Ajouter un numéro)/.test(e.getAttribute('aria-label') || ''))"), false);
  const screenshot = await command('Page.captureScreenshot', { format: 'png' });
  await writeFile('/tmp/mobilesp-calendriers-connected.png', Buffer.from(screenshot.data, 'base64'));
  await click('Déconnexion'); await wait("!!document.querySelector('[aria-label=\"Mot de passe\"]')");

  const workerPassword = 'Mobile-test-password-123!';
  const workerName = `Camille Martin ${suffix}`;
  const worker = await api('/sapeurs', 'POST', { nom: workerName, email: `mobile-${suffix}@example.test`, password: workerPassword, role: 'ROLE_SAPEUR' });
  const workerTour = await api('/tournees', 'POST', { nom: `Tournée sapeur ${suffix}`, secteurId: sector.id, dateDebut: '2026-11-10' });
  await api(`/tournees/${workerTour.id}/affectations`, 'POST', { userId: worker.id });
  await login({ amicaleId: accounts.B.amicaleId, email: worker.email, password: workerPassword });
  await wait(`document.body.innerText.includes(${JSON.stringify('Équipe : ' + workerName)})`);
  assert.equal(await evaluate(`document.body.innerText.includes(${JSON.stringify(privateTour.nom)})`), false);
  await click(`Ouvrir ${workerTour.nom}`); await wait("document.body.innerText.includes('adresses affichées')");
  await wait(`document.body.innerText.includes(${JSON.stringify('Équipe : ' + workerName)})`);
  await counter(0, 0, 0, 0);
  await wait("document.body.innerText.includes('Stock à renseigner')");
  assert.equal(await evaluate("[...document.querySelectorAll('[role=button],button')].some(e => e.textContent.trim() === 'Ajouter un carnet')"), false);
  await clickAddress('Ajouter un numéro dans la même rue', source.ligne1);
  await wait("!!document.querySelector('[aria-label=Numéro]')");
  assert.equal(await evaluate("document.querySelector('[aria-label=Numéro]').value"), '');
  assert.equal(await evaluate("document.body.innerText.includes('Rue : Rue Traversière')"), true);
  assert.equal(await evaluate("document.querySelector('[aria-label=\"Code postal\"]').value"), '10000');
  assert.equal(await evaluate("document.querySelector('[aria-label=Ville]').value"), 'Troyes');
  for (const field of ['Bâtiment', 'Étage', 'Appartement', 'Porte']) assert.equal(await evaluate(`document.querySelector('[aria-label=${JSON.stringify(field)}]').value`), '');
  await fill('Numéro', '30 bis'); await select('Résultat du passage', 'Absent');
  await click('Enregistrer le passage'); await saved();
  let newDetail = await api(`/tournees/${workerTour.id}`);
  const newAddress = newDetail.listeAdresses.find(a => a.ligne1 === '30 bis Rue Traversière');
  assert(newAddress && newAddress.id !== source.id && newAddress.passage.statut === 'ABSENT');
  for (const field of ['batiment', 'etage', 'appartement', 'porte']) assert.equal(newAddress[field], null);
  assert.equal(newDetail.listeAdresses.find(a => a.id === source.id).ligne1, source.ligne1);
  await clickAddress('Ajouter un numéro dans la même rue', source.ligne1);
  await fill('Numéro', '30 bis'); await select('Résultat du passage', 'Absent');
  await click('Enregistrer le passage');
  await wait("!!document.querySelector('[role=alert]')");
  assert.equal(await evaluate("document.querySelector('[aria-label=Numéro]').value"), '30 bis');
  await fill('Numéro', '30 ter'); await click('Enregistrer le passage'); await saved();
  newDetail = await api(`/tournees/${workerTour.id}`);
  assert(newDetail.listeAdresses.some(a => a.ligne1 === '30 ter Rue Traversière' && a.passage.statut === 'ABSENT'));
  assert.equal(await evaluate('document.documentElement.scrollWidth <= window.innerWidth'), true);
  await click('Déconnexion'); await wait("!!document.querySelector('[aria-label=\"Mot de passe\"]')");
  await login(accounts.A);
  assert.equal(await evaluate(`document.body.innerText.includes(${JSON.stringify(workerTour.nom)})`), false);
  await click('Déconnexion');
  assert.equal(exceptions.length, 0, JSON.stringify(exceptions));
  console.log('OK: MobileSP actual web export, opt-in credential storage after successful login, masked restoration on reload, immediate opt-out and forgetting, cookie/CSRF session, sold/donated/stock counters with corrections and zero stock, server tours, same-street numbers with duplicate rejection and source preservation, receipts, filters, conflicts, offline preservation, locking, worker permissions and tenant isolation.');
} finally {
  socket?.close(); chrome.kill('SIGTERM'); server.closeAllConnections();
  await new Promise(resolve => server.close(resolve));
  await delay(300); await rm(profileDir, { recursive: true, force: true, maxRetries: 3 });
}

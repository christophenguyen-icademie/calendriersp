import { housingLabel } from './components/Housing';
import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { api, session, money, date, labels, memberName, type Address, type Campaign, type Dashboard, type Member, type Profile, type Sector, type Tour } from './api';
import { Field, Form, Modal, integer, text } from './components/Form';
import { TourDetail } from './components/TourDetail';
import { BookTable } from './components/Carnets';
import { SectorForm, SectorList } from './components/Sectors';
import { DeleteButton } from './components/DeleteButton';
import { Campaigns } from './components/Campaigns';
import { ActivityCards, ProgressCount } from './components/ActivityCards';

export default function App() {
  const [user, setUser] = useState<Profile | null>();
  const [error, setError] = useState('');
  const connect = useCallback(async () => {
    setError('');
    try { setUser(await session()); }
    catch (e) { setError((e as Error).message); }
  }, []);
  useEffect(() => { void connect(); }, [connect]);
  useEffect(() => {
    const expired = () => { setUser(null); void connect(); };
    window.addEventListener('session-expired', expired);
    return () => window.removeEventListener('session-expired', expired);
  }, [connect]);
  if (user === undefined) return <main className="login"><h1>CalendrierSP</h1>{error ? <><p className="error" role="alert">{error}</p><button className="primary" onClick={() => void connect()}>Réessayer</button></> : <p role="status">Connexion au serveur…</p>}</main>;
  if (!user) return <main className="login"><div className="brand-mark">CS</div><h1>CalendrierSP</h1><p>Connectez-vous à votre amicale.</p><Form label="Se connecter" submit={async d => { await api('/login', 'POST', { amicaleId: text(d, 'amicaleId'), email: text(d, 'email'), password: text(d, 'password') }); await connect(); }}>
    <Field label="Identifiant de l’amicale"><input name="amicaleId" required autoComplete="organization" minLength={4} maxLength={20} pattern="[A-Za-z0-9]{4,20}" placeholder="Ex. troyes10" autoCapitalize="none" spellCheck={false} title="4 à 20 lettres non accentuées ou chiffres, sans espace ni signe" /></Field>
    <Field label="Adresse e-mail"><input type="email" name="email" required autoComplete="username" maxLength={180} /></Field>
    <Field label="Mot de passe"><input type="password" name="password" required autoComplete="current-password" /></Field>
  </Form><small>Votre administrateur vous transmet l’identifiant de l’amicale et votre compte.</small>{error && <p className="error" role="alert">{error}</p>}</main>;
  return <Workspace key={user.id} profile={user} onProfile={setUser} onLogout={async () => { await api('/logout', 'POST', {}); setUser(null); await connect(); }} />;
}

type Data = { dashboard: Dashboard | null; tours: Tour[]; sectors: Sector[]; addresses: Address[]; members: Member[]; campaigns: Campaign[] };
type Page = 'Tableau de bord' | 'Tournées' | 'Secteurs' | 'Adresses' | 'Sapeurs' | 'Campagne' | 'Paramètres';

function Workspace({ profile, onProfile, onLogout }: { profile: Profile; onProfile: (p: Profile) => void; onLogout: () => Promise<void> }) {
  const [page, setPage] = useState<Page>(profile.canManage ? 'Tableau de bord' : 'Tournées');
  const [data, setData] = useState<Data | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [modal, setModal] = useState<Page | null>(null);
  const [editingMember, setEditingMember] = useState<Member | null>(null);
  const [selected, setSelected] = useState<string | null>(null);
  const [year, setYear] = useState(profile.amicale.campagne);
  const [refresh, setRefresh] = useState(0);
  const admin = profile.roles.includes('ROLE_ADMIN_AMICALE');
  const reload = () => setRefresh(r => r + 1);
  const nav: Page[] = profile.canManage ? ['Tableau de bord', 'Tournées', 'Secteurs', 'Adresses', 'Sapeurs', 'Campagne', 'Paramètres'] : ['Tournées'];

  useEffect(() => {
    let active = true;
    setLoading(true); setError('');
    const load = async () => {
      try {
        const [tours, dashboard, sectors, addresses, members, campaigns] = await Promise.all([
          api<Tour[]>('/tournees'),
          profile.canManage ? api<Dashboard>(`/dashboard?campagne=${year}`) : Promise.resolve(null),
          profile.canManage ? api<Sector[]>('/secteurs') : Promise.resolve([]),
          profile.canManage ? api<Address[]>('/adresses') : Promise.resolve([]),
          profile.canManage ? api<Member[]>('/sapeurs') : Promise.resolve([]),
          profile.canManage ? api<Campaign[]>('/campagnes') : Promise.resolve([]),
        ]);
        if (active) setData({ tours, dashboard, sectors, addresses, members, campaigns });
      } catch (e) { if (active) { setData(null); setError((e as Error).message); } }
      finally { if (active) setLoading(false); }
    };
    void load();
    return () => { active = false; };
  }, [profile.canManage, year, refresh]);

  const navigate = (next: Page) => { setPage(next); setSelected(null); };
  const done = () => { setModal(null); reload(); };
  const canCreate = profile.canManage && (page !== 'Sapeurs' || admin) && ['Tournées', 'Secteurs', 'Adresses', 'Sapeurs'].includes(page);
  return <div className="shell"><aside className="sidebar">
    <div className="brand"><span className="brand-mark">CS</span><div><b>CalendrierSP</b><small>Gestion amicale</small></div></div>
    <div className="tenant">{profile.amicale.nom}</div>
    <nav aria-label="Navigation principale">{nav.map(n => <button className={page === n ? 'nav active' : 'nav'} onClick={() => navigate(n)} key={n} title={n} aria-current={page === n ? 'page' : undefined}>{n}</button>)}</nav>
    <div className="side-bottom">Campagne {profile.amicale.campagne}<small>{profile.email}</small></div>
  </aside><main className="main"><header><div><span className="eyebrow">ESPACE DE GESTION</span><h1>{selected ? 'Détail de la tournée' : page}</h1></div><div className="profile"><span>{profile.email}<small>{profile.amicale.nom}</small></span><button className="secondary" onClick={() => void onLogout().catch(e => setError((e as Error).message))}>Déconnexion</button></div></header>
    {error && <div className="error" role="alert">{error} <button className="secondary" onClick={reload}>Réessayer</button></div>}
    {selected ? <TourDetail id={selected} members={data?.members ?? []} canManage={profile.canManage} onBack={() => setSelected(null)} onChange={reload} /> : <>
      <div className="toolbar"><span>{loading ? 'Chargement des données…' : data ? 'Données enregistrées sur le serveur' : 'Données indisponibles'}</span><button className="secondary" onClick={reload} disabled={loading}>Actualiser</button></div>
      {loading ? <p role="status" className="empty">Chargement…</p> : data && <>
        {page === 'Tableau de bord' && data.dashboard && <>
          <section className="welcome"><div><span className="eyebrow light">CAMPAGNE {year}</span><h2>{profile.amicale.nom}</h2><p>Suivi des passages et des ventes de calendriers.</p></div><button className="primary" onClick={() => navigate('Tournées')}>Voir les tournées →</button></section>
          <Stats dashboard={data.dashboard} /><section className="panel"><h3>Suivi des tournées</h3><TourTable tours={data.dashboard.tournees} onSelect={setSelected} /></section>
          <section className="panel"><h3>Suivi des carnets</h3><BookTable books={data.dashboard.carnets} showTour /></section>
          <section className="bottom-grid"><Payments dashboard={data.dashboard} /><div className="panel"><h3>Actions rapides</h3><button className="quick" onClick={() => setModal('Tournées')}>＋ Créer une tournée</button><button className="quick" onClick={() => navigate('Adresses')}>Gérer les adresses</button><button className="quick" onClick={() => navigate('Secteurs')}>Gérer les secteurs</button></div></section>
        </>}
        {['Tournées', 'Secteurs', 'Adresses', 'Sapeurs'].includes(page) && <section className="panel"><div className="panel-head"><div><h3>{page}</h3><p>{page === 'Tournées' ? 'Sélectionnez une tournée pour consulter ses adresses et saisir les passages.' : 'Données de votre amicale'}</p></div>{canCreate && <button className="primary" onClick={() => setModal(page)}>＋ Ajouter</button>}</div>
          {page === 'Tournées' && <TourTable tours={data.tours} onSelect={setSelected} onDeleted={profile.canManage ? reload : undefined} />}
          {page === 'Secteurs' && <SectorList sectors={data.sectors} addresses={data.addresses} onChange={reload} />}
          {page === 'Adresses' && <SimpleTable columns={['Adresse', 'Logement', 'Code postal', 'Ville', 'Secteur', 'Action']} rows={data.addresses.map(a => [a.ligne1, housingLabel(a) || '—', a.codePostal, a.ville, a.secteur, <DeleteButton path={`/adresses/${a.id}`} name={`${a.ligne1} ${housingLabel(a)} · ${a.ville}`} onDeleted={reload} />])} />}
          {page === 'Sapeurs' && <SimpleTable columns={['Nom', 'Identifiant (e-mail)', 'Rôle', 'État', ...(admin ? ['Action'] : [])]} rows={data.members.map(u => [u.nom || 'Nom à renseigner', u.email, u.roles.filter(r => r !== 'ROLE_USER').map(r => labels[r] ?? r).join(', '), u.active ? 'Actif' : 'Désactivé', ...(admin ? [<div className="row-actions"><button className="secondary" onClick={() => setEditingMember(u)} aria-label={`Modifier le nom de ${memberName(u)}`}>Modifier le nom</button><DeleteButton path={`/sapeurs/${u.id}`} name={memberName(u)} onDeleted={reload} /></div>] : [])])} />}
        </section>}
        {page === 'Campagne' && data.dashboard && <><Campaigns campaigns={data.campaigns} year={year} onYear={setYear} defaultYear={profile.amicale.campagne} onChange={reload} /><Stats dashboard={data.dashboard} /><Payments dashboard={data.dashboard} /></>}
        {page === 'Paramètres' && <section className="panel"><h3>Amicale</h3>{!admin && <p>Les paramètres sont modifiables par l’administrateur de l’amicale.</p>}<Form disabled={!admin} key={`${profile.amicale.identifiant}-${profile.amicale.nom}-${profile.amicale.campagne}`} submit={async d => { const p = await api<Profile>('/parametres', 'PATCH', { nom: text(d, 'nom'), identifiant: text(d, 'identifiant'), departement: text(d, 'departement'), campagne: integer(d, 'campagne') }); onProfile(p); setYear(p.amicale.campagne); reload(); }}>
          <Field label="Identifiant de connexion (4 à 20 lettres ou chiffres)"><input name="identifiant" required minLength={4} maxLength={20} pattern="[A-Za-z0-9]{4,20}" autoCapitalize="none" spellCheck={false} defaultValue={profile.amicale.identifiant} /></Field>
          <Field label="Nom de l’amicale"><input name="nom" defaultValue={profile.amicale.nom} required maxLength={180} /></Field><Field label="Département"><input name="departement" defaultValue={profile.amicale.departement} required maxLength={3} /></Field><Field label="Campagne par défaut"><input name="campagne" type="number" defaultValue={profile.amicale.campagne} required min={2000} max={2100} /></Field>
        </Form></section>}
      </>}
    </>}
    <footer>CalendrierSP · {profile.amicale.nom}</footer>
  </main>
  {modal && data && <Modal title={`Ajouter · ${modal}`} wide={modal === 'Secteurs'} onClose={() => setModal(null)}>
    {modal === 'Secteurs' && <SectorForm onSaved={done} />}
    {(modal === 'Adresses' || modal === 'Tournées') && (data.sectors.length === 0 ? <p>Créez d’abord un secteur dans la rubrique Secteurs.</p> : <Form onSuccess={done} submit={d => modal === 'Adresses' ? api('/adresses', 'POST', { secteurId: text(d, 'secteurId'), ligne1: text(d, 'ligne1'), codePostal: text(d, 'codePostal'), ville: text(d, 'ville') }) : api('/tournees', 'POST', { secteurId: text(d, 'secteurId'), nom: text(d, 'nom'), dateDebut: text(d, 'dateDebut'), stockCalendriers: integer(d, 'stockCalendriers') })}>
      <Field label="Secteur"><select name="secteurId" required>{data.sectors.map(s => <option key={s.id} value={s.id}>{s.nom}</option>)}</select></Field>
      {modal === 'Adresses' ? <><Field label="Numéro et voie"><input name="ligne1" required maxLength={180} /></Field><Field label="Code postal"><input name="codePostal" required maxLength={10} /></Field><Field label="Ville"><input name="ville" required maxLength={120} /></Field></> : <><Field label="Nom de la tournée"><input name="nom" required maxLength={140} /></Field><Field label="Date de début"><input type="date" name="dateDebut" required /></Field><Field label="Stock de calendriers"><input name="stockCalendriers" type="number" min={0} max={100000} defaultValue={0} required /></Field></>}
    </Form>)}
    {modal === 'Sapeurs' && <Form onSuccess={done} submit={d => api('/sapeurs', 'POST', { nom: text(d, 'nom'), email: text(d, 'email'), password: text(d, 'password'), role: text(d, 'role') })}><Field label="Nom"><input name="nom" required maxLength={180} autoComplete="name" /></Field><Field label="Identifiant (adresse e-mail)"><input type="email" name="email" required maxLength={180} autoComplete="off" /></Field><Field label="Mot de passe initial"><input type="password" name="password" required minLength={12} maxLength={256} autoComplete="new-password" /></Field><Field label="Rôle"><select name="role"><option value="ROLE_SAPEUR">Sapeur</option><option value="ROLE_RESPONSABLE">Responsable</option><option value="ROLE_ADMIN_AMICALE">Administrateur</option></select></Field></Form>}
  </Modal>}
  {editingMember && <Modal title="Modifier le nom du sapeur" onClose={() => setEditingMember(null)}><Form submit={async d => {
    const saved = await api<Member>(`/sapeurs/${editingMember.id}`, 'PATCH', { nom: text(d, 'nom') });
    if (saved.id === profile.id) onProfile({ ...profile, nom: saved.nom });
  }} onSuccess={() => { setEditingMember(null); reload(); }}><p>Identifiant : {editingMember.email}</p><Field label="Nom"><input name="nom" required maxLength={180} defaultValue={editingMember.nom ?? ''} autoComplete="name" autoFocus /></Field></Form></Modal>}
  </div>;
}

function Stats({ dashboard: { stats: s } }: { dashboard: Dashboard }) {
  return <section className="stats"><ActivityCards metrics={s} /><Stat label="Montant encaissé" value={money(s.montant)} detail="Tous moyens de paiement" /><Stat label="Tournées actives" value={String(s.tourneesActives)} detail="Tournées en cours" /></section>;
}
function Stat({ label, value, detail }: { label: string; value: string; detail: string }) { return <article className="stat"><span>{label}</span><strong>{value}</strong><small>{detail}</small></article>; }
function Payments({ dashboard }: { dashboard: Dashboard }) { return <section className="panel"><h3>Répartition des paiements</h3>{Object.entries(dashboard.stats.paiements).map(([key, amount]) => <div className="payment" key={key}><span>{labels[key]}</span><b>{money(amount)}</b></div>)}</section>; }
function SimpleTable({ columns, rows }: { columns: string[]; rows: ReactNode[][] }) {
  if (!rows.length) return <p className="empty">Aucune donnée enregistrée.</p>;
  return <div className="table-wrap"><table><thead><tr>{columns.map(c => <th key={c}>{c}</th>)}</tr></thead><tbody>{rows.map((row, i) => <tr key={i}>{row.map((cell, j) => <td key={j}>{cell}</td>)}</tr>)}</tbody></table></div>;
}
function TourTable({ tours, onSelect, onDeleted }: { tours: Tour[]; onSelect: (id: string) => void; onDeleted?: () => void }) {
  if (!tours.length) return <p className="empty">Aucune tournée enregistrée pour cette sélection.</p>;
  return <div className="table-wrap"><table><thead><tr><th>Tournée</th><th>Secteur</th><th>Date</th><th>Équipe</th><th>Calendriers vendus ou donnés</th><th>Adresses à visiter</th><th>Statut</th>{onDeleted && <th>Action</th>}</tr></thead><tbody>{tours.map(t => <tr key={t.id}><td><button className="link" onClick={() => onSelect(t.id)}>{t.nom}</button></td><td>{t.secteur}</td><td>{date(t.dateDebut)}</td><td>{t.equipe.map(memberName).join(', ') || 'Non affectée'}</td><td><ProgressCount value={t.calendriersDistribues} total={t.stockCalendriers} label="Calendriers vendus ou donnés" /><small>{t.calendriersVendus} vendus · {t.calendriersDonnes} donnés</small></td><td><ProgressCount value={t.adressesAVisiter} total={t.adresses} label="Adresses à visiter" addresses /><small>{t.traitees} déjà renseignées</small></td><td><span className={`pill ${t.statut.toLowerCase()}`}>{labels[t.statut]}</span></td>{onDeleted && <td><DeleteButton path={`/tournees/${t.id}`} name={t.nom} onDeleted={onDeleted} /></td>}</tr>)}</tbody></table></div>;
}

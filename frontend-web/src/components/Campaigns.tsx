import { useState } from 'react';
import { api, type Campaign } from '../api';
import { DeleteButton } from './DeleteButton';
import { Field, Form, Modal, integer } from './Form';

export function Campaigns({ campaigns, year, onYear, onChange, defaultYear }: { campaigns: Campaign[]; year: number; onYear: (year: number) => void; onChange: () => void; defaultYear: number }) {
  const [adding, setAdding] = useState(false);
  return <section className="panel"><div className="panel-head"><div><h3>Campagnes</h3><p>Les tournées sont rattachées à l’année de leur date de début.</p></div><button className="primary" onClick={() => setAdding(true)}>＋ Ajouter une campagne</button></div>
    <Field label="Année des résultats"><select value={year} onChange={e => onYear(Number(e.target.value))}>{campaigns.map(c => <option value={c.annee} key={c.id}>{c.annee}</option>)}</select></Field>
    <div className="table-wrap"><table><thead><tr><th>Campagne</th><th>Tournées</th><th>État</th><th>Action</th></tr></thead><tbody>{campaigns.map(c => <tr key={c.id}><td>{c.annee}</td><td>{c.tournees}</td><td>{c.parDefaut ? 'Par défaut' : '—'}</td><td><DeleteButton path={`/campagnes/${c.id}`} name={`Campagne ${c.annee}`} onDeleted={() => { if (year === c.annee) onYear(defaultYear); onChange(); }} /></td></tr>)}</tbody></table></div>
    {adding && <Modal title="Ajouter une campagne" onClose={() => setAdding(false)}><Form submit={async d => { const c = await api<Campaign>('/campagnes', 'POST', { annee: integer(d, 'annee') }); setAdding(false); onYear(c.annee); onChange(); }}><Field label="Année"><input type="number" name="annee" required min={2000} max={2100} defaultValue={Math.max(defaultYear, ...campaigns.map(c => c.annee)) + 1} /></Field></Form></Modal>}
  </section>;
}

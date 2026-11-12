import { useState } from 'react';
import { api, money, type Book, type Tour } from '../api';
import { Field, Form, Modal, integer, text } from './Form';
import { DeleteButton } from './DeleteButton';

export function BookTable({ books, showTour = false, onChange }: { books: Book[]; showTour?: boolean; onChange?: () => void }) {
  if (!books.length) return <p className="empty">Aucun carnet enregistré.</p>;
  return <div className="table-wrap"><table><thead><tr><th>Carnet</th>{showTour && <th>Tournée</th>}<th>Numéros des reçus</th><th>Total</th><th>Utilisés</th><th>Disponibles</th><th>Ventes</th><th>Dons</th><th>Annulés</th><th>Montant</th>{onChange && <th>Action</th>}</tr></thead><tbody>{books.map(b => <tr key={b.id}><td><b>{b.numero}</b></td>{showTour && <td>{b.tournee}</td>}<td>{b.premierRecu} à {b.dernierRecu}</td><td>{b.nombreRecus}</td><td>{b.recusUtilises}</td><td>{b.recusDisponibles}</td><td>{b.ventes}</td><td>{b.dons}</td><td>{b.recusAnnules}</td><td>{money(b.montant)}</td>{onChange && <td><DeleteButton path={`/carnets/${b.id}`} name={`Carnet ${b.numero}`} onDeleted={onChange} /></td>}</tr>)}</tbody></table></div>;
}

export function Carnets({ tour, canManage, onChange }: { tour: Tour; canManage: boolean; onChange: () => void }) {
  const [adding, setAdding] = useState(false);
  const [first, setFirst] = useState('');
  const [count, setCount] = useState('25');
  return <section className="panel"><div className="panel-head"><div><h3>Carnets et reçus</h3><p>Un reçu est nécessaire pour chaque vente ou don.</p></div>{canManage && tour.statut !== 'VALIDEE' && <button className="primary" onClick={() => { setFirst(''); setCount('25'); setAdding(true); }}>＋ Ajouter un carnet</button>}</div>
    <BookTable books={tour.carnets} onChange={canManage ? onChange : undefined} />
    {tour.carnets.map(b => <details className="receipt-details" key={b.id}><summary>Carnet {b.numero} · {b.nombreRecus} reçus · {b.recusDisponibles} disponibles</summary><ul className="receipt-list">{b.recus?.map(r => <li key={r.id} className={r.utilise ? 'receipt-used' : ''}><b>N° {r.numero}</b><span>{r.annule ? 'Annulé' : r.utilise ? 'Utilisé' : 'Disponible'}</span></li>)}</ul></details>)}
    {adding && <Modal title="Ajouter un carnet" onClose={() => setAdding(false)}><Form label="Créer le carnet et ses reçus" submit={d => api(`/tournees/${tour.id}/carnets`, 'POST', { numero: text(d, 'numero'), premierRecu: integer(d, 'premierRecu'), nombreRecus: integer(d, 'nombreRecus') })} onSuccess={() => { setAdding(false); onChange(); }}>
      <Field label="Numéro du carnet"><input name="numero" required maxLength={40} /></Field>
      <Field label="Numéro du premier reçu"><input name="premierRecu" type="number" required min={1} max={999999999} value={first} onChange={e => setFirst(e.target.value)} /></Field>
      <Field label="Nombre de reçus"><input name="nombreRecus" type="number" required min={1} max={1000} value={count} onChange={e => setCount(e.target.value)} /></Field>
      {Number(first) > 0 && Number(count) > 0 && <p>Reçus du n° {first} au n° {Number(first) + Number(count) - 1}.</p>}
    </Form></Modal>}
  </section>;
}

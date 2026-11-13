import { useId, useState } from 'react';
import { labels, money, type Address, type Tour } from '../api';
import { compareAddresses, streetKey, streetOf } from '../addressOrder';
import { DeleteButton } from './DeleteButton';
import { Field } from './Form';
import { housingLabel } from './Housing';

export const passageStatuses = ['VENTE', 'DON', 'REFUS', 'ABSENT', 'A_REVOIR', 'NON_VISITEE'];
export type PassageFilters = { street: string; status: string; book: string; receipt: string; sort: 'adresse' | 'montant' | 'resultat'; descending: boolean };
export const defaultPassageFilters: PassageFilters = { street: '', status: '', book: '', receipt: '', sort: 'adresse', descending: false };
const compare = new Intl.Collator('fr', { numeric: true, sensitivity: 'base' }).compare;
const statusOf = (address: Address) => address.passage?.statut ?? 'NON_VISITEE';

export function PassagesTable({ tour, canManage, filters, onFilters, onEdit, onDuplicate, onSameStreet, onNew, onChange }: { tour: Tour; canManage: boolean; filters: PassageFilters; onFilters: (filters: PassageFilters) => void; onEdit: (address: Address) => void; onDuplicate: (address: Address) => void; onSameStreet: (address: Address) => void; onNew: () => void; onChange: () => void }) {
  const [filtersOpen, setFiltersOpen] = useState(false);
  const filtersId = useId();
  const activeFilters = [filters.street, filters.status, filters.book, filters.receipt.trim()].filter(Boolean).length;
  const addresses = tour.listeAdresses ?? [];
  const streets = [...new Map(addresses.map(a => [streetKey(streetOf(a)), streetOf(a)])).entries()].sort((a, b) => compare(a[1], b[1]));
  const update = (patch: Partial<PassageFilters>) => onFilters({ ...filters, ...patch });
  const visible = addresses.filter(a => (!filters.street || streetKey(streetOf(a)) === filters.street)
    && (!filters.status || statusOf(a) === filters.status)
    && (!filters.book || (filters.book === 'sans-carnet' ? !a.passage?.recu : a.passage?.recu?.carnetId === filters.book))
    && (!filters.receipt.trim() || (/^\d+$/.test(filters.receipt.trim()) && a.passage?.recu?.numero === Number(filters.receipt.trim()))));
  visible.sort((a, b) => {
    const addressOrder = compareAddresses(a, b);
    const order = filters.sort === 'montant' ? Number(a.passage?.montant ?? 0) - Number(b.passage?.montant ?? 0)
      : filters.sort === 'resultat' ? compare(labels[statusOf(a)], labels[statusOf(b)]) : addressOrder;
    return (order || addressOrder) * (filters.descending ? -1 : 1);
  });
  const locked = tour.statut === 'VALIDEE';
  const sortHeader = (key: PassageFilters['sort'], label: string) => <th aria-sort={filters.sort === key ? filters.descending ? 'descending' : 'ascending' : 'none'}><button type="button" className="sort-heading" onClick={() => update({ sort: key, descending: filters.sort === key ? !filters.descending : false })}>{label}{filters.sort === key ? filters.descending ? ' ↓' : ' ↑' : ' ↕'}</button></th>;
  return <section className="panel passages-panel"><div className="panel-head"><h3>Passages aux adresses</h3><button className="primary" disabled={locked} onClick={onNew}>＋ Nouvelle adresse</button></div>{!addresses.length ? <p className="empty">Aucune adresse dans ce secteur. Ajoutez une adresse pour enregistrer un passage.</p> : <>
    <div className="toolbar"><span aria-live="polite">{visible.length} / {addresses.length} adresses affichées</span><button type="button" className="secondary" aria-expanded={filtersOpen} aria-controls={filtersId} onClick={() => setFiltersOpen(value => !value)}>{filtersOpen ? 'Masquer les filtres' : 'Afficher les filtres et le tri'}{activeFilters > 0 ? ` (${activeFilters} actif${activeFilters > 1 ? 's' : ''})` : ''}</button></div>
    <div id={filtersId} hidden={!filtersOpen}>
      <div className="passage-filters" role="group" aria-label="Filtres et tri des passages">
      <Field label="Rue"><select name="filtreRue" value={filters.street} onChange={e => update({ street: e.target.value })}><option value="">Toutes les rues</option>{streets.map(([key, street]) => <option key={key} value={key}>{street}</option>)}</select></Field>
      <Field label="Résultat"><select name="filtreStatut" value={filters.status} onChange={e => update({ status: e.target.value })}><option value="">Tous les résultats</option>{passageStatuses.map(status => <option key={status} value={status}>{labels[status]}</option>)}</select></Field>
      <Field label="Carnet"><select name="filtreCarnet" value={filters.book} onChange={e => update({ book: e.target.value })}><option value="">Tous les carnets</option><option value="sans-carnet">Sans carnet</option>{tour.carnets.map(book => <option key={book.id} value={book.id}>Carnet {book.numero}</option>)}</select></Field>
      <Field label="N° de reçu"><input type="search" inputMode="numeric" name="rechercheRecu" placeholder="Numéro exact" value={filters.receipt} onChange={e => update({ receipt: e.target.value })} /></Field>
      <Field label="Trier par"><select name="triPassages" value={filters.sort} onChange={e => update({ sort: e.target.value as PassageFilters['sort'] })}><option value="adresse">Adresse</option><option value="montant">Montant</option><option value="resultat">Résultat</option></select></Field>
      <Field label="Ordre"><select name="ordrePassages" value={filters.descending ? 'desc' : 'asc'} onChange={e => update({ descending: e.target.value === 'desc' })}><option value="asc">Croissant</option><option value="desc">Décroissant</option></select></Field>
      </div>
      <button type="button" className="secondary filter-reset" onClick={() => onFilters({ ...defaultPassageFilters })}>Réinitialiser les filtres et le tri</button>
    </div>
    <div className="table-wrap"><table className="passages-table" aria-label="Résultats des passages"><thead><tr>{sortHeader('adresse', 'Adresse')}<th>Logement</th>{sortHeader('resultat', 'Résultat')}<th>Carnet / reçu</th><th>Quantité</th>{sortHeader('montant', 'Montant')}<th>Action</th></tr></thead><tbody>{visible.map(a => <tr key={a.id}>
      <td data-label="Adresse" className="passage-address">{a.ligne1}<small className="address-meta">{a.codePostal} {a.ville}</small></td>
      <td data-label="Logement" className="housing-cell">{housingLabel(a) || '—'}</td>
      <td data-label="Résultat"><span className={`passage-status status-${statusOf(a).toLowerCase()}`}>{labels[statusOf(a)]}</span></td>
      <td data-label="Carnet / reçu">{a.passage?.recu ? `${a.passage.recu.carnetNumero} / n° ${a.passage.recu.numero}` : a.passage && ['VENTE', 'DON'].includes(a.passage.statut) ? 'Reçu à renseigner' : '—'}</td>
      <td data-label="Quantité">{a.passage?.nbCalendriers ?? 0}</td>
      <td data-label="Montant">{money(a.passage?.montant ?? '0.00')}</td>
      <td data-label="Actions" className="passage-actions"><div className="row-actions">
        <button className="secondary" disabled={locked} onClick={() => onEdit(a)} aria-label={`${a.passage ? 'Modifier' : 'Saisir'} le passage · ${a.ligne1} ${housingLabel(a)}`}>{a.passage ? 'Modifier' : 'Saisir'}</button>
        <button className="secondary" disabled={locked} onClick={() => onDuplicate(a)} aria-label={`Ajouter un appartement · ${a.ligne1} ${housingLabel(a)}`}>＋ Appartement</button>
        <button className="secondary" disabled={locked} onClick={() => onSameStreet(a)} aria-label={`Ajouter un numéro dans la même rue · ${a.ligne1}`}>＋ Numéro</button>
        {canManage && a.passage && <DeleteButton path={`/passages/${a.passage.id}`} name={`Passage · ${a.ligne1} ${housingLabel(a)}`} onDeleted={onChange} />}
      </div></td>
    </tr>)}</tbody></table></div>
    {!visible.length && <p className="empty">Aucune adresse ne correspond aux filtres.</p>}
  </>}</section>;
}

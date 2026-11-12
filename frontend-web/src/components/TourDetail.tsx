import { useEffect, useState } from 'react';
import { api, date, labels, money, type Address, type Book, type Member, type Tour } from '../api';
import { Field, Form, Modal, integer, text } from './Form';
import { Carnets } from './Carnets';
import { DeleteButton } from './DeleteButton';
import { defaultPassageFilters, PassagesTable, type PassageFilters } from './PassagesTable';
import { HousingFields, housingData } from './Housing';
import { ActivityCards } from './ActivityCards';

type PassageMode = 'existing' | 'duplicate' | 'new';
type PassageEditor = { mode: PassageMode; address: Address | null };

export function TourDetail({ id, members, canManage, onBack, onChange }: { id: string; members: Member[]; canManage: boolean; onBack: () => void; onChange: () => void }) {
  const [tour, setTour] = useState<Tour | null>(null);
  const [error, setError] = useState('');
  const [refresh, setRefresh] = useState(0);
  const [editor, setEditor] = useState<PassageEditor | null>(null);
  const [confirmValidation, setConfirmValidation] = useState(false);
  const [filters, setFilters] = useState<PassageFilters>({ ...defaultPassageFilters });
  useEffect(() => { setFilters({ ...defaultPassageFilters }); setEditor(null); }, [id]);
  useEffect(() => {
    let active = true;
    setError(''); setTour(null);
    void api<Tour>(`/tournees/${id}`).then(t => { if (active) setTour(t); }).catch(e => { if (active) setError(e.message); });
    return () => { active = false; };
  }, [id, refresh]);
  const changed = () => { setRefresh(n => n + 1); onChange(); };
  const locked = tour?.statut === 'VALIDEE';
  return <><button className="secondary" onClick={onBack}>← Retour aux tournées</button>{error ? <p className="error" role="alert">{error} <button onClick={() => setRefresh(n => n + 1)}>Réessayer</button></p> : !tour ? <p role="status" className="empty">Chargement de la tournée…</p> : <>
    <section className="panel tour-summary"><div className="panel-head"><h2>{tour.nom}</h2>{canManage && <DeleteButton path={`/tournees/${id}`} name={tour.nom} onDeleted={() => { onBack(); onChange(); }} />}</div><p>{tour.secteur} · {date(tour.dateDebut)} · <b>{labels[tour.statut]}</b></p><section className="stats tour-stats"><ActivityCards metrics={tour} /></section><p>{money(tour.montant)} encaissés</p><p>Équipe : {tour.equipe.map(u => u.email).join(', ') || 'Aucun sapeur affecté'}</p>
      {canManage && tour.equipe.length > 0 && <ul className="team-list">{tour.equipe.map(u => <li key={u.id}>{u.email}<DeleteButton path={`/tournees/${id}/affectations/${u.id}`} name={`Affectation de ${u.email}`} label="Retirer de l’équipe" onDeleted={changed} /></li>)}</ul>}
      {locked && <p className="notice">Cette tournée a été validée. Les saisies sont verrouillées.</p>}
      {canManage && !locked && <div className="bottom-grid"><Form label="Modifier le statut" onSuccess={changed} submit={d => api(`/tournees/${id}/statut`, 'PATCH', { statut: text(d, 'statut') })}><Field label="Statut"><select name="statut" defaultValue={tour.statut}>{['PREPAREE', 'EN_COURS', 'TERMINEE'].map(s => <option value={s} key={s}>{labels[s]}</option>)}</select></Field></Form>
        <TeamForm tour={tour} members={members} onSaved={changed} /></div>}
      {canManage && tour.statut === 'TERMINEE' && <button className="primary" onClick={() => setConfirmValidation(true)}>Valider et verrouiller la tournée</button>}
    </section>
    <Carnets tour={tour} canManage={canManage} onChange={changed} />
    <PassagesTable tour={tour} canManage={canManage} filters={filters} onFilters={setFilters} onEdit={a => setEditor({ mode: 'existing', address: a })} onDuplicate={a => setEditor({ mode: 'duplicate', address: a })} onNew={() => setEditor({ mode: 'new', address: null })} onChange={changed} />
    {editor && <Modal title={editor.mode === 'new' ? 'Nouvelle adresse et passage' : editor.mode === 'duplicate' ? `Ajouter un appartement · ${editor.address?.ligne1}` : editor.address?.ligne1 ?? ''} onClose={() => setEditor(null)}><PassageForm address={editor.address} tourId={id} books={tour.carnets} mode={editor.mode} locality={tour.listeAdresses?.[0]} onSaved={() => { if (editor.mode === 'new') setFilters({ ...defaultPassageFilters }); setEditor(null); changed(); }} /></Modal>}
    {confirmValidation && <Modal title="Valider la tournée" onClose={() => setConfirmValidation(false)}><p>La validation verrouille définitivement les passages, le statut et les affectations de cette tournée.</p><Form label="Confirmer la validation" submit={() => api(`/tournees/${id}/valider`, 'POST', {})} onSuccess={() => { setConfirmValidation(false); changed(); }}><p>{tour.nom} · {tour.traitees}/{tour.adresses} adresses traitées</p></Form></Modal>}
  </>}</>;
}

function TeamForm({ tour, members, onSaved }: { tour: Tour; members: Member[]; onSaved: () => void }) {
  const [selected, setSelected] = useState<string[]>([]);
  const available = members.filter(u => u.active && !tour.equipe.some(m => m.id === u.id));
  const selectedIds = selected.filter(id => available.some(u => u.id === id));
  return <Form label="Ajouter à l’équipe" submitDisabled={selectedIds.length === 0} onSuccess={onSaved} submit={() => api(`/tournees/${tour.id}/affectations`, 'POST', { userIds: selectedIds })}>
    <h3>Ajouter des sapeurs à l’équipe</h3>
    {available.length === 0 ? <p>Aucun autre sapeur actif disponible dans votre amicale.</p> : <>
      <p className="map-help">Cochez les sapeurs qui participeront à cette tournée.</p>
      <div className="team-options" role="group" aria-label="Sapeurs à ajouter à l’équipe">{available.map(u => <label className="team-option" key={u.id}><input type="checkbox" name="userIds" value={u.id} checked={selectedIds.includes(u.id)} onChange={e => setSelected(ids => e.target.checked ? [...ids, u.id] : ids.filter(id => id !== u.id))} /><span>{u.email}</span></label>)}</div>
      <p role="status">{selectedIds.length} sapeur{selectedIds.length > 1 ? 's' : ''} sélectionné{selectedIds.length > 1 ? 's' : ''}</p>
    </>}
  </Form>;
}

function PassageForm({ address, tourId, books, mode, locality, onSaved }: { address: Address | null; tourId: string; books: Book[]; mode: PassageMode; locality?: Address; onSaved: () => void }) {
  const duplicate = mode === 'duplicate';
  const newAddress = mode === 'new';
  const p = mode === 'existing' ? address?.passage : null;
  const [status, setStatus] = useState(p?.statut ?? 'VENTE');
  const sale = status === 'VENTE';
  const needsReceipt = sale || status === 'DON';
  const [bookId, setBookId] = useState(p?.recu?.carnetId ?? '');
  const [receiptId, setReceiptId] = useState(p?.recu?.id ?? '');
  const book = books.find(b => b.id === bookId);
  const available = book?.recus?.filter(r => !r.utilise || r.id === p?.recu?.id) ?? [];
  return <Form label={newAddress ? 'Créer l’adresse et enregistrer' : duplicate ? 'Ajouter le logement et enregistrer' : 'Enregistrer'} onSuccess={onSaved} submitDisabled={needsReceipt && !books.some(b => b.recusDisponibles > 0 || b.id === p?.recu?.carnetId)} submit={d => api(newAddress ? `/tournees/${tourId}/adresses` : duplicate ? `/tournees/${tourId}/adresses/${address!.id}/dupliquer` : `/tournees/${tourId}/passages/${address!.id}`, mode === 'existing' ? 'PUT' : 'POST', {
    statut: status, commentaire: text(d, 'commentaire') || null, ...housingData(d),
    ...(newAddress ? { ligne1: text(d, 'ligne1'), codePostal: text(d, 'codePostal'), ville: text(d, 'ville') } : {}),
    ...(needsReceipt ? { carnetId: bookId, recuId: receiptId } : {}),
    ...(status === 'DON' ? {} : { nbCalendriers: sale ? integer(d, 'nbCalendriers') : 0, montant: sale ? text(d, 'montant').replace(',', '.') : '0.00', moyenPaiement: sale ? text(d, 'moyenPaiement') : null }),
  })}>
    {duplicate && <p className="notice">Une nouvelle ligne sera créée à cette adresse, avec son propre passage. Précisez un logement différent et sélectionnez un nouveau reçu pour une vente ou un don.</p>}
    {newAddress && <><p className="notice">Cette adresse sera ajoutée au secteur de la tournée avec son passage.</p><Field label="Numéro et rue"><input name="ligne1" required maxLength={180} autoFocus placeholder="Ex. 12 bis rue Traversière" /></Field><Field label="Code postal"><input name="codePostal" required maxLength={10} defaultValue={locality?.codePostal ?? ''} /></Field><Field label="Ville"><input name="ville" required maxLength={120} defaultValue={locality?.ville ?? ''} /></Field></>}
    <HousingFields address={mode === 'existing' ? address ?? undefined : undefined} />
    <Field label="Résultat du passage"><select name="statut" value={status} onChange={e => setStatus(e.target.value)}>{['VENTE', 'DON', 'REFUS', 'ABSENT', 'A_REVOIR', 'NON_VISITEE'].map(s => <option value={s} key={s}>{labels[s]}</option>)}</select></Field>
    {needsReceipt && <>
      {!books.some(b => b.recusDisponibles > 0 || b.id === p?.recu?.carnetId) && <p className="notice">Aucun reçu disponible. Demandez à un responsable d’ajouter un carnet à cette tournée.</p>}
      <Field label="Carnet"><select name="carnetId" required value={bookId} onChange={e => { setBookId(e.target.value); setReceiptId(''); }}><option value="">Sélectionner un carnet</option>{books.filter(b => b.recusDisponibles > 0 || b.id === p?.recu?.carnetId).map(b => <option value={b.id} key={b.id}>Carnet {b.numero}</option>)}</select></Field>
      <Field label="Numéro du reçu"><select name="recuId" required value={receiptId} onChange={e => setReceiptId(e.target.value)}><option value="">Sélectionner un reçu</option>{available.map(r => <option key={r.id} value={r.id}>N° {r.numero}{r.id === p?.recu?.id ? ' (reçu actuel)' : ''}</option>)}</select></Field>
      {p?.recu && receiptId !== p.recu.id && <p className="notice">Le reçu actuel restera utilisé après son remplacement.</p>}
    </>}
    {sale && <><Field label="Calendriers vendus"><input name="nbCalendriers" type="number" min={1} max={100000} defaultValue={p?.nbCalendriers || 1} required /></Field><Field label="Montant reçu (€)"><input name="montant" inputMode="decimal" pattern="[0-9]{1,7}([.,][0-9]{1,2})?" defaultValue={p?.montant ?? ''} required /></Field><Field label="Moyen de paiement"><select name="moyenPaiement" defaultValue={p?.moyenPaiement ?? 'ESPECES'}>{['ESPECES', 'CHEQUE', 'CB', 'AUTRE'].map(s => <option value={s} key={s}>{labels[s]}</option>)}</select></Field></>}
    <Field label="Commentaire (facultatif)"><textarea name="commentaire" maxLength={2000} defaultValue={p?.commentaire ?? ''} /></Field>
  </Form>;
}

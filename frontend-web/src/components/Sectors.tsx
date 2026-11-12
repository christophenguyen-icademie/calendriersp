import { useState } from 'react';
import { api, type Address, type Geometry, type ImportPreview, type Sector } from '../api';
import { Field, Form, Modal, text } from './Form';
import { PolygonMap } from './PolygonMap';
import { DeleteButton } from './DeleteButton';

export function SectorForm({ sector, onSaved }: { sector?: Sector; onSaved: (s: Sector) => void }) {
  const [geometry, setGeometry] = useState<Geometry | null>(sector?.polygone ?? null);
  const [drawing, setDrawing] = useState(false);
  return <Form submitDisabled={drawing} submit={async d => {
    const saved = await api<Sector>(sector ? `/secteurs/${sector.id}` : '/secteurs', sector ? 'PATCH' : 'POST', { nom: text(d, 'nom'), type: text(d, 'type'), polygone: geometry });
    onSaved(saved);
  }}>
    <Field label="Nom"><input name="nom" required maxLength={120} defaultValue={sector?.nom} /></Field><Field label="Type"><select name="type" defaultValue={sector?.type ?? 'LIBRE'}><option value="LIBRE">Libre</option><option value="URBAIN">Urbain</option><option value="RURAL">Rural</option></select></Field>
    <PolygonMap value={geometry} onChange={setGeometry} onDrawingChange={setDrawing} />
  </Form>;
}

export function SectorList({ sectors, addresses, onChange }: { sectors: Sector[]; addresses: Address[]; onChange: () => void }) {
  const [selected, setSelected] = useState<Sector | null>(null);
  const [changed, setChanged] = useState(false);
  return <>{!sectors.length ? <p className="empty">Aucun secteur enregistré.</p> : <div className="table-wrap"><table><thead><tr><th>Nom</th><th>Type</th><th>Zone</th><th>Adresses</th><th>Action</th></tr></thead><tbody>{sectors.map(s => <tr key={s.id}><td>{s.nom}</td><td>{s.type}</td><td>{s.polygone ? 'Polygone défini' : 'À dessiner'}</td><td>{addresses.filter(a => a.secteurId === s.id).length}</td><td><div className="row-actions"><button className="secondary" onClick={() => setSelected(s)}>Carte et adresses</button><DeleteButton path={`/secteurs/${s.id}`} name={s.nom} onDeleted={onChange} /></div></td></tr>)}</tbody></table></div>}
    {selected && <Modal title={`Secteur · ${selected.nom}`} wide onClose={() => { setSelected(null); if (changed) onChange(); setChanged(false); }}><SectorEditor initial={selected} addresses={addresses.filter(a => a.secteurId === selected.id)} onChange={() => setChanged(true)} /></Modal>}
  </>;
}

function SectorEditor({ initial, addresses, onChange }: { initial: Sector; addresses: Address[]; onChange: () => void }) {
  const [sector, setSector] = useState(initial);
  const [preview, setPreview] = useState<ImportPreview | null>(null);
  const [message, setMessage] = useState('');
  const [tab, setTab] = useState<'map' | 'import'>('map');
  const [source, setSource] = useState('BAN');
  return <><div className="map-actions"><button className="secondary" onClick={() => setTab('map')}>Définir le secteur</button><button className="secondary" onClick={() => setTab('import')}>Extraire les adresses</button></div>
    {message && <p className="notice" role="status">{message}</p>}
    {tab === 'map' ? <SectorForm sector={sector} onSaved={s => { setSector(s); setPreview(null); setMessage('Secteur et polygone enregistrés.'); onChange(); }} /> : <>
      <p>Pour les secteurs français, la Base Adresse Nationale recense les numéros et voies à l’intérieur du polygone enregistré. OpenStreetMap reste disponible comme autre source. La couverture dépend du référentiel choisi.</p>
      {!sector.polygone ? <p className="notice">Définissez et enregistrez d’abord le polygone dans « Définir le secteur ».</p> : <>
        <Form label="Rechercher les adresses du polygone" pendingLabel="Recherche des adresses…" submit={async d => { setMessage(''); setPreview(null); setPreview(await api<ImportPreview>(`/secteurs/${sector.id}/adresses/preview`, 'POST', { source, codePostal: text(d, 'codePostal'), ville: text(d, 'ville') })); }}>
          <Field label="Source des adresses"><select name="source" value={source} onChange={e => { setSource(e.target.value); setPreview(null); setMessage(''); }}><option value="BAN">Base Adresse Nationale (France)</option><option value="OSM">OpenStreetMap</option></select></Field>
          {source === 'OSM' && <div className="bottom-grid"><Field label="Code postal si absent d’OpenStreetMap (facultatif)"><input name="codePostal" maxLength={10} /></Field><Field label="Ville si absente d’OpenStreetMap (facultatif)"><input name="ville" maxLength={120} /></Field></div>}
        </Form>
        {preview && <section className="import-preview"><h3>Aperçu de l’extraction</h3><p>Source : {preview.source}{preview.departements.length > 0 ? ` · Département(s) : ${preview.departements.join(', ')}` : ''}. {source === 'BAN' && <a href="https://adresse.data.gouv.fr/donnees-nationales" target="_blank" rel="noreferrer">Licence Ouverte 2.0</a>}</p><p><b>{preview.total}</b> adresses dans le polygone : <b>{preview.nouvelles}</b> nouvelles, <b>{preview.doublons}</b> doublons ignorés.</p>
          {preview.incompletes > 0 && <p className="notice">{preview.incompletes} adresses n’ont pas de code postal ou de ville. Vous pouvez relancer la recherche avec des valeurs de complément.</p>}
          {preview.ignorees > 0 && <p>{preview.ignorees} enregistrements de la source ont été ignorés faute de numéro, de voie ou de coordonnées utilisables.</p>}
          <PolygonMap value={sector.polygone} onChange={() => {}} readOnly markers={preview.adresses.map(a => ({ ...a, label: `${a.ligne1} ${a.codePostal} ${a.ville}`, duplicate: a.doublon }))} />
          <div className="table-wrap preview-table"><table><thead><tr><th>Adresse</th><th>Code postal</th><th>Ville</th><th>Import</th></tr></thead><tbody>{preview.adresses.slice(0, 100).map((a, i) => <tr key={i}><td>{a.ligne1}</td><td>{a.codePostal || 'Non renseigné'}</td><td>{a.ville || 'Non renseignée'}</td><td>{a.doublon ? 'Déjà présente' : 'Nouvelle'}</td></tr>)}</tbody></table></div>
          {preview.total > 100 && <p>Les 100 premières adresses sont affichées. Les {preview.nouvelles} nouvelles adresses seront importées.</p>}
          <p>Les adresses existantes sont conservées. Les doublons sont vérifiés à nouveau au moment de l’import.</p>
          <Form label={`Importer ${preview.nouvelles} adresses`} submitDisabled={preview.nouvelles === 0} submit={async () => { const result = await api<{ creees: number; doublons: number }>(`/secteurs/${sector.id}/adresses/import`, 'POST', { token: preview.token }); setPreview(null); setMessage(`${result.creees} adresses importées, ${result.doublons} doublons ignorés.`); onChange(); }}><span /></Form>
        </section>}
      </>}
      {!preview && addresses.some(a => a.latitude !== null && a.longitude !== null) && <p>{addresses.filter(a => a.latitude !== null && a.longitude !== null).length} adresses géolocalisées déjà enregistrées.</p>}
    </>}
  </>;
}

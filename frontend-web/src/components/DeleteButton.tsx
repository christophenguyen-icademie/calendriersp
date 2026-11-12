import { useEffect, useState } from 'react';
import { api, ApiError, type DeletionCheck } from '../api';
import { Form, Modal } from './Form';

export function DeleteButton({ path, name, onDeleted, label = 'Supprimer' }: { path: string; name: string; onDeleted: () => void; label?: string }) {
  const [open, setOpen] = useState(false);
  return <><button type="button" className="danger" aria-label={`${label} · ${name}`} onClick={() => setOpen(true)}>{label}</button>
    {open && <Modal title={`Supprimer · ${name}`} onClose={() => setOpen(false)}><DeleteConfirmation path={path} name={name} onDeleted={() => { setOpen(false); onDeleted(); }} /></Modal>}
  </>;
}

function DeleteConfirmation({ path, name, onDeleted }: { path: string; name: string; onDeleted: () => void }) {
  const [check, setCheck] = useState<DeletionCheck | null>(null);
  const [error, setError] = useState('');
  const [refresh, setRefresh] = useState(0);
  useEffect(() => {
    let active = true;
    setCheck(null); setError('');
    void api<DeletionCheck>(`${path}/suppression`).then(result => { if (active) setCheck(result); }).catch(e => { if (active) setError(e.message); });
    return () => { active = false; };
  }, [path, refresh]);
  return <>{error && <p className="error" role="alert">{error}</p>}{!check && !error && <p role="status">Vérification des liens…</p>}
    {check && <>{!check.autorisee && <p className="notice" role="status">Suppression bloquée. Cet élément est encore utilisé ou protégé.</p>}
      {check.dependances.length > 0 && <><p>Éléments liés à traiter avant la suppression :</p><ul>{check.dependances.map(d => <li key={d.type}><b>{d.nombre}</b> {d.libelle}</li>)}</ul></>}
      {check.raisons.map(reason => <p key={reason}>{reason}</p>)}
      {check.autorisee && <Form label="Supprimer définitivement" pendingLabel="Suppression…" submit={async () => {
        try { await api(path, 'DELETE'); onDeleted(); }
        catch (e) { if (e instanceof ApiError && e.deletion) setCheck(e.deletion); throw e; }
      }}><p>Confirmer la suppression de <b>{name}</b> ? Cette suppression est définitive.</p>{check.consequences.map(effect => <p key={effect}>{effect}</p>)}</Form>}
    </>}
    {(error || check && !check.autorisee) && <button className="secondary" onClick={() => setRefresh(n => n + 1)}>Recontrôler les liens</button>}
  </>;
}

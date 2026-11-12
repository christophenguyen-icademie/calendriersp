import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';

export function Form({ children, submit, label = 'Enregistrer', pendingLabel = 'Enregistrement…', onSuccess, disabled = false, submitDisabled = false }: { children: ReactNode; submit: (data: FormData) => Promise<unknown>; label?: string; pendingLabel?: string; onSuccess?: () => void; disabled?: boolean; submitDisabled?: boolean }) {
  const [pending, setPending] = useState(false);
  const [error, setError] = useState('');
  async function handle(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (pending || disabled || submitDisabled) return;
    const data = new FormData(event.currentTarget);
    setPending(true); setError('');
    try { await submit(data); onSuccess?.(); }
    catch (e) { setError(e instanceof Error ? e.message : 'La demande a échoué.'); }
    finally { setPending(false); }
  }
  return <form className="data-form" onSubmit={handle}><fieldset disabled={pending || disabled}>{children}</fieldset>{error && <p className="error" role="alert">{error}</p>}<button className="primary" disabled={pending || disabled || submitDisabled} type="submit">{pending ? pendingLabel : label}</button></form>;
}

export function Field({ label, children }: { label: string; children: ReactNode }) { return <label className="field"><span>{label}</span>{children}</label>; }

export function Modal({ title, children, onClose, wide = false }: { title: string; children: ReactNode; onClose: () => void; wide?: boolean }) {
  const ref = useRef<HTMLDialogElement>(null);
  useEffect(() => { ref.current?.showModal(); }, []);
  return <dialog className={wide ? 'wide-dialog' : undefined} ref={ref} onCancel={onClose} onClick={e => { if (e.target === e.currentTarget) onClose(); }} aria-label={title}><div className="panel-head"><h3>{title}</h3><button className="secondary" type="button" onClick={onClose} aria-label="Fermer">✕</button></div>{children}</dialog>;
}

export const text = (data: FormData, key: string) => String(data.get(key) ?? '');
export const integer = (data: FormData, key: string) => Number(text(data, key));

import type { ActivityMetrics } from '../api';

const number = (value: number) => value.toLocaleString('fr-FR');

export function ActivityCards({ metrics }: { metrics: ActivityMetrics }) {
  return <>
    <article className="stat"><span>Calendriers vendus ou donnés</span><ProgressCount value={metrics.calendriersDistribues} total={metrics.stockCalendriers} label="Calendriers vendus ou donnés" /><small>{number(metrics.calendriersVendus)} vendus · {number(metrics.calendriersDonnes)} donnés{metrics.stockCalendriers === 0 ? ' · Stock à renseigner' : ''}</small></article>
    <article className="stat"><span>Adresses à visiter</span><ProgressCount value={metrics.adressesAVisiter} total={metrics.adresses} label="Adresses à visiter" addresses /><small>{number(metrics.traitees)} déjà renseignées · {number(metrics.adresses)} au total</small></article>
  </>;
}

export function ProgressCount({ value, total, label, addresses = false }: { value: number; total: number; label: string; addresses?: boolean }) {
  return <><strong className="metric-count">{number(value)}<span className="metric-total"> / {number(total)}</span></strong><progress className={`metric-bar${addresses ? ' addresses' : ''}`} value={Math.min(value, total)} max={total || 1} aria-label={`${label} : ${number(value)} sur ${number(total)}`} /></>;
}

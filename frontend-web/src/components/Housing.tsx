import type { Housing } from '../api';
import { Field, text } from './Form';

const fields = { batiment: 'Bâtiment', etage: 'Étage', appartement: 'Appartement', porte: 'Porte' } as const;

export function housingLabel(address: Housing & { complement?: string | null }) {
  return [address.complement, ...Object.entries(fields).map(([key, label]) => address[key as keyof Housing] ? `${label} ${address[key as keyof Housing]}` : null)].filter(Boolean).join(' · ');
}

export function HousingFields({ address }: { address?: Housing }) {
  return <div className="housing-fields">{Object.entries(fields).map(([name, label]) => <Field label={label} key={name}><input name={name} defaultValue={address?.[name as keyof Housing] ?? ''} maxLength={80} /></Field>)}</div>;
}

export const housingData = (data: FormData) => Object.fromEntries(Object.keys(fields).map(key => [key, text(data, key).trim() || null]));

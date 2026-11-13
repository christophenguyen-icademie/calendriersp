import type { Address } from './api';

type StreetAddress = Pick<Address, 'ligne1'>;
const compare = new Intl.Collator('fr', { numeric: true, sensitivity: 'base' }).compare;
export const streetOf = (address: StreetAddress) => address.ligne1.replace(/^\s*\d+(?:\s*(?:bis|ter|quater|quinquies|[a-z])(?=\s|[-/]|$))?(?:\s*[-/]\s*\d+(?:\s*(?:bis|ter|quater|quinquies|[a-z])(?=\s|$))?)?\s+/iu, '').trim().replace(/\s+/g, ' ');
export const streetKey = (street: string) => street.normalize('NFC').toLocaleLowerCase('fr');

export function compareAddresses(a: StreetAddress, b: StreetAddress): number {
  const street = compare(streetOf(a), streetOf(b));
  if (street) return street;
  const numberA = a.ligne1.match(/^\s*(\d+)/u);
  const numberB = b.ligne1.match(/^\s*(\d+)/u);
  // Les suffixes et logements ne départagent pas les numéros identiques.
  if (numberA && numberB) return Number(numberA[1]) - Number(numberB[1]);
  if (numberA) return -1;
  if (numberB) return 1;
  return 0;
}

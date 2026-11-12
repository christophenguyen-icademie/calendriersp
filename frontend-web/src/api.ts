export type Member = { id: string; email: string; roles: string[]; active: boolean };
export type Campaign = { id: string; annee: number; tournees: number; parDefaut: boolean };
export type DeletionCheck = { autorisee: boolean; dependances: { type: string; nombre: number; libelle: string }[]; raisons: string[]; consequences: string[] };
export type Profile = { id: string; email: string; roles: string[]; canManage: boolean; amicale: { id: string; identifiant: string; nom: string; departement: string; campagne: number } };
export type Geometry = { type: 'Polygon'; coordinates: [number, number][][] };
export type Sector = { id: string; nom: string; type: string; polygone: Geometry | null };
export type Receipt = { id: string; numero: number; utilise: boolean; annule: boolean };
export type Book = { id: string; numero: string; tourneeId: string; tournee: string; premierRecu: number; dernierRecu: number; nombreRecus: number; recusUtilises: number; recusDisponibles: number; ventes: number; dons: number; recusAnnules: number; montant: string; recus?: Receipt[] };
export type Passage = { id: string; adresseId: string; statut: string; nbCalendriers: number; montant: string; moyenPaiement: string | null; commentaire: string | null; recu: { id: string; numero: number; carnetId: string; carnetNumero: string } | null };
export type Housing = { batiment: string | null; etage: string | null; appartement: string | null; porte: string | null };
export type Address = Housing & { id: string; ligne1: string; codePostal: string; ville: string; secteurId: string; secteur: string; latitude: number | null; longitude: number | null; sourceId: string | null; complement: string | null; passage?: Passage | null };
export type Candidate = { ligne1: string; codePostal: string; ville: string; latitude: number; longitude: number; sourceId: string; doublon: boolean };
export type ImportPreview = { token: string; source: string; departements: string[]; adresses: Candidate[]; total: number; nouvelles: number; doublons: number; incompletes: number; horsPolygone: number; ignorees: number };
export type ActivityMetrics = { stockCalendriers: number; calendriersVendus: number; calendriersDonnes: number; calendriersDistribues: number; adresses: number; traitees: number; adressesAVisiter: number; progression: number };
export type Tour = ActivityMetrics & { id: string; nom: string; secteurId: string; secteur: string; dateDebut: string; statut: string; equipe: Member[]; montant: string; paiements: Record<string, string>; carnets: Book[]; listeAdresses?: Address[] };
export type Dashboard = { campagne: number; tournees: Tour[]; carnets: Book[]; stats: ActivityMetrics & { montant: string; tourneesActives: number; paiements: Record<string, string> } };

const base = (import.meta.env.VITE_API_BASE_URL || '/api').replace(/\/$/, '');
let csrfToken = '';

export class ApiError extends Error {
  constructor(message: string, public status: number, public deletion?: DeletionCheck) { super(message); }
}

export async function api<T>(path: string, method = 'GET', body?: unknown): Promise<T> {
  let response: Response;
  try {
    response = await fetch(`${base}${path}`, {
      method, credentials: 'same-origin', headers: { Accept: 'application/json', ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}), ...(method !== 'GET' ? { 'X-CSRF-Token': csrfToken } : {}) },
      ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
    });
  } catch {
    throw new ApiError('Impossible de joindre le serveur. Vérifiez votre connexion, puis réessayez.', 0);
  }
  let data;
  try { data = await response.json(); }
  catch { throw new ApiError('Le serveur a renvoyé une réponse invalide.', response.status); }
  if (!response.ok) {
    if ((response.status === 401 && path !== '/login') || data.code === 'INVALID_CSRF') window.dispatchEvent(new Event('session-expired'));
    throw new ApiError(data.error || 'La demande a échoué.', response.status, Array.isArray(data.dependances) ? data as DeletionCheck : undefined);
  }
  return data as T;
}

export async function session(): Promise<Profile | null> {
  const data = await api<{ csrfToken: string; user: Profile | null }>('/session');
  csrfToken = data.csrfToken;
  return data.user;
}

export const money = (value: string) => new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(Number(value));
export const date = (value: string) => new Intl.DateTimeFormat('fr-FR').format(new Date(`${value}T12:00:00`));
export const labels: Record<string, string> = { PREPAREE: 'Préparée', EN_COURS: 'En cours', TERMINEE: 'Terminée', VALIDEE: 'Validée', NON_VISITEE: 'Non visitée', VENTE: 'Vente', DON: 'Don', REFUS: 'Refus', ABSENT: 'Absent', A_REVOIR: 'À revoir', ESPECES: 'Espèces', CHEQUE: 'Chèque', CB: 'CB', AUTRE: 'Autre', ROLE_SAPEUR: 'Sapeur', ROLE_RESPONSABLE: 'Responsable', ROLE_ADMIN_AMICALE: 'Administrateur' };

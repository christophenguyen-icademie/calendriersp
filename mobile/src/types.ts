export type Tournee = { id: string; nom: string; secteur: string; date: string; statut: 'PREPAREE'|'EN_COURS'|'TERMINEE'|'VALIDEE'; adresses: number; traitees: number; };
export type PassageStatut = 'NON_VISITEE'|'VENTE'|'REFUS'|'ABSENT';
export type MoyenPaiement = 'ESPECES'|'CHEQUE'|'AUTRE';

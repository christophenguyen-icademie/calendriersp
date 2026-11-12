# Synchronisation mobile offline

## Principe
Le mobile conserve un journal local des modifications. Chaque événement possède un UUID généré sur le terminal (`clientMutationId`) et une version locale. Le serveur est la source de vérité.

## Contrat recommandé
`POST /api/mobile/sync` avec un lot de mutations :
```json
{
  "mutations": [
    {
      "clientMutationId": "uuid",
      "type": "PASSAGE_UPSERT",
      "entityId": "uuid",
      "tourneeId": "uuid",
      "adresseId": "uuid",
      "version": 1,
      "occurredAt": "2026-11-06T10:00:00Z",
      "payload": {
        "statut": "VENTE",
        "nbCalendriers": 2,
        "montant": "20.00",
        "moyenPaiement": "ESPECES",
        "commentaire": null
      }
    }
  ]
}
```

## Règles
- Idempotence : contrainte unique `(user_id, client_mutation_id)` côté serveur.
- Le serveur renvoie un résultat par mutation : `APPLIED`, `ALREADY_APPLIED`, `CONFLICT`, `REJECTED`.
- Ne pas utiliser `updatedAt` du téléphone comme arbitrage de conflit : horloges non fiables.
- Deux terminaux modifiant le même passage doivent provoquer un conflit explicite ou suivre une règle métier déterministe.
- Une tournée validée rejette les écritures.
- Le client ne marque une mutation comme synchronisée qu'après confirmation serveur.
- Prévoir une stratégie de reprise après fermeture de l'application et perte réseau.

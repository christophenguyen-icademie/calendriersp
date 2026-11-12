# Module mobile CalendrierSP

Le parcours utilisé est intégré à l’application **MobileSP**, dans l’onglet **Calendriers** :

- Page : [`../../MobileSP/app/(tabs)/calendrier.tsx`](../../MobileSP/app/%28tabs%29/calendrier.tsx).
- Écran : [`../../MobileSP/components/Calendriers/CalendriersScreen.tsx`](../../MobileSP/components/Calendriers/CalendriersScreen.tsx).
- Client API : [`../../MobileSP/utils/calendriersApi.ts`](../../MobileSP/utils/calendriersApi.ts).

Ce dossier conserve la maquette Expo 53 d’origine. Pour développer et utiliser le module intégré, lancer Expo depuis MobileSP :

```bash
cd ../../MobileSP
npm install
npx expo start
```

Configurer `EXPO_PUBLIC_CALENDRIERS_API_URL` dans le `.env.local` de MobileSP avec l’adresse du serveur accessible depuis le téléphone, par exemple `http://192.168.1.20:8080/api`. Adapter le port à `WEB_PORT` de CalendrierSP. Voir la section [Mobile du README principal](../README.md#mobile) pour la connexion et les vérifications.

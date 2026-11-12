import * as SQLite from 'expo-sqlite';

let db: SQLite.SQLiteDatabase | null = null;
export async function getDb() {
  if (!db) {
    db = await SQLite.openDatabaseAsync('calendriersp.db');
    await db.execAsync(`
      PRAGMA journal_mode = WAL;
      CREATE TABLE IF NOT EXISTS local_passages (
        id TEXT PRIMARY KEY NOT NULL,
        tournee_id TEXT NOT NULL,
        adresse_id TEXT NOT NULL,
        statut TEXT NOT NULL DEFAULT 'NON_VISITEE',
        nb_calendriers INTEGER NOT NULL DEFAULT 0,
        montant TEXT NOT NULL DEFAULT '0.00',
        moyen_paiement TEXT,
        commentaire TEXT,
        updated_at TEXT NOT NULL,
        synced INTEGER NOT NULL DEFAULT 0
      );
      CREATE UNIQUE INDEX IF NOT EXISTS idx_local_passage_route_address
      ON local_passages(tournee_id, adresse_id);
    `);
  }
  return db;
}

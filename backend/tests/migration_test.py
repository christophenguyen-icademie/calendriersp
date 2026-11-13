"""Backfill test in a disposable database on compose.test.yml, never the local database."""
import json
import re
import subprocess

COMPOSE = ["docker", "compose", "-f", "compose.test.yml"]
DATABASE = "calendriersp_migration_test"


def sql(statement, database=DATABASE):
    return subprocess.check_output(COMPOSE + ["exec", "-T", "database", "psql", "-v", "ON_ERROR_STOP=1", "-U", "calendriersp_test", "-d", database, "-At"], input=statement, text=True)


def migrate(target):
    subprocess.run(COMPOSE + ["run", "--rm", "-T", "--no-deps", "--user", "www-data", "-e",
        f"DATABASE_URL=postgresql://calendriersp_test:test_only_password@database:5432/{DATABASE}?serverVersion=16&charset=utf8",
        "backend", "php", "bin/console", "doctrine:migrations:migrate", target, "--no-interaction"], check=True)


sql(f"CREATE DATABASE {DATABASE};", "postgres")
try:
    migrate("DoctrineMigrations\\Version20261110142300")
    sql("""
        INSERT INTO amicale (id,nom,departement,parametres,created_at)
        VALUES ('10000000-0000-4000-8000-000000000001','Migration historique','10','{"campagne":2027}',CURRENT_TIMESTAMP);
        INSERT INTO secteur (id,nom,type,amicale_id)
        VALUES ('10000000-0000-4000-8000-000000000002','Secteur conservé','LIBRE','10000000-0000-4000-8000-000000000001');
        INSERT INTO adresse (id,secteur_id,ligne1,complement,code_postal,ville)
        VALUES ('10000000-0000-4000-8000-000000000005','10000000-0000-4000-8000-000000000002','12 rue historique','Entrée par la cour','10000','Troyes');
        INSERT INTO tournee (id,nom,statut,date_debut,stock_calendriers,created_at,amicale_id,secteur_id)
        VALUES ('10000000-0000-4000-8000-000000000003','Historique 2024','PREPAREE','2024-11-10',10,CURRENT_TIMESTAMP,'10000000-0000-4000-8000-000000000001','10000000-0000-4000-8000-000000000002'),
               ('10000000-0000-4000-8000-000000000004','Historique 2026','PREPAREE','2026-11-10',20,CURRENT_TIMESTAMP,'10000000-0000-4000-8000-000000000001','10000000-0000-4000-8000-000000000002');
        INSERT INTO app_user (id,amicale_id,email,roles,password,is_active,created_at)
        VALUES ('10000000-0000-4000-8000-000000000006','10000000-0000-4000-8000-000000000001','migration@example.test','[]','not-a-password',TRUE,CURRENT_TIMESTAMP);
        INSERT INTO passage (id,tournee_id,adresse_id,saisi_par_id,statut,nb_calendriers,montant,moyen_paiement,commentaire,updated_at)
        VALUES ('10000000-0000-4000-8000-000000000007','10000000-0000-4000-8000-000000000003','10000000-0000-4000-8000-000000000005','10000000-0000-4000-8000-000000000006','ABSENT',0,0,NULL,'Visite historique','2024-11-10 21:15:00');
    """)
    sql("""
        INSERT INTO amicale (id,nom,departement,parametres,created_at) VALUES
        ('20000000-0000-4000-8000-000000000001','Équipe de Troyes','10','{}','2026-01-01'),
        ('20000000-0000-4000-8000-000000000002','Equipe de Troyes','10','{}','2026-01-02'),
        ('20000000-0000-4000-8000-000000000003','A','10','{}','2026-01-03'),
        ('20000000-0000-4000-8000-000000000004','Ce nom dépasse très largement vingt caractères','10','{}','2026-01-04'),
        ('20000000-0000-4000-8000-000000000005','','10','{}','2026-01-05');
    """)
    original_ids = sql("SELECT id FROM amicale ORDER BY id;")
    migrate("latest")
    assert sql("SELECT string_agg(annee::text, ',' ORDER BY annee) FROM campagne WHERE amicale_id='10000000-0000-4000-8000-000000000001';").strip() == "2024,2026,2027"
    assert sql("SELECT COUNT(*) FROM tournee t JOIN campagne c ON c.id=t.campagne_id AND c.amicale_id=t.amicale_id AND c.annee=EXTRACT(YEAR FROM t.date_debut);").strip() == "2"
    assert sql("SELECT SUM(stock_calendriers) FROM tournee;").strip() == "30"
    assert sql("SELECT COUNT(*) FROM adresse WHERE complement='Entrée par la cour' AND batiment IS NULL AND etage IS NULL AND appartement IS NULL AND porte IS NULL AND identite IS NULL;").strip() == "1"
    assert sql("SELECT id FROM amicale ORDER BY id;") == original_ids
    assert sql("SELECT date_passage::text || '|' || updated_at::text FROM passage;").strip() == "2024-11-10|2024-11-10 21:15:00"
    assert sql("SELECT COUNT(*) FROM app_user WHERE nom IS NULL AND email='migration@example.test' AND id='10000000-0000-4000-8000-000000000006';").strip() == '1'
    assert sql("SELECT saisi_par_id::text FROM passage;").strip() == '10000000-0000-4000-8000-000000000006'
    identifiers = json.loads(sql("SELECT json_agg(identifiant ORDER BY created_at,id) FROM amicale;"))
    assert len(identifiers) == len(set(identifiers)) == 6
    assert all(re.fullmatch(r"[a-z0-9]{4,20}", code) for code in identifiers), identifiers
    assert identifiers[:3] == ["equipedetroyes", "equipedetroyes2", "amicalea"], identifiers
    assert sql("SELECT COUNT(*) FROM secteur WHERE amicale_id='10000000-0000-4000-8000-000000000001';").strip() == "1"
    for invalid in ["abc", "a" * 21, "code-tiret", "CODE", "equipedetroyes"]:
        try:
            sql(f"UPDATE amicale SET identifiant='{invalid}' WHERE id='10000000-0000-4000-8000-000000000001';")
            raise AssertionError(f"Database accepted invalid or duplicate identifier: {invalid}")
        except subprocess.CalledProcessError:
            pass
    try:
        sql("DELETE FROM campagne WHERE annee=2024;")
        raise AssertionError("Database allowed deleting a referenced campaign")
    except subprocess.CalledProcessError:
        pass
    print("OK: historical tours, campaigns, addresses and UUID references preserved; readable unique identifiers backfilled for accented, duplicate, short and long names; database enforces identifiers and referenced campaign deletion.")
finally:
    sql(f"DROP DATABASE {DATABASE} WITH (FORCE);", "postgres")

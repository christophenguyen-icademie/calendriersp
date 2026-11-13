"""HTTP integration tests against compose.test.yml (never the development database)."""
import http.cookiejar
import json
import math
import subprocess
import threading
import unittest
import urllib.error
import urllib.parse
import urllib.request
from concurrent.futures import ThreadPoolExecutor

BASE = "http://127.0.0.1:18080/api"


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.csrf = ""
        self.csrf = self.request("GET", "/session")["csrfToken"]

    def request(self, method, path, body=None, status=200, csrf=True):
        headers = {"Accept": "application/json"}
        if csrf:
            headers["X-CSRF-Token"] = self.csrf
        data = None if body is None else json.dumps(body).encode()
        if data is not None:
            headers["Content-Type"] = "application/json"
        request = urllib.request.Request(BASE + path, data=data, method=method, headers=headers)
        try:
            response = self.opener.open(request, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        raw = response.read().decode()
        if status is not None:
            assert response.status == status, f"{method} {path}: expected {status}, got {response.status}: {raw}"
        assert "no-store" in response.headers.get("Cache-Control", ""), response.headers
        try:
            result = json.loads(raw)
            return (response.status, result) if status is None else result
        except ValueError:
            raise AssertionError(f"Invalid JSON: {raw}")

    def login(self, credentials):
        self.request("POST", "/login", credentials)
        return self.request("GET", "/me")


class ApiTests(unittest.TestCase):
    next_book = 10000

    @classmethod
    def setUpClass(cls):
        raw = subprocess.check_output(["docker", "compose", "-f", "compose.test.yml", "exec", "-T", "backend", "cat", "/tmp/test-accounts.json"])
        cls.accounts = json.loads(raw)
        cls.a, cls.b = Client(), Client()
        cls.profile_a = cls.a.login(cls.accounts["A"])
        cls.profile_b = cls.b.login(cls.accounts["B"])
        cls.sector_a = cls.a.request("POST", "/secteurs", {"nom": "Secteur A"}, 201)
        cls.sector_b = cls.b.request("POST", "/secteurs", {"nom": "Secteur B"}, 201)
        cls.sector_other = cls.a.request("POST", "/secteurs", {"nom": "Autre secteur A"}, 201)
        cls.addresses = [cls.a.request("POST", "/adresses", {"secteurId": cls.sector_a["id"], "ligne1": f"{i} rue de test", "codePostal": "75001", "ville": "Paris"}, 201) for i in [1, 2]]
        cls.foreign_address = cls.b.request("POST", "/adresses", {"secteurId": cls.sector_b["id"], "ligne1": "1 rue B", "codePostal": "75001", "ville": "Paris"}, 201)
        cls.other_address = cls.a.request("POST", "/adresses", {"secteurId": cls.sector_other["id"], "ligne1": "1 autre rue", "codePostal": "75001", "ville": "Paris"}, 201)
        cls.tour_a = cls.make_tour(cls.a, cls.sector_a, "Tournée A")
        cls.tour_b = cls.make_tour(cls.b, cls.sector_b, "Tournée B")
        cls.tour_private = cls.make_tour(cls.a, cls.sector_a, "Non affectée")
        cls.book_a = cls.make_book(cls.a, cls.tour_a)
        cls.book_b = cls.make_book(cls.b, cls.tour_b)
        cls.worker = cls.a.request("POST", "/sapeurs", {"email": "worker@example.test", "password": "Worker-password-123!", "role": "ROLE_SAPEUR"}, 201)
        cls.a.request("POST", f'/tournees/{cls.tour_a["id"]}/affectations', {"userId": cls.worker["id"]})
        cls.worker_credentials = {"amicaleId": cls.accounts["A"]["amicaleId"], "email": "worker@example.test", "password": "Worker-password-123!"}
        cls.w = Client()
        cls.w.login(cls.worker_credentials)

    @staticmethod
    def make_tour(client, sector, name, year=2026):
        return client.request("POST", "/tournees", {"nom": name, "secteurId": sector["id"], "dateDebut": f"{year}-11-10", "stockCalendriers": 50}, 201)

    def path(self, tour=None, address=None):
        return f'/tournees/{(tour or self.tour_a)["id"]}/passages/{(address or self.addresses[0])["id"]}'

    @classmethod
    def make_book(cls, client, tour, count=None):
        cls.next_book += 1000
        data = {"numero": f'Carnet-{cls.next_book}', "premierRecu": cls.next_book}
        if count is not None:
            data["nombreRecus"] = count
        return client.request("POST", f'/tournees/{tour["id"]}/carnets', data, 201)

    @staticmethod
    def receipt(book, index=0):
        return {"carnetId": book["id"], "recuId": book["recus"][index]["id"]}

    @classmethod
    def sale(cls, amount="0.10", nb=1, receipt=None):
        return {"statut": "VENTE", "nbCalendriers": nb, "montant": amount, "moyenPaiement": "ESPECES", "commentaire": None, **(receipt or cls.receipt(cls.book_a))}

    def housing_workspace(self, year=2091):
        sector = self.a.request("POST", "/secteurs", {"nom": self.id()}, 201)
        address = self.a.request("POST", "/adresses", {"secteurId": sector["id"], "ligne1": "12 bis rue des Appartements", "codePostal": "10000", "ville": "Troyes"}, 201)
        tour = self.make_tour(self.a, sector, self.id(), year)
        book = self.make_book(self.a, tour)
        return sector, address, tour, book

    def test_activity_metrics_separate_calendars_and_remaining_addresses(self):
        sector = self.a.request("POST", "/secteurs", {"nom": self.id()}, 201)
        addresses = [self.a.request("POST", "/adresses", {"secteurId": sector["id"], "ligne1": f"{i} rue des Compteurs", "codePostal": "10000", "ville": "Troyes"}, 201) for i in range(1, 8)]
        tour = self.make_tour(self.a, sector, "Compteurs indépendants", 2086)
        book = self.make_book(self.a, tour)
        self.a.request("PUT", self.path(tour, addresses[0]), self.sale("15.00", 3, self.receipt(book)))
        self.a.request("PUT", self.path(tour, addresses[1]), {"statut": "DON", **self.receipt(book, 1)})
        for address, status in zip(addresses[2:], ["REFUS", "ABSENT", "A_REVOIR", "NON_VISITEE"]):
            self.a.request("PUT", self.path(tour, address), {"statut": status, "nbCalendriers": 0, "montant": "0.00"})
        expected = {"calendriersVendus": 3, "calendriersDonnes": 1, "calendriersDistribues": 4, "stockCalendriers": 50,
                    "adresses": 7, "traitees": 5, "adressesAVisiter": 2}
        fresh = Client()
        fresh.login(self.accounts["A"])
        detail = fresh.request("GET", f'/tournees/{tour["id"]}')
        listed = next(t for t in fresh.request("GET", "/tournees") if t["id"] == tour["id"])
        stats = fresh.request("GET", "/dashboard?campagne=2086")["stats"]
        for metrics in [detail, listed, stats]:
            for key, value in expected.items():
                self.assertEqual(value, metrics[key], key)
            self.assertEqual("15.00", metrics["montant"])
        # Corrections replace the current result; a cancelled receipt is not a distributed calendar.
        fresh.request("PUT", self.path(tour, addresses[0]), {"statut": "DON", **self.receipt(book)})
        corrected = fresh.request("GET", "/dashboard?campagne=2086")["stats"]
        self.assertEqual(0, corrected["calendriersVendus"])
        self.assertEqual(2, corrected["calendriersDonnes"])
        self.assertEqual(2, corrected["calendriersDistribues"])
        self.assertEqual(2, corrected["adressesAVisiter"])
        fresh.request("PUT", self.path(tour, addresses[1]), {"statut": "NON_VISITEE", "nbCalendriers": 0, "montant": "0.00"})
        corrected = fresh.request("GET", "/dashboard?campagne=2086")["stats"]
        self.assertEqual(1, corrected["calendriersDistribues"])
        self.assertEqual(3, corrected["adressesAVisiter"])
        self.assertEqual("0.00", corrected["montant"])
        self.assertEqual(1, fresh.request("GET", f'/tournees/{tour["id"]}/carnets')[0]["recusAnnules"])

    def test_activity_campaign_totals_are_scoped_and_handle_empty_or_zero_stock(self):
        for metrics in [self.a.request("GET", "/dashboard?campagne=2085")["stats"], self.b.request("GET", "/dashboard?campagne=2085")["stats"]]:
            for key in ["calendriersDistribues", "calendriersVendus", "calendriersDonnes", "stockCalendriers", "adressesAVisiter", "adresses"]:
                self.assertEqual(0, metrics[key])
        sector = self.a.request("POST", "/secteurs", {"nom": self.id()}, 201)
        zero = self.a.request("POST", "/tournees", {"nom": "Sans stock ni adresse", "secteurId": sector["id"], "dateDebut": "2085-11-10"}, 201)
        self.assertEqual(0, zero["stockCalendriers"])
        self.assertEqual(0, zero["calendriersDistribues"])
        self.assertEqual(0, zero["adressesAVisiter"])
        address = self.a.request("POST", "/adresses", {"secteurId": sector["id"], "ligne1": "1 rue du Stock", "codePostal": "10000", "ville": "Troyes"}, 201)
        book = self.make_book(self.a, zero)
        self.a.request("PUT", self.path(zero, address), self.sale("10.00", 2, self.receipt(book)))
        self.make_tour(self.a, sector, "Autre tournée du même secteur", 2085)
        self.make_tour(self.b, self.sector_b, "Autre amicale", 2085)
        self.make_tour(self.a, sector, "Autre campagne", 2084)
        stats = self.a.request("GET", "/dashboard?campagne=2085")["stats"]
        self.assertEqual(2, stats["calendriersDistribues"])
        self.assertEqual(50, stats["stockCalendriers"])
        self.assertEqual(2, stats["adresses"])
        self.assertEqual(1, stats["adressesAVisiter"])
        self.assertEqual(0, self.a.request("GET", f'/tournees/{zero["id"]}')["stockCalendriers"])
        self.assertEqual(0, self.b.request("GET", "/dashboard?campagne=2085")["stats"]["calendriersDistribues"])

    def test_housing_cb_and_address_details_persist_with_payment_totals(self):
        sector, address, tour, book = self.housing_workspace()
        details = {"batiment": " A ", "etage": "0", "appartement": "12", "porte": "Droite"}
        payload = {**self.sale("23.45", receipt=self.receipt(book)), "moyenPaiement": "CB", **details}
        result = self.a.request("PUT", self.path(tour, address), payload)
        self.assertEqual("CB", result["moyenPaiement"])
        fresh = Client()
        fresh.login(self.accounts["A"])
        saved = fresh.request("GET", f'/tournees/{tour["id"]}')
        self.assertEqual("23.45", saved["paiements"]["CB"])
        item = saved["listeAdresses"][0]
        for key, value in details.items():
            self.assertEqual(value.strip(), item[key])
        self.assertEqual("23.45", fresh.request("GET", "/dashboard?campagne=2091")["stats"]["paiements"]["CB"])
        # Old clients omitting the new fields must preserve them.
        self.a.request("PUT", self.path(tour, address), {k: v for k, v in payload.items() if k not in details})
        item = fresh.request("GET", f'/tournees/{tour["id"]}')["listeAdresses"][0]
        self.assertEqual("12", item["appartement"])
        self.a.request("PUT", self.path(tour, address), {**payload, "porte": None})
        self.assertIsNone(fresh.request("GET", f'/tournees/{tour["id"]}')["listeAdresses"][0]["porte"])
        for invalid in [123, [], "x" * 81]:
            self.a.request("PUT", self.path(tour, address), {**payload, "appartement": invalid}, status=422)
        self.assertEqual("12", fresh.request("GET", f'/tournees/{tour["id"]}')["listeAdresses"][0]["appartement"])

    def test_housing_duplication_is_atomic_distinct_and_available_to_assigned_workers(self):
        sector, address, tour, book = self.housing_workspace(2092)
        worker = self.a.request("POST", "/sapeurs", {"email": "housing-worker@example.test", "password": "Worker-password-123!", "role": "ROLE_SAPEUR"}, 201)
        self.a.request("POST", f'/tournees/{tour["id"]}/affectations', {"userId": worker["id"]})
        client = Client()
        client.login({"amicaleId": self.accounts["A"]["amicaleId"], "email": worker["email"], "password": "Worker-password-123!"})
        path = f'/tournees/{tour["id"]}/adresses/{address["id"]}/dupliquer'
        payload = {**self.sale("5.00", receipt=self.receipt(book)), "batiment": "A", "etage": "1", "appartement": "1", "porte": "Gauche"}
        self.w.request("POST", path, payload, status=404)
        self.b.request("POST", path, payload, status=404)
        client.request("POST", path, payload, status=403, csrf=False)
        copy = client.request("POST", path, payload, 201)
        self.assertNotEqual(address["id"], copy["id"])
        for key in ["ligne1", "codePostal", "ville", "secteurId", "latitude", "longitude"]:
            self.assertEqual(address[key], copy[key])
        self.assertEqual("1", copy["appartement"])
        self.assertEqual("5.00", copy["passage"]["montant"])
        client.request("POST", path, {**payload, **self.receipt(book, 1)}, status=409)
        client.request("POST", path, {**payload, "appartement": "2"}, status=409)
        client.request("POST", path, {**self.sale(receipt=self.receipt(book, 1))}, status=422)
        self.assertEqual(2, client.request("GET", f'/tournees/{tour["id"]}')["adresses"])
        self.assertFalse(client.request("GET", f'/tournees/{tour["id"]}/carnets')[0]["recus"][1]["utilise"])
        donation = client.request("POST", path, {"statut": "DON", "appartement": "2", **self.receipt(book, 1)}, 201)
        self.assertEqual("DON", donation["passage"]["statut"])
        # Editing one apartment to collide with another also rolls the whole write back.
        client.request("PUT", self.path(tour, copy), {"statut": "DON", "batiment": None, "etage": None, "porte": None, "appartement": "2", **self.receipt(book)}, status=409)
        self.assertEqual("VENTE", next(a for a in client.request("GET", f'/tournees/{tour["id"]}')["listeAdresses"] if a["id"] == copy["id"])["passage"]["statut"])
        # From a completed apartment the location fields are inherited, but a different unit is required.
        copy_path = f'/tournees/{tour["id"]}/adresses/{copy["id"]}/dupliquer'
        client.request("POST", copy_path, {"statut": "REFUS", "nbCalendriers": 0, "montant": "0.00"}, status=422)
        third = client.request("POST", copy_path, {"statut": "REFUS", "nbCalendriers": 0, "montant": "0.00", "appartement": "3"}, 201)
        self.assertEqual("A", third["batiment"])
        self.assertIsNone(third["passage"]["recu"])
        client.request("POST", f'/tournees/{tour["id"]}/adresses/{self.other_address["id"]}/dupliquer', payload, status=404)

    def test_housing_import_identity_and_coordinates_survive_apartment_creation(self):
        sector = self.geo_sector("Appartements importés")
        path = f'/secteurs/{sector["id"]}/adresses'
        preview = self.a.request("POST", path + "/preview", {"source": "BAN"})
        self.a.request("POST", path + "/import", {"token": preview["token"]})
        tour = self.make_tour(self.a, sector, "Logements géolocalisés")
        address = self.a.request("GET", f'/tournees/{tour["id"]}')["listeAdresses"][0]
        self.assertIsNotNone(address["sourceId"])
        self.assertIsNotNone(address["latitude"])
        refusal = {"statut": "REFUS", "nbCalendriers": 0, "montant": "0.00", "appartement": "101"}
        duplicate = self.a.request("POST", f'/tournees/{tour["id"]}/adresses/{address["id"]}/dupliquer', refusal, 201)
        self.assertIsNone(duplicate["sourceId"])
        self.assertEqual(address["latitude"], duplicate["latitude"])
        self.assertEqual(address["longitude"], duplicate["longitude"])
        self.a.request("PUT", self.path(tour, address), {**refusal, "appartement": "102"})
        preview = self.a.request("POST", path + "/preview", {"source": "BAN"})
        self.assertEqual(0, preview["nouvelles"])

    def test_housing_validated_passages_protect_address_details_and_duplication(self):
        sector, address, tour, book = self.housing_workspace(2093)
        refusal = {"statut": "REFUS", "nbCalendriers": 0, "montant": "0.00", "appartement": "1"}
        self.a.request("PUT", self.path(tour, address), refusal)
        self.a.request("PATCH", f'/tournees/{tour["id"]}/statut', {"statut": "TERMINEE"})
        self.a.request("POST", f'/tournees/{tour["id"]}/valider', {})
        self.a.request("POST", f'/tournees/{tour["id"]}/adresses/{address["id"]}/dupliquer', {**refusal, "appartement": "2"}, status=409)
        other = self.make_tour(self.a, sector, "Année suivante", 2094)
        self.a.request("PUT", self.path(other, address), {**refusal, "appartement": "2"}, status=409)
        self.a.request("PUT", self.path(other, address), refusal)
        copy = self.a.request("POST", f'/tournees/{other["id"]}/adresses/{address["id"]}/dupliquer', {**refusal, "appartement": "2"}, 201)
        self.assertEqual("2", copy["appartement"])
        self.assertEqual("1", next(a for a in self.a.request("GET", f'/tournees/{tour["id"]}')["listeAdresses"] if a["id"] == address["id"])["appartement"])

    def test_housing_concurrent_duplicate_creates_one_apartment_and_consumes_one_receipt(self):
        sector, address, tour, book = self.housing_workspace(2095)
        clients = [Client(), Client()]
        for client in clients:
            client.login(self.accounts["A"])
        barrier = threading.Barrier(2)
        def duplicate(index):
            barrier.wait()
            return clients[index].request("POST", f'/tournees/{tour["id"]}/adresses/{address["id"]}/dupliquer', {**self.sale(receipt=self.receipt(book, index)), "appartement": "10"}, status=None)[0]
        with ThreadPoolExecutor(max_workers=2) as executor:
            statuses = sorted(executor.map(duplicate, range(2)))
        self.assertEqual([201, 409], statuses)
        detail = self.a.request("GET", f'/tournees/{tour["id"]}')
        self.assertEqual(2, detail["adresses"])
        self.assertEqual(1, detail["traitees"])
        self.assertEqual(1, detail["carnets"][0]["recusUtilises"])

    def test_new_address_and_sale_are_atomic_scoped_and_available_to_assigned_workers(self):
        sector = self.a.request("POST", "/secteurs", {"nom": "Vente exceptionnelle sans adresse initiale"}, 201)
        tour = self.make_tour(self.a, sector, "Vente hors référentiel", 2096)
        book = self.make_book(self.a, tour)
        worker = self.a.request("POST", "/sapeurs", {"email": "exception-worker@example.test", "password": "Worker-password-123!", "role": "ROLE_SAPEUR"}, 201)
        client = Client()
        client.login({"amicaleId": self.accounts["A"]["amicaleId"], "email": worker["email"], "password": "Worker-password-123!"})
        path = f'/tournees/{tour["id"]}/adresses'
        payload = {**self.sale("14.50", receipt=self.receipt(book)), "moyenPaiement": "CB", "ligne1": "18 T Rue Traversière", "codePostal": "10000", "ville": "Troyes", "batiment": "A", "appartement": "10", "secteurId": self.sector_b["id"]}
        client.request("POST", path, payload, status=404)
        self.b.request("POST", path, payload, status=404)
        self.a.request("POST", f'/tournees/{self.tour_b["id"]}/adresses', payload, status=404)
        self.a.request("POST", path, payload, status=403, csrf=False)
        self.a.request("POST", path, {**payload, "ligne1": " "}, status=422)
        self.a.request("POST", path, {**payload, "appartement": []}, status=422)
        self.a.request("POST", path, {**payload, "recuId": self.book_b["recus"][0]["id"]}, status=404)
        self.assertEqual(0, self.a.request("GET", f'/tournees/{tour["id"]}')["adresses"])
        self.a.request("POST", f'/tournees/{tour["id"]}/affectations', {"userId": worker["id"]})
        saved = client.request("POST", path, payload, 201)
        self.assertEqual(sector["id"], saved["secteurId"])
        self.assertEqual("14.50", saved["passage"]["montant"])
        self.assertEqual("CB", saved["passage"]["moyenPaiement"])
        self.assertEqual("10", saved["appartement"])
        self.assertIsNone(saved["sourceId"])
        # A duplicate address or unavailable receipt must leave neither a row nor a consumed new receipt.
        client.request("POST", path, {**payload, **self.receipt(book, 1)}, status=409)
        client.request("POST", path, {**payload, "ligne1": "22 rue Nouvelle"}, status=409)
        client.request("POST", path, {**payload, "ligne1": "24 rue Nouvelle", "montant": "-1.00", **self.receipt(book, 1)}, status=422)
        detail = client.request("GET", f'/tournees/{tour["id"]}')
        self.assertEqual(1, detail["adresses"])
        self.assertEqual(1, detail["traitees"])
        self.assertEqual("14.50", detail["paiements"]["CB"])
        self.assertFalse(detail["carnets"][0]["recus"][1]["utilise"])
        fresh = Client()
        fresh.login(self.accounts["A"])
        self.assertEqual(saved, fresh.request("GET", f'/tournees/{tour["id"]}')["listeAdresses"][0])
        self.a.request("PATCH", f'/tournees/{tour["id"]}/statut', {"statut": "TERMINEE"})
        self.a.request("POST", f'/tournees/{tour["id"]}/valider', {})
        client.request("POST", path, {**payload, "ligne1": "26 rue Nouvelle", **self.receipt(book, 1)}, status=409)
        self.assertEqual(1, fresh.request("GET", f'/tournees/{tour["id"]}')["adresses"])

    def test_new_address_concurrent_creations_preserve_receipts_and_one_passage(self):
        sector, address, tour, book = self.housing_workspace(2097)
        clients = [Client(), Client()]
        for client in clients:
            client.login(self.accounts["A"])
        barrier = threading.Barrier(2)
        def create(index):
            barrier.wait()
            return clients[index].request("POST", f'/tournees/{tour["id"]}/adresses', {**self.sale(receipt=self.receipt(book, index)), "ligne1": "99 nouvelle rue", "codePostal": "10000", "ville": "Troyes"}, status=None)[0]
        with ThreadPoolExecutor(max_workers=2) as executor:
            statuses = sorted(executor.map(create, range(2)))
        self.assertEqual([201, 409], statuses)
        detail = self.a.request("GET", f'/tournees/{tour["id"]}')
        self.assertEqual(2, detail["adresses"])
        self.assertEqual(1, detail["traitees"])
        self.assertEqual(1, detail["carnets"][0]["recusUtilises"])

    def test_geocoder_official_street_city_and_address_results(self):
        results = self.a.request('GET', '/carte/recherche?q=' + urllib.parse.quote('rue blanqui, troyes'))
        self.assertEqual({'label': 'Boulevard Blanqui 10000 Troyes', 'latitude': 48.3038, 'longitude': 4.052527}, results[0])
        self.assertEqual('10 Boulevard Blanqui 10000 Troyes', results[1]['label'])
        self.assertEqual(results, self.a.request('GET', '/carte/recherche?q=' + urllib.parse.quote('rue blanqui, troyes')))
        city = self.a.request('GET', '/carte/recherche?q=Troyes')
        self.assertEqual('Troyes', city[0]['label'])
        self.assertEqual(4.0748, city[0]['longitude'])
        self.w.request('GET', '/carte/recherche?q=Troyes', status=403)
        Client().request('GET', '/carte/recherche?q=Troyes', status=401)
        self.a.request('GET', '/carte/recherche?q=x', status=422)

    def test_geocoder_empty_results_and_upstream_errors_are_distinguished(self):
        self.assertEqual([], self.a.request('GET', '/carte/recherche?q=aucun-resultat'))
        for query in ['service-indisponible', 'reponse-invalide', 'coordonnees-invalides']:
            response = self.a.request('GET', '/carte/recherche?q=' + query, status=503)
            self.assertEqual('SOURCE_UNAVAILABLE', response['code'])

    def test_authentication_and_csrf(self):
        anonymous = Client()
        anonymous.request("GET", "/tournees", status=401)
        anonymous.request("POST", "/login", self.accounts["A"], status=403, csrf=False)
        anonymous.request("POST", "/login", {**self.accounts["A"], "password": "incorrect"}, status=401)
        anonymous.request("POST", "/login", {**self.accounts["A"], "amicaleId": "invalid"}, status=401)
        self.a.request("POST", "/secteurs", {"nom": "CSRF"}, status=403, csrf=False)
        fresh = Client()
        fresh.login(self.accounts["A"])
        fresh.request("POST", "/logout", {})
        fresh.request("GET", "/me", status=401)

    def test_scoped_lists_and_same_email_in_two_tenants(self):
        self.assertNotEqual(self.profile_a["amicale"]["id"], self.profile_b["amicale"]["id"])
        sectors = self.a.request("GET", "/secteurs")
        self.assertIn(self.sector_a["id"], [s["id"] for s in sectors])
        self.assertNotIn(self.sector_b["id"], [s["id"] for s in sectors])
        self.assertNotIn(self.foreign_address["id"], [a["id"] for a in self.a.request("GET", "/adresses")])
        self.assertNotIn(self.tour_b["id"], [t["id"] for t in self.a.request("GET", "/tournees")])
        forged = self.a.request("POST", "/secteurs", {"nom": "Tenant choisi par le serveur", "amicaleId": self.accounts["B"]["amicaleId"]}, 201)
        self.assertNotIn(forged["id"], [s["id"] for s in self.b.request("GET", "/secteurs")])
        for member in self.a.request("GET", "/sapeurs"):
            self.assertNotIn("password", member)

    def test_cross_tenant_and_cross_sector_references(self):
        self.a.request("GET", f'/tournees/{self.tour_b["id"]}', status=404)
        self.a.request("GET", "/tournees/not-a-uuid", status=404)
        self.a.request("POST", "/tournees", {"nom": "Forgée", "secteurId": self.sector_b["id"], "dateDebut": "2026-11-10"}, status=404)
        self.a.request("POST", "/adresses", {"secteurId": self.sector_b["id"], "ligne1": "1 voie", "codePostal": "75001", "ville": "Paris"}, status=404)
        self.a.request("POST", f'/tournees/{self.tour_a["id"]}/affectations', {"userId": self.profile_b["id"]}, status=404)
        self.a.request("PUT", self.path(address=self.foreign_address), self.sale(), status=404)
        self.a.request("PUT", self.path(address=self.other_address), self.sale(), status=404)
        self.a.request("PUT", self.path(tour=self.tour_b, address=self.foreign_address), self.sale(), status=404)

    def test_worker_permissions_and_mobile_read_routes(self):
        tours = self.w.request("GET", "/tournees")
        self.assertEqual([self.tour_a["id"]], [t["id"] for t in tours])
        self.assertEqual(tours, self.w.request("GET", "/mobile/tournees"))
        detail = self.w.request("GET", f'/mobile/tournees/{self.tour_a["id"]}/adresses')
        self.assertEqual(2, len(detail["listeAdresses"]))
        self.w.request("GET", f'/tournees/{self.tour_private["id"]}', status=404)
        self.w.request("PUT", self.path(tour=self.tour_private), self.sale(), status=404)
        self.w.request("GET", "/secteurs", status=403)
        self.w.request("GET", "/adresses", status=403)
        self.w.request("GET", "/sapeurs", status=403)
        self.w.request("GET", "/dashboard", status=403)
        self.w.request("POST", "/tournees", {}, status=403)
        self.w.request("PATCH", "/parametres", {}, status=403)
        self.w.request("POST", f'/tournees/{self.tour_a["id"]}/valider', {}, status=403)
        self.w.request("PUT", self.path(), self.sale())

    def team_workspace(self):
        tour = self.make_tour(self.a, self.sector_a, self.id(), 2092)
        members = [self.a.request("POST", "/sapeurs", {
            "email": f'team-{tour["id"]}-{i}@example.test', "password": "Team-password-123!", "role": "ROLE_SAPEUR",
        }, 201) for i in range(3)]
        return tour, members

    def test_team_bulk_assignment_persists_without_duplicates_and_grants_access(self):
        tour, members = self.team_workspace()
        path = f'/tournees/{tour["id"]}'
        ids = [member["id"] for member in members]
        result = self.a.request("POST", path + "/affectations", {"userIds": ids[:2]})
        self.assertEqual(set(ids[:2]), {member["id"] for member in result["equipe"]})
        # Individual clients remain compatible, and adding members preserves the existing team.
        self.a.request("POST", path + "/affectations", {"userId": ids[2]})
        result = self.a.request("POST", path + "/affectations", {"userIds": [*ids, ids[0], ids[1].upper()]})
        self.assertEqual(3, len(result["equipe"]))
        self.assertEqual(set(ids), {member["id"] for member in result["equipe"]})
        fresh = Client()
        fresh.login(self.accounts["A"])
        self.assertEqual(set(ids), {member["id"] for member in fresh.request("GET", path)["equipe"]})
        clients = []
        for member in members:
            client = Client()
            client.login({"amicaleId": self.accounts["A"]["amicaleId"], "email": member["email"], "password": "Team-password-123!"})
            self.assertIn(tour["id"], [t["id"] for t in client.request("GET", "/mobile/tournees")])
            self.assertEqual(3, len(client.request("GET", path)["equipe"]))
            self.assertEqual(tour["id"], client.request("GET", path.replace("/tournees/", "/mobile/tournees/") + "/adresses")["id"])
            clients.append(client)
        passage = clients[0].request("PUT", self.path(tour), {"statut": "REFUS", "nbCalendriers": 0, "montant": "0.00"})
        clients[1].request("PUT", self.path(tour, self.addresses[1]), {"statut": "ABSENT", "nbCalendriers": 0, "montant": "0.00"})
        clients[2].request("PUT", self.path(tour, self.addresses[1]), {"statut": "A_REVOIR", "nbCalendriers": 0, "montant": "0.00"})
        self.a.request("DELETE", path + f"/affectations/{ids[0]}")
        clients[0].request("GET", path, status=404)
        self.assertNotIn(tour["id"], [t["id"] for t in clients[0].request("GET", "/mobile/tournees")])
        remaining = clients[1].request("GET", path)
        self.assertEqual(set(ids[1:]), {member["id"] for member in remaining["equipe"]})
        self.assertEqual(passage["id"], next(a for a in remaining["listeAdresses"] if a["id"] == self.addresses[0]["id"])["passage"]["id"])

    def test_team_bulk_assignment_rejects_invalid_or_foreign_members_atomically(self):
        tour, members = self.team_workspace()
        path = f'/tournees/{tour["id"]}'
        member_id = members[0]["id"]
        invalid_bodies = [{}, {"userIds": []}, {"userIds": None}, {"userIds": member_id}, {"userIds": {"member": member_id}},
                          {"userIds": [member_id, "invalid"]}, {"userIds": [member_id, None]}, {"userIds": [member_id, 42]},
                          {"userIds": [member_id], "userId": member_id}]
        for body in invalid_bodies:
            with self.subTest(body=body):
                self.a.request("POST", path + "/affectations", body, status=422)
                self.assertEqual([], self.a.request("GET", path)["equipe"])
        for invalid_id in [self.profile_b["id"], "00000000-0000-4000-8000-000000000000"]:
            self.a.request("POST", path + "/affectations", {"userIds": [member_id, invalid_id]}, status=404)
            self.assertEqual([], self.a.request("GET", path)["equipe"])

    def test_team_bulk_assignment_rejects_inactive_accounts_atomically(self):
        tour, members = self.team_workspace()
        path = f'/tournees/{tour["id"]}'
        inactive_id = members[1]["id"]
        subprocess.check_call(["docker", "compose", "-f", "compose.test.yml", "exec", "-T", "database", "psql", "-U", "calendriersp_test", "-d", "calendriersp_test",
                               "-c", f"UPDATE app_user SET is_active = false WHERE id = '{inactive_id}'"], stdout=subprocess.DEVNULL)
        for ids in [[members[0]["id"], inactive_id], [inactive_id, members[0]["id"]]]:
            self.a.request("POST", path + "/affectations", {"userIds": ids}, status=409)
            self.assertEqual([], self.a.request("GET", path)["equipe"])

    def test_team_bulk_assignment_enforces_permissions_csrf_and_validation_lock(self):
        tour, members = self.team_workspace()
        path = f'/tournees/{tour["id"]}'
        payload = {"userIds": [member["id"] for member in members]}
        self.a.request("POST", path + "/affectations", payload, status=403, csrf=False)
        self.b.request("POST", path + "/affectations", payload, status=404)
        self.w.request("POST", f'/tournees/{self.tour_a["id"]}/affectations', payload, status=403)
        manager = self.a.request("POST", "/sapeurs", {"email": f'team-manager-{tour["id"]}@example.test', "password": "Team-password-123!", "role": "ROLE_RESPONSABLE"}, 201)
        client = Client()
        client.login({"amicaleId": self.accounts["A"]["amicaleId"], "email": manager["email"], "password": "Team-password-123!"})
        result = client.request("POST", path + "/affectations", {"userIds": [members[0]["id"]]})
        self.assertEqual([members[0]["id"]], [member["id"] for member in result["equipe"]])
        self.a.request("PATCH", path + "/statut", {"statut": "TERMINEE"})
        self.a.request("POST", path + "/valider", {})
        client.request("POST", path + "/affectations", payload, status=409)
        client.request("DELETE", path + f'/affectations/{members[0]["id"]}', status=409)
        self.assertEqual([members[0]["id"]], [member["id"] for member in self.a.request("GET", path)["equipe"]])

    def test_input_validation(self):
        self.a.request("POST", "/secteurs", {"nom": " "}, status=422)
        self.a.request("POST", "/tournees", {"nom": "Date invalide", "secteurId": self.sector_a["id"], "dateDebut": "2026-02-31"}, status=422)
        self.a.request("POST", "/tournees", {"nom": "Stock invalide", "secteurId": self.sector_a["id"], "dateDebut": "2026-11-10", "stockCalendriers": -1}, status=422)
        self.a.request("POST", "/sapeurs", {"email": "invalid", "password": "Long-password-123!", "role": "ROLE_SAPEUR"}, status=422)
        self.a.request("POST", "/sapeurs", {"email": "test@example.test", "password": "short", "role": "ROLE_SAPEUR"}, status=422)
        self.a.request("POST", "/sapeurs", {"email": "test@example.test", "password": "Long-password-123!", "role": "ROLE_SUPER_ADMIN"}, status=422)
        for amount in ["-1.00", "1.001", "NaN", "10000000.00", "1e3"]:
            self.a.request("PUT", self.path(), self.sale(amount), status=422)
        self.a.request("PUT", self.path(), self.sale(nb=0), status=422)
        self.a.request("PUT", self.path(), {**self.sale(), "statut": "REFUS"}, status=422)
        self.a.request("PUT", self.path(), {**self.sale(), "moyenPaiement": None}, status=422)
        self.a.request("GET", "/dashboard?campagne=9999", status=422)
        self.a.request("POST", "/sapeurs", {"email": "admin@example.test", "password": "Long-password-123!", "role": "ROLE_SAPEUR"}, status=409)

    @staticmethod
    def polygon(lon=2.3515, lat=48.8565, size=0.001):
        return {"type": "Polygon", "coordinates": [[[lon, lat], [lon + size, lat], [lon + size, lat + size], [lon, lat + size], [lon, lat]]]}

    def geo_sector(self, name="Secteur cartographique", geometry=None):
        return self.a.request("POST", "/secteurs", {"nom": name, "polygone": geometry or self.polygon()}, 201)

    def test_polygon_validation_and_tenant_permissions(self):
        sector = self.geo_sector()
        self.assertEqual(self.polygon(), sector["polygone"])
        fresh = Client()
        fresh.login(self.accounts["A"])
        self.assertEqual(sector, fresh.request("GET", f'/secteurs/{sector["id"]}'))
        self.b.request("GET", f'/secteurs/{sector["id"]}', status=404)
        self.b.request("PATCH", f'/secteurs/{sector["id"]}', {"polygone": self.polygon()}, status=404)
        self.b.request("POST", f'/secteurs/{sector["id"]}/adresses/preview', {}, status=404)
        self.w.request("GET", f'/secteurs/{sector["id"]}', status=403)
        self.w.request("PATCH", f'/secteurs/{sector["id"]}', {}, status=403)
        self.w.request("POST", f'/secteurs/{sector["id"]}/adresses/preview', {}, status=403)
        invalid = [
            {"type": "Point", "coordinates": [2, 48]},
            {"type": "Polygon", "coordinates": [[[2, 48], [2.01, 48], [2.01, 48.01], [2, 48.01]]]},
            {"type": "Polygon", "coordinates": [[[2, 48], [2.01, 48.01], [2, 48.01], [2.01, 48], [2, 48]]]},
            self.polygon(size=5), self.polygon(lat=100), self.polygon(size=0),
            {"type": "Polygon", "coordinates": [[[2, 48]] * 502]},
        ]
        for geometry in invalid:
            with self.subTest(geometry=geometry):
                self.a.request("POST", "/secteurs", {"nom": "Invalide", "polygone": geometry}, status=422)
        self.a.request("POST", f'/secteurs/{self.sector_a["id"]}/adresses/preview', {}, status=409)
        changed = self.a.request("PATCH", f'/secteurs/{sector["id"]}', {"nom": "Secteur renommé", "polygone": self.polygon(size=0.002)})
        self.assertEqual("Secteur renommé", changed["nom"])
        self.assertEqual(self.polygon(size=0.002), changed["polygone"])

    def test_polygon_import_clipping_deduplication_and_persistence(self):
        sector = self.geo_sector("Import dédupliqué")
        path = f'/secteurs/{sector["id"]}/adresses'
        # A manually created address must not be duplicated by extraction.
        self.a.request("POST", "/adresses", {"secteurId": sector["id"], "ligne1": "1 rue du Polygone", "codePostal": "75004", "ville": "Paris"}, 201)
        preview = self.a.request("POST", path + "/preview", {"source": "OSM", "codePostal": "75004", "ville": "Paris"})
        self.assertEqual(5, preview["total"])
        self.assertEqual(3, preview["nouvelles"])
        self.assertEqual(2, preview["doublons"])
        self.assertEqual(1, preview["horsPolygone"])
        self.assertEqual(1, preview["ignorees"])
        self.assertEqual(0, preview["incompletes"])
        self.assertEqual(1, len([a for a in self.a.request("GET", "/adresses") if a["secteurId"] == sector["id"]]))
        # A different session cannot commit this session's preview.
        fresh = Client()
        fresh.login(self.accounts["A"])
        fresh.request("POST", path + "/import", {"token": preview["token"]}, status=409)
        result = self.a.request("POST", path + "/import", {"token": preview["token"], "adresses": [{"ligne1": "Forgée"}]})
        self.assertEqual({"creees": 3, "doublons": 2}, result)
        saved = [a for a in fresh.request("GET", "/adresses") if a["secteurId"] == sector["id"]]
        self.assertEqual(4, len(saved))
        self.assertEqual({"1 rue du Polygone", "2 rue du Polygone", "3 rue du Polygone", "6 rue du Polygone"}, {a["ligne1"] for a in saved})
        self.assertEqual(3, sum(a["latitude"] is not None and a["longitude"] is not None for a in saved))
        preview2 = self.a.request("POST", path + "/preview", {"source": "OSM", "codePostal": "75004", "ville": "Paris"})
        self.assertEqual(0, preview2["nouvelles"])
        self.assertEqual({"creees": 0, "doublons": 5}, self.a.request("POST", path + "/import", {"token": preview2["token"]}))
        self.a.request("POST", path + "/import", {"token": preview2["token"]}, status=409)
        self.b.request("POST", path + "/import", {"token": preview["token"]}, status=404)
        self.w.request("POST", path + "/import", {"token": preview["token"]}, status=403)

    def test_polygon_import_rejects_stale_preview_and_handles_missing_fields(self):
        sector = self.geo_sector("Aperçu périmé")
        path = f'/secteurs/{sector["id"]}'
        preview = self.a.request("POST", path + "/adresses/preview", {"source": "OSM"})
        self.assertEqual(1, preview["incompletes"])
        self.a.request("PATCH", path, {"polygone": self.polygon(size=0.002)})
        self.a.request("POST", path + "/adresses/import", {"token": preview["token"]}, status=409)
        self.assertFalse(any(a["secteurId"] == sector["id"] for a in self.a.request("GET", "/adresses")))
        preview = self.a.request("POST", path + "/adresses/preview", {"source": "OSM"})
        self.a.request("POST", path + "/adresses/import", {"token": preview["token"]})
        incomplete = next(a for a in self.a.request("GET", "/adresses") if a["secteurId"] == sector["id"] and a["ligne1"] == '3 rue du Polygone')
        self.assertEqual('', incomplete["codePostal"])
        self.assertEqual('', incomplete["ville"])

    def test_polygon_detailed_boundary_can_extract_addresses(self):
        ring = [[2.352 + 0.001 * math.cos(i * math.tau / 500), 48.8568 + 0.001 * math.sin(i * math.tau / 500)] for i in range(500)]
        ring.append(ring[0])
        sector = self.geo_sector("Contour à 500 sommets", {"type": "Polygon", "coordinates": [ring]})
        preview = self.a.request("POST", f'/secteurs/{sector["id"]}/adresses/preview', {"source": "OSM", "codePostal": "75004", "ville": "Paris"})
        self.assertEqual(5, preview["total"])
        self.assertEqual(4, preview["nouvelles"])

    def test_polygon_concurrent_imports_do_not_duplicate_addresses(self):
        sector = self.geo_sector("Imports concurrents")
        path = f'/secteurs/{sector["id"]}/adresses'
        clients = [Client(), Client()]
        previews = []
        for client in clients:
            client.login(self.accounts["A"])
            previews.append(client.request("POST", path + "/preview", {"source": "OSM", "codePostal": "75004", "ville": "Paris"}))
        barrier = threading.Barrier(2)
        def commit(index):
            barrier.wait(timeout=10)
            return clients[index].request("POST", path + "/import", {"token": previews[index]["token"]})
        with ThreadPoolExecutor(max_workers=2) as pool:
            results = list(pool.map(commit, [0, 1]))
        self.assertEqual([0, 4], sorted(r["creees"] for r in results))
        self.assertEqual(4, len([a for a in self.a.request("GET", "/adresses") if a["secteurId"] == sector["id"]]))

    def test_polygon_upstream_failure_has_no_partial_import(self):
        for latitude in [47.0, 46.0]:
            sector = self.geo_sector("Source indisponible", self.polygon(lat=latitude))
            response = self.a.request("POST", f'/secteurs/{sector["id"]}/adresses/preview', {"source": "OSM"}, status=503)
            self.assertEqual('SOURCE_UNAVAILABLE', response["code"])
            self.assertFalse(any(a["secteurId"] == sector["id"] for a in self.a.request("GET", "/adresses")))
        places = self.a.request("GET", "/carte/recherche?q=Paris")
        self.assertEqual(48.8568, places[0]["latitude"])
        self.w.request("GET", "/carte/recherche?q=Paris", status=403)
        self.a.request("GET", "/carte/recherche?q=x", status=422)

    def test_ban_default_full_import_repetitions_and_cross_source_deduplication(self):
        sector = self.geo_sector("BAN et OSM")
        path = f'/secteurs/{sector["id"]}/adresses'
        # BAN is the default, with no commune/postcode needed and no geocoder limit.
        preview = self.a.request("POST", path + "/preview", {})
        self.assertEqual("Base Adresse Nationale (BAN)", preview["source"])
        self.assertEqual(["75"], preview["departements"])
        self.assertEqual(5, preview["total"])
        self.assertEqual(4, preview["nouvelles"])
        self.assertEqual(0, preview["incompletes"])
        self.assertIn("2 bis rue du Polygone", [a["ligne1"] for a in preview["adresses"]])
        self.assertTrue(all(a["sourceId"].startswith('ban:') for a in preview["adresses"]))
        self.assertEqual({"creees": 4, "doublons": 1}, self.a.request("POST", path + "/import", {"token": preview["token"]}))
        again = self.a.request("POST", path + "/preview", {})
        self.assertEqual(0, again["nouvelles"])
        osm = self.a.request("POST", path + "/preview", {"source": "OSM", "codePostal": "75004", "ville": "Paris"})
        self.assertEqual(2, osm["nouvelles"])
        self.assertEqual({"creees": 2, "doublons": 3}, self.a.request("POST", path + "/import", {"token": osm["token"]}))
        saved = [a for a in self.a.request("GET", "/adresses") if a["secteurId"] == sector["id"]]
        self.assertEqual(6, len(saved))
        self.assertTrue(all(a["latitude"] is not None for a in saved))
        self.a.request("POST", path + "/preview", {"source": "UNKNOWN"}, status=422)

    def test_ban_collects_all_intersected_departments(self):
        sector = self.geo_sector("Frontière départementale", self.polygon(lon=2.3795))
        path = f'/secteurs/{sector["id"]}/adresses'
        preview = self.a.request("POST", path + "/preview", {})
        self.assertEqual(["75", "77"], preview["departements"])
        self.assertEqual({"8 rue Frontiere", "9 rue Frontiere"}, {a["ligne1"] for a in preview["adresses"]})
        self.assertEqual(2, preview["nouvelles"])
        self.assertEqual({"creees": 2, "doublons": 0}, self.a.request("POST", path + "/import", {"token": preview["token"]}))

    def test_ban_rejects_partial_corrupt_and_unavailable_downloads(self):
        # Missing gzip footer, invalid CRC, unexpected CSV columns and HTTP errors.
        for latitude in [43.0, 44.0, 45.0, 46.0]:
            sector = self.geo_sector("BAN indisponible", self.polygon(lat=latitude))
            error = self.a.request("POST", f'/secteurs/{sector["id"]}/adresses/preview', {}, status=503)
            self.assertEqual("SOURCE_UNAVAILABLE", error["code"])
            self.assertFalse(any(a["secteurId"] == sector["id"] for a in self.a.request("GET", "/adresses")))
        sector = self.geo_sector("Hors BAN", self.polygon(lat=42.0))
        self.a.request("POST", f'/secteurs/{sector["id"]}/adresses/preview', {}, status=422)

    def test_donation_comment_persistence_and_sale_conversion(self):
        tour = self.make_tour(self.a, self.sector_a, "Don sans encaissement", 2024)
        book = self.make_book(self.a, tour)
        receipt = self.receipt(book)
        payload = {"statut": "DON", "commentaire": "Don signalé lors du passage.", **receipt}
        donation = self.a.request("PUT", self.path(tour=tour), payload)
        self.assertEqual("DON", donation["statut"])
        self.assertEqual(0, donation["nbCalendriers"])
        self.assertEqual("0.00", donation["montant"])
        self.assertIsNone(donation["moyenPaiement"])
        self.assertEqual(payload["commentaire"], donation["commentaire"])

        fresh = Client()
        fresh.login(self.accounts["A"])
        detail = fresh.request("GET", f'/tournees/{tour["id"]}')
        saved = next(a["passage"] for a in detail["listeAdresses"] if a["id"] == self.addresses[0]["id"])
        self.assertEqual(donation, saved)
        stats = fresh.request("GET", "/dashboard?campagne=2024")["stats"]
        self.assertEqual(1, stats["traitees"])
        self.assertEqual(0, stats["calendriersVendus"])
        self.assertEqual("0.00", stats["montant"])
        self.assertTrue(all(amount == "0.00" for amount in stats["paiements"].values()))

        for invalid in [{"nbCalendriers": 1}, {"montant": "0.01"}, {"moyenPaiement": "ESPECES"}, {"commentaire": "x" * 2001}]:
            with self.subTest(invalid=invalid):
                fresh.request("PUT", self.path(tour=tour), {**payload, **invalid}, status=422)
        self.assertEqual(donation, fresh.request("PUT", self.path(tour=tour), payload))

        sale = fresh.request("PUT", self.path(tour=tour), self.sale("20.00", 2, receipt))
        self.assertEqual(donation["id"], sale["id"])
        self.assertEqual("20.00", fresh.request("GET", "/dashboard?campagne=2024")["stats"]["montant"])
        cleared = fresh.request("PUT", self.path(tour=tour), {"statut": "DON", **receipt})
        self.assertEqual(sale["id"], cleared["id"])
        self.assertEqual(0, cleared["nbCalendriers"])
        self.assertEqual("0.00", cleared["montant"])
        self.assertIsNone(cleared["moyenPaiement"])
        self.assertIsNone(cleared["commentaire"])
        self.assertEqual("0.00", fresh.request("GET", "/dashboard?campagne=2024")["stats"]["montant"])
        self.a.request("PUT", self.path(tour=self.tour_b, address=self.foreign_address), payload, status=404)

    def test_book_generation_uniqueness_and_permissions(self):
        self.assertEqual(25, self.book_a["nombreRecus"])
        self.assertEqual(list(range(self.book_a["premierRecu"], self.book_a["premierRecu"] + 25)), [r["numero"] for r in self.book_a["recus"]])
        self.assertEqual(25, self.book_a["recusDisponibles"])
        tour = self.make_tour(self.a, self.sector_a, "Carnet configurable")
        small = self.make_book(self.a, tour, 3)
        self.assertEqual(3, small["nombreRecus"])
        self.assertEqual(small["premierRecu"] + 2, small["dernierRecu"])
        path = f'/tournees/{tour["id"]}/carnets'
        self.a.request("POST", path, {"numero": "Chevauchement", "premierRecu": self.book_a["premierRecu"] + 24, "nombreRecus": 2}, status=409)
        self.a.request("POST", path, {"numero": self.book_a["numero"], "premierRecu": 888000}, status=409)
        self.assertEqual([small["id"]], [b["id"] for b in self.a.request("GET", path)])
        for invalid in [{"nombreRecus": 0}, {"nombreRecus": 1001}, {"premierRecu": 0}, {"premierRecu": 999999999}, {"numero": " "}]:
            self.a.request("POST", path, {"numero": "Invalide", "premierRecu": 777000, **invalid}, status=422)
        # Receipt numbers and book numbers are unique within an amicale.
        self.b.request("POST", f'/tournees/{self.tour_b["id"]}/carnets', {"numero": self.book_a["numero"], "premierRecu": self.book_a["premierRecu"]}, 201)
        self.w.request("GET", f'/tournees/{self.tour_a["id"]}/carnets')
        self.w.request("POST", f'/tournees/{self.tour_a["id"]}/carnets', {"numero": "Interdit", "premierRecu": 444000}, status=403)
        self.w.request("GET", f'/tournees/{tour["id"]}/carnets', status=404)
        self.a.request("GET", f'/tournees/{self.tour_b["id"]}/carnets', status=404)

    def test_receipt_required_scoped_and_never_reusable(self):
        tour = self.make_tour(self.a, self.sector_a, "Reçus consommés")
        book = self.make_book(self.a, tour, 3)
        first = self.receipt(book)
        second = self.receipt(book, 1)
        self.a.request("PUT", self.path(tour=tour), {"statut": "DON", "commentaire": "Sans reçu"}, status=422)
        sale_without_receipt = {k: v for k, v in self.sale().items() if k not in ["carnetId", "recuId"]}
        self.a.request("PUT", self.path(tour=tour), sale_without_receipt, status=422)
        self.a.request("PUT", self.path(tour=tour), self.sale(receipt=self.receipt(self.book_a)), status=404)
        self.a.request("PUT", self.path(tour=tour), self.sale(receipt=self.receipt(self.book_b)), status=404)
        self.a.request("PUT", self.path(tour=tour), self.sale(receipt={**first, "recuId": self.book_a["recus"][0]["id"]}), status=404)
        p = self.a.request("PUT", self.path(tour=tour), self.sale("10.00", receipt=first))
        self.a.request("PUT", self.path(tour=tour, address=self.addresses[1]), self.sale(receipt=first), status=409)
        correction = self.a.request("PUT", self.path(tour=tour), self.sale("12.00", receipt=first))
        self.assertEqual(p["id"], correction["id"])
        self.a.request("PUT", self.path(tour=tour), self.sale("15.00", receipt=second))
        self.a.request("PUT", self.path(tour=tour), self.sale(receipt=first), status=409)
        self.a.request("PUT", self.path(tour=tour), {"statut": "REFUS", "nbCalendriers": 0, "montant": "0", "moyenPaiement": None})
        self.a.request("PUT", self.path(tour=tour, address=self.addresses[1]), {"statut": "DON", **second}, status=409)
        self.a.request("PUT", self.path(tour=tour, address=self.addresses[1]), {"statut": "DON", "commentaire": "Don avec reçu", **self.receipt(book, 2)})
        summary = self.a.request("GET", f'/tournees/{tour["id"]}/carnets')[0]
        self.assertEqual(3, summary["recusUtilises"])
        self.assertEqual(0, summary["recusDisponibles"])
        self.assertEqual(2, summary["recusAnnules"])
        self.assertEqual(1, summary["dons"])
        self.assertEqual("0.00", summary["montant"])

    def test_receipt_dashboard_totals_per_book(self):
        tour = self.make_tour(self.a, self.sector_a, "Totaux par carnet", 2023)
        first = self.make_book(self.a, tour, 2)
        second = self.make_book(self.a, tour, 1)
        self.a.request("PUT", self.path(tour=tour), self.sale("12.30", 2, self.receipt(first)))
        self.a.request("PUT", self.path(tour=tour, address=self.addresses[1]), {"statut": "DON", "commentaire": "Don sans montant", **self.receipt(second)})
        dashboard = self.a.request("GET", "/dashboard?campagne=2023")
        books = {b["id"]: b for b in dashboard["carnets"]}
        self.assertEqual({first["id"], second["id"]}, set(books))
        self.assertEqual(1, books[first["id"]]["ventes"])
        self.assertEqual(1, books[first["id"]]["recusUtilises"])
        self.assertEqual(1, books[first["id"]]["recusDisponibles"])
        self.assertEqual("12.30", books[first["id"]]["montant"])
        self.assertEqual(1, books[second["id"]]["dons"])
        self.assertEqual("0.00", books[second["id"]]["montant"])
        self.assertEqual("12.30", dashboard["stats"]["montant"])
        self.assertFalse(any(b["id"] in books for b in self.b.request("GET", "/dashboard?campagne=2023")["carnets"]))

    def test_receipt_concurrent_use_is_rejected(self):
        tour = self.make_tour(self.a, self.sector_a, "Concurrence des reçus")
        book = self.make_book(self.a, tour, 1)
        clients = [Client(), Client()]
        for client in clients:
            client.login(self.accounts["A"])
        barrier = threading.Barrier(2)
        def reserve(index):
            barrier.wait(timeout=10)
            return clients[index].request("PUT", self.path(tour=tour, address=self.addresses[index]), self.sale(receipt=self.receipt(book)), status=None)
        with ThreadPoolExecutor(max_workers=2) as pool:
            results = list(pool.map(reserve, [0, 1]))
        self.assertEqual([200, 409], sorted(status for status, _ in results))
        self.assertEqual(1, self.a.request("GET", f'/tournees/{tour["id"]}/carnets')[0]["recusUtilises"])

    def test_deletion_rechecks_links_and_preserves_used_sectors(self):
        sector = self.a.request("POST", "/secteurs", {"nom": "À supprimer après contrôle"}, 201)
        path = f'/secteurs/{sector["id"]}'
        self.assertTrue(self.a.request("GET", path + "/suppression")["autorisee"])
        address = self.a.request("POST", "/adresses", {"secteurId": sector["id"], "ligne1": "1 rue suppression", "codePostal": "75001", "ville": "Paris"}, 201)
        blocked = self.a.request("DELETE", path, status=409)
        self.assertEqual("DELETION_BLOCKED", blocked["code"])
        self.assertEqual([{"type": "adresses", "nombre": 1, "libelle": "adresses du secteur"}], blocked["dependances"])
        self.assertTrue(any(s["id"] == sector["id"] for s in self.a.request("GET", "/secteurs")))
        self.a.request("DELETE", f'/adresses/{address["id"]}')
        self.assertEqual({"supprime": True}, self.a.request("DELETE", path))
        self.a.request("GET", path + "/suppression", status=404)
        self.a.request("DELETE", path, status=404)
        used = self.a.request("GET", f'/secteurs/{self.sector_a["id"]}/suppression')
        self.assertEqual({"adresses", "tournees"}, {d["type"] for d in used["dependances"]})

    def test_deletion_dependency_order_books_assignments_passages_and_tour(self):
        sector = self.a.request("POST", "/secteurs", {"nom": "Ordre des suppressions"}, 201)
        address = self.a.request("POST", "/adresses", {"secteurId": sector["id"], "ligne1": "2 rue suppression", "codePostal": "75001", "ville": "Paris"}, 201)
        tour = self.make_tour(self.a, sector, "Tournée à supprimer", 2033)
        path = f'/tournees/{tour["id"]}'
        member = self.a.request("POST", "/sapeurs", {"email": "deletion-team@example.test", "password": "Deletion-test-123!", "role": "ROLE_SAPEUR"}, 201)
        self.a.request("POST", path + "/affectations", {"userId": member["id"]})
        self.a.request("DELETE", f'/sapeurs/{member["id"]}', status=409)
        self.a.request("DELETE", path, status=409)
        assignment = path + f'/affectations/{member["id"]}'
        self.assertTrue(self.a.request("GET", assignment + "/suppression")["autorisee"])
        self.a.request("DELETE", assignment)
        self.a.request("DELETE", f'/sapeurs/{member["id"]}')
        book = self.make_book(self.a, tour, 3)
        self.assertEqual("carnets", self.a.request("DELETE", path, status=409)["dependances"][0]["type"])
        self.a.request("DELETE", f'/carnets/{book["id"]}')
        self.assertEqual([], self.a.request("GET", path + "/carnets"))
        # An entirely unused book does not reserve its former physical numbers.
        replacement = self.a.request("POST", path + "/carnets", {"numero": book["numero"], "premierRecu": book["premierRecu"], "nombreRecus": 3}, 201)
        self.a.request("DELETE", f'/carnets/{replacement["id"]}')
        passage = self.a.request("PUT", self.path(tour, address), {"statut": "REFUS", "nbCalendriers": 0, "montant": "0"})
        self.a.request("DELETE", path, status=409)
        self.a.request("DELETE", f'/passages/{passage["id"]}')
        self.a.request("DELETE", path)
        self.a.request("DELETE", f'/adresses/{address["id"]}')
        self.a.request("DELETE", f'/secteurs/{sector["id"]}')
        campaign = next(c for c in self.a.request("GET", "/campagnes") if c["annee"] == 2033)
        self.a.request("DELETE", f'/campagnes/{campaign["id"]}')

    def test_deletion_retains_receipt_history_even_after_cancellation(self):
        tour = self.make_tour(self.a, self.sector_a, "Historique à conserver")
        book = self.make_book(self.a, tour, 1)
        passage = self.a.request("PUT", self.path(tour), self.sale(receipt=self.receipt(book)))
        self.a.request("PUT", self.path(tour), {"statut": "REFUS", "nbCalendriers": 0, "montant": "0"})
        for path in [f'/passages/{passage["id"]}', f'/carnets/{book["id"]}']:
            check = self.a.request("GET", path + "/suppression")
            self.assertFalse(check["autorisee"])
            self.assertIn("recusUtilises", [d["type"] for d in check["dependances"]])
            self.a.request("DELETE", path, status=409)
        self.assertTrue(self.a.request("GET", f'/tournees/{tour["id"]}/carnets')[0]["recus"][0]["utilise"])

    def test_deletion_blocks_addresses_implicitly_used_by_tours(self):
        sector = self.a.request("POST", "/secteurs", {"nom": "Adresse liée sans passage"}, 201)
        address = self.a.request("POST", "/adresses", {"secteurId": sector["id"], "ligne1": "3 rue suppression", "codePostal": "75001", "ville": "Paris"}, 201)
        tour = self.make_tour(self.a, sector, "Préparée sans passage")
        check = self.a.request("DELETE", f'/adresses/{address["id"]}', status=409)
        self.assertEqual(["tournees"], [d["type"] for d in check["dependances"]])
        self.a.request("PATCH", f'/tournees/{tour["id"]}/statut', {"statut": "TERMINEE"})
        self.a.request("POST", f'/tournees/{tour["id"]}/valider', {})
        blocked = self.a.request("DELETE", f'/tournees/{tour["id"]}', status=409)
        self.assertTrue(any('validée' in r for r in blocked["raisons"]))
        self.a.request("DELETE", f'/adresses/{address["id"]}', status=409)

    def test_deletion_permissions_csrf_tenant_scope_and_self_protection(self):
        passage = self.a.request("PUT", self.path(tour=self.tour_private), {"statut": "ABSENT", "nbCalendriers": 0, "montant": "0"})
        campaign = self.a.request("GET", "/campagnes")[0]
        paths = [f'/secteurs/{self.sector_a["id"]}', f'/adresses/{self.addresses[0]["id"]}', f'/tournees/{self.tour_a["id"]}',
                 f'/sapeurs/{self.worker["id"]}', f'/carnets/{self.book_a["id"]}', f'/passages/{passage["id"]}', f'/campagnes/{campaign["id"]}']
        for path in paths:
            self.b.request("GET", path + "/suppression", status=404)
            self.b.request("DELETE", path, status=404)
            self.w.request("GET", path + "/suppression", status=403)
            self.w.request("DELETE", path, status=403)
            self.a.request("DELETE", path, status=403, csrf=False)
        self.a.request("DELETE", f'/sapeurs/{self.profile_a["id"]}', status=409)
        self.a.request("GET", "/me")
        manager = self.a.request("POST", "/sapeurs", {"email": "deletion-manager@example.test", "password": "Manager-test-123!", "role": "ROLE_RESPONSABLE"}, 201)
        client = Client()
        client.login({"amicaleId": self.accounts["A"]["amicaleId"], "email": manager["email"], "password": "Manager-test-123!"})
        client.request("DELETE", f'/sapeurs/{self.worker["id"]}', status=403)
        empty = client.request("POST", "/secteurs", {"nom": "Suppression par responsable"}, 201)
        client.request("DELETE", f'/secteurs/{empty["id"]}')

    def test_deletion_of_users_checks_passage_authors_and_expires_sessions(self):
        member = self.a.request("POST", "/sapeurs", {"email": "deletion-unused@example.test", "password": "Member-test-123!", "role": "ROLE_SAPEUR"}, 201)
        client = Client()
        client.login({"amicaleId": self.accounts["A"]["amicaleId"], "email": member["email"], "password": "Member-test-123!"})
        self.a.request("DELETE", f'/sapeurs/{member["id"]}')
        client.request("GET", "/me", status=401)
        author = self.a.request("POST", "/sapeurs", {"email": "deletion-author@example.test", "password": "Member-test-123!", "role": "ROLE_SAPEUR"}, 201)
        client = Client()
        client.login({"amicaleId": self.accounts["A"]["amicaleId"], "email": author["email"], "password": "Member-test-123!"})
        tour = self.make_tour(self.a, self.sector_a, "Auteur à conserver")
        self.a.request("POST", f'/tournees/{tour["id"]}/affectations', {"userId": author["id"]})
        passage = client.request("PUT", self.path(tour), {"statut": "ABSENT", "nbCalendriers": 0, "montant": "0"})
        self.a.request("DELETE", f'/tournees/{tour["id"]}/affectations/{author["id"]}')
        blocked = self.a.request("DELETE", f'/sapeurs/{author["id"]}', status=409)
        self.assertEqual(["passages"], [d["type"] for d in blocked["dependances"]])
        self.a.request("DELETE", f'/passages/{passage["id"]}')
        self.a.request("DELETE", f'/sapeurs/{author["id"]}')

    def test_campaigns_persistence_default_protection_and_tour_references(self):
        campaign = self.a.request("POST", "/campagnes", {"annee": 2097}, 201)
        self.a.request("POST", "/campagnes", {"annee": 2097}, status=409)
        self.b.request("POST", "/campagnes", {"annee": 2097}, 201)
        self.w.request("GET", "/campagnes", status=403)
        self.w.request("POST", "/campagnes", {"annee": 2098}, status=403)
        self.a.request("POST", "/campagnes", {"annee": 2101}, status=422)
        self.a.request("DELETE", f'/campagnes/{campaign["id"]}')
        self.assertNotIn(campaign["id"], [c["id"] for c in self.a.request("GET", "/campagnes")])
        default = next(c for c in self.a.request("GET", "/campagnes") if c["parDefaut"])
        self.a.request("DELETE", f'/campagnes/{default["id"]}', status=409)
        tour = self.make_tour(self.a, self.sector_a, "Création de campagne automatique", 2022)
        linked = next(c for c in self.a.request("GET", "/campagnes") if c["annee"] == 2022)
        self.assertEqual(1, linked["tournees"])
        self.a.request("DELETE", f'/campagnes/{linked["id"]}', status=409)
        self.a.request("DELETE", f'/tournees/{tour["id"]}')
        self.a.request("DELETE", f'/campagnes/{linked["id"]}')

    def test_deletion_of_validated_tour_children_and_assignments_is_rejected(self):
        tour = self.make_tour(self.a, self.sector_a, "Suppression après validation")
        book = self.make_book(self.a, tour, 1)
        member = self.a.request("POST", "/sapeurs", {"email": "deletion-locked@example.test", "password": "Member-test-123!", "role": "ROLE_SAPEUR"}, 201)
        self.a.request("POST", f'/tournees/{tour["id"]}/affectations', {"userId": member["id"]})
        passage = self.a.request("PUT", self.path(tour), {"statut": "ABSENT", "nbCalendriers": 0, "montant": "0"})
        self.a.request("PATCH", f'/tournees/{tour["id"]}/statut', {"statut": "TERMINEE"})
        self.a.request("POST", f'/tournees/{tour["id"]}/valider', {})
        for path in [f'/tournees/{tour["id"]}', f'/carnets/{book["id"]}', f'/passages/{passage["id"]}', f'/tournees/{tour["id"]}/affectations/{member["id"]}']:
            self.assertFalse(self.a.request("GET", path + "/suppression")["autorisee"])
            self.a.request("DELETE", path, status=409)

    def test_deletion_concurrent_book_removal_and_receipt_use(self):
        tour = self.make_tour(self.a, self.sector_a, "Suppression et consommation concurrentes")
        book = self.make_book(self.a, tour, 1)
        clients = [Client(), Client()]
        for client in clients:
            client.login(self.accounts["A"])
        barrier = threading.Barrier(2)
        def action(index):
            barrier.wait(timeout=10)
            if index == 0:
                return clients[0].request("DELETE", f'/carnets/{book["id"]}', status=None)
            return clients[1].request("PUT", self.path(tour), self.sale(receipt=self.receipt(book)), status=None)
        with ThreadPoolExecutor(max_workers=2) as pool:
            results = list(pool.map(action, [0, 1]))
        statuses = [r[0] for r in results]
        self.assertIn(statuses, [[200, 404], [200, 409], [409, 200]])
        books = self.a.request("GET", f'/tournees/{tour["id"]}/carnets')
        if statuses[1] == 200:
            self.assertEqual(1, books[0]["recusUtilises"])
        else:
            self.assertEqual([], books)
            self.assertEqual(0, self.a.request("GET", f'/tournees/{tour["id"]}')["traitees"])

    def test_manager_cannot_create_accounts_or_change_settings(self):
        self.a.request("POST", "/sapeurs", {"email": "manager@example.test", "password": "Manager-password-123!", "role": "ROLE_RESPONSABLE"}, 201)
        manager = Client()
        profile = manager.login({"amicaleId": self.accounts["A"]["amicaleId"], "email": "manager@example.test", "password": "Manager-password-123!"})
        self.assertTrue(profile["canManage"])
        manager.request("GET", "/dashboard")
        manager.request("GET", "/sapeurs")
        manager.request("POST", "/secteurs", {"nom": "Création par responsable"}, 201)
        manager.request("POST", "/sapeurs", {"email": "forged@example.test", "password": "Forged-password-123!", "role": "ROLE_ADMIN_AMICALE"}, status=403)
        manager.request("PATCH", "/parametres", {"nom": "Forgée", "departement": "75", "campagne": 2026}, status=403)

    def test_money_corrections_and_persistence(self):
        tour = self.make_tour(self.a, self.sector_a, "Calculs exacts", 2025)
        book = self.make_book(self.a, tour)
        receipt = self.receipt(book)
        first = self.a.request("PUT", self.path(tour=tour), self.sale("0.10", receipt=receipt))
        self.a.request("PUT", self.path(tour=tour, address=self.addresses[1]), self.sale("0.20", 2, self.receipt(book, 1)))
        stats = self.a.request("GET", "/dashboard?campagne=2025")["stats"]
        self.assertEqual("0.30", stats["montant"])
        self.assertEqual(3, stats["calendriersVendus"])
        self.assertEqual("0.30", stats["paiements"]["ESPECES"])
        correction = self.a.request("PUT", self.path(tour=tour), self.sale("1.10", 2, receipt))
        self.assertEqual(first["id"], correction["id"])
        fresh = Client()
        fresh.login(self.accounts["A"])
        detail = fresh.request("GET", f'/tournees/{tour["id"]}')
        self.assertEqual("1.30", detail["montant"])
        self.assertEqual(4, detail["calendriersVendus"])
        self.assertEqual(100, detail["progression"])
        fresh.request("PUT", self.path(tour=tour), {"statut": "ABSENT", "nbCalendriers": 0, "montant": "0", "moyenPaiement": None})
        self.assertEqual("0.20", fresh.request("GET", "/dashboard?campagne=2025")["stats"]["montant"])

    def test_settings_are_persisted_and_scoped(self):
        payload = {"nom": "Amicale A modifiée", "departement": "2A", "campagne": 2027}
        self.a.request("PATCH", "/parametres", payload)
        self.assertEqual(2027, self.a.request("GET", "/me")["amicale"]["campagne"])
        self.assertEqual(2027, self.a.request("GET", "/dashboard")["campagne"])
        self.assertEqual("Amicale de test B", self.b.request("GET", "/me")["amicale"]["nom"])
        self.a.request("PATCH", "/parametres", {**payload, "departement": "bad"}, status=422)

    def test_amicale_identifier_login_validation_and_case(self):
        account = self.accounts["A"]
        client = Client()
        profile = client.login({**account, "amicaleId": account["amicaleId"].upper()})
        self.assertEqual(account["amicaleId"], profile["amicale"]["identifiant"])
        self.assertEqual(self.profile_a["amicale"]["id"], profile["amicale"]["id"])
        for invalid in ["abc", "a" * 21, "test-name", "test name", "équipe", 1234, None, [], profile["amicale"]["id"]]:
            with self.subTest(identifier=invalid):
                Client().request("POST", "/login", {**account, "amicaleId": invalid}, status=401)

    def test_sapeur_name_persists_in_profiles_and_web_and_mobile_teams(self):
        email = 'named-worker@example.test'
        password = 'Named-worker-password-123!'
        member = self.a.request('POST', '/sapeurs', {'nom': '  Élodie Martin  ', 'email': email,
            'password': password, 'role': 'ROLE_SAPEUR'}, 201)
        self.assertEqual('Élodie Martin', member['nom'])
        self.assertEqual(email, member['email'])
        tour = self.make_tour(self.a, self.sector_a, 'Équipe avec noms', 2083)
        assigned = self.a.request('POST', f'/tournees/{tour["id"]}/affectations', {'userId': member['id']})
        self.assertEqual('Élodie Martin', assigned['equipe'][0]['nom'])
        renamed = self.a.request('PATCH', f'/sapeurs/{member["id"]}', {'nom': '  Élodie Durand  '})
        self.assertEqual(member['id'], renamed['id'])
        self.assertEqual(email, renamed['email'])
        self.assertEqual('Élodie Durand', renamed['nom'])
        worker = Client()
        profile = worker.login({'amicaleId': self.accounts['A']['amicaleId'], 'email': email, 'password': password})
        self.assertEqual('Élodie Durand', profile['nom'])
        self.assertEqual('Élodie Durand', worker.request('GET', '/session')['user']['nom'])
        for path in [f'/tournees/{tour["id"]}', f'/mobile/tournees/{tour["id"]}/adresses']:
            detail = worker.request('GET', path)
            self.assertEqual('Élodie Durand', detail['equipe'][0]['nom'])
            self.assertEqual(email, detail['equipe'][0]['email'])
        listed = next(t for t in worker.request('GET', '/mobile/tournees') if t['id'] == tour['id'])
        self.assertEqual('Élodie Durand', listed['equipe'][0]['nom'])
        fresh = Client()
        fresh.login(self.accounts['A'])
        stored = next(u for u in fresh.request('GET', '/sapeurs') if u['id'] == member['id'])
        self.assertEqual('Élodie Durand', stored['nom'])

    def test_sapeur_name_validation_permissions_and_tenant_isolation(self):
        legacy = self.a.request('POST', '/sapeurs', {'email': 'legacy-name@example.test',
            'password': 'Legacy-password-123!', 'role': 'ROLE_SAPEUR'}, 201)
        self.assertIsNone(legacy['nom'])
        path = f'/sapeurs/{legacy["id"]}'
        before = len(self.a.request('GET', '/sapeurs'))
        for invalid in ['', '   ', None, 123, [], 'x' * 181]:
            self.a.request('POST', '/sapeurs', {'nom': invalid, 'email': 'invalid-name@example.test',
                'password': 'Invalid-password-123!', 'role': 'ROLE_SAPEUR'}, status=422)
            self.a.request('PATCH', path, {'nom': invalid}, status=422)
        self.assertEqual(before, len(self.a.request('GET', '/sapeurs')))
        self.a.request('PATCH', path, {}, status=422)
        self.a.request('PATCH', path, {'nom': 'Sans CSRF'}, status=403, csrf=False)
        self.w.request('PATCH', path, {'nom': 'Interdit au sapeur'}, status=403)
        self.b.request('PATCH', path, {'nom': 'Autre amicale'}, status=404)
        manager_email = 'name-manager@example.test'
        self.a.request('POST', '/sapeurs', {'nom': 'Responsable', 'email': manager_email,
            'password': 'Manager-password-123!', 'role': 'ROLE_RESPONSABLE'}, 201)
        manager = Client()
        manager.login({'amicaleId': self.accounts['A']['amicaleId'], 'email': manager_email, 'password': 'Manager-password-123!'})
        manager.request('PATCH', path, {'nom': 'Interdit au responsable'}, status=403)
        self.assertIsNone(next(u for u in self.a.request('GET', '/sapeurs') if u['id'] == legacy['id'])['nom'])
        saved = self.a.request('PATCH', path, {'nom': 'É' * 180})
        self.assertEqual('É' * 180, saved['nom'])
        self.assertEqual(legacy['email'], saved['email'])
        self.assertEqual(legacy['roles'], saved['roles'])

    def test_mobile_passage_dates_are_persisted_preserved_and_validated(self):
        sector, address, tour, book = self.housing_workspace(2090)
        path = self.path(tour, address)
        saved = self.a.request("PUT", path, {**self.sale(receipt=self.receipt(book)), "datePassage": "2025-02-28"})
        self.assertEqual("2025-02-28", saved["datePassage"])
        self.assertIn("T", saved["updatedAt"])
        legacy = self.a.request("PUT", path, {**self.sale("12.00", receipt=self.receipt(book))})
        self.assertEqual("2025-02-28", legacy["datePassage"])
        before = self.a.request("GET", f'/tournees/{tour["id"]}')
        for invalid in ["2025-02-29", "2100-01-01", "1899-12-31", "28/02/2025", None]:
            self.a.request("PUT", path, {**self.sale(receipt=self.receipt(book)), "datePassage": invalid}, status=422)
        self.assertEqual(before, self.a.request("GET", f'/tournees/{tour["id"]}'))
        duplicate = self.a.request("POST", f'/tournees/{tour["id"]}/adresses/{address["id"]}/dupliquer',
            {"statut": "DON", "datePassage": "2024-02-29", "appartement": "mobile", **self.receipt(book, 1)}, 201)
        self.assertEqual("2024-02-29", duplicate["passage"]["datePassage"])
        fresh = Client()
        fresh.login(self.accounts["A"])
        detail = fresh.request("GET", f'/mobile/tournees/{tour["id"]}/adresses')
        self.assertEqual("2025-02-28", next(a for a in detail["listeAdresses"] if a["id"] == address["id"])["passage"]["datePassage"])

    def test_mobile_web_cors_preflights_origins_credentials_and_csrf(self):
        def request(method, path, origin, headers=None):
            req = urllib.request.Request(BASE + path, method=method, headers={"Origin": origin, **(headers or {})})
            try: return urllib.request.urlopen(req, timeout=15)
            except urllib.error.HTTPError as error: return error
        origin = "http://127.0.0.1:18082"
        allowed = request("OPTIONS", "/tournees/example/passages/example", origin,
            {"Access-Control-Request-Method": "PUT", "Access-Control-Request-Headers": "Content-Type,X-CSRF-Token"})
        self.assertEqual(204, allowed.status)
        self.assertEqual(origin, allowed.headers.get("Access-Control-Allow-Origin"))
        self.assertEqual("true", allowed.headers.get("Access-Control-Allow-Credentials"))
        self.assertIn("Origin", allowed.headers.get("Vary", ""))
        self.assertIsNone(allowed.headers.get("Set-Cookie"))
        # Expo peut choisir 8083 lorsque les ports précédents sont occupés.
        for expo_origin in ["http://localhost:8083", "http://127.0.0.1:8083"]:
            with self.subTest(origin=expo_origin):
                preflight = request("OPTIONS", "/login", expo_origin,
                    {"Access-Control-Request-Method": "POST", "Access-Control-Request-Headers": "Content-Type,X-CSRF-Token"})
                self.assertEqual(204, preflight.status)
                self.assertEqual(expo_origin, preflight.headers.get("Access-Control-Allow-Origin"))
                self.assertEqual("true", preflight.headers.get("Access-Control-Allow-Credentials"))
                session = request("GET", "/session", expo_origin)
                self.assertEqual(200, session.status)
                self.assertEqual(expo_origin, session.headers.get("Access-Control-Allow-Origin"))
                denied = request("GET", "/mobile/tournees", expo_origin)
                self.assertEqual(401, denied.status)
                self.assertEqual(expo_origin, denied.headers.get("Access-Control-Allow-Origin"))
        self.assertEqual(403, request("OPTIONS", "/tournees", origin,
            {"Access-Control-Request-Method": "TRACE"}).status)
        self.assertEqual(403, request("OPTIONS", "/tournees", origin,
            {"Access-Control-Request-Method": "POST", "Access-Control-Request-Headers": "Authorization"}).status)
        forbidden = request("GET", "/session", "https://untrusted.example")
        self.assertEqual(403, forbidden.status)
        self.assertIsNone(forbidden.headers.get("Access-Control-Allow-Origin"))
        public = request("GET", "/session", origin)
        self.assertEqual(200, public.status)
        self.assertEqual(origin, public.headers.get("Access-Control-Allow-Origin"))
        protected = request("GET", "/mobile/tournees", origin)
        self.assertEqual(401, protected.status)
        self.assertEqual(origin, protected.headers.get("Access-Control-Allow-Origin"))
        csrf = request("POST", "/login", origin)
        self.assertEqual(403, csrf.status)
        self.assertEqual("INVALID_CSRF", json.load(csrf)["code"])

    def test_amicale_identifier_settings_unique_atomic_and_session_preserved(self):
        original = self.a.request("GET", "/me")["amicale"]
        payload = {key: original[key] for key in ["nom", "departement", "campagne"]}
        identifier = "renamed" + original["identifiant"][5:]
        self.w.request("PATCH", "/parametres", {**payload, "identifiant": identifier}, status=403)
        for invalid in ["abc", "a" * 21, "test-name", "équipe", 1234, None]:
            self.a.request("PATCH", "/parametres", {**payload, "identifiant": invalid}, status=422)
        conflict = self.a.request("PATCH", "/parametres", {**payload, "identifiant": self.accounts["B"]["amicaleId"].upper(), "nom": "Ne doit pas être enregistré", "campagne": 2088}, status=409)
        self.assertIn("identifiant", conflict["error"])
        self.assertEqual(original, self.a.request("GET", "/me")["amicale"])
        self.assertFalse(any(c["annee"] == 2088 for c in self.a.request("GET", "/campagnes")))
        try:
            updated = self.a.request("PATCH", "/parametres", {**payload, "identifiant": identifier.upper()})
            self.assertEqual(identifier, updated["amicale"]["identifiant"])
            self.assertEqual(original["id"], updated["amicale"]["id"])
            self.assertEqual(self.sector_a["id"], self.a.request("GET", f'/secteurs/{self.sector_a["id"]}')["id"])
            self.assertEqual(identifier, self.w.request("GET", "/me")["amicale"]["identifiant"])
            self.assertEqual(self.accounts["B"]["amicaleId"], self.b.request("GET", "/me")["amicale"]["identifiant"])
            Client().request("POST", "/login", self.accounts["A"], status=401)
            fresh = Client()
            self.assertEqual(original["id"], fresh.login({**self.accounts["A"], "amicaleId": identifier})["amicale"]["id"])
            self.assertEqual(identifier, self.a.request("PATCH", "/parametres", payload)["amicale"]["identifiant"])
        finally:
            self.a.request("PATCH", "/parametres", {**payload, "identifiant": original["identifiant"]})
        self.assertEqual(original["id"], Client().login(self.accounts["A"])["amicale"]["id"])

    def test_validation_locks_all_tour_writes(self):
        tour = self.make_tour(self.a, self.sector_a, "À clôturer")
        book = self.make_book(self.a, tour)
        self.a.request("POST", f'/tournees/{tour["id"]}/valider', {}, status=409)
        self.a.request("PATCH", f'/tournees/{tour["id"]}/statut', {"statut": "TERMINEE"})
        result = self.a.request("POST", f'/tournees/{tour["id"]}/valider', {})
        self.assertEqual("VALIDEE", result["statut"])
        self.a.request("PUT", self.path(tour=tour), self.sale(receipt=self.receipt(book)), status=409)
        self.a.request("PUT", self.path(tour=tour), {"statut": "DON", "commentaire": "Écriture verrouillée", **self.receipt(book)}, status=409)
        self.a.request("POST", f'/tournees/{tour["id"]}/carnets', {"numero": "Verrouillé", "premierRecu": 888000}, status=409)
        self.a.request("PATCH", f'/tournees/{tour["id"]}/statut', {"statut": "EN_COURS"}, status=409)
        self.a.request("POST", f'/tournees/{tour["id"]}/affectations', {"userId": self.worker["id"]}, status=409)


if __name__ == "__main__":
    unittest.main(verbosity=2)

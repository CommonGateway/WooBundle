# OpenWoo Service

De OpenWoo Service versterkt de toegankelijkheid van overheidspublicaties volgens de Wet open overheid (Woo) door organisatiebronnen naadloos te synchroniseren met Open Index. Deze kernservice ondersteunt efficiënte zoekacties binnen Woo-categorieën en bevordert data-uitwisseling via het Common Ground OpenServices framework. Het faciliteert integratie met lokale en landelijke publicatieplatformen, inclusief koppelingen met het Kennis- en Exploitatiecentrum Officiële Publicaties (KOOP). OpenWoo.app is ontworpen voor zowel open source als proprietary platforms, waardoor het een centrale oplossing biedt voor het beheer en de zoekbaarheid van overheidsinformatie..

## Kernfunctionaliteiten

* **Automatische Synchronisatie:** Naadloze integratie met Open Index om overheidspublicaties automatisch bij te werken en te synchroniseren.
* **Efficiënt Zoeken:** Maakt geavanceerde zoekopdrachten mogelijk binnen Woo-categorieën door de data-uitwisseling met Open Index.
* **Veelzijdige Ondersteuning:** Geschikt voor zowel lokale als landelijke publicatieplatformen, waaronder een verbinding met het Kennis- en Exploitatiecentrum Officiële Publicaties (KOOP).
* **Federale Zoekopdrachten:** Ondersteunt federale zoekopdrachten via koophulpje.nl, waardoor een breder bereik en toegankelijkheid van overheidspublicaties wordt gegarandeerd.
* **OpenServices Framework:** Geïntegreerd met het Common Ground OpenServices framework voor gestandaardiseerde data-uitwisseling en interoperabiliteit.

## Installatie

### Vereisten

* PHP 7.4 of hoger
* Symfony 5 of hoger
* Docker (voor containerisatie en lokale ontwikkeling)

### Stap-voor-stap Installatiegids in een Common Gateway

1. Clone het OpenWoo repository: `git clone https://github.com/OpenWoo/OpenWooService.git`
2. Installeer afhankelijkheden met Composer: `composer install`
3. Pas de `.env` bestanden aan met uw specifieke configuraties voor de database en andere diensten.
4. Start de OpenWoo Service met Docker: `docker-compose up -d`

### Docker container

Er is ook een docker-compose.yml beschikbaar voor de OpenWoo service om bovenstaande snel op te bouwen.
Deze file is beschikbaar in de root folder van deze repository. Om deze te draaien is docker benodigd en zet u de volgende stappen:

1. Open een terminalvenster waarmee Docker kan worden gedraaid
2. Navigeer naar de map waarin u de repository heeft gecloned
3. Draai het commando `docker compose up`
   1. Indien de image nog niet in docker beschikbaar is, zal de image opnieuw worden opgebouwd op basis van de latest versie van de [common gateway images](https://github.com/conductionnl/commonground-gateway).
   2. Daarna worden de containers gestart (als de image wel beschikbaar is zal dit direct gebeuren)
4. De admin omgeving komt beschikbaar op https://localhost:8000, de frontend op https://localhost:8080

Op de admin omgeving kan worden ingelogd met de default credentials `username: no-reply@test.com, password: !ChangeMe!`. Daarmee kan dan de configuratie van de omgeving worden beïnvloed.

Wijzigingen die in de code van deze repository (de map src) worden gedaan worden lokaal direct overgenomen. Changes in de configuratiebestanden in deze repository (Installation-folder) worden eveneens overgenomen, maar moeten om actief te worden in de lokale omgeving nog worden ingeladen met het commando `docker compose exec php bin/console commongateway:initialize`

## Gebruik

Na de installatie kunt u de OpenWoo Service configureren om te beginnen met de automatische synchronisatie van uw organisatiebronnen naar Open Index. Raadpleeg de gedetailleerde documentatie voor verdere instructies en beste praktijken.

## Bijdragen

Wij verwelkomen bijdragen aan de OpenWoo Service, of het nu gaat om bugrapporten, feature suggesties, of codebijdragen. Zie onze `CONTRIBUTING.md` voor meer informatie over hoe u kunt bijdragen.

## Licentie

De OpenWoo Service is uitgegeven onder de EUPL 1.2 licentie. Voor meer details, zie het `LICENSE.md` bestand in onze GitHub repository.

## Contact

Voor meer informatie over de OpenWoo Service en hoe deze in uw organisatie geïmplementeerd kan worden, kunt u contact met ons opnemen via <info@openwoo.nl>.

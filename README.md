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

### Stap-voor-stap Installatiegids

1. Clone het OpenWoo repository: `git clone https://github.com/OpenWoo/OpenWooService.git`
2. Installeer afhankelijkheden met Composer: `composer install`
3. Pas de `.env` bestanden aan met uw specifieke configuraties voor de database en andere diensten.
4. Start de OpenWoo Service met Docker: `docker-compose up -d`

## Gebruik

Na de installatie kunt u de OpenWoo Service configureren om te beginnen met de automatische synchronisatie van uw organisatiebronnen naar Open Index. 

De synchronisaties werken aan de hand van een action in combinatie met een source.

### Action

Voor een voorbeeld van een action voor het zaaksysteem kan je kijken naar SyncEpeAction en als voor OpenWoo naar SyncBurenOpenWooAction. Voor OpenConvenant kan er gekeken worden naar SyncBurenOpenConvenantAction.

Een action heeft standaard een reference en een name nodig. De reference moet uniek zijn als bijvoorbeeld `https://commongateway.nl/woo.SyncXxllncAction.action.json`.

Een action heeft ook een listens veld. Deze is in deze context meestal de 'throws' van de cronjob: `woo.default.listens`. Dit zorgt ervoor dat de action (synchronisatie) elke x minuten afgaat als in de cronjob ingesteld staat.

De action heeft in configuratie array een aantal velden wat geconfigureerd moet worden, sommige velden zijn verplicht en andere niet. Het verschilt ook vanuit wat voor type source (zaaksysteem, OpenWoo of OpenConvenant) gesynchroniseerd wordt:
- oin (required): oin vanuit het oin register van Logius. Deze waarde wordt gebruikt zodat er later op gefiltered kan worden `?organsiatie.oin=value`.
- portalUrl (required): Wordt gebruikt om de link naar de frontend mee te genereren.
- source (required): De reference van de source, zodat vanuit die source  gesynchroniseerd wordt.
- schema (required): Meestal de reference van het publicatie schema, voor bijna alle Woo synchronisaties wil je naar het publicatie schema mappen:` https://commongateway.nl/woo.publicatie.schema.json`
- mapping (required): De mapping die gebruikt wordt om het van source object naar publicatie object te krijgen. Voor het zaaksysteem is dit `https://commongateway.nl/mapping/woo.xxllncCaseToWoo.mapping.json` en voor OpenWoo is dit `https://commongateway.nl/mapping/woo.openWooToWoo.mapping.json`, voor OpenConvenant is het `https://commongateway.nl/mapping/woo.openConvenantToWoo.mapping.json`.
- organisatie (required): Textueele representatie van de organisatie waar de publicaties van zijn, meestal de gemeente naam in het geval van een gemeente.
- zaaksysteemSearchEndpoint (required in geval zaaksysteem): Het endpoint wat gebruikt wordt om de publicaties op te halen in het zaaksysteem.
- sourceEndpoint (required in geval OpenWoo of OpenConvenant): Endpoint waar publicaties vandaan gehaald worden.
- fileEndpointReference (required in geval zaaksysteem): De reference naar het view-file endpoint voor de gesynchroniseerde documenten. Zorgt ervoor dat binnengehaalde documenten een endpoint hebben en bekeken kunnen worden. Standaard: `https://commongateway.nl/woo.ViewFile.endpoint.json` invoeren.
- sourceType (required in geval OpenWoo of OpenConvenant): Niet verplicht in geval zaaksysteem, staat default op zaaksysteem. Anders voor OpenWoo of OpenCovenant 'OpenWoo' invoeren.
- autoPublish: Niet verplicht, standaard op true. Als de wens is dat gesynchroniseerde publicaties niet meteen op te halen zijn, dan moet dit veld op false staan.
- allowPDFOnly: Niet verplicht. Op true zetten als de wens is om alleen pdf documenten te synchroniseren.

### Source



## Bijdragen

Wij verwelkomen bijdragen aan de OpenWoo Service, of het nu gaat om bugrapporten, feature suggesties, of codebijdragen. Zie onze `CONTRIBUTING.md` voor meer informatie over hoe u kunt bijdragen.

## Licentie

De OpenWoo Service is uitgegeven onder de EUPL 1.2 licentie. Voor meer details, zie het `LICENSE.md` bestand in onze GitHub repository.

## Contact

Voor meer informatie over de OpenWoo Service en hoe deze in uw organisatie geïmplementeerd kan worden, kunt u contact met ons opnemen via <info@openwoo.nl>.

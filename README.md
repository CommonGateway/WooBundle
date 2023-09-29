# WOOBundle [![Codacy Badge](https://app.codacy.com/project/badge/Grade/980ea2efc85a427ea909518f29506ff6)](https://app.codacy.com/gh/CommonGateway/WOOBundle/dashboard?utm_source=gh&)

## Installation

You can install the bundle with a command in php bash `composer require common-gateway/woo-bundle` or through the Gateway UI plugins page.

Make sure a few things are set up:
1. Check the Sources are configured and enabled for the municipalities.
2. Check the Cronjobs are enabled and dont show any errors after the configured crontab time has passed.

## Deploying / Updating

Before rolling out a new bundle update, disable all Cronjobs so not every time the crontab passes errors get logged if the synchronizations might go wrong.

## Synchronizations

### How does synchronization work

In short: when synchronization triggers cases are fetched from a xxllnc zaaksysteem and mapped to a OpenWebConcept WOO publicatie object.
The mapping schema can be found here: https://github.com/CommonGateway/WooBundle/blob/main/Installation/Mapping/woo.xxllncCaseToWoo.mapping.json
Some custom logic for the Portal\_url and document url properties can be found in the SyncXxllncCasesService.

### How to synchronize

To synchronize publications from the xxllnc zaaksysteem for a municipality, check if its Source is configured properly with info about the zaaksysteem for that municipality: an location, the proper zql query, and make sure the Source is enabled.
Also find the Action for that municipality and check if the oidn is set properly, the Action is enabled, and then copy the Action reference.

Then in the php container you can execute the following command:
`bin/console woo:case:synchronize null {action reference}`
to start synchronizing zaaksysteem cases to woo publications.
For example:
`bin/console woo:case:synchronize null https://commongateway.nl/pdd.SyncNoordwijkAction.action.json`

There are also Cronjobs for existing Actions that run the synchronization each 10 minutes when the Action and Source are configured properly. Make sure the Cronjob is also enabled.

Then you can fetch the objects by just requesting `/api/openWOO` to fetch all publications or `/api/openWOO?oidn={your muncipality oidn}` to fetch the publications belonging to a single municipality.
Make sure here the oidn parameter value is the same that is set as configuration on the Action of that municipality.


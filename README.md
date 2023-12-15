# WOOBundle [![Codacy Badge](https://app.codacy.com/project/badge/Grade/980ea2efc85a427ea909518f29506ff6)](https://app.codacy.com/gh/CommonGateway/WOOBundle/dashboard?utm_source=gh&)

This bundle is used for storing OpenWoo objects and synchronizing these objects from OpenWoo & xxllnc zaaksysteem.

For the api specifications of this bundle see: [redocly](https://redocly.github.io/redoc/?url=https://raw.githubusercontent.com/CommonGateway/WooBundle/ce02a6928bc469e4965715ed899e5f6608cc3791/docs/openapi.json#tag/openwoo/operation/openwoo-put-item)

## Installation

You can install the bundle with a command in php bash `composer require common-gateway/woo-bundle` or through the Gateway UI plugins page.

Make sure a few things are set up:

1. Check the Sources are configured and enabled for all the municipalities.
2. Check if the "WOO default cronjob" Cronjob is enabled and make sure that it doesn't show any errors after the configured crontab time has passed.

## Deploying / Updating

Before rolling out a new bundle update, disable the "WOO default cronjob" Cronjob.
This prevents the Gateway from trying to synchronize objects when the crontab of these Cronjobs passes.
And while deploying these synchronization might go wrong.

## Synchronizations

### How does synchronization work

In short: when synchronization is triggered all cases are fetched from a xxllnc zaaksysteem and mapped to an OpenWebConcept WOO publicatie object.\
The mapping schema can be found here: https://github.com/CommonGateway/WooBundle/blob/main/Installation/Mapping/woo.xxllncCaseToWoo.mapping.json \\

Besides just mapping through a [Gateway mapping](https://commongateway.github.io/CoreBundle/pages/Features/Mappings) there is some custom logic for the portalUrl and the bijlagen (documents) properties.

This can be found for xxllnc synchronizations in the SyncXxllncCasesService.
For bijlagen, an extra call to the xxllnc zaaksysteem is needed to get the document data.
And the portalUrl property is configured through the Action->configuration.

And for OpenWoo synchronizations in the SyncOpenWooService.

### How to synchronize

To synchronize publications for a specific municipality from the xxllnc zaaksysteem,
first, check if its Source is configured properly with information about the zaaksysteem for that municipality.
Does it have the correct location (URL)? And make sure the Source is enabled.
Then find the Action for that municipality, check if the oin is set properly, and also check if the Action is enabled.
After this, copy the Action reference.

Then in the PHP container you can execute the following command:
`bin/console woo:objects:sync null {action reference}`
to start synchronizing zaaksysteem cases to woo publications.
For example: \
`bin/console woo:objects:sync null https://commongateway.nl/pdd.SyncNoordwijkAction.action.json`

Besides being able to start synchronizing these publications manually through a command,
the "WOO default cronjob" Cronjob also synchronizes publications every 10 minutes if the Source and Action are configured properly.
For this, the Cronjob itself has to be enabled as well.

### Getting the synchronized objects

After synchronizing objects, you can fetch the objects by just requesting `GET /api/publications` to fetch all publications or `GET /api/publications?organisation.oin={oin}` to fetch the publications belonging to organisation.
Make sure here the oin parameter value is the same that is set as configuration on the Action of that municipality.

# CommonGateway\WOOBundle\Service\SyncXxllncCasesService  

Service responsible for synchronizing xxllnc cases to woo objects.





## Methods

| Name | Description |
|------|-------------|
|[__construct](#syncxxllnccasesservice__construct)|SyncXxllncCasesService constructor.|
|[setStyle](#syncxxllnccasesservicesetstyle)|Set symfony style in order to output to the console.|
|[syncXxllncCasesHandler](#syncxxllnccasesservicesyncxxllnccaseshandler)|Handles the synchronization of xxllnc cases.|




### SyncXxllncCasesService::__construct  

**Description**

```php
public __construct (\GatewayResourceService $resourceService, \CallService $callService, \SynchronizationService $syncService, \EntityManagerInterface $entityManager, \MappingService $mappingService, \LoggerInterface $pluginLogger, \FileService $fileService)
```

SyncXxllncCasesService constructor. 

 

**Parameters**

* `(\GatewayResourceService) $resourceService`
* `(\CallService) $callService`
* `(\SynchronizationService) $syncService`
* `(\EntityManagerInterface) $entityManager`
* `(\MappingService) $mappingService`
* `(\LoggerInterface) $pluginLogger`
* `(\FileService) $fileService`

**Return Values**

`void`


<hr />


### SyncXxllncCasesService::setStyle  

**Description**

```php
public setStyle (\SymfonyStyle $style)
```

Set symfony style in order to output to the console. 

 

**Parameters**

* `(\SymfonyStyle) $style`

**Return Values**

`self`




<hr />


### SyncXxllncCasesService::syncXxllncCasesHandler  

**Description**

```php
public syncXxllncCasesHandler (array $data, array $configuration)
```

Handles the synchronization of xxllnc cases. 

 

**Parameters**

* `(array) $data`
* `(array) $configuration`

**Return Values**

`array`




**Throws Exceptions**


`\CacheException|\InvalidArgumentException`


<hr />


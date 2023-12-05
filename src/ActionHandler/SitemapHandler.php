<?php

namespace CommonGateway\WOOBundle\ActionHandler;

use CommonGateway\WOOBundle\Service\SitemapService;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use CommonGateway\CoreBundle\ActionHandler\ActionHandlerInterface;

/**
 * ActionHandler that generates a sitemap, sitemapindex and robot.txt.
 *
 * @author  Conduction BV <info@conduction.nl>, Sarai Misidjan <sarai@conduction.nl>
 * @license EUPL <https://github.com/ConductionNL/contactcatalogus/blob/master/LICENSE.md>
 *
 * @package  CommonGateway\WOOBundle
 * @category ActionHandler
 */
class SitemapHandler implements ActionHandlerInterface
{

    /**
     * @var SitemapService
     */
    private SitemapService $service;


    /**
     * SitemapHandler constructor.
     *
     * @param SitemapService $service
     */
    public function __construct(SitemapService $service)
    {
        $this->service = $service;

    }//end __construct()


    /**
     *  This function returns the required configuration as a [json-schema](https://json-schema.org/) array.
     *
     * @throws array a [json-schema](https://json-schema.org/) that this  action should comply to
     */
    public function getConfiguration(): array
    {
        return [
            '$id'         => 'https://commongateway.nl/woo.SitemapAction.action.json',
            '$schema'     => 'https://docs.commongateway.nl/schemas/ActionHandler.schema.json',
            'title'       => 'SitemapHandler',
            'description' => 'Generates the sitemap, sitemapindex or robot.txt.',
            'required'    => [],
            'properties'  => [],
        ];

    }//end getConfiguration()


    /**
     * This function runs the Sitemap service plugin.
     *
     * @param array $data          The data from the call
     * @param array $configuration The configuration of the action
     *
     * @throws TransportExceptionInterface|LoaderError|RuntimeError|SyntaxError
     *
     * @return array
     */
    public function run(array $data, array $configuration): array
    {
        return $this->service->sitemapHandler($data, $configuration);

    }//end run()


}//end class

<?php

namespace CommonGateway\WOOBundle\Command;

use App\Entity\Action;
use CommonGateway\WOOBundle\Service\SyncXxllncCasesService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * This class handles the command for the synchronization of a xxllnc case to a woo object.
 *
 * This Command executes the syncXxllncCasesService->syncXxllncCasesHandler.
 *
 * @author  Conduction BV <info@conduction.nl>, Barry Brands <barry@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @package  CommonGateway\WOOBundle
 * @category Command
 */
class SyncXxllncCasesCommand extends Command
{

    /**
     * The actual command.
     *
     * @var static
     */
    protected static $defaultName = 'woo:case:synchronize';

    /**
     * The case service.
     *
     * @var SyncXxllncCasesService
     */
    private SyncXxllncCasesService $syncXxllncCasesService;

    /**
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;


    /**
     * Class constructor.
     *
     * @param SyncXxllncCasesService $syncXxllncCasesService The case service
     */
    public function __construct(SyncXxllncCasesService $syncXxllncCasesService, EntityManagerInterface $entityManager)
    {
        $this->syncXxllncCasesService = $syncXxllncCasesService;
        $this->entityManager          = $entityManager;
        parent::__construct();

    }//end __construct()


    /**
     * Configures this command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this
            ->setDescription('This command triggers SyncXxllncCasesService')
            ->setHelp('This command triggers SyncXxllncCasesService')
            ->addArgument(
                'id',
                InputArgument::OPTIONAL,
                'Case id to fetch from xxllnc'
            )
            ->addArgument(
                'action',
                InputArgument::OPTIONAL,
                'Action reference to find the action and execute for different organizations (municipalities)'
            );

    }//end configure()


    /**
     * Executes syncXxllncCasesService->syncXxllncCasesHandler or syncXxllncCasesService->getCase if a id is given.
     *
     * @param InputInterface  Handles input from cli
     * @param OutputInterface Handles output from cli
     *
     * @return int 0 for failure, 1 for success
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $style = new SymfonyStyle($input, $output);
        $this->syncXxllncCasesService->setStyle($style);

        if (($caseId = $input->getArgument('id')) === null
            || ($actionRef = $input->getArgument('action')) === null
        ) {
            $style->error("No id and/or caseId given to the command");

            return Command::FAILURE;
        }

        $action = $this->entityManager->getRepository('App:Action')->findOneBy(['reference' => $actionRef]);
        if ($action instanceof Action === false) {
            $style->error("Action with reference $actionRef not found");

            return Command::FAILURE;
        }

        if (Uuid::isValid($caseId) === true
        ) {
            // if ($this->syncXxllncCasesService->getZaak($action->getConfiguration(), $caseId) === true) {
            // return Command::FAILURE;
            // }
            isset($style) === true && $style->error("Single object synchronization not supported yet.");

            return Command::FAILURE;
        }//end if

        if ($this->syncXxllncCasesService->syncXxllncCasesHandler([], $action->getConfiguration()) === null) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;

    }//end execute()


}//end class

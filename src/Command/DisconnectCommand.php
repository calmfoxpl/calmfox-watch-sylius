<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Command;

use Calmfox\WatchBundle\Hub\HubClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Świadome zakończenie monitoringu wnętrza. Mówimy hubowi wprost, zamiast
 * zostawiać mu milczący adres i budzić ludzi zdarzeniem o zablokowanym pakiecie.
 */
#[AsCommand(name: 'calmfox:watch:disconnect', description: 'Kończy monitoring wnętrza sklepu i informuje o tym panel')]
final class DisconnectCommand extends Command
{
    public function __construct(private readonly HubClient $hub)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->hub->disconnect();

        if (!$result['ok']) {
            $io->warning(sprintf('Monitoring wstrzymany w sklepie, ale panel nie potwierdził rozłączenia: %s', $result['message']));

            return Command::FAILURE;
        }
        $io->success($result['message']);

        return Command::SUCCESS;
    }
}

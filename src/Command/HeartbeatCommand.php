<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Command;

use Calmfox\WatchBundle\Check\Health\ScheduledTasksCheck;
use Calmfox\WatchBundle\Core\StateStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Bicie serca dla checku zadań cyklicznych. Świadomie nie robi nic poza
 * zapisaniem znacznika: ma być tak tanie, żeby dało się je wywoływać co
 * kilka minut obok właściwych zadań sklepu, i tak proste, żeby jego awaria
 * znaczyła awarię crona, a nie awarię samego polecenia.
 */
#[AsCommand(name: 'calmfox:watch:heartbeat', description: 'Zapisuje znacznik czasu dla sprawdzenia zadań cyklicznych')]
final class HeartbeatCommand extends Command
{
    public function __construct(private readonly StateStore $state)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->state->set([ScheduledTasksCheck::STATE_KEY => time()]);
        $output->writeln(sprintf('<info>Znacznik zapisany: %s UTC</info>', gmdate('Y-m-d H:i:s')), OutputInterface::VERBOSITY_VERBOSE);

        return Command::SUCCESS;
    }
}

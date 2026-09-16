<?php

declare(strict_types=1);

namespace Calmfox\WatchBundle\Command;

use Calmfox\WatchBundle\Core\StateStore;
use Calmfox\WatchBundle\History\UpdateHistory;
use Calmfox\WatchBundle\Payload\PayloadProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

/**
 * Liczy zaległe aktualizacje i zapisuje wynik do stanu. TO polecenie idzie
 * do crona (raz na dobę wystarczy): Composer potrzebuje sieci i kilkudziesięciu
 * sekund, więc w trakcie żądania HTTP nie ma o tym mowy.
 *
 * Dopóki nikt go nie uruchomi, pole `updates` w payloadzie NIE jest wysyłane.
 * Zero znaczyłoby „sprawdzone, nie ma czego aktualizować", a to byłaby nieprawda.
 *
 * Przy okazji uzgadniamy migawkę wersji, bo cron to najpewniejszy moment,
 * w którym wykryjemy wdrożenie zrobione poza sklepem.
 */
#[AsCommand(name: 'calmfox:watch:updates', description: 'Liczy zaległe aktualizacje pakietów i zapisuje wynik do stanu')]
final class UpdatesCommand extends Command
{
    private const TIMEOUT = 300;

    public function __construct(
        private readonly StateStore $state,
        private readonly UpdateHistory $history,
        private readonly PayloadProvider $payloads,
        private readonly string $projectDir,
        private readonly string $composerBinary,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Licz także pakiety zależne, nie tylko wymagane wprost w composer.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!class_exists(Process::class)) {
            $io->error('Brak komponentu symfony/process, bez niego nie uruchomimy Composera.');

            return Command::FAILURE;
        }

        $command = [$this->composerBinary, 'outdated', '--format=json', '--no-interaction', '--working-dir='.$this->projectDir];
        if (!$input->getOption('all')) {
            // Domyślnie tylko pakiety wymagane wprost: reszta i tak podniesie się
            // razem z nimi, a lista bez tego filtra jest nie do przeczytania.
            $command[] = '--direct';
        }

        $process = new Process($command, $this->projectDir, null, null, self::TIMEOUT);
        $process->run();

        if (!$process->isSuccessful()) {
            $io->error(sprintf('Composer zakończył się błędem. %s', trim($process->getErrorOutput()) ?: trim($process->getOutput())));

            return Command::FAILURE;
        }

        $data = json_decode($process->getOutput(), true);
        if (!\is_array($data) || !\is_array($data['installed'] ?? null)) {
            $io->error('Nie rozumiem odpowiedzi Composera. Sprawdź wersję polecenia composer outdated.');

            return Command::FAILURE;
        }

        $core = 0;
        $packages = 0;
        foreach ($data['installed'] as $package) {
            $name = \is_array($package) ? (string) ($package['name'] ?? '') : '';
            if ('' === $name) {
                continue;
            }
            if ('sylius/sylius' === $name) {
                ++$core;

                continue;
            }
            ++$packages;
        }

        $this->state->set(['updates' => ['core' => $core, 'plugins' => $packages, 'themes' => 0, 'at' => gmdate('c')]]);
        $found = $this->history->reconcile(true);
        $this->payloads->forget();

        $io->success(sprintf('Zaległe aktualizacje: platforma %d, pozostałe pakiety %d.', $core, $packages));
        if ($found > 0) {
            $io->text(sprintf('Do historii dopisano %d zmian wersji wykrytych od ostatniego sprawdzenia.', $found));
        }

        return Command::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AudioLibrary\Inventory\AudioInventoryCommandRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:audio-inventory:run', description: 'Inventaire MP3 en lecture seule, non certifié.')]
final class AudioInventoryRunCommand extends Command implements SignalableCommandInterface
{
    private bool $interrupted = false;

    public function __construct(private readonly AudioInventoryCommandRunner $runner) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addOption('inventory-profile', null, InputOption::VALUE_REQUIRED, 'Profil autorisé dans la configuration du déploiement.')
            ->addOption('scope', null, InputOption::VALUE_REQUIRED, 'Périmètre explicite : web_full ou test_subset.');
    }

    public function getSubscribedSignals(): array
    {
        return defined('SIGINT') && defined('SIGTERM') ? [SIGINT, SIGTERM] : [];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        $this->interrupted = true;
        return false; // Cooperative cancellation at the engine's existing boundaries.
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->runner->run((string) $input->getOption('inventory-profile'), (string) $input->getOption('scope'),
                fn (): bool => !$this->interrupted);
            if ($result['status'] !== 'completed' || $result['publication'] !== 'published') {
                $output->writeln('<error>Inventaire interrompu ; aucun rapport publié.</error>');
                return self::FAILURE;
            }
            $output->writeln('Exécution terminée avec restrictions.');
            $output->writeln('inventory_id='.$result['inventory_id']);
            $output->writeln(sprintf('Résultats=%d ; chemins physiques=%d ; associations=%d.',
                $result['result_count'], $result['physical_paths'], $result['association_count']));
            $output->writeln('observation_only ; storage_certified=false ; absence_conclusions_allowed=false');
            $output->writeln('Aucune permission de suppression ; aucune absence certifiée.');
            return self::SUCCESS;
        } catch (\InvalidArgumentException) {
            $output->writeln('<error>Configuration ou options invalides : vérifier le profil, le périmètre, les racines et les budgets.</error>');
            return self::INVALID;
        } catch (\Throwable $error) {
            // Do not leak SQL errors, DSNs, paths or credentials through raw exception messages.
            $output->writeln('<error>Échec de l’inventaire ; aucune réussite annoncée ('.get_class($error).').</error>');
            if ($this->runner->getSecondaryErrors() !== []) { $output->writeln('<error>Nettoyage incomplet : intervention nécessaire.</error>'); }
            return self::FAILURE;
        }
    }
}

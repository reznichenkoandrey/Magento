<?php
declare(strict_types=1);

namespace Scr1be\BackInStock\Console\Command;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Scr1be\BackInStock\Model\ResourceModel\PopupStatusWriter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `bin/magento scr1be:back-in-stock:reset`
 *
 * Puts fired alerts back in the queue so their popup shows again.
 *
 * The popup is a once-per-restock surface, which makes it awkward to work on: showing it a second
 * time means finding a product that is out of stock, subscribing, restocking it and running the
 * alert cron — three minutes of setup for one screenshot. This command replays the last step.
 *
 * It only ever moves rows core has already marked sent. Re-queueing an alert that never fired would
 * put a card for an out-of-stock product in front of a customer, which is the one thing this module
 * exists not to do.
 *
 * There is no bare form: `--all` has to be spelled out, because on a production database the bare
 * form would re-open a popup for every customer who has ever dismissed one.
 */
class ResetPopupCommand extends Command
{
    private const OPTION_CUSTOMER = 'customer';
    private const OPTION_WEBSITE = 'website';
    private const OPTION_ALL = 'all';

    public function __construct(
        private readonly PopupStatusWriter $writer,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly WebsiteRepositoryInterface $websiteRepository,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('scr1be:back-in-stock:reset')
            ->setDescription('Re-queue dismissed back-in-stock popups so they show again.')
            ->addOption(
                self::OPTION_CUSTOMER,
                'c',
                InputOption::VALUE_REQUIRED,
                'Email address of the customer to reset.'
            )
            ->addOption(
                self::OPTION_WEBSITE,
                'w',
                InputOption::VALUE_REQUIRED,
                'Website code to limit the reset to. Required alongside --customer on a multi-website install.'
            )
            ->addOption(
                self::OPTION_ALL,
                null,
                InputOption::VALUE_NONE,
                'Reset every customer. Mutually exclusive with --customer.'
            );

        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = $input->getOption(self::OPTION_CUSTOMER);
        $all = (bool)$input->getOption(self::OPTION_ALL);

        if ($email === null && !$all) {
            $output->writeln('<error>Give either --customer=<email> or --all.</error>');

            return Cli::RETURN_FAILURE;
        }

        if ($email !== null && $all) {
            $output->writeln('<error>--customer and --all cannot be combined.</error>');

            return Cli::RETURN_FAILURE;
        }

        try {
            $websiteId = $this->resolveWebsiteId($input->getOption(self::OPTION_WEBSITE));
            $customerId = $email !== null ? $this->resolveCustomerId((string)$email, $websiteId) : null;
        } catch (NoSuchEntityException | LocalizedException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');

            return Cli::RETURN_FAILURE;
        }

        $moved = $this->writer->requeueSent($customerId, $websiteId);

        $output->writeln(sprintf(
            '<info>%d alert%s re-queued.</info>',
            $moved,
            $moved === 1 ? '' : 's'
        ));

        return Cli::RETURN_SUCCESS;
    }

    /**
     * @throws NoSuchEntityException
     */
    private function resolveWebsiteId(?string $code): ?int
    {
        if ($code === null || $code === '') {
            return null;
        }

        return (int)$this->websiteRepository->get($code)->getId();
    }

    /**
     * @throws NoSuchEntityException
     * @throws LocalizedException
     */
    private function resolveCustomerId(string $email, ?int $websiteId): int
    {
        // Customer emails are unique per website, not globally, so an install with two websites and
        // account sharing set to per-website can legitimately hold the same address twice. Passing
        // the website through is what makes the answer unambiguous when the caller supplied one.
        return (int)$this->customerRepository->get($email, $websiteId)->getId();
    }
}

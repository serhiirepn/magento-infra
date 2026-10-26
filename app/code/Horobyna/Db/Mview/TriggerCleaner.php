<?php
/**
 * PostgreSQL database adapter for Magento
 */
namespace Horobyna\Db\Mview;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Ddl\Trigger;
use Magento\Framework\Mview\View\CollectionFactory;
use Magento\Framework\Mview\View\StateInterface;
use Magento\Framework\Mview\View\Subscription;
use Magento\Framework\Mview\ViewFactory;
use Magento\Framework\Mview\ViewInterface;
use Horobyna\Db\Adapter\Pdo\Pgsql;

/**
 * Removes old mview triggers, and updates changed ones, in PostgreSQL
 *
 * The core class reads MySQL information_schema.TRIGGERS and compares the trigger bodies with the MySQL
 * statements of the views. Here triggers are read from the catalog, and their bodies (the functions made by
 * Pgsql::createTrigger()) are compared with the translated statements. The core methods are private, so the
 * logic of removeTriggers() is repeated.
 */
class TriggerCleaner extends \Magento\Framework\Mview\TriggerCleaner
{
    /**
     * @var CollectionFactory
     */
    private $viewCollectionFactory;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var ViewFactory
     */
    private $viewFactory;

    /**
     * @var array
     */
    private $processedTriggers = [];

    /**
     * @var array
     */
    private $dbTriggers = [];

    /**
     * @param CollectionFactory $viewCollectionFactory
     * @param ResourceConnection $resource
     * @param ViewFactory $viewFactory
     */
    public function __construct(
        CollectionFactory $viewCollectionFactory,
        ResourceConnection $resource,
        ViewFactory $viewFactory
    ) {
        parent::__construct($viewCollectionFactory, $resource, $viewFactory);
        $this->viewCollectionFactory = $viewCollectionFactory;
        $this->resource = $resource;
        $this->viewFactory = $viewFactory;
    }

    /**
     * Remove the triggers of disabled views, and save the changed triggers of enabled ones
     *
     * @return bool
     * @throws \Exception
     */
    public function removeTriggers(): bool
    {
        $this->loadDbTriggers();

        // Get list of views that are enabled
        $viewCollection = $this->viewCollectionFactory->create();
        $viewList = $viewCollection->getViewsByStateMode(StateInterface::MODE_ENABLED);

        // Check triggers declaration for the enabled views and update them if any changes
        foreach ($viewList as $view) {
            foreach ($view->getSubscriptions() as $subscriptionConfig) {
                /* @var $subscription Subscription */
                $subscription = $view->initSubscriptionInstance($subscriptionConfig);
                $viewTriggers = $subscription->create(false)->getTriggers();
                $this->processViewTriggers($viewTriggers, $subscription);
            }
        }

        // Remove any remaining triggers from db that are not linked to a view
        $remainingTriggers = array_diff_key($this->dbTriggers, $this->processedTriggers);
        foreach ($remainingTriggers as $trigger) {
            $view = $this->createViewByTableName($trigger['EVENT_OBJECT_TABLE']);
            if ($view->getActionClass()) {
                $view->unsubscribe();
                $view->getState()->delete();
            }
        }

        return true;
    }

    /**
     * Save the view triggers that are missing or differ from the stored ones
     *
     * @param Trigger[] $viewTriggers
     * @param Subscription $subscription
     * @return void
     */
    private function processViewTriggers(array $viewTriggers, Subscription $subscription): void
    {
        /** @var Pgsql $connection */
        $connection = $this->resource->getConnection();
        foreach ($viewTriggers as $viewTrigger) {
            if (array_key_exists($viewTrigger->getName(), $this->dbTriggers)) {
                foreach ($this->getStatementsFromViewTrigger($viewTrigger) as $statement) {
                    if (!empty($statement) && !str_contains(
                        $this->dbTriggers[$viewTrigger->getName()]['ACTION_STATEMENT'],
                        $connection->translateTriggerStatements($statement)
                    )) {
                        $subscription->saveTrigger($viewTrigger);
                        break;
                    }
                }
            } else {
                $subscription->saveTrigger($viewTrigger);
            }
            $this->processedTriggers[$viewTrigger->getName()] = true;
        }
    }

    /**
     * Load the triggers of the current schema, with the body of their function as statement
     *
     * @return void
     */
    private function loadDbTriggers(): void
    {
        $this->dbTriggers = $this->resource->getConnection()->fetchAssoc(
            'SELECT t.tgname AS "TRIGGER_NAME", p.prosrc AS "ACTION_STATEMENT", c.relname AS "EVENT_OBJECT_TABLE"'
            . ' FROM pg_trigger AS t'
            . ' JOIN pg_class AS c ON c.oid = t.tgrelid'
            . ' JOIN pg_namespace AS n ON n.oid = c.relnamespace'
            . ' JOIN pg_proc AS p ON p.oid = t.tgfoid'
            . ' WHERE NOT t.tgisinternal AND n.nspname = current_schema()'
            . ' ORDER BY t.tgname'
        );
    }

    /**
     * Create a view for the table of a trigger, to unsubscribe from it
     *
     * @param string $tableName
     * @return ViewInterface
     */
    private function createViewByTableName(string $tableName): ViewInterface
    {
        $subscription[$tableName] = [
            'name' => $tableName,
            'column' => '',
            'subscription_model' => null
        ];
        $data['data'] = [
            'subscriptions' => $subscription,
        ];
        $view = $this->viewFactory->create($data);
        $view->setId('old_view');
        $view->getState()->setMode(StateInterface::MODE_ENABLED);

        return $view;
    }

    /**
     * Get the statements of a view trigger, splitting the statement of staged entity attribute subscriptions
     *
     * @param Trigger $trigger
     * @return string[]
     */
    private function getStatementsFromViewTrigger(Trigger $trigger): array
    {
        $statements = $trigger->getStatements();

        //Check for staged entity attribute subscription
        $statement = array_shift($statements);
        if (str_contains($statement, 'SET')) {
            $splitStatements = explode(PHP_EOL, $statement);
            $statements += $splitStatements;
        } else {
            array_unshift($statements, $statement);
        }

        return $statements;
    }
}

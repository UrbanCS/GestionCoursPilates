<?php
/**
 * @package     Memi.Plugin
 * @subpackage  Task.Memipilates
 */

declare(strict_types=1);

namespace Memi\Plugin\Task\Memipilates\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status as TaskStatus;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Event\SubscriberInterface;
use Memi\Component\Memipilates\Administrator\Service\ComponentServices;

final class Memipilates extends CMSPlugin implements SubscriberInterface
{
    use TaskPluginTrait;

    /**
     * @var array<string, array{langConstPrefix:string, method:string, form:string}>
     */
    protected const TASKS_MAP = [
        'plg_task_memipilates_run_due_tasks' => [
            'langConstPrefix' => 'PLG_TASK_MEMIPILATES_TASK_RUN_DUE_TASKS',
            'method' => 'runDueTasks',
            'form' => 'run_due_tasks',
        ],
    ];

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList' => 'advertiseRoutines',
            'onExecuteTask' => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }

    protected function runDueTasks(ExecuteTaskEvent $event): int
    {
        try {
            $params = $event->getArgument('params');
            $options = [
                'horizon_days' => max(1, min(365, (int) $this->parameter($params, 'horizon_days', 90))),
                'email_limit' => max(1, min(500, (int) $this->parameter($params, 'email_limit', 100))),
                'skip_reminders' => (bool) $this->parameter($params, 'skip_reminders', false),
                'dry_run' => (bool) $this->parameter($params, 'dry_run', false),
            ];
            $result = ComponentServices::scheduler()->runDueTasks($options);
            $this->snapshot['memipilates'] = $result;
            $this->logTask(
                Text::sprintf(
                    'PLG_TASK_MEMIPILATES_LOG_SUMMARY',
                    (int) ($result['sessions_generated'] ?? 0),
                    (int) ($result['credits_expired'] ?? 0),
                    (int) ($result['offers_expired'] ?? 0),
                    (int) ($result['notifications_sent'] ?? 0)
                )
            );

            return TaskStatus::OK;
        } catch (\Throwable) {
            // Do not emit raw exception text; it can contain transport details.
            $this->snapshot['memipilates_error'] = 'scheduler_failed';
            $this->logTask(Text::_('PLG_TASK_MEMIPILATES_LOG_FAILURE'), 'error');

            return TaskStatus::KNOCKOUT;
        }
    }

    private function parameter(mixed $params, string $name, mixed $default): mixed
    {
        if (is_object($params) && method_exists($params, 'get')) {
            return $params->get($name, $default);
        }

        if (is_object($params) && isset($params->{$name})) {
            return $params->{$name};
        }

        if (is_array($params) && array_key_exists($name, $params)) {
            return $params[$name];
        }

        return $default;
    }
}

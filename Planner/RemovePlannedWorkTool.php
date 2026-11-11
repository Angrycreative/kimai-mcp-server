<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Planner;

use App\Entity\User;
use App\Repository\CustomerRepository;
use App\Repository\ProjectRepository;
use KimaiPlugin\McpServerBundle\Server\ToolHandlerAdapter;
use KimaiPlugin\McpServerBundle\Support\Args;
use KimaiPlugin\McpServerBundle\Support\Schema;
use KimaiPlugin\McpServerBundle\Tool\AbstractWriteTool;
use KimaiPlugin\McpServerBundle\Tool\ToolException;
use KimaiPlugin\McpServerBundle\Tool\WritePlan;
use KimaiPlugin\PlannerBundle\Entity\PlannedActivity;
use KimaiPlugin\PlannerBundle\Repository\PlannedActivityRepository;
use KimaiPlugin\PlannerBundle\Voter\PlannedActivityVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class RemovePlannedWorkTool extends AbstractWriteTool
{
    private const DELETE = 'delete';
    private const SHORTEN = 'shorten';
    private const SPLIT = 'split';

    public function __construct(
        private readonly PlannerSupport $support,
        private readonly PlannedActivityRepository $plannings,
        private readonly CustomerRepository $customers,
        private readonly ProjectRepository $projects,
        private readonly AuthorizationCheckerInterface $auth,
    ) {
    }

    public function getName(): string
    {
        return 'remove_planned_work';
    }

    public function getDescription(): string
    {
        return 'Clears a person\'s Weekly Planner for a period, for example when they are off sick: plannings inside '
            . 'the period are removed, ones reaching past it are shortened, and one spanning it is split around it. '
            . 'Optionally only for some customers or projects, or only given planning IDs. Time off is left alone '
            . 'unless include_time_off is true. Returns what was taken off so it can be planned elsewhere.';
    }

    protected function getArgumentSchema(): array
    {
        return Schema::object([
            'user_id' => Schema::id('Whose planning (ID from find_users). Default: yours'),
            'from' => Schema::date('First day to clear'),
            'to' => Schema::date('Last day to clear, inclusive. Default: the same as "from"'),
            'customer_ids' => Schema::ids('Only plannings for these customers'),
            'project_ids' => Schema::ids('Only plannings for these projects'),
            'planning_ids' => Schema::ids('Only these plannings (IDs from planned_work)'),
            'include_time_off' => ['type' => 'boolean', 'description' => 'Also clear time off. Default: false'],
        ], ['from']);
    }

    public function getRequiredPermissions(): array
    {
        return ['delete_planner'];
    }

    protected function plan(array $arguments, User $user): WritePlan
    {
        $owner = $this->support->owner($arguments, $user);
        $from = PlannerSupport::day($arguments, 'from', $user);
        $to = PlannerSupport::day($arguments, 'to', $user) ?? ($from !== null ? clone $from : null);
        if ($from === null || $to === null || $to < $from) {
            throw new ToolException('"to" cannot be before "from".');
        }

        $customerIds = Args::ids($arguments, 'customer_ids');
        $customers = $customerIds === [] ? [] : array_values($this->customers->findByIds($customerIds));
        $projectIds = Args::ids($arguments, 'project_ids');
        $projects = $projectIds === [] ? [] : array_values($this->projects->findByIds($projectIds));
        if (($customerIds !== [] && $customers === []) || ($projectIds !== [] && $projects === [])) {
            throw new ToolException('None of the given customer or project IDs exist.');
        }
        $only = Args::ids($arguments, 'planning_ids');
        $withTimeOff = Args::bool($arguments, 'include_time_off') === true;

        $steps = [];
        foreach ($this->plannings->findByFilter([$owner], $from, $to, $customers, $projects) as $activity) {
            if (($only !== [] && !\in_array($activity->getId(), $only, true)) || ($activity->isTimeOff() && !$withTimeOff)) {
                continue;
            }
            $action = $this->action($activity, $from, $to);
            $attribute = $action === self::DELETE ? PlannedActivityVoter::DELETE : PlannedActivityVoter::EDIT;
            if (!$this->auth->isGranted($attribute, $activity) || !$this->auth->isGranted(PlannedActivityVoter::DELETE, $activity)) {
                throw new ToolException(\sprintf('You may not change planning %d (%s); nothing was removed.', $activity->getId(), $activity->getName()));
            }
            $steps[] = ['activity' => $activity, 'action' => $action, 'before' => PlannerSupport::describe($activity)];
        }

        $summary = [
            'user' => ['id' => $owner->getId(), 'name' => $owner->getDisplayName()],
            'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
            'changes' => array_map(fn (array $step): array => ['action' => $step['action'], 'planning' => $step['before'], 'days_cleared' => $this->overlapDays($step['activity'], $from, $to)], $steps),
        ];
        if ($steps === []) {
            $summary['note'] = 'Nothing planned to clear in that period.';
        }

        return new WritePlan($summary, function () use ($steps, $from, $to, $summary): array {
            $changed = [];
            foreach ($steps as $step) {
                $changed = [...$changed, ...$this->apply($step['activity'], $step['action'], $from, $to)];
            }

            return ['cleared' => $summary['changes'], ToolHandlerAdapter::CHANGED => $changed];
        });
    }

    private function action(PlannedActivity $activity, \DateTime $from, \DateTime $to): string
    {
        $begin = $activity->getBegin()?->format('Y-m-d') ?? '';
        $end = $activity->getEnd()?->format('Y-m-d') ?? '';
        $first = $from->format('Y-m-d');
        $last = $to->format('Y-m-d');

        if ($begin >= $first && $end <= $last) {
            return self::DELETE;
        }

        return ($begin < $first && $end > $last) ? self::SPLIT : self::SHORTEN;
    }

    private function overlapDays(PlannedActivity $activity, \DateTime $from, \DateTime $to): int
    {
        $begin = max($activity->getBegin()?->format('Y-m-d') ?? '', $from->format('Y-m-d'));
        $end = min($activity->getEnd()?->format('Y-m-d') ?? '', $to->format('Y-m-d'));

        return (int) (new \DateTime($begin))->diff(new \DateTime($end))->days + 1;
    }

    /**
     * @return list<string>
     */
    private function apply(PlannedActivity $activity, string $action, \DateTime $from, \DateTime $to): array
    {
        $ref = 'planned_activity:' . $activity->getId();
        $dayBefore = (clone $from)->modify('-1 day');
        $dayAfter = (clone $to)->modify('+1 day');

        if ($action === self::DELETE) {
            $this->plannings->deletePlannedActivity($activity);

            return [$ref];
        }

        if ($action === self::SPLIT) {
            $rest = $activity->copyPlanningTo(new PlannedActivity());
            $rest->setRecurrenceGroup($activity->getRecurrenceGroup());
            $rest->setBegin($dayAfter);
            $rest->setEnd(new \DateTime((string) $activity->getEnd()?->format('Y-m-d')));
            $activity->setEnd($dayBefore);
            $this->plannings->savePlannedActivity($activity);
            $this->plannings->savePlannedActivity($rest);

            return [$ref, 'planned_activity:' . $rest->getId()];
        }

        // shorten: the part inside the period goes, from one end
        if (($activity->getBegin()?->format('Y-m-d') ?? '') >= $from->format('Y-m-d')) {
            $activity->setBegin($dayAfter);
        } else {
            $activity->setEnd($dayBefore);
        }
        $this->plannings->savePlannedActivity($activity);

        return [$ref];
    }
}

<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Planner;

use App\Entity\Customer;
use App\Entity\Project;
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
use KimaiPlugin\PlannerBundle\Entity\Reservation;
use KimaiPlugin\PlannerBundle\Repository\PlannedActivityRepository;
use KimaiPlugin\PlannerBundle\Repository\ReservationRepository;
use KimaiPlugin\PlannerBundle\Voter\PlannedActivityVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class SetPlannedHoursTool extends AbstractWriteTool
{
    public function __construct(
        private readonly PlannerSupport $support,
        private readonly PlannedActivityRepository $plannings,
        private readonly ReservationRepository $reservations,
        private readonly CustomerRepository $customers,
        private readonly ProjectRepository $projects,
        private readonly AuthorizationCheckerInterface $auth,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function getName(): string
    {
        return 'set_planned_hours';
    }

    public function getDescription(): string
    {
        return 'Plans hours in the Weekly Planner, or changes a planning. Without planning_id it creates one: a '
            . 'person, a period and hours per day, for a project (or a customer), for reserved time (by name), or as '
            . 'time off. With planning_id (from planned_work) it changes only what is given: the period, hours per '
            . 'day, tentative, comment, or the person. Changes one planning of a recurring series, never the series.';
    }

    protected function getArgumentSchema(): array
    {
        return Schema::object([
            'planning_id' => Schema::id('An existing planning to change (ID from planned_work). Leave out to create one'),
            'user_id' => Schema::id('Whose planning it is (ID from find_users). Default: yours, or unchanged'),
            'project_id' => Schema::id('New planning: the project it is for (ID from find_projects)'),
            'customer_id' => Schema::id('New planning: the customer, when it is not for one project'),
            'reserved_time' => ['type' => 'string', 'description' => 'New planning: reserved time by name, e.g. "Internal"'],
            'time_off' => ['type' => 'boolean', 'description' => 'New planning: true for time off'],
            'from' => Schema::date('First day (required for a new planning)'),
            'to' => Schema::date('Last day, inclusive. Default for a new planning: the same as "from"'),
            'hours_per_day' => ['type' => 'number', 'minimum' => 0, 'maximum' => 24, 'description' => 'Hours per day. Required for new work; time off defaults to the whole day'],
            'tentative' => ['type' => 'boolean', 'description' => 'Tentative rather than confirmed'],
            'comment' => ['type' => 'string', 'description' => 'A comment shown on the planning'],
        ]);
    }

    public function getRequiredPermissions(): array
    {
        return ['create_planner|edit_planner'];
    }

    protected function plan(array $arguments, User $user): WritePlan
    {
        $id = Args::int($arguments, 'planning_id');

        return $id === null ? $this->create($arguments, $user) : $this->change($id, $arguments, $user);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function create(array $arguments, User $user): WritePlan
    {
        $activity = new PlannedActivity();
        $activity->setUser($this->support->owner($arguments, $user));

        $timeOff = Args::bool($arguments, 'time_off') === true;
        $reservationName = Args::string($arguments, 'reserved_time');
        $projectId = Args::int($arguments, 'project_id');
        $customerId = Args::int($arguments, 'customer_id');
        $kinds = \count(array_filter([$timeOff, $reservationName !== null, $projectId !== null || $customerId !== null]));
        if ($kinds !== 1) {
            throw new ToolException('Say what the planning is for: a project (or customer), reserved_time, or time_off, exactly one of them.');
        }

        if ($timeOff) {
            $activity->setTimeOff(true);
            $activity->setHoursPerDay(null);
        } elseif ($reservationName !== null) {
            $activity->setReservation($this->reservation($reservationName));
        } else {
            [$customer, $project] = $this->target($projectId, $customerId);
            $activity->setCustomer($customer);
            $activity->setProject($project);
        }

        $from = PlannerSupport::day($arguments, 'from', $user);
        if ($from === null) {
            throw new ToolException('A new planning needs "from".');
        }
        $activity->setBegin($from);
        $activity->setEnd(PlannerSupport::day($arguments, 'to', $user) ?? clone $from);
        if (isset($arguments['hours_per_day']) && is_numeric($arguments['hours_per_day'])) {
            $activity->setHoursPerDay((float) $arguments['hours_per_day']);
        } elseif (!$timeOff) {
            throw new ToolException('Say how many hours per day ("hours_per_day").');
        }
        $this->applyCommon($activity, $arguments);
        $this->check($activity, PlannedActivityVoter::CREATE);

        $summary = ['create_planning' => PlannerSupport::describe($activity)];
        $overlapping = $this->plannings->countOverlapping($activity);
        if ($overlapping > 0) {
            $summary['note'] = \sprintf('The person already has %d planning(s) for the same thing on some of these days; this adds to them.', $overlapping);
        }

        return new WritePlan($summary, function () use ($activity): array {
            $this->plannings->savePlannedActivity($activity);

            return ['planning' => PlannerSupport::describe($activity), ToolHandlerAdapter::CHANGED => ['planned_activity:' . $activity->getId()]];
        });
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function change(int $id, array $arguments, User $user): WritePlan
    {
        $activity = $this->plannings->find($id);
        if (!$activity instanceof PlannedActivity || !$this->auth->isGranted(PlannedActivityVoter::EDIT, $activity)) {
            throw new ToolException('No planning with that ID that you may change.');
        }
        foreach (['project_id', 'customer_id', 'reserved_time', 'time_off'] as $key) {
            if (\array_key_exists($key, $arguments)) {
                throw new ToolException(\sprintf('"%s" cannot be changed on an existing planning: remove it and plan anew.', $key));
            }
        }
        $before = PlannerSupport::describe($activity);

        if (Args::int($arguments, 'user_id') !== null) {
            $activity->setUser($this->support->owner($arguments, $user));
        }
        if (($from = PlannerSupport::day($arguments, 'from', $user)) !== null) {
            $activity->setBegin($from);
        }
        if (($to = PlannerSupport::day($arguments, 'to', $user)) !== null) {
            $activity->setEnd($to);
        }
        if (isset($arguments['hours_per_day']) && is_numeric($arguments['hours_per_day'])) {
            $activity->setHoursPerDay((float) $arguments['hours_per_day']);
        }
        $this->applyCommon($activity, $arguments);
        // the voter looks at the (possibly new) person, so planning cannot be moved to someone the caller may not plan for
        $this->check($activity, PlannedActivityVoter::EDIT);

        $summary = ['change_planning' => ['before' => $before, 'after' => PlannerSupport::describe($activity)]];

        return new WritePlan($summary, function () use ($activity): array {
            $this->plannings->savePlannedActivity($activity);

            return ['planning' => PlannerSupport::describe($activity), ToolHandlerAdapter::CHANGED => ['planned_activity:' . $activity->getId()]];
        });
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function applyCommon(PlannedActivity $activity, array $arguments): void
    {
        if (($tentative = Args::bool($arguments, 'tentative')) !== null) {
            $activity->setTentative($tentative);
        }
        if (($comment = Args::string($arguments, 'comment')) !== null) {
            $activity->setComment($comment);
        }
    }

    /**
     * @throws ToolException
     */
    private function check(PlannedActivity $activity, string $attribute): void
    {
        $begin = $activity->getBegin();
        $end = $activity->getEnd();
        if ($begin !== null && $end !== null && $end < $begin) {
            throw new ToolException('"to" cannot be before "from".');
        }
        if (!$this->auth->isGranted($attribute, $activity)) {
            throw new ToolException($activity->getReservation() !== null
                ? 'You may not plan reserved time: that needs the plan_reservations_planner permission.'
                : 'You may not plan for this person: your role needs the planner permissions for them.');
        }

        $violations = $this->validator->validate($activity);
        if (\count($violations) > 0) {
            $messages = [];
            foreach ($violations as $violation) {
                $messages[] = $violation->getPropertyPath() . ': ' . $violation->getMessage();
            }
            throw new ToolException('The planner would refuse this: ' . implode('; ', $messages));
        }
    }

    /**
     * @return array{0: Customer, 1: Project|null}
     * @throws ToolException
     */
    private function target(?int $projectId, ?int $customerId): array
    {
        if ($projectId !== null) {
            $project = $this->projects->find($projectId);
            if (!$project instanceof Project || !$this->auth->isGranted('view', $project) || $project->getCustomer() === null) {
                throw new ToolException('No project with that ID that you may see.');
            }
            if ($customerId !== null && $project->getCustomer()->getId() !== $customerId) {
                throw new ToolException('That project belongs to another customer.');
            }

            return [$project->getCustomer(), $project];
        }

        $customer = $this->customers->find((int) $customerId);
        if (!$customer instanceof Customer || !$this->auth->isGranted('view', $customer)) {
            throw new ToolException('No customer with that ID that you may see.');
        }

        return [$customer, null];
    }

    /**
     * @throws ToolException
     */
    private function reservation(string $name): Reservation
    {
        $available = [];
        foreach ($this->reservations->findSelectable() as $reservation) {
            if (mb_strtolower(trim((string) $reservation->getName())) === mb_strtolower(trim($name))) {
                return $reservation;
            }
            $available[] = $reservation->getName();
        }

        throw new ToolException(\sprintf('No reserved time called "%s". Available: %s.', $name, $available === [] ? 'none' : implode(', ', $available)));
    }
}

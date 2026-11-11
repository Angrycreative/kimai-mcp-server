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
use KimaiPlugin\McpServerBundle\Support\Args;
use KimaiPlugin\McpServerBundle\Support\Format;
use KimaiPlugin\McpServerBundle\Tool\ToolException;
use KimaiPlugin\PlannerBundle\Entity\PlannedActivity;
use KimaiPlugin\PlannerBundle\Planner\PlannerUserProvider;

/**
 * What the planner tools share: whose plans the caller may see (the planner's own rules, including plugins that
 * leave people out), and how a planning reads.
 */
final class PlannerSupport
{
    public function __construct(private readonly PlannerUserProvider $users)
    {
    }

    /**
     * The people the caller may see in the planner, narrowed to "user_ids" when given.
     *
     * @param array<string, mixed> $args
     * @return list<User>
     * @throws ToolException
     */
    public function visibleUsers(array $args, User $caller): array
    {
        $visible = [];
        foreach ($this->users->getVisibleUsers($caller) as $user) {
            $visible[(int) $user->getId()] = $user;
        }

        $ids = Args::ids($args, 'user_ids');
        if ($ids === []) {
            return array_values($visible);
        }

        $missing = array_diff($ids, array_keys($visible));
        if ($missing !== []) {
            throw new ToolException(\sprintf('You may not see the plans of user %s in the planner.', implode(', ', $missing)));
        }

        return array_values(array_intersect_key($visible, array_flip($ids)));
    }

    /**
     * Whose planning it is: the caller, or the person "user_id" names when the caller may see them in the planner
     * (the planner's voter then decides whether they may change it).
     *
     * @param array<string, mixed> $args
     * @throws ToolException
     */
    public function owner(array $args, User $caller): User
    {
        $id = Args::int($args, 'user_id');
        if ($id === null || $id === $caller->getId()) {
            return $caller;
        }

        return $this->visibleUsers(['user_ids' => [$id]], $caller)[0];
    }

    /**
     * A day for the planner, which stores plain dates.
     *
     * @param array<string, mixed> $args
     * @throws ToolException
     */
    public static function day(array $args, string $key, User $user): ?\DateTime
    {
        $date = Args::date($args, $key, $user);

        return $date === null ? null : new \DateTime($date->format('Y-m-d'));
    }

    /**
     * @return array<string, mixed>
     */
    public static function describe(PlannedActivity $activity): array
    {
        $out = [
            'id' => $activity->getId(),
            'user' => ['id' => $activity->getUser()?->getId(), 'name' => $activity->getUser()?->getDisplayName()],
            'from' => $activity->getBegin()?->format('Y-m-d'),
            'to' => $activity->getEnd()?->format('Y-m-d'),
            'hours_per_day' => $activity->getHoursPerDay(),
        ];

        if ($activity->isTimeOff()) {
            $out['time_off'] = true;
        } elseif ($activity->getReservation() !== null) {
            $out['reserved_time'] = $activity->getReservation()->getName();
        } else {
            $out['customer'] = ['id' => $activity->getCustomer()?->getId(), 'name' => $activity->getCustomer()?->getName()];
            if ($activity->getProject() !== null) {
                $out['project'] = ['id' => $activity->getProject()->getId(), 'name' => $activity->getProject()->getName()];
            }
        }
        if ($activity->getTitle() !== null && $activity->getTitle() !== '') {
            $out['title'] = $activity->getTitle();
        }
        if ($activity->isTentative()) {
            $out['tentative'] = true;
        }
        if ($activity->hasComment()) {
            $out['comment'] = $activity->getComment();
        }
        if ($activity->isRecurring()) {
            $out['recurring'] = true;
        }

        return $out;
    }

    public static function hours(float $hours): float
    {
        return Format::hours($hours * 3600);
    }
}

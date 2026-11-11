<?php

declare(strict_types=1);

/*
 * This file is part of the McpServerBundle for Kimai.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\McpServerBundle\Category;

use App\Entity\Project;
use App\Entity\User;

/**
 * Kimai has no notion of absence, internal work or maintenance: an instance logs those on projects and activities of
 * its own choosing. Tools ask this interface instead of deciding. The bundle's default reads the "Absence projects"
 * setting; another plugin can provide its own by implementing this interface, and is then used instead.
 */
interface TimeCategoryProviderInterface
{
    public const ABSENCE = 'absence';

    /**
     * Every category key with its English label, e.g. ['customer_work' => 'Customer work', 'absence' => 'Absence'].
     * Must include self::ABSENCE.
     *
     * @return array<string, string>
     */
    public function getCategories(): array;

    /**
     * The category of time logged on this project and activity, for this customer. Always one of getCategories().
     */
    public function categoryFor(?int $projectId, ?int $activityId, ?int $customerId): string;

    /**
     * The projects whose time counts as absence.
     *
     * @return list<int>
     */
    public function getAbsenceProjectIds(): array;

    /**
     * The absence project this person logs absence on, or null when it cannot be told.
     */
    public function getAbsenceProjectFor(User $user): ?Project;
}
